#!/usr/bin/env bash
#
# reconcile-migrations.sh
#
# CakePHP Migrations の適用状況を既存スキーマと照合し、
# 既に適用済みのマイグレーションを cake_migrations テーブルに記録する。
#
# 本番DBがダンプ/復元で構築され、cake_migrations が空の状態から
# 安全にマイグレーション基盤を整備するためのスクリプト。
#
# 使い方:
#   ./reconcile-migrations.sh [--dry-run] [-h|--help]
#
# 環境変数:
#   DB_HOST   データベースホスト（既定: localhost）
#   DB_PORT   データベースポート（既定: 3306）
#   DB_NAME   データベース名   （既定: irohaboard）
#   DB_USER   ユーザー名       （既定: root）
#   DB_PASS   パスワード       （既定: rootpass）
#
# 注意:
#   - 冪等: 既に記録済みのバージョンはスキップ（INSERT IGNORE 相当）
#   - 破壊的操作: 一切含まない（DROP / TRUNCATE / DELETE なし）
#   - 本番実行前に必ず --dry-run で確認すること
#   - DB バックアップを推奨する
#
set -euo pipefail

# ── 設定 ─────────────────────────────────────────────────────
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-irohaboard}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-rootpass}"

DRY_RUN=false

# ── 引数解析 ─────────────────────────────────────────────────
show_help() {
  cat <<'EOF'
Usage: reconcile-migrations.sh [OPTIONS]

Options:
  --dry-run, -n   実際の INSERT を行わず、検証結果と実行予約のみ表示する
  -h, --help      このヘルプを表示する

Environment variables:
  DB_HOST   (default: localhost)
  DB_PORT   (default: 3306)
  DB_NAME   (default: irohaboard)
  DB_USER   (default: root)
  DB_PASS   (default: rootpass)

Steps:
  1. Run with --dry-run to verify which migrations can be marked as applied
  2. Review the output carefully
  3. Run without --dry-run to apply the changes
  4. Verify with: bin/cake migrations status (all should show "up")
EOF
}

for arg in "$@"; do
  case "$arg" in
    --dry-run|-n) DRY_RUN=true ;;
    -h|--help)    show_help; exit 0 ;;
    *)
      echo "ERROR: Unknown option: $arg" >&2
      echo "Run with -h for usage." >&2
      exit 1
      ;;
  esac
done

# ── 共通ユーティリティ ───────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

