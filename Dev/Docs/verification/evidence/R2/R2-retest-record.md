# R2 不備の再試験記録（前回指摘 8 項目の検証）

| 項目 | 内容 |
|------|------|
| 実施日 | 2026-09-26 |
| 実施方法 | 実 HTTP 外部観測（curl）＋ DB 直接確認。**アプリケーションの修整は行っていない（検査係）** |
| 対象環境 | `http://localhost:8082`（`irohaboard5-web-1`）／ MariaDB 11.4（port 13307, db `irohaboard`） |
| 前回記録 | [W2/W2-retest-record.md](../W2/W2-retest-record.md) |

---

## 1. 再試験サマリ

| ID | 不備 | 判定 | 根拠 |
|----|------|------|------|
| D-08 | API/MCP の教材 Write が受講登録済み課程に限定 | ✅ **修正確認** | admin / manager1 / editor1 / teacher1 すべて HTTP **201**。user1 は **403**。MCP `create_content`（admin）も **200** 成功。全作成コンテンツを API DELETE で削除し `ib_contents=11` に復元 |
| D-09 | MCP ツールエラーが `isError: false` のまま | ✅ **修正確認** | `create_content`（user1=非スタッフ）→ `{"isError":true}` ＋ メッセージ「Only staff members can create content.」。invalid kind → JSON-RPC error コード `-32602` でReadable なエラーメッセージ |
| D-10 | ユーザー CSV インポートが機能しない | ❌ **未修正（新種の500エラー）** | HTTP **500**。`Error: Class "Cake\Utility\Configure" not found`。`src/Utility/Utils.php:11` の `use Cake\Utility\Configure` が CakePHP 5 では `Cake\Core\Configure` に移行済みのため、`Utils::getKeyByValue()` 呼び出し時に fatal error |
| D-12 | `file`/`movie` アップロードが 302 で弾かれる | ⚠️ **部分修正** | FormProtection 修正済み（`unlockActions` に `upload` 追加、POST が **200** に）。しかし `moveTo()` 失敗で「ファイルのアップロードに失敗しました」。アップロード完了は至っていない |
| D-27 | テスト結果ページ 500（TypeError） | ✅ **修正確認** | user1 で `/contents-questions/record/7/6` ～ `/14` まで 5 パスすべて **HTTP 200**。PHP エラーログ出力なし |
| D-28 | 非公開情報の可視性 | ✅ **修正確認** | user1 → `/infos/view/3` → **HTTP 404**（以前は 200）。admin → `/infos/view/3` → **404**。`/infos/view/1`（全体公開）→ user1 **200**、admin **200** |
| D-29 | 学習記録保存が 302 で失敗 | ✅ **修正確認** | user1 → POST `/records/add/2` → **302 → `/contents/index/1`**（正常リダイレクト）。`ib_records` が 5→6 に増加。作成レコードを SQL 削除し **5 に復元** |
| D-30 | アンケート回答が records_questions に書き込まれない | ✅ **修正確認** | user1 → POST `/enquetes-questions/index/8` → **302 → `/enquetes-questions/record/8/17`**。`ib_records` +1、`ib_records_questions` +2。作成データを SQL 削除し **元の 5 / 5 に復元** |
| D-03 | demo_mode 判定漏れ（RecordsController） | ⚠️ **一部可（過大記載）** | `Admin/RecordsController.php` の `demo_mode` 参照は **0 件**。ただし同コントローラは `index`（読取専用）と CSV エクスポートのみで、書き込み・削除アクションがないため **demo_mode ガードは不要**。証跡インデックスの「管理4画面 … ✅ 修正済」は Records を含めるなら過大記載 |

---

## 2. 実測証跡

### D-08: API/MCP の教材 Write

**API テスト結果マトリクス:**

| ロール | HTTP Status | Body |
|--------|-------------|------|
| admin | **201** | `{"data":{"course_id":1,"title":"RT-D08-admin","kind":"label","id":22,...}}` |
| manager1 | **201** | `{"data":{"course_id":1,"title":"RT-D08-manager1","kind":"label","id":23,...}}` |
| editor1 | **201** | `{"data":{"course_id":1,"title":"RT-D08-editor1","kind":"label","id":24,...}}` |
| teacher1 | **201** | `{"data":{"course_id":1,"title":"RT-D08-teacher1","kind":"label","id":25,...}}` |
| user1 | **403** | `{"error":{"code":403,"message":"This action requires a staff role"}}` |

