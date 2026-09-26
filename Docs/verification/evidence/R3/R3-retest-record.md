# R3 再試験記録（P0 残存 2 件の再検証 ＋ 回帰確認）

| 項目 | 内容 |
|------|------|
| 実施日 | 2026-09-26 |
| 実施範囲 | R2 で未修正となった P0 2 件（**D-10** CSV インポート / **D-36** アップロード先の書込権限）の再検証 ＋ 修正済み 6 件の回帰確認 ＋ 自動テスト |
| 実施方法 | 実 HTTP による外部観測（Python / curl）＋ 静的コード点検 ＋ `scripts/test-fresh.sh`（自動テスト）。**アプリケーションの修整は行っていない（検査係）** |
| 対象環境 | `http://localhost:8082`（`irohaboard5-web-1` php:8.4-apache、Apache ワーカー `www-data`）／ MariaDB 11.4.13（port 13307, db `irohaboard`） |
| 参照コミット | HEAD = `7e34d9e`（`ci: mariadb:11.4 のヘルスチェックを healthcheck.sh に変更`）。**R2 以降のアプリコード変更は無し** |
| 作業開始時 | `git status` は `Docs/verification/` 配下のみ（`src/`・`templates/`・`config/`・`tests/` は無改変） |

---

## 1. 結果サマリ

| ID | 検証項目 | 判定 | 根拠 |
|----|----------|------|------|
| **D-10** | ユーザー CSV インポート | ❌ **未修正（P0）** | **CP932 エンコードの CSV で HTTP 500**、`Class "Cake\Utility\Configure" not found`。DB 反映 **0 件** |
| **D-36** | アップロード先の書込権限 | ❌ **未修正（P0）** | 実 HTTP で**ファイルが 1 つも生成されない**（痕跡なし・件数増減なし）。`www-data` は `webroot/uploads`・`files` の両方へ書けない |
| D-29 | 学習記録保存 | ✅ 修正維持 | `POST /records/add/2` → **302 `/contents/index/1`**、`ib_records` に `study_sec=45, understanding=3, is_complete=1` で作成 |
| D-27 | テスト結果ページ | ✅ 修正維持 | `/contents-questions/record/7/6` = 200、`/7/7` = 200 |
| D-28 | 非公開お知らせの閲覧制御 | ✅ 修正維持 | `/infos/view/3` = **404** |
| D-08 | Contents API Write の staff 権限 | ✅ 修正維持 | admin `POST /api/v1/contents` = **201**、user1 = **403**、後片付け DELETE = 200 |
| D-09 | MCP `isError` 契約 | ✅ **修正確認**（R3 追試） | 実 HTTP で 9/10 が `isError:true`（user1 の権限拒否・不存在 id・アクセス拒否）。残り 1 件（`kind` 不正）は **SDK の入力スキーマ検証**が JSON-RPC `-32602` を返す＝仕様どおり。§2.4 参照 |
| — | 自動テスト（回帰） | ✅ 維持 | **744 tests / 3689 assertions / 0 errors / 0 failures / 0 deprecations / PHPUnit Notices 8**（R2 と同一） |

---

## 2. 実測証跡

### 2.1 D-10（CSV インポート）— HTTP 500 を再現

`Utils::getCsvData()` は **SJIS-Win → UTF-8** 変換を前提としているため、CP932 で投入して検証した。

```
# CP932 化した CSV（ヘッダ＋1行、5列）
ログインID,パスワード,氏名,権限,メールアドレス
r3impC,pass,RT3C,受講者,r3c@example.com

POST /admin/users/import  (multipart, field=csvfile)
→ HTTP 500
→ 本文: Class "Cake\Utility\Configure" not found
→ DB: ib_users = 6 件（r3impC は未登録）
→ logs/error.log: 「Class "Cake\Utility\Configure" not found in .../src/Utility/Utils.php on line 148」が 1 → 2 件に増加
```

クラス定義の直接確認:

