# W6 検出項目 原因解析（Root Cause Analysis）

> 対象: `Docs/verification/evidence/W6/W6-execution-record.md` の D-31〜D-33
> 実施日: 2026-09-26 / 方法: 静的コード点検（file:line）＋ W6実測・訂正記録の参照
> 前提: コード修正は未実施。原因の特定と影響評価のみ。

---

## 総括

| ID | 事象 | 根本原因（要約） | 分類 | 重要度 |
|----|------|------------------|------|--------|
| D-31 | `GET /api/v1/contents/abc` 等が JSON ではなく HTML 404 を返す | CakePHP 5 の `ControllerFactory` がパラメータ型変換失敗時に `InvalidParameterException`(code=404) をスローするが、`ApiErrorMiddleware` が `ApiException` のみ捕捉するため `ErrorHandlerMiddleware` が HTML 404 を描画。加えて GET ルートに `\d+` 制約がない | 設計欠落＋実装バグ | S4 |
| D-32 | NULL byte（`%00`）等の不正パスで Apache 既定の HTML 404 が返る | PHP の SAPI 層が NULL byte を含むリクエスト URI を拒否し、Apache がアプリ到達前に既定の HTML 404 を返す | フレームワーク・サーバ設定 | S4 |
| D-33 | `OPTIONS /mcp` の CORS preflight が 401 を返し CORS ヘッダを伴わない | `McpController::options()` が CORS preflight を特別処理せず `handle()` に委譲し、`IrohaAuthMiddleware` が Bearer トークン不在で 401。加えて MCP SDK の `CorsMiddleware` が `allowedOrigins=[]`（既定）で `Access-Control-Allow-Origin` を付与しない | 実装バグ | S4 |

---

## D-31: API の一部が JSON ではなく HTML 404 を返す

### 根本原因: **`ApiErrorMiddleware` が `ApiException` のみ捕捉し、フレームワークが投げる `InvalidParameterException` を逃している。加えて GET ルートに型制約がない。**

### 発生メカニズム

**Step 1: ルートは `abc` にマッチする**

`config/routes.php:300-304` の GET 用 `/contents/{id}` ルート（grep 実測）:

```php
$builder->connect('/contents/{id}', [
    'controller' => 'Contents',
    'action' => 'view',
    '_method' => 'GET',
], ['pass' => ['id']]);          // ← 'id' => '\d+' 制約なし
```

対照的に PUT/PATCH/DELETE ルート（`config/routes.php:316,321,326` 付近）には `'id' => '\d+'` が指定されている（実測: 3 本とも制約あり）。`DashedRoute` の `{id}` の既定パターンは `[^/]+` であり、文字列 `abc` はマッチする。

**Step 2: `ControllerFactory` がパラメータ型変換に失敗**

`Api\ContentsController::view(int $id)` の `int $id` に対して文字列 `'abc'` を変換しようとする。`FILTER_VALIDATE_INT` は `null` を返し、`InvalidParameterException` がスローされる（`vendor/cakephp/cakephp/src/Controller/ControllerFactory.php`）。

**Step 3: `InvalidParameterException` は `ApiException` ではない**

- `InvalidParameterException`（`vendor/cakephp/cakephp/src/Controller/Exception/InvalidParameterException.php:29`）: `CakeException` 継承、`$_defaultCode = 404`
- `ApiException`（`src/Controller/Api/ApiException.php`）: `HttpException` 継承 → **別クラス**

**Step 4: `ApiErrorMiddleware` が捕捉できない**

`src/Middleware/ApiErrorMiddleware.php:26` 付近は `catch (ApiException $e)` のみ（実測の catch 範囲）。`InvalidParameterException` は通過し、ミドルウェアスタックを抜けて `ErrorHandlerMiddleware`（`src/Application.php` のミドルウェアキュー）に到達。

**Step 5: `ErrorHandlerMiddleware` が HTML 404 を描画**

`templates/error/` に `error404.php` が存在しないため（`error400.php` / `error500.php` のみ）、CakePHP の内部デフォルト HTML が返る。

### 根拠

| 根拠 | 場所 |
|------|------|
| GET ルートに `\d+` 制約なし | `config/routes.php:300-304` |
| PUT/PATCH/DELETE には制約あり | `config/routes.php:316,321,326` 付近 |
| `InvalidParameterException` の `$_defaultCode = 404` | `vendor/cakephp/cakephp/src/Controller/Exception/InvalidParameterException.php:29` |
| `ApiErrorMiddleware` が `ApiException` のみ捕捉 | `src/Middleware/ApiErrorMiddleware.php:26` |
| `templates/error/error404.php` 不在 | `templates/error/` に 400/500 のみ |

### 影響

- API クライアントが `Content-Type: text/html` の 404 を受信し JSON パースに失敗する
- 認証・認可の迂回には至らない（型変換フェーズで失敗、コントローラに到達しない）
- 同様の問題は `/api/v1/users/{id}`、`courses/{id}`、`records/{id}`、`groups/{id}` の GET ルートにも潜在（いずれも制約欠落）

### 分類

- **設計欠落**: `ApiErrorMiddleware` が `ApiException` に特化しており、フレームワーク汎用例外（`InvalidParameterException` / `NotFoundException` 等）を逃す
- **実装バグ**: GET ルートの `{id}` のみ正規表現制約が欠落（Write 系にはある）

---

## D-32: NULL byte／不正パスで Apache 既定の HTML 404

### 根本原因: **NULL byte（`%00`）を含むリクエスト URI を PHP の SAPI 層がアプリ実行前に拒否し、Apache が既定の HTML 404 を返す。**

### 発生メカニズム

