# W6 修正記録（Fix Record）

> 対象: `Docs/verification/evidence/W6/W6-execution-record.md` および `W6-cause-analysis.md` の D-31〜D-33
> 実施日: 2026-09-26 / 対象アプリ: irohaboard（CakePHP 5.4.2 / PHP 8.4.25 / MariaDB 11.4.13）
> 前提: 記録規約に従い、**既存記録は上書きせず**本ファイルを追加する。

---

## 1. 修正サマリ

| ID | 重大度 | 問題 | 修正方針 | 状態 |
|----|--------|------|----------|------|
| D-31 | S4 | `GET /api/v1/contents/abc` 等が JSON 404 ではなく HTML 404 を返す | ルート制約追加＋例外捕捉範囲拡張 | ✅ 修正済 |
| D-32 | S4 | NULL byte（`%00`）等の不正パスで Apache 既定の HTML 404 が返る | **アプリ対応不要**（サーバ層で遮断） | ✅ 対応不要 |
| D-33 | S4 | `OPTIONS /mcp` の CORS preflight が 401 を返し CORS ヘッダを伴わない | preflight 判定＋CORS ヘッダ明示 | ✅ 修正済 |

**関連修正（D-17: W5 範囲、同一コミットで修正）**

| ID | 重大度 | 問題 | 修正方針 | 状態 |
|----|--------|------|----------|------|
| D-17 | S2 | `error400.php` 2 ファイルの `$url` が未エスケープ（XSS 余地） | `h()` によるエスケープ適用 | ✅ 修正済（`4deb99e`） |

> **注意**: D-17 は ID 的に W5 範囲（D-13〜D-22）に該当するが、本コミット `4deb99e` で W6 の D-31/D-33 と同時に修正された。W5 側の fix-record が未作成のため、本記録の「関連修正」として掲載する。

---

## 2. 各修正の詳細

### D-31: API の一部が JSON ではなく HTML 404 を返す

**根本原因**: 2 つの独立した原因の重なり。

#### 原因 1: ルート定義の不整合

`config/routes.php` の API GET `{id}` ルート 7 本に `'id' => '\d+'` のパス制約が欠落していた。PUT/PATCH/DELETE の同一 `{id}` ルート（`config/routes.php:316,321,326`）には制約があり、GET のみが漏れていた。

| ルート | 行番号（修正後） | 制約 |
|--------|------------------|------|
| `GET /api/v1/users/{id}` | `config/routes.php:216` | `['id' => '\d+', 'pass' => ['id']]` |
| `GET /api/v1/users/{id}/courses` | `config/routes.php:250` | `['id' => '\d+', 'pass' => ['id']]` |
| `GET /api/v1/courses/{id}` | `config/routes.php:277` | `['id' => '\d+', 'pass' => ['id']]` |
| `GET /api/v1/contents/{id}` | `config/routes.php:304` | `['id' => '\d+', 'pass' => ['id']]` |
| `GET /api/v1/records/{id}` | `config/routes.php:338` | `['id' => '\d+', 'pass' => ['id']]` |
| `GET /api/v1/groups/{id}` | `config/routes.php:355` | `['id' => '\d+', 'pass' => ['id']]` |
| `GET /api/v1/groups/{id}/users` | `config/routes.php:377` | `['id' => '\d+', 'pass' => ['id']]` |

`DashedRoute` の `{id}` の既定パターンは `[^/]+` であり、文字列 `abc` がマッチしていた。`'id' => '\d+'` を追加することで数値以外はルート不一致となり、CakePHP の catch-all（`/api/*` → `Api\ErrorsController::notFound`）に委譲され JSON 404 が返る。

#### 原因 2: 例外捕捉範囲の狭さ

`src/Middleware/ApiErrorMiddleware.php` が `ApiException`（`HttpException` 継承）のみ catch し、CakePHP の `ControllerFactory` がパラメータ型変換失敗時に投げる `InvalidParameterException`（`CakeException` 継承、`$_defaultCode = 404`）を逃がしていた。

発生チェーン:
1. `ControllerFactory` が `Api\ContentsController::view(int $id)` に文字列 `'abc'` を渡す
2. `coerceStringToType()` が `FILTER_VALIDATE_INT` で `null` を返す（`vendor/cakephp/cakephp/src/Controller/ControllerFactory.php:238-248`）
3. `InvalidParameterException` がスローされる（`$_defaultCode = 404`、`vendor/cakephp/cakephp/src/Controller/Exception/InvalidParameterException.php:29`）
4. `ApiErrorMiddleware` が catch できず、`ErrorHandlerMiddleware` に到達
5. `templates/error/` に `error404.php` が存在しないため（`error400.php` / `error500.php` のみ）、CakePHP 内部のデフォルト HTML 404 が返る