info()  { echo -e "${CYAN}[INFO]${NC}  $*"; }
ok()    { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
fail()  { echo -e "${RED}[FAIL]${NC}  $*"; }
header(){ echo -e "\n${CYAN}════════════════════════════════════════════════════════${NC}"; echo -e "${CYAN}  $*${NC}"; echo -e "${CYAN}════════════════════════════════════════════════════════${NC}"; }

# MySQL クエリ実行ラッパー
# docker exec 経由でも、直接 mysql クライアントでも動くようにする
run_sql() {
  local sql="$1"
  # ローカル mysql クライアントが利用可能なら直接実行
  if command -v mysql &>/dev/null; then
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
      --batch --skip-column-names -e "$sql" 2>/dev/null
  else
    echo "ERROR: mysql client not found. Install mysql-client or run inside a container." >&2
    exit 1
  fi
}

# SHOW COLUMNS の結果に指定カラムが含まれるか確認
column_exists() {
  local table="$1"
  local column="$2"
  local result
  result=$(run_sql "SHOW COLUMNS FROM \`${table}\` LIKE '${column}';" 2>/dev/null)
  [[ -n "$result" ]]
}

# SHOW INDEX の結果に指定インデックス名が含まれるか確認
index_exists() {
  local table="$1"
  local index_name="$2"
  local result
  result=$(run_sql "SHOW INDEX FROM \`${table}\` WHERE Key_name = '${index_name}';" 2>/dev/null)
  [[ -n "$result" ]]
}

# テーブルが存在するか確認
table_exists() {
  local table="$1"
  local result
  result=$(run_sql "SHOW TABLES LIKE '${table}';" 2>/dev/null)
  [[ -n "$result" ]]
}

# cake_migrations に指定バージョンが既に記録済みか確認
already_recorded() {
  local version="$1"
  local result
  result=$(run_sql "SELECT COUNT(*) FROM cake_migrations WHERE version = ${version};" 2>/dev/null)
  [[ "$result" -gt 0 ]]
}

# ── 接続テスト ───────────────────────────────────────────────
header "Step 0: DB接続テスト"
info "Host: ${DB_HOST}:${DB_PORT}  DB: ${DB_NAME}  User: ${DB_USER}"

if ! run_sql "SELECT 1;" &>/dev/null; then
  fail "データベースに接続できません。接続情報を確認してください。"
  exit 1
fi
ok "DB接続成功"

# ── マイグレーション定義 ────────────────────────────────────
# 各マイグレーションにつき「検証関数」と「記録情報を定義する。
# 検証パスした場合のみ INSERT 対象とする。
#
# フォーマット: validate_<version>() が 0 を返せば適用済みと判定

VERSIONS=(20260921235832 20260927000000 20260928000000 20260929000000)
NAMES=(
  "InitialSchema"
  "CreateConfigOverrides"
  "AddUniqueIndexesToJunctionTables"
  "AddIsActiveToUsers"
)

# ── 検証関数群 ──────────────────────────────────────────────
# Migration 1: InitialSchema
# app/config/Migrations/20260921235832_InitialSchema.php で作成される
# 全16テーブルの存在を確認
validate_20260921235832() {
  local all_ok=true
  local tables=(
    ib_cake_sessions
    ib_contents
    ib_contents_questions
    ib_courses
    ib_groups
    ib_groups_courses
    ib_infos
    ib_infos_groups
    ib_logs
    ib_records
    ib_records_questions
    ib_settings
    ib_user_tokens
    ib_users
    ib_users_courses
    ib_users_groups
  )

  for t in "${tables[@]}"; do
    if ! table_exists "$t"; then
      fail "  テーブル '${t}' が存在しません"
      all_ok=false
    fi
  done

  # 主要テーブルの主要カラムも確認（軽量チェック）
  local columns_ok=true
  if ! column_exists "ib_users" "username"; then
    fail "  ib_users.username カラムが存在しません"
    columns_ok=false
  fi
  if ! column_exists "ib_users" "deleted"; then
    fail "  ib_users.deleted カラムが存在しません"
    columns_ok=false
  fi
  if ! column_exists "ib_contents" "course_id"; then
    fail "  ib_contents.course_id カラムが存在しません"
    columns_ok=false
  fi
  if ! column_exists "ib_records" "course_id"; then
    fail "  ib_records.course_id カラムが存在しません"
    columns_ok=false
  fi

  if $all_ok && $columns_ok; then
    return 0
  fi
  return 1
}

# Migration 2: CreateConfigOverrides
# app/config/Migrations/20260927000000_CreateConfigOverrides.php で
# ib_config_overrides テーブル（id, config_key, config_value, created, modified）
# を作成
validate_20260927000000() {
  if ! table_exists "ib_config_overrides"; then
    fail "  テーブル 'ib_config_overrides' が存在しません"
    return 1
  fi

  local ok=true
  for col in id config_key config_value created modified; do
    if ! column_exists "ib_config_overrides" "$col"; then
      fail "  ib_config_overrides.${col} カラムが存在しません"
      ok=false
    fi
  done

  # uk_config_key 一意インデックスの存在確認
  if ! index_exists "ib_config_overrides" "uk_config_key"; then
    fail "  インデックス 'uk_config_key' が存在しません"
    ok=false
  fi

  $ok
}

# Migration 3: AddUniqueIndexesToJunctionTables
# app/config/Migrations/20260928000000_AddUniqueIndexesToJunctionTables.php で
# - uk_user_course (ib_users_courses.user_id, course_id)
# - uk_user_group (ib_users_groups.user_id, group_id)
# - uk_setting_key (ib_settings.setting_key)
# の一意インデックスを追加
validate_20260928000000() {
  local ok=true

  if ! index_exists "ib_users_courses" "uk_user_course"; then
    fail "  インデックス 'uk_user_course' が存在しません (ib_users_courses)"
    ok=false
  fi
  if ! index_exists "ib_users_groups" "uk_user_group"; then
    fail "  インデックス 'uk_user_group' が存在しません (ib_users_groups)"
    ok=false
  fi
  if ! index_exists "ib_settings" "uk_setting_key"; then
    fail "  インデックス 'uk_setting_key' が存在しません (ib_settings)"
    ok=false
  fi

  $ok
}

# Migration 4: AddIsActiveToUsers
# app/config/Migrations/20260929000000_AddIsActiveToUsers.php で
# ib_users.is_active カラムを追加
validate_20260929000000() {
  if ! column_exists "ib_users" "is_active"; then
    fail "  ib_users.is_active カラムが存在しません"
    return 1
  fi
  return 0
}

# ── メインロジック ──────────────────────────────────────────
header "Step 1: マイグレーションスキーマ検証"

declare -A RESULTS  # version -> "pass" | "fail" | "skip"
declare -A EXISTING_COUNTS  # version -> 既存行数

total=0
pass_count=0
skip_count=0
fail_count=0

for i in "${!VERSIONS[@]}"; do
  ver="${VERSIONS[$i]}"
  name="${NAMES[$i]}"
  total=$((total + 1))

  echo ""
  info "[${ver}] ${name}"

  # 既に cake_migrations に記録済みか？
  if already_recorded "$ver"; then
    existing_count=$(run_sql "SELECT COUNT(*) FROM cake_migrations WHERE version = ${ver};" 2>/dev/null)
    EXISTING_COUNTS[$ver]=$existing_count
    ok "  -> 既に記録済み (${existing_count}行)。スキップ。"
    RESULTS[$ver]="skip"
    skip_count=$((skip_count + 1))
    continue
  fi

  # スキーマ検証
  if "validate_${ver}"; then
    ok "  -> スキーマ検証パス（適用済みと判定）"
    RESULTS[$ver]="pass"
    pass_count=$((pass_count + 1))
  else
    fail "  -> スキーマ検証失敗（記録対象外）"
    RESULTS[$ver]="fail"
    fail_count=$((fail_count + 1))
  fi
done

# ── 結果サマリー ────────────────────────────────────────────
header "Step 2: サマリー"

echo ""
echo "  合計:       ${total} 件"
echo -e "  ${GREEN}スキップ:   ${skip_count} 件（既に記録済み）${NC}"
echo -e "  ${GREEN}適用対象:   ${pass_count} 件（検証パス → 記録予定）${NC}"
if [[ $fail_count -gt 0 ]]; then
  echo -e "  ${RED}失敗:       ${fail_count} 件（スキーマ不整合 → 記録しない）${NC}"
fi

# ── INSERT 実行 ─────────────────────────────────────────────
header "Step 3: マイグレーション記録"

if [[ $pass_count -eq 0 ]]; then
  ok "記録対象なし。すべてスキップまたは失敗です。"
  echo ""
  if $DRY_RUN; then
    info "(dry-run モード) 実際の INSERT はスキップしました。"
  fi
  exit 0
fi

if $DRY_RUN; then
  info "=== DRY-RUN モード: 実際の INSERT は行いません ==="
  echo ""
  for i in "${!VERSIONS[@]}"; do
    ver="${VERSIONS[$i]}"
    name="${NAMES[$i]}"
    if [[ "${RESULTS[$ver]}" == "pass" ]]; then
      info "[INSERT予定] version=${ver} migration_name=${name}"
    fi
  done
  echo ""
  info "実際の適用は --dry-run を外して再実行してください。"
  exit 0
fi

# 実行モード
inserted=0
for i in "${!VERSIONS[@]}"; do
  ver="${VERSIONS[$i]}"
  name="${NAMES[$i]}"

  if [[ "${RESULTS[$ver]}" != "pass" ]]; then
    continue
  fi

  # 再チェック（冪等性の保証）
  if already_recorded "$ver"; then
    warn "[${ver}] ${name}: 実行直前に既記録を検出。スキップ。"
    continue
  fi

  info "[INSERT] version=${ver} migration_name=${name}"

  run_sql "
    INSERT INTO cake_migrations (version, migration_name, plugin, start_time, end_time, breakpoint)
    VALUES (${ver}, '${name}', NULL, NOW(), NOW(), 0);
  "

  if [[ $? -eq 0 ]]; then
    ok "  -> 記録完了"
    inserted=$((inserted + 1))
  else
    fail "  -> 記録失敗"
  fi
done

echo ""
ok "完了: ${inserted} 件のマイグレーションを記録しました。"

# ── 最終確認 ─────────────────────────────────────────────────
header "Step 4: 最終確認 (cake_migrations 内容)"
run_sql "SELECT id, version, migration_name, plugin, start_time, end_time, breakpoint FROM cake_migrations ORDER BY version;"

echo ""
info "bin/cake migrations status で全件 'up' になることを確認してください。"
