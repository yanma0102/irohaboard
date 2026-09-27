#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# smoke-mcp.sh — MCP (Model Context Protocol) smoke test against a running
#                 iroha Board instance
#
# Prerequisites:
#   - Docker app running at $BASE_URL (default http://localhost:8082)
#   - curl and jq installed
#   - Valid API token (from env or obtained via auth endpoint)
#
# Environment variables:
#   BASE_URL  - Application base URL          (default: http://localhost:8082)
#   MCP_TOKEN - Bearer token for MCP auth     (optional; auto-obtained if empty)
#   API_USER  - Login username for auto-token (default: admin)
#   API_PASS  - Login password for auto-token (default: adminpass)
# ---------------------------------------------------------------------------

BASE_URL="${BASE_URL:-http://localhost:8082}"
API_USER="${API_USER:-admin}"
API_PASS="${API_PASS:-adminpass}"
MCP_TOKEN="${MCP_TOKEN:-}"

PASS_COUNT=0
FAIL_COUNT=0

pass() { printf "  PASS: %s\n" "$1"; PASS_COUNT=$((PASS_COUNT + 1)); }
fail() { printf "  FAIL: %s\n" "$1"; FAIL_COUNT=$((FAIL_COUNT + 1)); }

# ---------------------------------------------------------------------------
# Pre-flight: check that the base URL is reachable
# ---------------------------------------------------------------------------
printf "Connecting to %s …\n" "$BASE_URL"
if ! curl -sf --max-time 5 -o /dev/null "$BASE_URL" 2>/dev/null; then
    printf "ERROR: Cannot reach %s\n" "$BASE_URL"
    printf "Make sure the Docker app is running.\n"
    exit 1
fi
printf "Base URL reachable.\n\n"

# ---------------------------------------------------------------------------
# Obtain a Bearer token if not provided via env
# ---------------------------------------------------------------------------
if [ -z "$MCP_TOKEN" ]; then
    printf "MCP_TOKEN not set — authenticating via /api/v1/auth/token …\n"
    AUTH_RESPONSE=$(curl -s -w "\n%{http_code}" \
        -X POST "$BASE_URL/api/v1/auth/token" \
        -H "Content-Type: application/json" \
        -d "{\"username\":\"$API_USER\",\"password\":\"$API_PASS\"}")

    AUTH_BODY=$(echo "$AUTH_RESPONSE" | sed '$d')
    AUTH_STATUS=$(echo "$AUTH_RESPONSE" | tail -n1)

    if [ "$AUTH_STATUS" -ne 201 ]; then
        printf "ERROR: Authentication failed (HTTP %s)\n" "$AUTH_STATUS"
        printf "Response: %s\n" "$AUTH_BODY"
        exit 1
    fi

    MCP_TOKEN=$(echo "$AUTH_BODY" | jq -r '.data.token // empty')
    if [ -z "$MCP_TOKEN" ]; then
        printf "ERROR: Could not extract token from auth response\n"
        printf "Response: %s\n" "$AUTH_BODY"
        exit 1
    fi
    printf "Token obtained.\n\n"
fi

# ---------------------------------------------------------------------------
# Helper: send a JSON-RPC request to /mcp and capture response + session id
# ---------------------------------------------------------------------------
SESSION_ID=""

