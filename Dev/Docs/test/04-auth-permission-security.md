# 04-auth-permission-security.md — 認証・権限・セキュリティ試験項目マトリクス

| 項目 | 内容 |
|---|---|
| 対象 | ログイン/ログアウト（フロント/管理）、RememberMe、セッション管理、CSRF、FormProtection、旧 SHA1 パスワード互換、Host ヘッダ・XSS・SQLi 等のセキュリティ対策 |
| 根拠 | `src/Controller/AppController.php`、`src/Controller/UsersController.php`、`src/Controller/Component/RoleComponent.php`、`src/Controller/Trait/UserLoginTrait.php`、`src/Model/Table/UserTokensTable.php`、`src/Application.php`、`src/Middleware/HostHeaderMiddleware.php`、`config/ib_config.php`、`Docs/design/04-authentication.md`、`Docs/design/05-security.md` |
| 前提データ | DS-0〜DS-6（`Docs/test/README.md` §3 参照）。旧 SHA1 テストは DS-6 を使用 |
| 実行方法 | ブラウザ手動＋curl。ログイン POST: `curl -X POST http://localhost:8082/users/login -d 'username=admin&password=admin' -v`。Cookie 保持: `curl -c cookie.txt -b cookie.txt ...`。CSRF トークン取得: ログインページ GET で `input[name=_Token][key]` から抽出 |

## 1. ログイン / ログアウト

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AUTH-001 | 正常系 | 管理者ログイン成功 | DS-1（admin 存在） | 1. GET /users/login にアクセス<br>2. username=admin, password=admin で POST | 302 → /admin。セッションに admin 情報格納。`ib_sessions` にレコード追加 | 04-authentication.md §ログイン | 難 | P0 | □ | |
| AUTH-002 | 正常系 | 受講者ログイン成功 | DS-1（user 存在） | 1. GET /users/login<br>2. user の資格情報で POST | 302 → /（フロントトップ）。セッションに user 情報格納 | 04-authentication.md §ログイン | 難 | P0 | □ | |
| AUTH-003 | 異常系 | ログイン失敗（誤パスワード） | DS-1 | 1. username=admin, password=wrong で POST | 302 → /users/login。Flash エラーメッセージ表示。`ib_logs` に `login_error` レコード追加 | 04-authentication.md §ログイン | 難 | P0 | □ | |
| AUTH-004 | 異常系 | ログイン失敗（未登録ユーザー） | DS-0 | 1. username=nobody, password=test で POST | 302 → /users/login。`login_error` ログ追加 | 04-authentication.md | 難 | P0 | □ | |
| AUTH-005 | 異常系 | ログイン ID 空欄 | DS-0 | 1. username 空、password 入力で POST | 302 → /users/login | — | 難 | P1 | □ | |
| AUTH-006 | 異常系 | ログイン パスワード空欄 | DS-0 | 1. username 入力、password 空で POST | 302 → /users/login | — | 難 | P1 | □ | |
| AUTH-007 | 異常系 | ログイン ID 入力フィールド XSS | DS-0 | 1. username=`<script>alert(1)</script>` で POST | 302 → /users/login。スクリプト実行なし。エスケープ確認 | 05-security.md §XSS | 難 | P1 | □ | |
| AUTH-008 | 正常系 | ログアウト | DS-1 + ログイン済み | 1. GET /users/logout | 302 → /users/login。セッション破棄。Cookie 削除 | 04-authentication.md §ログアウト | 難 | P0 | □ | |
| AUTH-009 | 異常系 | ログアウト（未ログイン） | DS-0 | 1. GET /users/logout（未ログイン状態） | 302 → /users/login。エラーなし | — | 難 | P1 | □ | |
| AUTH-010 | 正常系 | 未ログインでフロント保護 URL アクセス | DS-0 | 1. 未ログインで /contents 等の保護URLに GET | 302 → /users/login。ログイン後に元URLへリダイレクト | 04-authentication.md | 難 | P0 | □ | |
| AUTH-011 | 正常系 | 未ログインで管理画面アクセス | DS-0 | 1. 未ログインで /admin/users に GET | 302 → /users/login | 04-authentication.md | 難 | P0 | □ | |
| AUTH-012 | 正常系 | 未ログインで API アクセス | DS-0 | 1. Authorization ヘッダなしで GET /api/v1/users | 401。リダイレクトなし（`$isApi ? null : $loginUrl`） | Application.php | 可 | P0 | □ | |
| AUTH-013 | 権限 | admin prefix に非スタッフ（user）アクセス | DS-1 + user トークン | 1. user で /admin/users に GET | 強制ログアウト（AppController `beforeFilter` の admin チェック）。セッション破棄 + CookieAuth 削除 | AppController.php beforeFilter | 難 | P0 | □ | |
| AUTH-014 | 権限 | role=user で管理画面 GET アクセス | DS-1 + ログイン済み（user） | 1. user で /admin/users に GET | 強制ログアウト + /users/login へリダイレクト | AppController.php | 難 | P0 | □ | |
| AUTH-015 | 正常系 | show_admin_link=true 時の管理リンク表示 | `ib_config.php` の `show_admin_link` を true に変更 + DS-1 | 1. ログイン後、フロント画面を確認 | 管理画面へのリンクが表示される | ib_config.php | 難 | P2 | □ | |
| AUTH-016 | 正常系 | demo_mode=true 時のデフォルト値適用 | `ib_config.php` の `demo_mode` を true に変更 + DS-1 | 1. ログイン POST。username/password を空欄 | 302。demo_mode=true 時はデフォルトの admin/admin でログイン成功 | UserLoginTrait `performLogin()` | 難 | P2 | □ | |