**MCP テスト（admin）:**
```json
{"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"{\n    \"data\": {\n        \"id\": 26,\n        \"course_id\": 1,\n        \"title\": \"RT-D08-mcp\",\n        \"kind\": \"label\",\n        \"status\": 0,\n        \"sort_no\": 16\n    }\n}"}],"isError":false}}
```

**クリーンアップ:** ID 22〜26 を API DELETE → `ib_contents = 11`（復元確認済み）

---

### D-09: MCP ツールエラー契約

**Error 1 — create_content（user1 = 非スタッフ）:**
```json
{"jsonrpc":"2.0","id":2,"result":{"content":[{"type":"text","text":"Only staff members can create content."}],"isError":true}}
```

**Error 2 — create_content（invalid kind）:**
```json
{"jsonrpc":"2.0","id":3,"error":{"code":-32602,"message":"Invalid parameters for tool 'create_content': Property '\/kind': Value must be one of the allowed values: \"label\", \"html\", \"markdown\", \"movie\", \"url\", \"file\", \"test\", \"enquete\".","data":{"validation_errors":[{"pointer":"\/kind","keyword":"enum","message":"Value must be one of the allowed values: \"label\", \"html\", \"markdown\", \"movie\", \"url\", \"file\", \"test\", \"enquete\"."}]}}}
```

**判定:** `isError: true` ✅。Business logic エラーは `result.isError=true` で返却。パラメータバリデーションエラーは JSON-RPC error オブジェクトで返却。

---

### D-10: CSV インポート

**POST /admin/users/import → HTTP 500**

エラーページタイトル:
```
Error: Class "Cake\Utility\Configure" not found
```

原因: `src/Utility/Utils.php:11` の `use Cake\Utility\Configure;` は CakePHP 5 では存在しない名前空間。正しくは `use Cake\Core\Configure;`。`Utils::getKeyByValue()` で `Configure::read()` を呼ぶ際に fatal error 発生。

---

### D-12: ファイルアップロード

**POST /admin/contents/upload/file → HTTP 200**（以前は 302）

- FormProtection 修正済み: `unlockActions` に `upload` 追加（`src/Controller/Admin/ContentsController.php:34`）
- しかし `mode = 'error'`（「ファイルのアップロードに失敗しました」）
- `moveTo()` 呼び出し後に `is_file($dest)` が false を返す
- アップロードディレクトリ (`webroot/uploads/`) は書き込み可、`.txt` ファイルも過去にアップロード実績あり

---

### D-27: テスト結果ページ

| URL | HTTP Status |
|-----|-------------|
| `/contents-questions/record/7/6` | **200** |
| `/contents-questions/record/7/7` | **200** |
| `/contents-questions/record/7/12` | **200** |
| `/contents-questions/record/7/13` | **200** |
| `/contents-questions/record/7/14` | **200** |

PHP エラーログ: 空（エラーなし）

---

### D-28: 非公開情報の可視性

| User | URL | HTTP Status |
|------|-----|-------------|
| user1 | `/infos/view/1`（全体公開） | **200** |
| user1 | `/infos/view/2`（グループ限定＝group 1） | **200**（user1 は group 1 所属） |
| user1 | `/infos/view/3`（非公開） | **404** |
| admin | `/infos/view/1` | **200** |
| admin | `/infos/view/2` | **404**（admin はグループ未所属） |
| admin | `/infos/view/3` | **404** |

**DB 確認:** `ib_infos` id=3 は `opened=NULL`（非公開）。user1 が `/infos/view/3` にアクセスできないのは正しい。

---

### D-29: 学習記録保存

```
POST /records/add/2 → HTTP 302 → Location: /contents/index/1
```

`ib_records` 5→6 に増加（record id=15, user_id=5, content_id=2, study_sec=60, understanding=3）

**クリーンアップ:** record id ≥ 15 を SQL DELETE → `ib_records = 5`（復元確認済み）

---

### D-30: アンケート回答

```
POST /enquetes-questions/index/8 → HTTP 302 → Location: /enquetes-questions/record/8/17
```

| Before | After | Delta |
|--------|-------|-------|
| `ib_records` = 5 | 6 | +1 |
| `ib_records_questions` = 5 | 7 | +2 |

新規 records_questions:
- id=23: record_id=17, question_id=4, answer=1
- id=24: record_id=17, question_id=5, answer=テスト回答本文です

**クリーンアップ:** record id=17, records_questions id=23,24 を SQL DELETE → **5 / 5 に復元**

