# W1/W2 不具合修正記録（D-01〜D-07 / W2-F1）

| 項目 | 内容 |
|---|---|
| 実施日 | 2026-09-25 |
| 対象 | W1（P0 セキュリティ/権限）・W2（P0 API/MCP/データ/CSV）で検出した不具合 |
| 方法 | 3 レーン並列で修正 → 統合フルスイート → 稼働中アプリへの実 HTTP 検証 |
| 環境 | `http://localhost:8082`（irohaboard5-web-1 php:8.4-apache）/ MariaDB 11.4.13（:13307, db `irohaboard`） |
| 前提 | `config/app_local.php` は gitignore・`debug=true`。テストは `bash scripts/test-fresh.sh` |

## 1. 修正一覧

| ID | 重大度 | 不具合 | 修正内容 | 主な修正ファイル |
|---|---|---|---|---|
| **D-01** | S2 | API 全体にレート制限が無い（有効 Bearer で無制限） | `ApiRateLimitMiddleware` を新規追加し `Application::middleware()` の BodyParser 直後に登録。`api_rate_limit` Cache（FileEngine, 分単位）で認証済み=token hash / 未認証=IP をキー化し、超過で 429 + `Retry-After` + JSON 契約。既定 120 req/min（`ib_config.php:api_rate_limit_per_minute`、0 で無効） | `src/Middleware/ApiRateLimitMiddleware.php`(新), `src/Application.php`, `config/app.php`, `config/ib_config.php` |
| **D-02** | S2 | `file` 種別アップロードが常時拒否（`upload_file_extensions`/`_maxsize` 不在） | `ContentsController::upload()` で `file_type==='file'` のとき汎用 `upload_extensions`/`upload_maxsize` へフォールバック（config 変更なし） | `src/Controller/Admin/ContentsController.php` |
| **D-03** | S2 | demo_mode ガードが管理 4 画面で漏れ | `GroupsController`（edit/delete）、`ContentsQuestionsController`（edit/delete/order）、`EnquetesQuestionsController`（edit/delete/order）に demo_mode ガード追加。Records は書込みアクション無しで対象外 | `src/Controller/Admin/{Groups,ContentsQuestions,EnquetesQuestions}Controller.php` |
| **D-04/D-05** | S3 | セキュリティヘッダが Apache 層のみ／CSP 等 4 種欠落 | `SecurityHeadersMiddleware` を新規追加し ErrorHandler 直後（routing 前）に登録。CSP/Referrer-Policy/Permissions-Policy を付与、HSTS は HTTPS 時のみ、X-Frame-Options/X-Content-Type-Options もアプリ層で付与 | `src/Middleware/SecurityHeadersMiddleware.php`(新), `src/Application.php` |
| **D-06** | S3 | ログイン Prelock がユーザー名単位 → ロックアウト DoS | `_isLoginBlocked()` / `isRateLimited()` の WHERE に `user_ip` を追加（ユーザー名＋IP 単位へ）。IP は `X-Forwarded-For`(先頭)→`REMOTE_ADDR` | `src/Controller/Trait/UserLoginTrait.php`, `src/Controller/Api/AuthController.php` |
| **D-07** | 要判定 | Cookie `Secure` 未設定（HTTP 環境） | Session に `secure`（env `SESSION_SECURE`、既定 false）と `session.cookie_samesite='Lax'` を明示。HTTPS 環境で再計測要 | `config/app.php` |
| **W2-F1** | S3/P2 | CSV の `Content-Type` が `charset=UTF-8` だが本文は CP932(SJIS-WIN) | `text/csv; charset=SJIS-WIN` に修正 | `src/Controller/Admin/{Records,Users}Controller.php` |

回帰テストを追加: `tests/TestCase/Middleware/ApiRateLimitMiddlewareTest.php`(9)、`SecurityHeadersMiddlewareTest.php`(11)、`tests/TestCase/Controller/BruteForceIpLockoutTest.php`(7)、および Admin 各コントローラテストへ D-02/D-03/W2-F1 の検証を追記。`tests/TestCase/ApplicationTest.php` のミドルウェア順序を更新。