**修正**（`src/Middleware/ApiErrorMiddleware.php`）:

1. `use Cake\Controller\Exception\InvalidParameterException;` を追加（`src/Middleware/ApiErrorMiddleware.php:7`）
2. `catch (InvalidParameterException $e)` ブロックを追加（`src/Middleware/ApiErrorMiddleware.php:32-41`）。`$e->getCode() ?: 404` でステータスコードを取得し、`{'error': {'code': ..., 'message': ...}}` 形式の JSON ペイロードを返す
3. `buildJsonResponse()` プライベートメソッドを抽出（`src/Middleware/ApiErrorMiddleware.php:51`）。`ApiException` 処理（`src/Middleware/ApiErrorMiddleware.php:30-31`）と `InvalidParameterException` 処理が共通の JSON 組み立てロジックを共有

**★設計判断: 500 系は意図的に catch していない**

`InternalErrorException` 等の 500 系例外は PHP fatal や内部エラーの結果であり、その詳細（スタックトレース、内部状態）を JSON API 経由でクライアントに漏洩させるのはセキュリティリスク。500 系は従来どおり `ErrorHandlerMiddleware` に委譲し、HTML エラーページ（`error500.php`）またはデバッグモードに応じた安全な表示に留める。JSON 化するのは 4xx のクライアント起因エラーに限定している。

CakePHP の例外階層で `HttpException` をすべて catch すると `InternalErrorException` も含まれるため、個別の例外クラス（`InvalidParameterException`）を明示的に選択した。これにより、PHP の fatal や内部エラーが API 経由で漏洩するリスクを排除している。

**★テスト実装の注意（経緯記録）**

`InvalidParameterException` を生成する attributes キーは CakePHP 本体（`vendor/cakephp/cakephp/src/Controller/ControllerFactory.php:239-248`）と同じ `passed` / `type` / `parameter` / `controller` / `action` / `prefix` / `plugin` を使うこと。最初のレーン実装では `param` / `class` / `function` を使用したが、`InvalidParameterException` のテンプレート展開で `ValueError: The arguments array must contain 5 items, 4 given` が発生し失敗した。Orchestrator が属性キーを CakePHP の実装と一致させ修正した。修正後は `tests/TestCase/Middleware/ApiErrorMiddlewareTest.php:149-158` の属性で正常動作。

**追加テスト**: `tests/TestCase/Middleware/ApiErrorMiddlewareTest.php`（7 メソッド）

| メソッド | 検証内容 |
|----------|----------|
| `testApiExceptionReturnsJson400` | ApiException(400) → JSON 400 + `application/json` |
| `testApiExceptionReturnsJson403` | ApiException(403) → JSON 403 |
| `testApiExceptionReturnsJson429` | ApiException(429) → JSON 429 |
| `testInvalidParameterExceptionReturnsJson404` | InvalidParameterException → JSON 404 |
| `testInvalidParameterExceptionResponseIsNotHtml` | InvalidParameterException → HTML タグ不在 |
| `testInternalErrorExceptionIsNotCaught` | InternalErrorException(500) → 例外が伝播（catch されない） |
| `testSuccessfulRequestPassesThrough` | 正常リクエスト → 200 パススルー |

**追加テスト**: `tests/TestCase/Controller/ErrorPageXssTest.php`（6 メソッド）
D-17 のリグレッションガードも兼ね、API エンドポイントに非数値 ID + XSS ペイロードを送り、レスポンスが JSON で `<script>` タグを含まないことを検証。

---

### D-32: NULL byte → Apache 既定 404: アプリ対応不要

**根本原因**: PHP 8.1+（`docker/Dockerfile` の `FROM php:8.1-apache`）が NULL byte を含むリクエスト URI を **SAPI 層でアプリ実行前に拒否**するため、Apache が独自の HTML 404 を返す。CakePHP のルーティングや `ApiErrorMiddleware` には到達しない。

`..%2F..%2Fetc%2Fpasswd` も Apache 2.4 の `AllowEncodedSlashes Off`（既定）で単一セグメント扱いとなり同様に解決されない（`webroot/.htaccess:44-47` の `RewriteRule` は `REQUEST_FILENAME` 判定で Apache が直接 404）。

**判定: パストラバーサルは不成立でアプリケーションのセキュリティに影響なし。アプリ層での修正は不要。** ただし API クライアントは Content-Type を見ず HTML を受け取る可能性があるため、フォールバック対応はクライアント側に委ねる。