## 2. RememberMe

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AUTH-017 | 正常系 | RememberMe Cookie 発行 | DS-1（user）+ HTTPS 環境 | 1. user でログイン POST に `remember_me=1` を追加 | CookieAuth 関連 Cookie が発行される。HttpOnly 属性あり | UserTokensTable `issueRememberToken()` | 難 | P1 | □ | |
| AUTH-018 | 異常系 | RememberMe 未チェック時は発行なし | DS-1（user） | 1. user でログイン POST（remember_me なし） | RememberMe Cookie なし。セッション Cookie のみ | UserLoginTrait `performLogin()` | 難 | P1 | □ | |
| AUTH-019 | 境界値 | RememberMe 有効期限 14 日 | DS-1 + 発行済 Cookie | 1. RememberMe Cookie 発行後、14日経過をシミュレート（DB の `expired` を過去に変更）<br>2. Cookie でアクセス | 未認証状態。`expired` が期限切れのためCookieAuth 認証失敗 | ib_config.php `remember_token_expired_days=14`、UserTokensTable | 難 | P1 | □ | |
| AUTH-020 | セキュリティ | RememberMe 改ざん Cookie | DS-1 + 発行済 Cookie | 1. Cookie の validator 部分をランダムに変更<br>2. 改ざん Cookie でアクセス | 未認証。Cookie 発行済みの token は自動 revoke | UserTokensTable `authenticateRememberCookie()` | 難 | P0 | □ | |
| AUTH-021 | セキュリティ | RememberMe revoke 済み Cookie | DS-1 + 発行済 Cookie + ログアウト済 | 1. ログアウトして RememberMe Cookie を revoke<br>2. revoke 済 Cookie でアクセス | 未認証 | UserTokensTable | 難 | P1 | □ | |
| AUTH-022 | 境界値 | parseCookie 形式検証: 正常 (selector:validator) | — | 1. `parseCookie("0123456789abcdef0123456789abcdef:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef")` | `["01234...","01234..."]`（32hex : 64hex） | UserTokensTable `parseCookie()` | 可 | P1 | □ | |
| AUTH-023 | 異常系 | parseCookie 形式検証: セパレータなし | — | 1. `parseCookie("0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef")` | false（`:` なし） | UserTokensTable `parseCookie()` | 可 | P1 | □ | |
| AUTH-024 | 異常系 | parseCookie 形式検証: selector 短い | — | 1. `parseCookie("abc:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef")` | false（selector 32hex 未満） | UserTokensTable `parseCookie()` | 可 | P2 | □ | |
| AUTH-025 | 異常系 | parseCookie 形式検証: validator 短い | — | 1. `parseCookie("0123456789abcdef0123456789abcdef:0123456789abcdef")` | false（validator 64hex 未満） | UserTokensTable `parseCookie()` | 可 | P2 | □ | |
| AUTH-026 | 異常系 | parseCookie 形式検証: hex 以外 | — | 1. `parseCookie("zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef")` | false（hex 以外文字） | UserTokensTable `parseCookie()` | 可 | P2 | □ | |
| AUTH-027 | 正常系 | RememberMe → セッション切替後も有効 | DS-1 + 発行済 Cookie | 1. RememberMe Cookie 付きでブラウザを閉じる（セッション消滅）<br>2. 再度ブラウザでアクセス | RememberMe Cookie で自動ログイン。セッション再生成 | 04-authentication.md §RememberMe | 難 | P1 | □ | |