## 2. 統合検証（フルスイート）

```
bash scripts/test-fresh.sh
→ Tests: 645, Assertions: 3173, Errors: 0, Failures: 0, Deprecations: 2
```
- 修正前ベースライン 605/3024 からテスト +40。Deprecations 2 件は既知（`Admin/ContentsControllerTest` の `Query::order()`、`AppController::beforeFilter` のイベント戻り値）。
- レーンAが途中観測した demo_mode 8 失敗はレーンBの修正で解消済。

## 3. ライブ実測（稼働中アプリ）

| 対象 | 結果 |
|---|---|
| セキュリティヘッダ | `Content-Security-Policy` / `Referrer-Policy: strict-origin-when-cross-origin` / `Permissions-Policy: geolocation=(), microphone=(), camera=()` / `X-Frame-Options: SAMEORIGIN` / `X-Content-Type-Options: nosniff` をアプリ層で付与。HTTP のため HSTS なし（正常） |
| Cookie | `AppSession=...; path=/; HttpOnly; SameSite=Lax` |
| API レート制限 | 同一トークンで 135 連続 GET → **200=120 / 429=15 / 初回 429 = 121 回目**。429 は `Retry-After: 29` + `Content-Type: application/json` + `{"error":{"code":429,"message":"Rate limit exceeded. Please try again later."}}` |
| smoke-api | **3/3 PASS**（token 201 / courses 200 / unknown 404 JSON） |
| smoke-mcp | **4/4 PASS**（initialize / notifications/initialized / tools/list 9 ツール / tools/call list_courses） |

## 4. 発生した環境問題と対処

- ライブ検証中、Web コンテナ（www-data）が CLI（root）所有の `tmp/cache/persistent/*` を上書きできず **Warning 512 の HTML が JSON 応答に混入**。レート制限カウンタも永続化されず 429 が出なかった。
- 対処: `rm -rf tmp/cache/{persistent,models,views}/*`（ホストとコンテナ）でキャッシュをクリア → JSON 正常化・レート制限発火を確認。
- **運用注意**: root で `phpunit` / `bin/cake` を実行すると root 所有キャッシュが生成され Web 実行を阻害する。テスト/CLI 後は `tmp/cache` を整理するか、www-data が上書きできる権限にする。

## 5. 残課題・未対応

| 項目 | 状態 |
|---|---|
| コミット/プッシュ | **未実施**（ユーザー指示待ち）。変更は `src/`, `config/`, `tests/` と `Docs/verification/` に存在 |
| D-07 Cookie `Secure` | HTTPS 環境で再計測が必要（現在 HTTP のため false が正しい） |
| phpcs | 残 1 件 `tests/TestCase/Utility/MarkdownRendererTest.php:145`（FQName, P2）。他に既存スタイル負債多数 |
| Playwright / axe / k6 | 未導入（E2E・a11y・負荷の自動化は今後） |
| W3 以降 | P0 運用/移行（install/update/復旧）は未着手 |

## 6. 変更ファイル（未コミット）

- 変更: `config/app.php`, `config/ib_config.php`, `src/Application.php`, `src/Controller/Admin/{Contents,ContentsQuestions,EnquetesQuestions,Groups,Records,Users}Controller.php`, `src/Controller/Api/AuthController.php`, `src/Controller/Trait/UserLoginTrait.php`, `tests/TestCase/ApplicationTest.php`, `tests/TestCase/Controller/Admin/*Test.php`, `Docs/verification/README.md`, `Docs/verification/evidence/W1/W1-execution-record.md`
- 新規: `src/Middleware/ApiRateLimitMiddleware.php`, `src/Middleware/SecurityHeadersMiddleware.php`, `tests/TestCase/Middleware/`, `tests/TestCase/Controller/BruteForceIpLockoutTest.php`, `Docs/verification/evidence/**`
- 除外: `.slim/`（opencode ワークツリーの既存成果物）
