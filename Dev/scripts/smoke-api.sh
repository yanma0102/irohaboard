#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# smoke-api.sh — REST API smoke test against a running iroha Board instance
#
# Prerequisites:
#   - Docker app running at $BASE_URL (default http://localhost:8082)
#   - curl and jq installed
#
# Environment variables:
#   BASE_URL   - Application base URL        (default: http://localhost:8082)
#   API_USER   - Login username              (default: admin)
#   API_PASS   - Login password              (default: adminpass)
# ---------------------------------------------------------------------------

BASE_URL="${BASE_URL:-http://localhost:8082}"
API_USER="${API_USER:-admin}"
API_PASS="${API_PASS:-adminpass}"

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
# 1. POST /api/v1/auth/token  — obtain a Bearer token
# ---------------------------------------------------------------------------
printf "1. Authenticate via POST /api/v1/auth/token\n"

AUTH_RESPONSE=$(curl -s -w "\n%{http_code}" \
    -X POST "$BASE_URL/api/v1/auth/token" \
    -H "Content-Type: application/json" \
    -d "{\"username\":\"$API_USER\",\"password\":\"$API_PASS\"}")

AUTH_BODY=$(echo "$AUTH_RESPONSE" | sed '$d')
AUTH_STATUS=$(echo "$AUTH_RESPONSE" | tail -n1)

if [ "$AUTH_STATUS" -ne 201 ]; then
    fail "Expected HTTP 201, got $AUTH_STATUS"
    printf "Response body: %s\n" "$AUTH_BODY"
    printf "\nRESULT: %d passed, %d failed\n" "$PASS_COUNT" "$FAIL_COUNT"
    exit 1
fi

TOKEN=$(echo "$AUTH_BODY" | jq -r '.data.token // empty')
if [ -z "$TOKEN" ]; then
    fail "Could not extract token from response"
    printf "Response body: %s\n" "$AUTH_BODY"
    printf "\nRESULT: %d passed, %d failed\n" "$PASS_COUNT" "$FAIL_COUNT"
    exit 1
fi

pass "Authenticated – token obtained"

# ---------------------------------------------------------------------------
# 2. GET /api/v1/courses  — expect HTTP 200
# ---------------------------------------------------------------------------
printf "\n2. GET /api/v1/courses (Bearer token)\n"

COURSES_RESPONSE=$(curl -s -w "\n%{http_code}" \
    -H "Authorization: Bearer $TOKEN" \
    "$BASE_URL/api/v1/courses")

COURSES_BODY=$(echo "$COURSES_RESPONSE" | sed '$d')
COURSES_STATUS=$(echo "$COURSES_RESPONSE" | tail -n1)

if [ "$COURSES_STATUS" -ne 200 ]; then
    fail "Expected HTTP 200, got $COURSES_STATUS"
    printf "Response body: %s\n" "$COURSES_BODY"
else
    pass "GET /api/v1/courses returned HTTP 200"
fi

# ---------------------------------------------------------------------------
# 3. GET /api/v1/__nope__  — expect HTTP 404 with JSON {error:{code:404}}
# ---------------------------------------------------------------------------
printf "\n3. GET /api/v1/__nope__ (expect 404)\n"

NOTFOUND_RESPONSE=$(curl -s -w "\n%{http_code}" \
    -H "Authorization: Bearer $TOKEN" \
    "$BASE_URL/api/v1/__nope__")

NOTFOUND_BODY=$(echo "$NOTFOUND_RESPONSE" | sed '$d')
NOTFOUND_STATUS=$(echo "$NOTFOUND_RESPONSE" | tail -n1)

if [ "$NOTFOUND_STATUS" -ne 404 ]; then
    fail "Expected HTTP 404, got $NOTFOUND_STATUS"
    printf "Response body: %s\n" "$NOTFOUND_BODY"
else
    ERROR_CODE=$(echo "$NOTFOUND_BODY" | jq -r '.error.code // empty')
    if [ "$ERROR_CODE" = "404" ]; then
        pass "GET /api/v1/__nope__ returned HTTP 404 with {error:{code:404}}"
    else
        fail "HTTP 404 but error.code is not 404"
        printf "Response body: %s\n" "$NOTFOUND_BODY"
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
