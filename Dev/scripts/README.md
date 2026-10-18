# Smoke Test Scripts

Quick integration smoke tests that validate the running iroha Board Docker application.

These scripts target a **running** instance of the application (Docker) and are not unit tests.
They require `curl` and `jq` to be installed on the machine where they are executed.

## Prerequisites

| Dependency | Purpose |
|------------|---------|
| Docker app running on `http://localhost:8082` | Target application |
| `curl` | HTTP requests |
| `jq` | JSON parsing |
| Python 3.10+ / Playwright (Chromium) | GUI 操作テスト（`gui-image-upload.py` / `gui-test-resume.py`） |

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `BASE_URL` | `http://localhost:8082` | Application base URL |
| `API_USER` | `admin` | Login username |
| `API_PASS` | `adminpass` | Login password |
| `MCP_TOKEN` | *(auto-obtained)* | Bearer token for MCP smoke (optional) |

## scripts/smoke-api.sh

REST API smoke test. Checks:

1. **POST /api/v1/auth/token** — authenticates and extracts the Bearer token.
2. **GET /api/v1/courses** — fetches courses with the token; asserts HTTP 200.
3. **GET /api/v1/\_\_nope\_\_** — hits an unknown path; asserts HTTP 404 with `{error:{code:404}}`.

```bash
# Run with defaults
bash Dev/scripts/smoke-api.sh

# Run against a custom base URL
BASE_URL=http://localhost:8081 bash Dev/scripts/smoke-api.sh
```

## scripts/smoke-mcp.sh

MCP (Model Context Protocol) smoke test. Checks:

1. **initialize** — sends an MCP initialize request and establishes a session.
2. **tools/list** — asserts the expected tools are listed (現行 11、拡張後は拡張後の数。最小集合を検証)。
3. **tools/call list_courses** — calls the `list_courses` tool and asserts a result.

```bash
# Run with defaults (auto-obtains token)
bash Dev/scripts/smoke-mcp.sh

# Provide a pre-existing token
MCP_TOKEN="selector:validator" bash Dev/scripts/smoke-mcp.sh
```

## scripts/test-fresh.sh

PHPUnit をフレッシュなテスト DB で実行する。`Dev/tests/bootstrap.php` の Migrator は
テーブルを DROP しないため、テスト DB に Seeder 等の残留データがあると偽の失敗を
招くことがある。定常緑を確認する場合は本スクリプトを使う。

```bash
# テスト DB を作り直して全テスト実行
bash Dev/scripts/test-fresh.sh

# 個別ファイルも指定可（app/ からの相対パス）
bash Dev/scripts/test-fresh.sh ../Dev/tests/TestCase/Controller/Api/ApiContractTest.php
```

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_CONTAINER` | `irohaboard5-db-1` | MariaDB コンテナ名 |
| `DB_USER` / `DB_PASS` | `root` / `rootpass` | DB 資格情報 |
| `DB_NAME` | `irohaboard_test` | 作り直すテスト DB 名 |

## scripts/gui-image-upload.py

管理画面 EasyMDE の Markdown 画像アップロード/貼付けを実ブラウザで検証する GUI 操作テスト
（`Dev/Docs/design/15-mcp-extension-markdown-image.md` §9.6）。PHPUnit では検出できない
JS/DOM の不良（貼付け・ドラッグ&ドロップのハンドラ未登録、admin prefix 混入による 404 URL、
「プレビュー」ボタンでの本文未同期など）を検出する。21 チェック（ツールバー / Ctrl+V /
ドラッグ&ドロップ / プレビュー popup / 非画像拒否 / コンソールエラー）。

- 前提: Python 3.10+ / Playwright（`pip install playwright && playwright install chromium`）
- コンテンツは保存しない（新規作成画面でアップロードのみ行う）

```bash
python3 Dev/scripts/gui-image-upload.py

BASE_URL=http://localhost:8082 API_USER=admin API_PASS=adminpass \
  COURSE_ID=1 python3 Dev/scripts/gui-image-upload.py
```

| Variable | Default | Description |
|----------|---------|-------------|
| `BASE_URL` | `http://localhost:8082` | Application base URL |
| `API_USER` / `API_PASS` | `admin` / `adminpass` | 管理者ログイン |
| `COURSE_ID` | `1` | 検証に使うコース ID |

## scripts/gui-test-resume.py

試験のセッション切れ対策（回答の `localStorage` 退避・復元、出題リストの Cookie 退避）を
実ブラウザで検証する GUI 操作テスト（`Dev/Docs/design/16-test-answer-resume.md` §4.2）。
PHPUnit では検出できない JS / `localStorage` / Cookie の挙動を検証する。21 チェック。

1. REST API で受講登録（テスト前に未登録だった場合のみ、終了時に解除）
2. ログイン → テスト画面表示 → 全設問に回答
3. 回答が出題内容とともに `localStorage` へ、出題リストが Cookie へ退避されること
4. `context.clear_cookies()` でセッション切れを模擬 → ログイン画面へ遷移すること
5. 再ログイン後にテスト画面を開くと、同じ出題内容で回答が復元されること
   （復元は再読込なしの 1 リクエストで完了すること）
6. 採点結果画面へ遷移し、元と同じ出題内容が採点されること
7. 採点後に退避データ（`localStorage`・Cookie）が削除されること／JS コンソールエラーが無いこと

- 前提: Python 3.10+ / Playwright（`pip install playwright && playwright install chromium`）
- 採点により学習履歴（`ib_records`）が 1 件作成される（作成された `record_id` を出力する）
- `USER_ID` のユーザが `COURSE_ID` のコースを受講している必要がある（未登録なら自動登録）

```bash
python3 Dev/scripts/gui-test-resume.py

BASE_URL=http://localhost:8082 API_USER=admin API_PASS=adminpass \
  USER_ID=1 COURSE_ID=1 TEST_CONTENT_ID=7 python3 Dev/scripts/gui-test-resume.py
```

| Variable | Default | Description |
|----------|---------|-------------|
| `BASE_URL` | `http://localhost:8082` | Application base URL |
| `API_USER` / `API_PASS` | `admin` / `adminpass` | 受験するユーザのログイン |
| `USER_ID` | `1` | 受験するユーザ ID（`localStorage` のキーにも使用） |
| `COURSE_ID` | `1` | 受講登録を確認・作成するコース ID |
| `TEST_CONTENT_ID` | `7` | 受験するテストコンテンツ ID（複数設問があること） |

## Exit Codes

| Code | Meaning |
|------|---------|
| `0` | All checks passed |
| `1` | One or more checks failed, or the app is unreachable |

## Notes

- curl 系スクリプト（`smoke-api.sh` / `smoke-mcp.sh`）はデータを変更しない。
  `gui-image-upload.py` は画像ファイルを `webroot/uploads` へアップロードする（コンテンツは保存しない）。
  `gui-test-resume.py` は受講登録（未登録時のみ・終了時に解除）と学習履歴 1 件の作成を行う。
- The MCP smoke requires the `/mcp` endpoint to be active (Phase 2+ build).
- Each script prints `PASS` / `FAIL` per check and a summary at the end.