**Docker 環境とホスト環境の PHP バージョン差異について**: `W6-cause-analysis.md` に Docker 環境の PHP 8.1 とホストの PHP 8.4.25 の差異が言及されているが、NULL byte 拒否は PHP 8.0 で導入された動作であり、いずれのバージョンでも同一の挙動を示す。SAPI 層での拒否は PHP バージョンによらず一貫している。

---

### D-33: MCP の OPTIONS preflight が 401 を返し CORS ヘッダを伴わない

**根本原因**: 2 点の複合。

1. `src/Controller/McpController.php:58` の `options()` が preflight 判定なしに `handle()` へ委譲しており、MCP トランスポートの `IrohaAuthMiddleware` が Bearer トークン不在で 401 を返していた。CORS preflight は仕様上 `Authorization` ヘッダを送らないため常に失敗していた。
2. SDK の `CorsMiddleware` が `StreamableHttpTransport` のミドルウェアとして `allowedOrigins=[]`（既定の空配列）で構築され、`resolveAllowedOrigin()` が null を返すため `Access-Control-Allow-Origin` が常に付与されていなかった。

**修正**:

1. `options()` を preflight 判定（メソッドが `OPTIONS` かつ `Access-Control-Request-Method` ヘッダが存在する）で分岐し、認証不要の `handleCorsPreflight()`（204 + CORS ヘッダ）を返す（`src/Controller/McpController.php:58-67`）。**preflight でない OPTIONS は従来どおり 401 のまま**（認可を弱めていない）。
2. `handle()` は `defaultMiddleware()` を使わず、`CorsMiddleware($allowedOrigins)` と `DnsRebindingProtectionMiddleware()` を明示生成（`src/Controller/McpController.php:104-109`）。`defaultMiddleware()` が返すのはまさにこの 2 つであること（MCP SDK ドキュメント確認）を確認済み。
3. `handleCorsPreflight()` を `src/Controller/McpController.php:135-167` に新設。`config/ib_config.php:211` の `mcp_cors_allowed_origins` 設定値を読み込み、`Access-Control-Allow-Origin` / `Allow-Methods` / `Allow-Headers` / `Max-Age` を付与。

**★セキュリティ評価**: 既定 `[]` = 全オリジン拒否 = secure by default。`CorsMiddleware` は `resolveAllowedOrigin()` が null を返し ACAO を付けず、`handleCorsPreflight()` も CORS ヘッダを付けないため、ブラウザは cross-origin を拒否する。`['*']` や明示的オリジンリストを設定した場合のみ許可される。`config/ib_config.php:208-211` に設定コメント付きで記載。

**追加テスト**: `tests/TestCase/Controller/McpControllerTest.php` に 3 メソッド追加（計 26 メソッド）

| メソッド | 検証内容 |
|----------|----------|
| `testOptionsPreflightReturns204WithCorsHeaders` | preflight → 204 + `Access-Control-Allow-Methods` / `Allow-Headers` / `Max-Age` |
| `testOptionsPreflightWithoutTokenReturns204` | preflight はトークンなしで 204（認証不要） |
| `testOptionsWithoutPreflightRequiresAuth` | preflight でない OPTIONS → 401（回帰なし） |

---

## 3. 同時に実施された付随修正（Deprecations 2 → 0）

本 W6 の作業で既存 Deprecation 2 件も解消した。

### 3.1 `Query::order()` → `orderBy()`

`tests/TestCase/Controller/Admin/ContentsControllerTest.php:531`: `->order(` を `->orderBy(` に変更。`Query::order()` は CakePHP 5 で deprecated。

### 3.2 `AppController::beforeFilter()` のイベントリスナー戻り値

`src/Controller/AppController.php:68-122`: `return $this->redirect($url)` を `$event->setResult($this->redirect($url)); return null;` に変更。イベントリスナーからの戻り値は CakePHP 5.2 で deprecated。CakePHP 本来の推奨パターン（`FormProtectionComponent` と同じ形）に合致する。

**★影響調査**: 呼び出し元を grep で洗い出した結果、以下の 3 クラスが `parent::beforeFilter($event)` を direct call している:

- `src/Controller/UpdateController.php:70`
- `src/Controller/InstallController.php:71`
- `src/Controller/Api/BaseController.php:79`

いずれも戻り値を破棄する（`void` で受ける） direct call であり、`src/` 内に `$this->beforeFilter()` の直接呼び出しは存在しない。`startupProcess()` は `$event->getResult()` を読むため実行時挙動は同一で、認可（app_dir 不一致での強制ログアウト、admin 画面への非 staff アクセスでの強制ログアウト）の条件は不変。

---

## 4. 検証結果

### 4.1 全スイート

```
$ bash scripts/test-fresh.sh
Tests: 744, Assertions: 3689, Deprecations: 0, PHPUnit Notices: 8, Time: 04:23
Errors: 0, Failures: 0
```

