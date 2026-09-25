# W1 検出項目 原因解析（Root Cause Analysis）

> 対象: `Docs/verification/evidence/W1/W1-execution-record.md` の D-01〜D-07 / W1-03 / W1-06 / W1-08 / W1-09
> 実施日: 2026-09-25 / 方法: 実 HTTP 外部観測（W1記録）＋ 静的コード点検（file:line）
> 前提: コード修正は未実施。原因の特定と影響評価のみ。

---

## 総括

| ID | 事象 | 根本原因（要約） | 分類 | 重要度 |
|---|---|---|---|---|
| D-01 / W1-06 | API 全体にレート制限なし | レート制限が「ログイン試行」専用ロジックのみで、ミドルウェア/横断ガードが存在しない | 設計欠落 | S2 |
| D-02 / W1-08 | `file` 種別アップロードが常時拒否 | 設定キー名の動的生成 `upload_{file_type}_*` と実キー `upload_*` の不一致 | 実装バグ | S2 |
| D-03 / W1-09 | demo_mode 漏れ（Groups/ContentsQuestions/EnquetesQuestions/Records） | demo_mode ガードがコントローラ単位で重複記述され、横断機構がない | 実装漏れ | S2 |
| D-04 / W1-02 | CSP/HSTS/Referrer-Policy/Permissions-Policy なし | アプリ層ヘッダ未実装、Apache 設定にも該当4ヘッダなし | 設計欠落 | S3 |
| D-05 / W1-01 | セキュリティヘッダが Apache 層のみ | アプリ層に SecurityHeaders 機構がなく、配置依存 | 設計上の懸念 | S3 |
| D-06 / W1-07 | Prelock がユーザー名単位 | ブロック判定キーが `log_content=username` のみ（IP 等なし） | 設計欠落 | S3 |
| D-07 / W1-04 | Cookie `Secure` なし | Session 設定に `secure` 指定なし＋HTTP 環境 | 設定/要判定 | 要判定 |
| W1-03 | セッションID再生成（実挙動） | アプリコードではなく Framework の `SessionAuthenticator` が `renew()` | 仕様確認 | — |

---

## D-01 / W1-06: API 全体のレート制限が存在しない

**根本原因: レート制限は「ログイン失敗」専用の実装しかなく、API 全体を対象とするミドルウェア/ガードが存在しない。**

- `src/Application.php`（`middleware()`、76〜137行）のミドルウェアキューは
  `ErrorHandlerMiddleware` → `HostHeaderMiddleware` → `AssetMiddleware` → `RoutingMiddleware` → `ApiErrorMiddleware` → `BodyParserMiddleware` → `AuthenticationMiddleware` → `CsrfProtectionMiddleware`
  のみで、レート制限ミドルウェアを含まない。
- レート制限に相当する実装は次の2箇所のみで、いずれも「ログイン試行」の文脈に限定:
  - `src/Controller/Api/AuthController.php:70` `isRateLimited($username)` / `:168` 実装
    → `ib_logs.log_type='api_login_error'` を直近1時間でカウント（`MAX_LOGIN_ATTEMPTS`）
  - `src/Controller/Trait/UserLoginTrait.php:223` `_isLoginBlocked($username)`
    → `log_type='login_error'` を直近1時間でカウント（≥10）
- 一般エンドポイント（`/api/v1/courses`、`users`、`contents`、`records`、`groups`）には
  カウンタ・バケット・429 送出のいずれも適用されない。MCP のみ `McpRateLimitMiddleware` を持つが、
  これは `McpController` のトランスポート限定。

**影響**: 有効な Bearer トークンがあれば任意エンドポイントを無制限に呼べる（W1-06: 70 回連続 200、429 なし）。DoS／リソース枯渇／ブルートフォース余地。

---

## D-02 / W1-08: `file` 種別アップロードが常時拒否される

**根本原因: キー名の動的生成規則と `ib_config.php` の実キーが一致しない（`file` のみ）。**

- `src/Controller/Admin/ContentsController.php:254`
  `$upload_extensions = (array)Configure::read('upload_' . $file_type . '_extensions');`
  `:255` `$upload_maxsize = Configure::read('upload_' . $file_type . '_maxsize');`
- `config/ib_config.php` の実キー:
  - `upload_extensions`（82行）, `upload_image_extensions`（107行）, `upload_movie_extensions`（114行）
  - `upload_maxsize`（122行）, `upload_image_maxsize`（123行）, `upload_movie_maxsize`（124行）
  - **`upload_file_extensions` / `upload_file_maxsize` は存在しない**
