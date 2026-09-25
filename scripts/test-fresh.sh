#!/usr/bin/env bash
#
# フレッシュなテスト DB (irohaboard_test) を作り直してから PHPUnit を実行する。
#
# 背景: tests/bootstrap.php の Migrator はスキーマを再作成するがテーブルを
#       DROP しない。テスト DB に Seeder 等の残留データがあると、テストが
#       偽の失敗（例: API 契約テストの誤検知）を起こすことがある。
#       定常緑を確認する場合は本スクリプトを使う。
#
# 使い方:
#   bash scripts/test-fresh.sh
#   bash scripts/test-fresh.sh tests/TestCase/Controller/Api/ApiContractTest.php
#
# 環境変数（既定は docker-compose.cakephp5.yml 想定）:
#   DB_CONTAINER=irohaboard5-db-1
#   DB_USER=root  DB_PASS=rootpass  DB_NAME=irohaboard_test

set -euo pipefail

DB_CONTAINER="${DB_CONTAINER:-irohaboard5-db-1}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-rootpass}"
DB_NAME="${DB_NAME:-irohaboard_test}"

echo "[test-fresh] recreating '${DB_NAME}' on '${DB_CONTAINER}' ..."
docker exec "${DB_CONTAINER}" mariadb -u"${DB_USER}" -p"${DB_PASS}" \
  -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

echo "[test-fresh] running phpunit ..."
vendor/bin/phpunit --colors=always "$@"
