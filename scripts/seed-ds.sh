#!/usr/bin/env bash
#
# seed-ds.sh — Run irohaboard DS-* test-data seeds in order.
#
# Usage:
#   DB_NAME=irohaboard_test DB_PORT=13307 ./scripts/seed-ds.sh
#
# Environment variables (all optional, with defaults):
#   DB_NAME   — database name            (default: irohaboard)
#   DB_PORT   — MariaDB/MySQL port       (default: 13307)
#   DB_HOST   — database host            (default: 127.0.0.1)
#   DB_USER   — database user            (default: root)
#   DB_PASS   — database password        (default: rootpass)
#   FORCE     — 1 to pass --force        (default: 0)
#
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-13307}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-rootpass}"
DB_NAME="${DB_NAME:-irohaboard}"
FORCE="${FORCE:-0}"

# Resolve the project root (parent of scripts/)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
CAKE="${PROJECT_ROOT}/bin/cake"

FORCE_FLAG=""
if [ "$FORCE" = "1" ]; then
    FORCE_FLAG="--force"
fi

echo "=== irohaboard DS Seed Runner ==="
echo "Database: ${DB_NAME}@${DB_HOST}:${DB_PORT} (user: ${DB_USER})"
echo ""

# Seeds in dependency order
SEEDS=(
    Ds0BaselineSeed
    Ds1UsersSeed
    Ds2ContentsSeed
    Ds3QuestionsSeed
    Ds5InfosSettingsSeed
    Ds7MarkdownSeed
)

for SEED in "${SEEDS[@]}"; do
    echo "--- Running ${SEED} ---"
    DB_HOST="$DB_HOST" \
    DB_PORT="$DB_PORT" \
    DB_USER="$DB_USER" \
    DB_PASS="$DB_PASS" \
    DB_NAME="$DB_NAME" \
    "$CAKE" seeds run "$SEED" \
        --source Seeds \
        --force \
        -q
    echo ""
done

echo "=== All seeds completed ==="