1. クライアントが `GET /api/v1/contents/1%00` を送信。Apache の `mod_rewrite` が `%00` を NULL byte にデコード。
2. PHP 7.0 以降、リクエスト URI に NULL byte が含まれる場合、SAPI 層がリクエストを拒否する。これは **PHP コードが実行される前**の段階で発生。
3. Apache はデフォルトの 404 応答（HTML）を返す。このリクエストは CakePHP のルーティングや `ApiErrorMiddleware` に**到達しない**。
4. パストラバーサル（`..%2F` 等）の場合: Apache 2.4 の `AllowEncodedSlashes Off`（既定）によりエンコードスラッシュはデコードされず、有効なファイルパスに解決されない。

### 根拠

| 根拠 | 場所 |
|------|------|
| NULL byte → Apache 404 の実測 | `W6-execution-record.md`（再実測で一致） |
| `.htaccess` の `RewriteRule` は `REQUEST_FILENAME` 判定 | `webroot/.htaccess` |
| アプリ側に NULL byte 対策コードなし | `src/` 内に該当処理なし（到達しないため） |

### 影響

- パストラバーサルは不成立（Apache が遮断）。**アプリケーションのセキュリティに影響なし**
- API クライアントが Apache の HTML 404 を受信し JSON 形式と不一致

### 分類

- **フレームワーク・サーバ設定**: PHP のセキュリティ向上による既定動作。Apache が遮断するためアプリ層での対応は不要
- ただし API クライアント側は `Content-Type` ベースのフォールバック処理が必要になる場合がある

---

## D-33: MCP の OPTIONS preflight が 401 を返し CORS ヘッダを伴わない

### 根本原因: **`McpController::options()` が CORS preflight を特別処理せず `handle()` に委譲し、`IrohaAuthMiddleware` が Bearer トークン不在で 401 を返す。加えて MCP SDK の `CorsMiddleware` が `allowedOrigins=[]`（既定）のため `Access-Control-Allow-Origin` を付与しない。**

### 発生メカニズム

**Step 1: ルートマッチ**

`config/routes.php` の `OPTIONS` ルート（`mcp:options` スキーム）が `McpController::options()` に到達。

**Step 2: `options()` が `handle()` に委譲（grep 実測）**

```php
// src/Controller/McpController.php:52-55
public function options(): Response
{
    return $this->handle();
}
```

CORS preflight に対する特別処理（認証不要の応答返却）がなく、そのまま `handle()` に委譲される。

**Step 3: `handle()` が認証付きの MCP トランスポートを構築**

`StreamableHttpTransport` のミドルウェアに `IrohaAuthMiddleware` が含まれる。

**Step 4: `IrohaAuthMiddleware` が Bearer トークンを要求**

`Authorization` ヘッダが `Bearer …` 形式でなければ 401 を返す（`src/Mcp/IrohaAuthMiddleware.php`）。**CORS preflight は仕様上 `Authorization` ヘッダを送信しないため、常に 401 になる。**

**Step 5: CORS ヘッダが付かない**

MCP SDK の `CorsMiddleware`（`vendor/mcp/sdk/src/Server/Transport/Http/Middleware/CorsMiddleware.php`）は既定で `allowedOrigins = []`。空配列のとき `resolveAllowedOrigin()` は `null` を返し、`Access-Control-Allow-Origin` は**付与されない**。アプリケーション側に独自の CORS ミドルウェアも存在しない（`src/` に CORS/Access-Control 関連コードなし・grep 確認）。

### 根拠

| 根拠 | 場所 |
|------|------|
| `options()` が `handle()` に委譲 | `src/Controller/McpController.php:52-55` |
| 401 ボディ `{"error":"invalid_token","error_description":"Missing bearer token"}` | `src/Mcp/IrohaAuthMiddleware.php` の `unauthorized()` |
| `CorsMiddleware` の `allowedOrigins` 既定 `[]` | `vendor/mcp/sdk/.../CorsMiddleware.php:51` 付近 |
| 空配列で `null` 返し | 同 `resolveAllowedOrigin()` |
| 当初「403 Origin 過厳」→ 再実測で「401」に反転 | `_orchestrator-corrections.md` |

### 影響

- ブラウザ内 MCP クライアントは preflight が失敗し `/mcp` に接続できない
- CLI / サーバ側クライアントは直接 Bearer を送るため影響なし（preflight なし）
- レスポンスが 401 のため CORS 問題と認証問題が混在しデバッグが困難

### 分類: **実装バグ（複合）**
1. `options()` が preflight（`OPTIONS` + `Access-Control-Request-Method`）を検出して認証をスキップし CORS ヘッダ付きで応答すべき
2. `CorsMiddleware` をアプリ固有の `allowedOrigins` で構築すべき

---

## 付録: 3 件に共通する構造的問題

### `ApiErrorMiddleware` の捕捉範囲の狭さ（D-31）

`ApiException` のみ catch する設計は、`InvalidParameterException` / `NotFoundException` / `BadRequestException` 等のフレームワーク例外をすべて逃す。API クライアントへの JSON 応答契約が破られる。

### CORS 設定の不在（D-33）

アプリ全体に CORS ミドルウェアが存在しない。MCP SDK 内の `CorsMiddleware` は存在するが既定設定のまま。`/api/v1/*` は CSRF をスキップしているが CORS ヘッダは付与されていない。

### ルート定義の不整合（D-31）

`/contents/{id}` の GET ルートでのみ `\d+` 制約が欠落しており、PUT/PATCH/DELETE との不整合。単発でなくルート定義の一貫性ガードの欠落を示唆する。

---

> 本解析は検査記録であり、コード修正は行っていない。