| ウェーブ | Tests | Assertions | Errors | Failures | Deprecations |
|----------|-------|------------|--------|----------|--------------|
| W1 | 645 | 3173 | 0 | 0 | 2 |
| W2 | 675 | 3324 | 0 | 0 | 2 |
| W3 | 690 | 3356 | 0 | 0 | 2 |
| W4 | 726 | 3622 | 0 | 0 | 2 |
| **W6** | **744** | **3689** | **0** | **0** | **0** |

**Deprecations 2 → 0 への削減内訳**

| 場所（修正前） | 内容 | 対応 |
|----------------|------|------|
| `tests/TestCase/Controller/Admin/ContentsControllerTest.php:529` | `Query::order()` | `orderBy()` へ変更 |
| `src/Controller/AppController.php` の `beforeFilter()` | イベントリスナー戻り値 | `$event->setResult()` + `return null` へ移行 |

### 4.2 ライブ実測

- `GET /api/v1/contents/abc` → **404 + `application/json`** + `{"error":{"code":404,"message":"Endpoint not found"}}`
- `OPTIONS /mcp`（preflight）→ **204** + `Access-Control-Allow-Methods` / `Access-Control-Allow-Headers` / `Access-Control-Max-Age`
- `OPTIONS /mcp`（preflight でない）→ **401**（回帰なし）
- 未認証 `GET /admin/contents` → **302 で `admin/users/login?redirect=`**（認可の回帰がないことを実証）
- `bash scripts/smoke-api.sh` 3/3 PASS
- `bash scripts/smoke-mcp.sh` 4/4 PASS

---

## 5. 変更ファイル一覧（コミット `4deb99e` の一部）

### 製品コード

| ファイル | 不備 | 変更内容 |
|----------|------|----------|
| `config/routes.php` | D-31 | GET `{id}` ルート 7 本に `'id' => '\d+'` 制約を追加 |
| `src/Middleware/ApiErrorMiddleware.php` | D-31 | `InvalidParameterException` の catch ブロック追加＋`buildJsonResponse()` メソッド抽出 |
| `src/Controller/McpController.php` | D-33 | `options()` に preflight 判定分岐＋`handleCorsPreflight()` メソッド新設 |
| `config/ib_config.php` | D-33 | `mcp_cors_allowed_origins` 設定キー追加（既定 `[]`） |
| `templates/error/error400.php` | D-17 | `$url` を `h()` でエスケープ（`"{$url}"` → `h($url)`） |
| `templates/Error/error400.php` | D-17 | `$url` を `h()` でエスケープ（`"{$url}"` → `h($url)`） |
| `src/Controller/AppController.php` | Deprecation | `beforeFilter()` の戻り値を `$event->setResult()` パターンに変更 |

### テスト

| ファイル | 種別 | 内容 |
|----------|------|------|
| `tests/TestCase/Middleware/ApiErrorMiddlewareTest.php` | 新規 | D-31：ApiException 400/403/429、InvalidParameterException 404/HTML不在、500系伝播、正常パススルー（7 メソッド） |
| `tests/TestCase/Controller/ErrorPageXssTest.php` | 新規 | D-31/D-17 リグレッション：非数値 ID + XSS ペイロードで JSON 応答確認（6 メソッド） |
| `tests/TestCase/Controller/McpControllerTest.php` | 追加 | D-33：preflight 204 + CORS ヘッダ、preflight 認証不要、非 preflight 401（3 メソッド追加、計 26 メソッド） |
| `tests/TestCase/Controller/Admin/ContentsControllerTest.php` | 修正 | Deprecation 解消：`order()` → `orderBy()`（`tests/TestCase/Controller/Admin/ContentsControllerTest.php:531`） |

### 記録

| ファイル | 種別 | 内容 |
|----------|------|------|
| `Docs/verification/evidence/W6/W6-fix-record.md` | 新規 | 本ファイル |

---

## 6. 残課題

1. **D-32**: NULL byte → Apache 404 はサーバ層で遮断。アプリ対応不要だが、API クライアントが Content-Type を見ずに JSON パースする場合はフォールバック処理が必要。
2. **D-33**: `mcp_cors_allowed_origins` は既定 `[]`（全拒否）。ブラウザ内 MCP クライアントを使用する場合は本番環境で適切なオリジンリストを設定する必要がある。
3. **phpcs 違反**: `vendor/bin/phpcbf` 導入済み・未実行。
4. **Playwright / axe / k6 未導入**: ブラウザ E2E・アクセシビリティ実測・負荷試験は未実施。

---

> 本記録は検査・修正の記録であり、成果物ではない。