## 3. セッション管理

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AUTH-028 | 整合性 | Cookie 名 `AppSession` | DS-1 + ログイン済み | 1. ログイン後のレスポンスヘッダ確認 | `Set-Cookie: AppSession=...`。`config/app.php` の `cookie => 'AppSession'` と一致 | config/app.php:419 | 難 | P0 | □ | |
| AUTH-029 | 境界値 | セッションタイムアウト 1440 分 | DS-1 + ログイン済み | 1. ログイン後、1440分（24時間）経過をシミュレート<br>2. 保護URLにアクセス | 未認証状態。`config/app.php` の `timeout => 1440` | config/app.php:420 | 難 | P1 | □ | |
| AUTH-030 | 正常系 | ログイン画面 GET でセッション破棄 | DS-1 + ログイン済み | 1. ログイン済み状態で GET /users/login | セッション破棄。ログアウト状態になる | UserLoginTrait、AppController `beforeFilter` | 難 | P0 | □ | |
| AUTH-031 | 整合性 | `ib_sessions` へのセッション保存 | DS-1 + ログイン済み | 1. ログイン<br>2. DB で `SELECT * FROM ib_sessions` を確認 | レコードが存在。セッションデータが格納されている | config/app.php `defaults => 'php'` | 難 | P1 | □ | |
| AUTH-032 | セキュリティ | セッション app_dir 不一致で強制ログアウト | DS-1 + ログイン済み | 1. `ib_settings` の `app_dir` を変更<br>2. 保護URLにアクセス | 強制ログアウト。AppController `beforeFilter` の `Setting` バリデーション | AppController.php beforeFilter | 難 | P1 | □ | |