```
php -r 'require "vendor/autoload.php";
        var_dump(class_exists("Cake\Utility\Configure"));   // bool(false)
        var_dump(class_exists("Cake\Core\Configure"));        // bool(true)'

php -r '... App\Utility\Utils::getKeyByValue("user_role","受講者") ...'
→ THROWN=Error: Class "Cake\Utility\Configure" not found
```

- 原因: `src/Utility/Utils.php:11` の `use Cake\Utility\Configure;`（CakePHP 5 に存在しない。 CakePHP 4 では `Cake\Core\Configure` へ移動）
- 影響範囲: `Utils::getKeyByValue()` の呼び出し元は `Admin/UsersController.php` の CSV インポート経路のみ（その他に `Configure` を触らない）
- 補足（**試験側が踏んだ罠**）: **UTF-8 の CSV を投入すると 302 で「無言のまま」0 件**になる。`getCsvData()` の SJIS 変換で壊れるためで、**エラー表示が無いこと自体が別の欠陥**（S3 相当）。

### 2.2 D-36（アップロード）— 制御実験で「1 つも保存されない」ことを確定

```
# 前提（権限）
ps -C apache2            → root(master) / www-data(worker)
stat webroot/uploads     → drwxr-xr-x root:root
su www-data -c "touch …/uploads/.w"  → Permission denied
su www-data -c "touch …/files/.w"    → Permission denied
# compose / Dockerfile に chown・chmod・USER の指示なし

# 制御実験（一意なマーカー付きファイル）
POST /admin/contents/upload-image  (field=image, 本文に "R3MARKER")
  → HTTP 200 / レスポンス本文 0 バイト
POST /admin/contents/upload/file   (field=file,  本文に "R3MARKER-FILE")
  → HTTP 200 / upload.php の再描画のみ（保存されない）
# 事後確認
uploads ファイル数: 106 → 106（増減なし）／files: 0 → 0
grep -rl R3MARKER uploads files      → 該当なし
```

- **所見（追加）**: `uploadImage` は HTTP 200 を返すが**本文が空**であり、Summernote 側は URL を得られない。`upload/file` 側は保存の.flash も表示されない（upload.php の再描画のみ）。
- 既存 106 個の PNG は `root` 所有（**PHPUnit を root で実行して生成された**ため）。したがって自動テストは緑のまま実環境のみ失敗する構造が再確認された。

### 2.3 D-29（学習記録保存）— 正常

```
POST /records/add/2   is_complete=1 / study_sec=45 / understanding=3
→ HTTP 302  Location: http://localhost:8082/contents/index/1
→ ib_records: id=19, user_id=5, content_id=2, study_sec=45, understanding=3, is_complete=1
```

> 重要: 送信フィールド名は **`data[Records][...]` ではなく平直名** `is_complete` / `study_sec` / `understanding`（`webroot/js/contents_view.js:66-78` が `form` を動的生成し `_csrfToken` のみ手工で付加）。`RecordsController::add()` は `$this->request->getData()` をそのまま読む。

### 2.4 D-09（MCP `isError` 契約）— R3 追試で確定

`scripts/smoke-mcp.sh` と同じ手順（`protocolVersion: 2025-03-26`、`Mcp-Session-Id` を別リクエストで捕捉、`notifications/initialized` 送信）で実 HTTP 検証した。

```
=== MCP エラー経路（isError=true を期待） ===
  user1 create_content (非staff)            OK  (isError=true, HTTP 200)
  user1 get_course 未受講(course2)          OK  (isError=true, HTTP 200)
  user1 get_content 未受講                  OK  (isError=true, HTTP 200)
  user1 get_user_profile 他者               OK  (isError=true, HTTP 200)
  admin create_content 不正kind             → JSON-RPC -32602（下記注記）
  admin update_content 不存在id             OK  (isError=true, HTTP 200)
  admin get_content 不存在id                OK  (isError=true, HTTP 200)
  admin get_course 不存在id                 OK  (isError=true, HTTP 200)
=== 対照（正常系） ===
  admin list_courses                        OK  (isError=false)
  admin get_user_profile 自分               OK  (isError=false)
```