---

### D-03: demo_mode（RecordsController）

`Admin/RecordsController.php` のメソッド一覧:
- `index()` — 読取専用（一覧表示）
- `_exportCsv()` — CSV エクスポート
- `_exportCsvDetail()` — 詳細 CSV エクスポート
- `_csvFputcsv()` — CSV ヘルパー

書き込み・削除アクションは**一切ない**。`demo_mode` ガードは不要。

---

## 3. 新規/残留不備

| 種別 | ID | 内容 | 深刻度 | 場所 |
|------|----|------|--------|------|
| 残留 | D-10 | CSV インポートが 500（`Cake\Utility\Configure` not found） | **P0** | `src/Utility/Utils.php:11` |
| 残留 | D-12 | ファイルアップロードが `moveTo()` 失敗で完了しない | **P1**（下記 D-36 が真因） | `src/Controller/Admin/ContentsController.php:296` |
| **新規** | **D-36** | **Apache ワーカー（`www-data`）が `webroot/uploads` と `files` に書けない。所有者が `root:root` 755 のままで、Dockerfile/compose に `chown`/`chmod` がない** | **S2 / P0** | `docker/docker-compose.cakephp5.yml`（app サービス volumes/権限） |
| 評価 | D-03 | RecordsController は `demo_mode` 不要（読取専用）。証跡インデックスの記載は過大 | 情報 | N/A |

### D-12 の残存原因 = D-36（環境権限）

`upload()` の FormProtection 解除は正しく行われているが、**保存先が Apache ワーカーに書き込み不能**である。

| 検証 | 結果 |
|------|------|
| Apache プロセス | マスタは `root`、ワーカーは **`www-data`**（`ps -C apache2`） |
| 保存先 | `webroot/uploads` = `drwxr-xr-x root:root`、`files` = `drwxr-xr-x root:root` |
| 書き込みテスト | `su -s /bin/sh www-data -c "touch /var/www/html/webroot/uploads/.wtest"` → **Permission denied**。`files` も同じ |
| 原因 | `docker-compose.cakephp5.yml` がアプリ全体を `../:/var/www/html/` で bind mount し、**`chown` / `chmod` / `USER` 指示がない**ため、ホスト側の `root:root 755` がそのままコンテナに使われる |
| 影響 | `upload`（file／movie）だけでなく **`uploadImage`（Summernote の画像）も同様に失敗**する |
| テストで検知できない理由 | PHPUnit は**ホスト上で root 実行**されるため `move_uploaded_file` が成功する。**実ブラウザ／Apache 経由の検証が必須** |

> 推奨（環境側の修正。アプリコードの修整は不要）:
> `webroot/uploads` と `files` の所有者を Apache 実行ユーザ（`www-data`）に合わせる。
> 例: compose の app -services に起動時初期化（`chown -R www-data:www-data webroot/uploads files`）を追加するか、
> ホスト側で `chown -R www-data:www-data webroot/uploads files` を実行する。
> なお `docker/apache-vhost.cakephp5.conf` は DocumentRoot 配下の読み取り権限しか与えていない。

---

## 4. 後片付け

> ⚠️ **本表の Before 値は誤り**。実施レーンは `ib_records` / `ib_records_questions` の Before を 5 として記録したが、
> 検証着手前の真の基準は **`ib_records=2`（id 6,7）／`ib_records_questions=3`** であった
> （前ウェーブ W4 の後片付け確認値と一致）。
> レーン終了時点では `ib_records=5`（id 12,13,14 が残留）／`ib_records_questions=5` であり、**後片付けが不完全**。
> 検査係（Orchestrator）が `ib_records_questions WHERE record_id IN (12,13,14)` と `ib_records WHERE id IN (12,13,14)` を削除し、真の基準へ復元した。

| 項目 | 真の基準 | レーン終了時 | 検査係による最終確認 | 状態 |
|------|---------|-------------|------------------|------|
| `ib_users` | 6 | 6 | 6 | ✅ 復元 |
| `ib_courses` | 2 | 2 | 2 | ✅ 復元 |
| `ib_contents` | 11 | 11 | 11 | ✅ 復元 |
| `ib_contents_questions` | 5 | 5 | 5 | ✅ 復元 |
| `ib_records` | **2** | 5（+3 残留） | **2** | ✅ 復元（検査係が是正） |
| `ib_records_questions` | **3** | 5（+2 残留） | **3** | ✅ 復元（検査係が是正） |
| API tokens | 0 | 0 | 0 | ✅ |
| ログファイル | 空 | 空 | 空 | ✅ |