mcp_request() {
    local method="$1"
    # NOTE: Do NOT use ${2:-{}} — bash closes the expansion at the first '}',
    # which appends a literal '}' and corrupts the JSON body.
    local params="$2"
    if [ -z "$params" ]; then
        params='{}'
    fi
    local rpc_id="${3:-1}"

    local body
    body=$(printf '{"jsonrpc":"2.0","id":%s,"method":"%s","params":%s}' \
        "$rpc_id" "$method" "$params")

    local curl_args=(
        -s -w "\n%{http_code}"
        -X POST "$BASE_URL/mcp"
        -H "Content-Type: application/json"
        -H "Accept: application/json, text/event-stream"
        -H "Authorization: Bearer $MCP_TOKEN"
    )

    # Include session id header if we have one
    if [ -n "$SESSION_ID" ]; then
        curl_args+=(-H "Mcp-Session-Id: $SESSION_ID")
    fi

    curl_args+=(-d "$body")

    local response
    response=$(curl "${curl_args[@]}")

    local resp_body http_status
    resp_body=$(echo "$response" | sed '$d')
    http_status=$(echo "$response" | tail -n1)

    # Capture Mcp-Session-Id from response headers if present
    # We re-request with -D to capture headers for the initialize call
    if [ "$method" = "initialize" ] && [ -z "$SESSION_ID" ]; then
        local header_output
        header_output=$(curl -s -D - -o /dev/null \
            -X POST "$BASE_URL/mcp" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json, text/event-stream" \
            -H "Authorization: Bearer $MCP_TOKEN" \
            -d "$body")
        SESSION_ID=$(echo "$header_output" | grep -i "^Mcp-Session-Id:" | tr -d '\r' | awk '{print $2}' || true)
    fi

    printf "%s\n%s" "$resp_body" "$http_status"
}

# ---------------------------------------------------------------------------
# 1. MCP initialize — establish session
# ---------------------------------------------------------------------------
printf "1. MCP initialize\n"

INIT_RESPONSE=$(mcp_request "initialize" '{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"smoke-test","version":"1.0.0"}}' 1)
INIT_BODY=$(echo "$INIT_RESPONSE" | sed '$d')
INIT_STATUS=$(echo "$INIT_RESPONSE" | tail -n1)

if [ "$INIT_STATUS" -ne 200 ]; then
    fail "Expected HTTP 200 from initialize, got $INIT_STATUS"
    printf "Response body: %s\n" "$INIT_BODY"
    printf "\nRESULT: %d passed, %d failed\n" "$PASS_COUNT" "$FAIL_COUNT"
    exit 1
fi

# Capture session id from first call headers if not yet captured
if [ -z "$SESSION_ID" ]; then
    SESSION_ID=$(curl -s -D - -o /dev/null \
        -X POST "$BASE_URL/mcp" \
        -H "Content-Type: application/json" \
        -H "Accept: application/json, text/event-stream" \
        -H "Authorization: Bearer $MCP_TOKEN" \
        -d '{"jsonrpc":"2.0","id":99,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"smoke-test","version":"1.0.0"}}}' \
        | grep -i "^Mcp-Session-Id:" | tr -d '\r' | awk '{print $2}' || true)
fi

SERVER_NAME=$(echo "$INIT_BODY" | jq -r '.result.serverInfo.name // empty')
pass "MCP initialize succeeded (server: ${SERVER_NAME:-unknown})"

# Send initialized notification (required by MCP protocol after initialize)
printf "\n  Sending notifications/initialized …\n"
NOTIF_RESPONSE=$(mcp_request "notifications/initialized" '{}' 2)
NOTIF_STATUS=$(echo "$NOTIF_RESPONSE" | tail -n1)
# notifications don't expect a response body, accept 200 or empty
pass "notifications/initialized sent"

# ---------------------------------------------------------------------------
# 2. tools/list — expect 9 tools (7 read-only + 2 write)
#    The McpServerFactory registers 9 tools total.
# ---------------------------------------------------------------------------
printf "\n2. MCP tools/list\n"

TOOLS_RESPONSE=$(mcp_request "tools/list" '{}' 10)
TOOLS_BODY=$(echo "$TOOLS_RESPONSE" | sed '$d')
TOOLS_STATUS=$(echo "$TOOLS_RESPONSE" | tail -n1)

if [ "$TOOLS_STATUS" -ne 200 ]; then
    fail "Expected HTTP 200 from tools/list, got $TOOLS_STATUS"
    printf "Response body: %s\n" "$TOOLS_BODY"
else
    TOOL_COUNT=$(echo "$TOOLS_BODY" | jq '.result.tools | length')
    TOOL_NAMES=$(echo "$TOOLS_BODY" | jq -r '.result.tools[].name' | sort)

    printf "  Tools found (%d): %s\n" "$TOOL_COUNT" "$(echo $TOOL_NAMES | tr '\n' ', ')"

    EXPECTED_TOOLS="create_content
get_content
get_content_html
get_course
get_user_profile
list_contents
list_courses
list_records
update_content"

    ALL_FOUND=true
    for tool in $EXPECTED_TOOLS; do
        if ! echo "$TOOL_NAMES" | grep -qx "$tool"; then
            fail "Expected tool '$tool' not found in tools/list"
            ALL_FOUND=false
        fi
    done

    if [ "$ALL_FOUND" = true ]; then
        pass "tools/list returned all expected tools ($TOOL_COUNT total)"
    fi
fi

# ---------------------------------------------------------------------------
# 3. tools/call list_courses — expect a result
# ---------------------------------------------------------------------------
printf "\n3. MCP tools/call list_courses\n"

CALL_RESPONSE=$(mcp_request "tools/call" '{"name":"list_courses","arguments":{}}' 20)
CALL_BODY=$(echo "$CALL_RESPONSE" | sed '$d')
CALL_STATUS=$(echo "$CALL_RESPONSE" | tail -n1)

if [ "$CALL_STATUS" -ne 200 ]; then
    fail "Expected HTTP 200 from tools/call, got $CALL_STATUS"
    printf "Response body: %s\n" "$CALL_BODY"
else
    # Check for isError in the result
    IS_ERROR=$(echo "$CALL_BODY" | jq -r '.result.isError // false')
    if [ "$IS_ERROR" = "true" ]; then
        ERROR_CONTENT=$(echo "$CALL_BODY" | jq -r '.result.content[]?.text // empty' | head -1)
        fail "tools/call list_courses returned an error: ${ERROR_CONTENT:-unknown}"
        printf "Response body: %s\n" "$CALL_BODY"
    else
        # Verify there is content in the result
        CONTENT_LENGTH=$(echo "$CALL_BODY" | jq '.result.content | length')
        if [ "$CONTENT_LENGTH" -gt 0 ] 2>/dev/null; then
            pass "tools/call list_courses returned a result with $CONTENT_LENGTH content block(s)"
        else
            fail "tools/call list_courses returned empty content"
            printf "Response body: %s\n" "$CALL_BODY"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
printf "\n==============================\n"
printf "RESULT: %d passed, %d failed\n" "$PASS_COUNT" "$FAIL_COUNT"
if [ "$FAIL_COUNT" -gt 0 ]; then
    exit 1
fi
exit 0