- `kind` 不正は、ツール本体に到達する前に **SDK の入力スキーマ検証**が `{"error":{"code":-32602,"message":"Invalid parameters for tool 'create_content': Property '/kind': Value must be one of the allowed values: ..."}}` を返す。**MCP 仕様上正しい挙動**（不正パラメータは JSON-RPC エラー、ツール実行エラーは `isError:true`）。
- 実装根拠: 各ツールが `Mcp\Exception\ToolCallException` を送出し、`vendor/mcp/sdk/.../CallToolHandler.php:154-163` が `CallToolResult::error(...)`（`isError:true`）へ変換する。構造化テストは `tests/TestCase/Mcp/ToolErrorPropagationTest.php`（MCP テスト 72 / 420 assertions / 0 failures）。
- HTTP ステータスはエラー時も **200**（MCP の仕様。JSON-RPC レベルで成否を表す）。

**結論: D-09 は修正済みで契約適合。** R3 初回の「未判定」は自作ハーネスの不備（`-32700`）によるもので、実装の欠陥ではない。

---

## 3. 検証ハーネスの誤りと訂正（自己申告）

R3 の初回計測では誤った結論に至りかけた。訂正して再計測した記録を残す。

| # | 誤り | 原因 | 訂正後 |
|---|------|------|--------|
| 1 | `upload-image` が 403、`/records/add` が 403 と判定 | **トークン取得元ページが誤り**。`upload-image` のトークンは編集画面（`/admin/contents/add/1`、かつ `unlockField('image')`）、`records/add` のトークンは `/contents/view/{id}` が発行する | 正しいページから取得して 200 / 302 を確認 |
| 2 | 学習記録が `0/0/0` で保存されたと報告 | フィールド名を `data[Records][...]` と推測したが，实际は平直名 | JS 実装を確認して平直名に修正 → `45/3/1` で保存（§2.3） |
| 3 | MCP が `-32700 Syntax error` | 自作 Python のセッション／`Accept` ヘッダの作りが誤り（`protocolVersion` は `2025-03-26`、`Mcp-Session-Id` は別リクエストで捕捉） | その後同手順で追試し、D-09 を **修正確認**（§2.4）。`scripts/smoke-mcp.sh` も 4/4 PASS |
| 4 | 4 列 CSV で「取込 0 件」を D-10 の証拠としかけた | `import()` は `count($row) < 5` の行をスキップする | 5 列以上の CP932 CSV で 500 を再現（§2.1） |

---

## 4. 後片付け

| 対象 | 結果 |
|------|------|
| R3 で作成した `ib_records`（id 18, 19）と `ib_records_questions` | 削除 |
| R3 で作成した `ib_contents`（`R3-`） | 削除 |
| 検証で発行した `ib_user_tokens` / `ib_logs` | 全削除（0 / 0） |
| dev DB 最終状態 | `users=6 courses=2 contents=11 questions=5 records=2 records_questions=3 tokens=0 logs=0`（基準値一致） |
| コード改変 | **無し**（`git status` は `Docs/verification/` 配下のみ） |

---

## 5. 判定

**❌ 不合格** — P0 2 件（D-10 / D-36）が未修正のまま残存。

| ID | 修正対象 | 種別 | 難易度 |
|----|----------|------|--------|
| D-10 | `src/Utility/Utils.php:11` の `use` 宣言を `Cake\Core\Configure` に変更 | コード 1 行 | 低 |
| D-36 | `webroot/uploads` と `files` の所有者を Apache 実行ユーザ（`www-data`）に合わせる（compose での起動時 `chown`、またはホスト側の `chown -R`） | 環境設定 | 低 |

**推奨**: D-10 は 1 行で解消できるため即修正を推奨。D-36 は自動テストで検知できないため、**実ブラウザ／Apache 経由のアップロード確認を検証工程に恒常的に組み込む**ことを推奨。