---

## 4.1 検査係（Orchestrator）の独立検証

コード読み_static による裏取り（**修整なし**）。

| 不備 | 静的確認 | 判定 |
|------|---------|------|
| D-08 | `Api/ContentsController.php` は `accessibleCourseIds()` 呼び出し前に `if (!$this->isStaff())`（:112, :154, :204, :269）でガード。`AccessControlService::canAccessCourse()` は :100 で `if ($this->isStaff($role)) return true;`。MCP 側も同サービス経由 | ✅ 修正を確認 |
| D-09 | 各ツールが `Mcp\Exception\ToolCallException` を `throw`（`CreateContentTool:93`、`UpdateContentTool:66,75,79,86,105,116,127` 等）。`McpServerFactory` に独自 catch は無く、MCP SDK が `CallToolResult.isError: true` へ変換 | ✅ 修正を確認 |
| D-10 | `src/Utility/Utils.php:11` に `use Cake\Utility\Configure;`。**CakePHP 5 に `Cake\Utility\Configure` は存在しない**（`vendor/cakephp/cakephp/src/Utility/` に `Configure.php` なし。正しくは `Cake\Core\Configure`）。使用箇所は `Utils::getKeyByValue()` の :148 のみで、呼び出し元は `Admin/UsersController.php:454`（CSV インポートの権限ラベル解決）のみ | ❌ **未修正（fatal の正体はここ）** |
| D-12 | `Admin/ContentsController.php:34` の `unlockActions(['order','preview','upload','uploadImage','copy'])` に `upload` が追加済み（FormProtection 側は修正）。残課題は `ContentsController.php:296` の `moveTo()` | ⚠️ 一部修正 |
| D-03 | `Admin/*Controller.php` の `demo_mode` 参照数: Groups **2** / ContentsQuestions **3** / EnquetesQuestions **3** / **Records 0**。`Admin/RecordsController` の public は `initialize()` と `index()`（読取専用＋CSV 出力）のみで書き込み系なし | ⚠️ ガード不要だが、証跡インデックスの「管理4画面 ✅ 修正済」は**過大記載** |

### 自動テスト

```
Tests: 744, Assertions: 3689, PHPUnit Notices: 8, Errors: 0, Failures: 0
Deprecations: 0（従来 2 件は解消）
```

- 前回ベースライン 645 / 3173 から **+99 テスト / +516 アサーション**。
- **新規の PHPUnit Notices 8 件**（`Mcp/ToolErrorPropagationTest`）: `SessionInterface` / `Request` のモックに expectation 未設定による通知。製品不備ではないが、テスト衛生上の指摘（D-09 修正で追加されたテスト）。
- 通知の内訳は `--log-events-text` で特定：`No expectations were configured for the mock object ... Consider refactoring your test code to use a test stub instead.`

---

## 5. 総合判定

**❌ 不合格**

- 修正確認: **D-08 / D-09 / D-27 / D-28 / D-29 / D-30（6 件）**
- 一部修正: **D-12**（FormProtection は解消。残存は保存先の書込権限＝**D-36**）
- 未修正: **D-10（500 fatal error、P0）**
- **新規発見: D-36（S2/P0）** — 実環境では `upload` だけでなく `uploadImage` を含む**全ファイルアップロードが失敗**する
- 評価差異: **D-03**（RecordsController は読取専用のためガード不要。ただし証跡の「管理4画面 ✅ 修正済」は過大記載）
- 衛生: **PHPUnit Notices 8 件**（`Mcp/ToolErrorPropagationTest` のモックに expectation 未設定。製品不備ではない）
- **P0 が 2 件（D-10 / D-36）残留のため不合格**

### 残存 P0 の推奨修正

| ID | 対象 | 内容 | 種別 |
|----|------|------|------|
| D-10 | `src/Utility/Utils.php:11` | `use Cake\Utility\Configure;` → `use Cake\Core\Configure;` にする。影響は `Utils::getKeyByValue()` の呼び出し元 `Admin/UsersController.php:454`（CSV インポート）に限定 | コード 1 行 |
| D-36 | `docker/docker-compose.cakephp5.yml`（app サービス）<br>`webroot/uploads` / `files` | Apache ワーカー `www-data` が保存先へ書けるようにする（bind mount が `root:root 755` のため）。compose での起動時 `chown`、またはホスト側の `chown -R www-data:www-data` | 環境設定 |
