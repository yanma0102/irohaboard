# W8 修正記録（Fix Record）

> 対象: `Docs/verification/evidence/W8/W8-execution-record.md` の D-39（AB-13）
> 実施日: 2026-09-27 / 対象アプリ: irohaboard（CakePHP 5 / PHP 8.4 / MariaDB 11.4）
> 前提: 記録規約に従い、**既存記録は上書きせず**本ファイルを追加する。

---

## 1. 修正サマリ

| ID | 重大度 | 問題 | 修正方針 | 状態 |
|----|--------|------|----------|------|
| D-39 | S4 | `PUT /api/v1/courses` 等に不正 JSON（`not-json{`、Content-Type: application/json）を送ると HTTP 400 / Content-Type: text/html / 867,808B（別試行 874KB）の CakePHP debug HTML ページが返り、内部パス・スタックトレースを露出 | `ApiErrorMiddleware` が `/api`・`/mcp` 経路の `BadRequestException` を捕捉し JSON 400 を返すよう修正 | ✅ 修正済 |
| VR-AUTH-043 | — | セッション固定・再生成攻撃の攻撃シナリオ | 修正不要（PASS）。攻撃シナリオ実演のみ（`session_regenerate_id(true)` の防御が有効） | ✅ PASS |
| D-07 | 要判定 | HTTPS ログイン時クッキーに Secure が付かない | 修正不要（測定のみ）。根因は D-40。TLS プロキシで実測し Secure 0/5 を確認 → D-40 修正後に 5/5 | ✅ 測定完了 |
| D-40 | S3 | `config/app.php` の Session `secure` 設定が CakePHP 5 で死に設定。AppController `writeCookie()` に `withSecure()` 欠落。Cookie Secure が未付与 | `config/app.php` の ini 配列設計＋`AppController::writeCookie()` に `withSecure()` 追加（§4 参照） | ✅ 修正済 |

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

---

## 9. D-40: Cookie Secure の設定が機能していない（修正・検証完了）

### 9.1 根本原因

1. `config/app.php` のトップレベル `'secure'` キーは CakePHP 5 の `Session` クラスが一切参照しない**死に設定**（`vendor/cakephp/cakephp/src/Http/Session.php` に `secure` 参照なし）。
2. `AppController::writeCookie()`（`LoginStatus` クッキー手动生成）に `withSecure()` がなかった。
3. compose（`docker/docker-compose.cakephp5.yml`）に `SESSION_SECURE` が未定義。
4. CakePHP の自動付与（`Session.php:112-118`）は `!isset($ini['session.cookie_secure']) && env('HTTPS')` 条件。**ini キーを常に置くと自動付与が遮断される**ため、未設定時はキーを落とす設計が必要。

### 9.2 修正内容

対象ファイル: `config/app.php`、`src/Controller/AppController.php`

**config/app.php（Session ブロック）**:
- トップレベル `'secure'` キーを削除。
- `ini` 配列に `'session.cookie_secure' => in_array(env('SESSION_SECURE'), [null, ''], true) ? null : filter_var(env('SESSION_SECURE'), FILTER_VALIDATE_BOOLEAN)` を追加。
- env 未設定・空文字（compose の `${VAR:-}` パターン相当）なら**キー自体を置かず** CakePHP の自動付与（env('HTTPS')）に委ね、明示値ならその値を採用。

**AppController::writeCookie()**:
- `->withHttpOnly(true)` の直後に `->withSecure((bool) ini_get('session.cookie_secure'))` を追加。
- セッション開始後の `ini_get` で当該リクエストの実効値を参照。

### 9.3 検証

1. `php -l` 両ファイルとも OK。`grep -rn "Session\.secure\|'secure'" tests/` ヒットなし（テスト非依存）。
2. CLI 5ケース（`Session::create` → `start()` 後に `ini_get('session.cookie_secure')` を実測）:

| env | HTTPS | ini キー | start後 cookie_secure |
|---|---|---|---|
| 未設定 | off | 不在 | `[0]` |
| 未設定 | on | 不在 | **`[1]`（自動付与パスが生きている）** |
| `true` | off | `true` | `[1]` |
| `false` | on | `false` | `[]`（明示オフが HTTPS 自動付与に優先） |
| 空文字 | on | 不在 | **`[1]`（空文字＝未設定扱い）** |

3. ライブ E2E（`SESSION_SECURE` 既定を一時 `true` 化 → 実測 → 復元）:
   - HTTPS GET /users/login → `Set-Cookie: AppSession=...; path=/; secure; HttpOnly; SameSite=Lax` 付与。
   - HTTPS ログイン応答 → **Set-Cookie 5/5 全て `secure`**: AppSession deleted×2＋新ID×2（`session_regenerate_id` による再生成も含む）、`LoginStatus=logined; secure; HttpOnly`。
   - ログイン成功 302 → `/users-courses` 200「コース一覧」到達確認済。
   - 復元後: `config/app.php` の md5 が実測前と一致（無改変復元）、HTTPS ベースライン Secure 0 に回帰。

### 9.4 残課題（記録のみ・スコープ外）

- ① `csrfToken` クッキーは Secure 未付与（CSRF ミドルウェア管理）。
- ② 実運用では TLS 終端が `HTTPS` を伝えない構成もあるため、確実性のため `SESSION_SECURE=true` の明示設定を推奨。

### 9.5 対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `config/app.php` | Session ブロック: トップレベル `secure` 削除＋`ini` 配列に `session.cookie_secure` 追加 |
| `src/Controller/AppController.php` | `writeCookie()`: `->withSecure((bool) ini_get('session.cookie_secure'))` 追加 |