- メカニズム: `file_type='file'` のとき `(array)Configure::read('upload_file_extensions')` は `[]`。
  `:267` `if (!in_array('.' . $ext, []))` が常に true → `:268-269` `$mode='error'` と
  「アップロードされたファイルの形式は許可されていません」。
- 注記: 許可リストが空になるため、任意拡張子が通るのではなく**全件拒否**（機能不全）。
  `image`/`movie` は対応キーが存在するため正常。

**影響**: 配布資料（`kind=file`）のアップロード操作が常に失敗。管理画面のコンテンツ作成が実質不能。

---

## D-03 / W1-09: demo_mode の判定漏れ

**根本原因: demo_mode ガードが各コントローラに個別記述されており、横断的な適用機構がない。**

- ガードの存在（`Configure::read('demo_mode')`）:
  `Admin/ContentsController.php`（92, 141, 258）, `Admin/CoursesController.php`（112, 146）,
  `Admin/InfosController.php`（95）, `Admin/SettingsController.php`（45）,
  `Admin/UsersController.php`（152, 217, 264, 348）, `UserLoginTrait.php`（149）, `UsersController.php`（94）
- ガードが**存在しない**: `Admin/GroupsController.php`, `Admin/ContentsQuestionsController.php`,
  `Admin/EnquetesQuestionsController.php`, `Admin/RecordsController.php`（grep 0 件）
- メカニズム: `AppController::beforeFilter` 等での一元判定ではなく、各アクション内の
  コピー＆ペーストに依存しているため、後から追加された/見落とされた画面で漏れる。

**影響**: `demo_mode=true` のデモ環境でも、グループ・問題・アンケート問題・学習履歴の
追加/編集/削除/並べ替えが実行でき、データを変更できる。

---

## D-04 / D-05 / W1-01 / W1-02: セキュリティヘッダの実装位置と欠落

**根本原因: セキュリティヘッダは Apache 層（vhost / .htaccess）にのみ定義され、アプリ層に SecurityHeaders 機構がない。加えて4ヘッダは未実装。**

- 存在するヘッダ:
  - `docker/apache-vhost.cakephp5.conf:12-13`（vhost 層・実効）: `X-Content-Type-Options: nosniff` / `X-Frame-Options: SAMEORIGIN`
  - `.htaccess:9` `Header always set X-Content-Type-Options nosniff`
    - **実効あり**。`docker/apache-app.conf:1-5`（コンテナでは `conf-enabled/irohaboard.conf` として有効化）が
      `<Directory /var/www/html>` に `AllowOverride All` を設定しているため、DocumentRoot の親にあるこの
      `.htaccess` も適用される。`:3-4` の `RewriteRule` が全 URL を `webroot/` へ rewrite しており、
      front-controller のルーティングが動作している事実が実効の証拠である。
    - 結果として `X-Content-Type-Options` は vhost と `.htaccess` の二重定義（値は同一）。
  - `X-Frame-Options` は vhost のみ（`.htaccess` には定義なし）。
- `webroot/.htaccess` の `Header` は 36-40 行のキャッシュ制御のみで、セキュリティヘッダは含まない。
- 実測（`GET /users/login`、Docker 稼働中）: `X-Content-Type-Options: nosniff` と `X-Frame-Options: SAMEORIGIN` の 2 ヘッダのみ付与されており、上記 vhost 定義と一致する。
- 不在（`src/`・`config/`・`webroot/.htaccess`・`docker/` の grep で 0 件）:
  **Content-Security-Policy / Strict-Transport-Security / Referrer-Policy / Permissions-Policy**
- `src/Application.php` に `SecurityHeadersMiddleware` 等のアプリ層ヘッダ付与は存在しない。

**影響**: ヘッダ付与がデプロイ構成（Apache 設定の適用有無）に依存し、アプリとして保証されない。
クリックジャッキングは X-Frame-Options で一部緩和されるが、CSP/HSTS/Referrer-Policy/Permissions-Policy は未対策。
「ヘッダ皆無」という計画の想定は誤りで、正しくは「2ヘッダ=Apache 層にあり、4ヘッダ=なし」。

---

## D-06 / W1-07: Prelock（ログインブロック）がユーザー名単位

**根本原因: ブロック判定のキーが `log_content=username` のみで、送信元（IP 等）を考慮しない。**

- `src/Controller/Trait/UserLoginTrait.php:223 _isLoginBlocked(string $username)`
  `:232-238` `ib_logs` を `log_type='login_error' AND log_content=$username AND created >= now-1h` でカウントし、`>= 10` でブロック。