## 4. CSRF 保護

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AUTH-033 | セキュリティ | フロント POST で CSRF トークン欠落 | DS-1 + ログイン済み（user） | 1. POST /users/change_password（例）で `_Token` フィールドなし | 403（blackHole）。`AppController::blackHole()` により /users/login へリダイレクト | Application.php `CsrfProtectionMiddleware`、AppController `blackHole()` | 難 | P0 | □ | |
| AUTH-034 | セキュリティ | 管理画面 POST で CSRF トークン改ざん | DS-1 + ログイン済み（admin） | 1. 管理画面の POST で `_Token` を変更 | 403（blackHole） | 同上 | 難 | P0 | □ | |
| AUTH-035 | セキュリティ | CSRF トークン再生成（2回目 POST） | DS-1 + ログイン済み | 1. 正しい CSRF トークンで POST<br>2. 同じトークンでもう一度 POST | 1 は成功。2 は 403（トークン再生成後、旧トークン無効） | CsrfProtectionMiddleware | 難 | P1 | □ | |
| AUTH-036 | 正常系 | /users/login は CSRF スキップ | DS-0 | 1. GET /users/login で CSRF トークン取得<br>2. ログイン POST（CSRF トークンなし） | 正常にログイン処理。CSRF チェックなし | Application.php CSRF skip list | 可 | P0 | □ | |
| AUTH-037 | 正常系 | /users/logout は CSRF スキップ | DS-1 + ログイン済み | 1. GET /users/logout（CSRF トークンなし） | 正常にログアウト | Application.php CSRF skip list | 可 | P0 | □ | |
| AUTH-038 | 正常系 | /api/* は CSRF スキップ | DS-1 + admin トークン | 1. POST /api/v1/users（CSRF トークンなし） | CSRF チェックなし。API 認証のみで動作 | Application.php CSRF skip `/api/*` | 可 | P0 | □ | |

## 5. FormProtection

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AUTH-039 | 正常系 | unlockActions: Admin Contents order | DS-1 + admin ログイン | 1. Admin コンテンツ一覧で並べ替え操作（order アクション） | ブラックホールなし。正常に並べ替え実行 | AppController `FormProtection` unlockActions | 難 | P1 | □ | |
| AUTH-040 | 正常系 | unlockActions: Admin Contents preview | DS-1 + admin ログイン | 1. Admin コンテンツプレビュー操作 | ブラックホールなし | 同上 | 難 | P1 | □ | |
| AUTH-041 | 正常系 | unlockActions: Admin Contents uploadImage | DS-1 + admin ログイン | 1. 画像アップロード操作 | ブラックホールなし | 同上 | 難 | P1 | □ | |
| AUTH-042 | 正常系 | unlockActions: Admin Courses order | DS-1 + admin ログイン | 1. コース並べ替え操作 | ブラックホールなし | 同上 | 難 | P1 | □ | |
| AUTH-043 | 正常系 | unlockActions: Admin EnquetesQuestions order | DS-1 + admin ログイン | 1. アンケート質問並べ替え操作 | ブラックホールなし | 同上 | 難 | P2 | □ | |
| AUTH-044 | 正常系 | unlockActions: Admin ContentsQuestions order | DS-1 + admin ログイン | 1. 問題並べ替え操作 | ブラックホールなし | 同上 | 難 | P2 | □ | |
| AUTH-045 | 正常系 | unlockActions: UsersController login | DS-0 | 1. ログイン POST | ブラックホールなし。正常ログイン | UsersController `unlockActions=['login']` | 難 | P0 | □ | |
| AUTH-046 | 正常系 | unlockActions: Admin UsersController login/logout | DS-0 | 1. 管理画面ログイン POST<br>2. ログアウト GET | ブラックホールなし | Admin UsersController `unlockActions=['login','logout']` | 難 | P1 | □ | |
| AUTH-047 | セキュリティ | FormProtection 改ざん POST | DS-1 + admin ログイン | 1. 管理画面フォームの hidden フィールド値を改ざんして POST | 403（blackHole）。FormProtection がフォームハッシュを検証 | FormProtection middleware | 難 | P0 | □ | |
| AUTH-048 | セキュリティ | FormProtection フィールド削除 | DS-1 + admin ログイン | 1. POST リクエストから必須 hidden フィールドを削除 | 403（blackHole） | FormProtection middleware | 難 | P1 | □ | |

## 6. 旧 SHA1 パスワード互換

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AUTH-049 | 回帰 | DS-6 ユーザーで API トークン発行（SHA1 legacy_salt） | DS-6（`sha1(legacy_salt . password)` のユーザー） | 1. DS-6 ユーザーの資格情報で POST /api/v1/auth/token | 201。トークン発行成功。<br>2. DB の passwords フィールドが `$2y$` プレフィックス（bcrypt）に更新 | AuthController `issueToken()` SHA1互換、UserLoginTrait `_login()` 自動更新 | 可 | P0 | □ | |
| AUTH-050 | 回帰 | DS-6 ユーザーで画面ログイン（SHA1 legacy_salt） | DS-6 | 1. DS-6 ユーザーの資格情報で画面ログイン POST | 302。ログイン成功。<br>2. DB の passwords が bcrypt（`$2y$`）に自動更新 | UserLoginTrait `_login()` SHA1→bcrypt 自動アップグレード | 難 | P0 | □ | |
| AUTH-051 | 回帰 | DS-6 ユーザー 誤パスワード（SHA1 legacy_salt） | DS-6 | 1. DS-6 ユーザー名＋誤パスワードで API POST<br>2. DS-6 ユーザー名＋誤パスワードで画面 POST | API: 401。画面: 302 → /users/login エラー。パスワードは更新されない | AuthController、UserLoginTrait | 可 | P0 | □ | |
| AUTH-052 | 回帰 | 旧 SHA1（salt なし: `sha1(password)`）ユーザー API トークン | DS-1 + `sha1(password)` のユーザーを手動投入 | 1. `sha1("password")` をハッシュにしたユーザーを DB に直接投入<br>2. API トークン発行 POST | 201。`sha1(password)` 単体でも認証通る。ログイン後に bcrypt へ自動更新 | AuthController `issueToken()` の3段階認証（bcrypt→SHA1+salt→SHA1） | 可 | P0 | □ | |
| AUTH-053 | 回帰 | 旧 SHA1（salt なし）ユーザー画面ログイン | 同上 | 1. 同ユーザーで画面ログイン POST | 302。ログイン成功。bcrypt へ自動更新 | UserLoginTrait `_login()` | 難 | P0 | □ | |
| AUTH-054 | 回帰 | bcrypt → SHA1→bcrypt の更新後再ログイン | AUTH-049 実施後 | 1. bcrypt 化済みの DS-6 ユーザーで再度 API トークン発行 | 201。bcrypt による認証。SHA1 へのフォールバック不要 | AuthController `_login()` password_verify | 可 | P1 | □ | |

## 7. レート制限 / ログ

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AUTH-055 | 異常系 | API ログインレート制限（10回/時） | DS-1 | 1. 誤パスワードで10回 POST /api/v1/auth/token<br>2. 11回目で正しい資格情報 POST | 10回目まで: 401。11回目: 429。`ib_logs` に `api_login_error` 10件以上 | AuthController `isRateLimited()` | 可 | P0 | □ | |
| AUTH-056 | 異常系 | 画面ログインレート制限（10回/時） | DS-1 | 1. 誤パスワードで10回画面ログイン POST<br>2. 11回目で正しい資格情報 POST | 10回目以降: ログインブロック。`ib_logs` に `login_error` 10件以上 | UserLoginTrait `_isLoginBlocked()` | 難 | P0 | □ | |
| AUTH-057 | 整合性 | ログイン失敗時の `login_error` ログ記録 | DS-1 | 1. 誤パスワードでログイン<br>2. DB で `SELECT * FROM ib_logs WHERE type='login_error'` | レコードが存在。IP・ユーザー名・日時が記録 | AppController `writeLog()` | 難 | P1 | □ | |
| AUTH-058 | 整合性 | API ログイン失敗時の `api_login_error` ログ記録 | DS-1 | 1. 誤パスワードで POST /api/v1/auth/token<br>2. DB で `SELECT * FROM ib_logs WHERE type='api_login_error'` | レコードが存在 | AuthController `logFailedAttempt()` | 可 | P1 | □ | |
| AUTH-059 | 正常系 | ログイン成功後にカウンタリセット | DS-1 | 1. 誤パスワードで9回 POST（1回不足状態）<br>2. 正しい資格情報でログイン成功<br>3. 再度誤パスワード9回 | 10回目でもログインブロックなし。成功時にカウンタリセット | UserLoginTrait `_isLoginBlocked()` | 難 | P1 | □ | |

## 8. Host ヘッダインジェクション

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AUTH-060 | セキュリティ | Host ヘッダ不一致（通常リクエスト） | — | 1. `curl -H 'Host: evil.com' http://localhost:8082/` | 400。`HostHeaderMiddleware` が `App.fullBaseUrl` と照合 | HostHeaderMiddleware.php | 可 | P0 | □ | |
| AUTH-061 | セキュリティ | Host ヘッダ不一致（API リクエスト） | — | 1. `curl -H 'Host: evil.com' http://localhost:8082/api/v1/users` | 400 | HostHeaderMiddleware.php | 可 | P0 | □ | |
| AUTH-062 | 境界値 | Host ヘッダ: ポート番号含む | — | 1. `curl -H 'Host: localhost:8082' http://localhost:8082/` | 200。正しいホスト名+ポート | HostHeaderMiddleware.php | 可 | P1 | □ | |
| AUTH-063 | 境界値 | Host ヘッダ: 大文字 | — | 1. `curl -H 'Host: LOCALHOST:8082' http://localhost:8082/` | 400。大文字は不一致扱い（実装依存。要確認） | HostHeaderMiddleware.php | 可 | P2 | □ | |

## 9. SQL インジェクション / XSS / パストラバーサル

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AUTH-064 | セキュリティ | SQL インジェクション: ログイン ID | DS-0 | 1. username=`admin' OR '1'='1` でログイン POST | ログイン失敗。SQL インジェクション不発（CakePHP のクエリビルダ使用） | 05-security.md §SQLi | 難 | P0 | □ | |
| AUTH-065 | セキュリティ | SQL インジェクション: 検索条件（API） | DS-1 + admin トークン | 1. GET /api/v1/users?username=admin' OR '1'='1 | 正常なレスポンス（200 or 400）。SQL インジェクション不発 | 05-security.md | 可 | P1 | □ | |
| AUTH-066 | セキュリティ | SQL インジェクション: API パラメータ | DS-1 + admin トークン | 1. POST /api/v1/users に `{"username":"test'; DROP TABLE ib_users; --","name":"x","role":"user"}` | 400（バリデーションエラー）または201（エスケープされて保存）。テーブル削除なし | 05-security.md | 可 | P1 | □ | |
| AUTH-067 | セキュリティ | SQL インジェクション: CSV インポート | DS-1 + admin ログイン | 1. CSV フィールドに `=admin' OR 1=1--` を含む CSV をアップロード | エスケープされて処理。SQL インジェクション不発 | 05-security.md | 難 | P1 | □ | |
| AUTH-068 | セキュリティ | XSS: お知らせ本文 | DS-5 + admin ログイン | 1. 管理画面でお知らせ本文に `<script>alert(1)</script>` を含むお知らせを作成<br>2. 一覧/詳細画面で確認 | HTML エスケープ済みで表示。スクリプト実行なし | 05-security.md §XSS | 難 | P0 | □ | |
| AUTH-069 | セキュリティ | XSS: コンテンツ HTML | DS-2 + admin ログイン | 1. HTML コンテンツに `<script>alert(1)</script>` を含む内容を設定<br>2. コンテンツ表示 | HTML コンテンツはサニタイズ済みで表示。スクリプト実行なし | 05-security.md | 難 | P0 | □ | |
| AUTH-070 | セキュリティ | XSS: CSV 出力の数式インジェクション | DS-1, DS-3 + admin ログイン | 1. 学習履歴 CSV をダウンロード<br>2. スプレッドシートで数式（`=SUM(A1:A10)`）が自動実行されないことを確認 | CSV 出力で数式プレフィックス（`=` `+` `-` `@`）が安全に処理される | 05-security.md | 難 | P1 | □ | |
| AUTH-071 | セキュリティ | パストラバーサル: file-image | DS-2 + admin ログイン | 1. URL `/file-image/../../../../etc/passwd` にアクセス | 404 または安全なエラー。ファイルシステムへの直接アクセス不可 | 05-security.md §パストラバーサル | 難 | P0 | □ | |
| AUTH-072 | セキュリティ | パストラバーサル: コンテンツファイル DL | DS-2 + admin ログイン | 1. コンテンツ DL URL のパラメータに `../` を含める | 安全なエラー。ディレクトリトラバーサル不発 | 05-security.md | 難 | P1 | □ | |
| AUTH-073 | セキュリティ | セッション固定化 | DS-1 | 1. ログイン前のセッション ID を記録<br>2. ログイン<br>3. ログイン後のセッション ID を確認 | ログイン後にセッション ID が再生成される | 05-security.md §セッション | 難 | P0 | □ | |

## 10. トークンログ出力・ファイル保護

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AUTH-074 | セキュリティ | トークン値のログ出力なし | DS-1 + admin トークン | 1. API トークン発行<br>2. `logs/error.log` および `logs/debug.log` を確認 | トークン値（Selector:Validator）がログに出力されていない | 05-security.md §ログ | 難 | P0 | □ | |
| AUTH-075 | セキュリティ | パスワードのログ出力なし | DS-1 | 1. ログイン操作実施<br>2. ログファイルを確認 | パスワード平文がログに出力されていない | 05-security.md | 難 | P0 | □ | |
| AUTH-076 | セキュリティ | .htaccess 拒否規則: .env | — | 1. `GET http://localhost:8082/.env` | 403。webroot/.htaccess で Deny | webroot/.htaccess | 可 | P0 | □ | |
| AUTH-077 | セキュリティ | .htaccess 拒否規則: vendor | — | 1. `GET http://localhost:8082/vendor/` | 403 | webroot/.htaccess | 可 | P1 | □ | |
| AUTH-078 | セキュリティ | .htaccess 拒否規則: .git | — | 1. `GET http://localhost:8082/.git/` | 403 | webroot/.htaccess | 可 | P1 | □ | |
| AUTH-079 | セキュリティ | .htaccess 拒否規則: .sql/.bak/.ini/.cgi/.py | — | 1. `GET http://localhost:8082/test.sql` 等 | 403 | webroot/.htaccess | 可 | P1 | □ | |
| AUTH-080 | セキュリティ | .htaccess 拒否規則: .well-known/ | — | 1. `GET http://localhost:8082/.well-known/` | 403 | webroot/.htaccess | 可 | P2 | □ | |
| AUTH-081 | セキュリティ | .htaccess: .php は index.php と test_pi.php のみ許可 | — | 1. `GET http://localhost:8082/nonexistent.php` | 403。index.php 以外の直接 PHP アクセス拒否 | webroot/.htaccess | 可 | P1 | □ | |
| AUTH-082 | セキュリティ | deny_install_update_access | `ib_config.php` の `deny_install_update_access` を true に変更 | 1. /install にアクセス<br>2. /update にアクセス | 403。アクセス拒否 | ib_config.php | 難 | P1 | □ | |
| AUTH-083 | セキュリティ | CSRF: POST ヘッダ Content-Type 不正 | DS-1 + admin ログイン | 1. POST を `Content-Type: text/plain` で送信 | 415 または 400。正しく処理されない | — | 難 | P2 | □ | |
| AUTH-084 | 異常系 | Cookie: 不正なフォーマット | DS-1 | 1. `Cookie: AppSession=../../../etc` でリクエスト | 正常なエラー応答。セキュリティ上問題なし | — | 難 | P2 | □ | |
| AUTH-085 | 正常系 | Cookie: HttpOnly 属性 | DS-1 + ログイン済み | 1. ログイン後の Set-Cookie ヘッダ確認 | `HttpOnly` 属性が付与されている | AppController `writeCookie()` | 難 | P1 | □ | |

## 集計表

| 分類 | 項目数 |
|---|---|
| 正常系 | 24 |
| 異常系 | 14 |
| 境界値 | 5 |
| 権限 | 2 |
| セキュリティ | 30 |
| 回帰 | 6 |
| 整合性 | 4 |
| **合計** | **85** |

| 優先度 | 項目数 |
|---|---|
| P0 | 37 |
| P1 | 37 |
| P2 | 11 |
| **合計** | **85** |

### 設計書との対応状況

| 設計書 | 対応範囲 | 備考 |
|---|---|---|
| 04-authentication.md | AUTH-001〜016, AUTH-017〜027, AUTH-028〜032 | ログイン/ログアウト/RememberMe/セッション |
| 05-security.md | AUTH-033〜048, AUTH-064〜075 | CSRF/FormProtection/SQLi/XSS/パストラバーサル/セッション固定化 |
| Application.php (middleware) | AUTH-036〜038, AUTH-060〜063 | CSRF skip/HostHeaderMiddleware |
| ib_config.php | AUTH-015〜016, AUTH-029, AUTH-082 | demo_mode/show_admin_link/timeout/deny_install |
| UserTokensTable | AUTH-017〜027 | RememberMe/parseCookie |
| UserLoginTrait | AUTH-049〜054, AUTH-055〜059 | SHA1 互換/レート制限 |
| webroot/.htaccess | AUTH-076〜081 | ファイル拒否規則 |
