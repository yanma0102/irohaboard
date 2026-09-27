# W8 修正記録（Fix Record）

> 対象: `Docs/verification/evidence/W8/W8-execution-record.md` の D-39（AB-13）
> 実施日: 2026-09-27 / 対象アプリ: irohaboard（CakePHP 5 / PHP 8.4 / MariaDB 11.4）
> 前提: 記録規約に従い、**既存記録は上書きせず**本ファイルを追加する。

---

## 1. 修正サマリ

| ID | 重大度 | 問題 | 修正方針 | 状態 |
|----|--------|------|----------|------|
| D-39 | S4 | `PUT /api/v1/courses` 等に不正 JSON（`not-json{`、Content-Type: application/json）を送ると HTTP 400 / Content-Type: text/html / 867,808B（別試行 874KB）の CakePHP debug HTML ページが返り、内部パス・スタックトレースを露出 | `ApiErrorMiddleware` が `/api`・`/mcp` 経路の `BadRequestException` を捕捉し JSON 400 を返すよう修正 | ✅ 修正済 |

---

## 2. 問題の詳細

### D-39: JSON API が不正 JSON に debug HTML（867KB・スタック/内部パス露出）を返す

**現象**: `PUT /api/v1/courses` 等に不正 JSON（`not-json{`、Content-Type: application/json）を送ると、**HTTP 400 / Content-Type: text/html / 867,808B（別試行 874KB）の CakePHP debug HTML ページ**が返る。`Accept: application/json` 指定でも HTML のまま。未認証でも再現。内部に `/var/www/html/src`・`Cake\Http\Exception\BadRequestException`・`ApiErrorMiddleware.php:29`・`BodyParserMiddleware`・`webroot/index.php:32` 等のスタックトレースと内部パスを露出。

---

## 3. 根本原因

1. `BodyParserMiddleware`（`vendor/cakephp/.../BodyParserMiddleware.php:168,187`）が不正 JSON で `Cake\Http\Exception\BadRequestException` を throw。
2. `src/Middleware/ApiErrorMiddleware.php`（修正前 `:30,32`）が catch するのは `ApiException` と `InvalidParameterException` のみ → `BadRequestException` は素通りし `ErrorHandlerMiddleware` が debug HTML を描画。
3. `config/app_local.php:21` の debug が env `DEBUG` 未設定時 default true（コンテナで DEBUG 未設定）→ スタックが全露出。debug=false でも「JSON API が HTML を返す契約違反」は残る。

---

## 4. 修正内容

対象ファイル: `src/Middleware/ApiErrorMiddleware.php`（66行→91行）

- `catch (BadRequestException $e)` ブロックを追加（`:47-67`）。
- API 経路判定: `RequestPathHelper::baseRelative($request)` でベースパス除去後、`str_starts_with($path, '/api/')` または `'/mcp'` → JSON 化。ベースパス対応は `ApiRateLimitMiddleware` `:40-43` と同一パターン（サブディレクトリ配信対応 ae85b20 との整合）。
- 非 API パスでは `throw $e` で再スローし、従来の HTML エラーページ挙動を維持（フロントエンドの挙動変更なし）。
- ペイロードは既存契約どおり `{"error":{"code":...,"message":...}}`。code は `$e->getCode()`（`BadRequestException` の `_defaultCode` = 400）、空なら 400。message は `$e->getMessage()`、空なら `'Bad Request'`（このバージョンの `HttpException` には `getStatusCode()` が存在しないため `getCode()` を使用）。
- 既存の `ApiException` / `InvalidParameterException` の catch と 500 系素通りの仕様は**無改変**。

---

## 5. 回帰テスト

対象ファイル: `tests/TestCase/Middleware/ApiErrorMiddlewareTest.php`（239行→329行、4件追加）

| テストメソッド | 概要 |
|----------------|------|
| `testBadRequestExceptionOnApiPathReturnsJson400()` | `/api/v1/courses` → JSON 400・HTML タグ不在 |
| `testBadRequestExceptionOnMcpPathReturnsJson400()` | `/mcp` → JSON 400 |
| `testBadRequestExceptionOnNonApiPathPropagates()` | `/users/login` → 例外伝播＝HTML 維持 |
| `testBadRequestExceptionWithBasePathDetectsApi()` | base `/irohaboard` 付き `/irohaboard/api/v1/courses` → JSON 400 |

---

## 6. 修正後のライブ再実測

| 経路 | 結果 |
|------|------|
| ① `PUT /api/v1/courses` 不正 JSON（未認証） | **400 / application/json / 46B** `{"error":{"code":400,"message":"Bad Request"}}` |
| ② 同 + `Accept: application/json` | 400 / application/json / 46B（同上） |
| ③ `POST /mcp` 不正 JSON | 400 / application/json / 46B（同上） |
| ④ `POST /users/login` 不正 JSON（非 API） | 400 / **text/html**（従来どおり。フロントは HTML 維持＝仕様） |
| ⑤ API 応答内の内部パス/スタック有無 | `/var/www/html`・`Stack trace`・`Exception` のヒット **0**（①②③すべて） |

---

## 7. 全スイート（修正後・確定値）

**748 tests / 3708 assertions / 0E / 0F / 8 PHPUnit Notices**（修正前 744/3689 から +4 テスト +19 アサーション＝追加テスト分。`bash scripts/test-fresh.sh`）

---

## 8. 残り・補足

- 非 API パスの debug HTML（867KB・スタック露出）は debug=true の**開発環境設定に起因**（`config/app_local.php`、gitignore 対象）。本番 debug=false ではスタック非露出。フロント HTML に HTML エラーを返すのは契約上正しい。
- D-07（HTTPS 再計測）・VR-AUTH-043（攻撃実演）・D-18/D-32（意図的延期）は別途残置。