- API 側も同型: `src/Controller/Api/AuthController.php:168 isRateLimited()`（`log_type='api_login_error'`、username 単位）。
- メカニズム: 同一ユーザー名で 10 回失敗させれば、そのアカウントは 1 時間ロックされる。
  攻撃者はユーザー名を知っていれば（または推測できれば）**被害者のアカウントを意図的にロックアウト**できる。
  正しいパスワードでもブロックされ（W1-07 実測）、CAPTCHA・段階的遅延・IP 併用・解除手段はない。

**影響**: 可用性侵害（アカウントロックアウト DoS）。`VR-AUTH-044` に相当。API ログインにも同様。

---

## D-07 / W1-04: Cookie `Secure` 属性が付与されない

**根本原因: Session 設定に `secure` 指定がなく、HTTP 環境で計測したため。**

- `config/app.php:419-425`:
  ```
  'Session' => [
      'defaults' => 'php',
      'cookie' => 'AppSession',
      'timeout' => 1440,
      'ini' => ['session.cookie_path' => '/'],
  ],
  ```
  `secure` / `SameSite` の明示設定なし。
- 観測: `AppSession=...; path=/; HttpOnly; SameSite=Lax`（`Secure` なし）。W1 は HTTP（`http://localhost:8082`）で実施。
- メカニズム: PHP/CakePHP は既定で `session.cookie_secure` を無効にしており、
  HTTPS でない限り `Secure` は付かない。明示設定もないため、HTTPS 配備でも
  設定次第で `Secure` が付かない可能性がある。

**影響**: HTTPS 配備時に `Secure` が未付与だと、Cookie が平文 HTTP で送出され得る（ダウングレード/盗聴リスク）。**HTTPS 環境での再計測が必要**。

---

## W1-03: ログイン時のセッションID再生成（実挙動の発生源）

**結論: アプリコードではなく、CakePHP Authentication プラグインの SessionAuthenticator が `renew()` を呼ぶため。**

- `src/` に `session_regenerate` の記述はない（grep 0 件）。
- 一方、依存ライブラリに再生成がある:
  - `vendor/cakephp/authentication/src/Authenticator/SessionAuthenticator.php:81,100 $session->renew();`
  - `vendor/cakephp/authentication/src/Authenticator/PrimaryKeySessionAuthenticator.php:117 $session->renew();`
  - `vendor/cakephp/cakephp/src/Http/Session.php:645 renew()`（実体は `session_regenerate_id`）
- `src/Application.php:176-177` が `Authentication.Session`（SessionAuthenticator）を読み込むため、
  ログイン成立時にセッションIDが再生成され、W1-03 の観測（`763a869a…`→`d083040c…`）と一致する。

**含意**: セッション固定はフレームワーク機構により緩和される。
`VR-AUTH-022` は「アプリ実装の防御」ではなく「Framework の `renew()` に依存」と記載するのが正確。
アプリ側の明示的な再生成・`session_regenerate` は存在しない（将来の Framework 更新で挙動が変わる点に留意）。

---

## 付録: W1-05（CSRF）の補足

CSRF は正常動作。`src/Application.php:118-133` の `skipCheckCallback` は
`/api/`・`/mcp`・`/users/login`・`/users/logout` を免除し、`/admin/users/login` は免除対象外。
そのため管理ログインはトークン無し POST で 403（FormProtection blackHole）となり、観測と一致する。

---

## 推奨される対応の方向（参考・未実施）

| ID | 対応案 |
|---|---|
| D-01 | API 用レート制限ミドルウェア（IP/トークン単位、429 + Retry-After）を追加。 |
| D-02 | `upload()` のキー生成を `$file_type==='file'` 時に `upload_extensions`/`upload_maxsize` へマップ、または `ib_config.php` に `upload_file_*` を追加。 |
| D-03 | demo_mode 判定を `AppController` の横断ガードへ集約、または4コントローラに追加。 |
| D-04/D-05 | アプリ層 SecurityHeaders ミドルウェアで CSP/HSTS/Referrer-Policy/Permissions-Policy/X-Frame-Options を付与。 |
| D-06 | Prelock を username ＋ IP 併用/段階遅延へ。解除手段・通知の検討。 |
| D-07 | HTTPS 環境で再計測し、必要なら Session に `secure`/`SameSite` を明示設定。 |

> 本解析は検査記録であり、コード修正は行っていない。
