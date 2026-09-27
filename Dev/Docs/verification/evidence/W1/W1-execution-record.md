# W1 実施記録（P0 セキュリティ／権限）

| 項目 | 内容 |
|------|------|
| 実施日 | 2026-09-25 |
| 実施範囲 | W1（P0 セキュリティ／権限）: RK-01/02/04/05/13/17、VR-SEC 系、ログインPrelock、CSRF、レート制限 |
| 実施方法 | 実 HTTP による外部観測（curl）＋ 静的コード点検。**アプリケーションの修整は行っていない** |
| 対象環境 | `http://localhost:8082`（`irohaboard5-web-1` php:8.4-apache）／ MariaDB 11.4.13（port 13307, db `irohaboard`） |
| 前提 | `debug=true`（`HostHeaderMiddleware` は無効）。HTTPS 環境なし |

---

## 1. 結果サマリ

| ID | 検証項目 | 判定 | 根拠 |
|----|----------|------|------|
| W1-01 | セキュリティヘッダ（クリックジャッキング） | ⚠️ 一部可 | `X-Frame-Options: SAMEORIGIN` / `X-Content-Type-Options: nosniff` は付与済。**ただし Apache 設定 `docker/apache-vhost.cakephp5.conf:12-13` 由来で、アプリケーション層ではない** |
| W1-02 | CSP / HSTS / Referrer-Policy / Permissions-Policy | ❌ 未設定 | 4 ヘッダすべて応答に不在 |
| W1-03 | セッション固定 | ✅ 防御済み | ログイン前後でセッション ID が変化（`763a869a…` → `d083040c…`）。静的 `session_regenerate` は `src/` に無いが、実挙動は再生成される |
| W1-04 | Cookie 属性（HttpOnly / SameSite / Secure） | ⚠️ 一部可 | `AppSession` に `HttpOnly; SameSite=Lax` あり。**`Secure` は不在**（HTTP 環境のため要 HTTPS での再判定） |
| W1-05 | CSRF 保護（管理画面） | ✅ 有効 | `/admin/users/add`（200）に `_csrfToken` を出力。`/admin/users/login` はトークン無し POST で **403**（FormProtection :blackHole） |
| W1-06 | API レート制限 | ❌ 未実装 | 正しい Bearer で `/api/v1/courses` を 70 回連続 GET → **70/70 が 200**、429 発火なし |
| W1-07 | ログインPrelock | ✅ 動作 | 同一ユーザー名で 13 回失敗後、正解パスワードでも body に "block" 表示。`ib_logs.log_type='login_error'` 27 件 |
| W1-08 | ファイルアップロード設定 | ❌ 機能不全 | `Admin/ContentsController.php:254-255` は `upload_{file_type}_extensions` / `_maxsize` を参照するが、`ib_config.php` に `upload_file_extensions` / `upload_file_maxsize` が**不在**（`upload_extensions` / `upload_maxsize` が実在）。`file_type=file` は許可拡張子 0 件で常時拒否 |
| W1-09 | demo_mode の一貫性 | ❌ 漏れ | `demo_mode` 参照数: Groups **0** / ContentsQuestions **0** / EnquetesQuestions **0** / Records **0** に対し Contents 3 / Users 4 / Courses 2 / Infos 1 / Settings 1 |
| W1-10 | MCP `get_content_html` の html サニタイズ | ✅ 修正済み | `GetContentHtmlTool.php:74` が `MarkdownRenderer::purifyHtml($body)` を使用（commit `b945bcb` U-5 適用） |
| W1-11 | IDOR（`GET /api/v1/users/{id}`） | ✅ 遮断 | user1→`/users/1`(admin)=**403**、user1→`/users/5`(self)=**200**、未認証=**401**、admin→`/users/1`=**200** |
| W1-12 | records スコープ（`?user_id=` 越境） | ✅ 漏洩なし | user1 が `?user_id=1` を指定 → **200** だが `{"data":[],"meta":{...,"total":0}}`。他ユーザー記録は返さず、フィルタは無視される |
| W1-13 | courses 可視範囲 | ✅ 適正 | user1=**1件**／admin=**2件**（未所属分は除外される） |
| W1-14 | API 権限マトリクス（5ロール×操作） | ✅ 全緑 | `ApiContractTest` **46 tests / 339 assertions OK** |

### 実測ヘッダ（証跡: `GET /users/login`）
```
HTTP/1.1 200 OK
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Set-Cookie: AppSession=<id>; path=/; HttpOnly; SameSite=Lax
Set-Cookie: csrfToken=<token>; path=/; HttpOnly
（Content-Security-Policy / Strict-Transport-Security / Referrer-Policy / Permissions-Policy は不在）
```
> 注記: 上記2ヘッダは `docker/apache-vhost.cakephp5.conf:12-13`（vhost 層）由来。
> `X-Content-Type-Options` は `.htaccess:9` にも同一値があり、`docker/apache-app.conf:1-5` の
> `<Directory /var/www/html> AllowOverride All` により**実効する**（同ファイル `:3-4` の RewriteRule が
> 全 URL を `webroot/` へ rewrite してルーティングを成立させている）。
> `webroot/.htaccess` の `Header` は 36-40 行のキャッシュ制御のみで、セキュリティヘッダは含まない。

---

## 2. 検出した不具合（要対応）

| 重大度 | ID | 内容 | 影響 |
|--------|----|------|------|
| **S2** | D-01 | API にレート制限がない（W1-06） | 認証済みトークンで無制限に叩ける。ブルートフォース・資源枯渇の余地 |
| **S2** | D-02 | `file` 種別のアップロードが常時拒否（W1-08） | 「配布資料」機能が事実上利用不可。設定キー名不一致 |
| **S2** | D-03 | demo_mode が管理 4 画面で未判定（W1-09） | デモモード時に Groups/ContentsQuestions/EnquetesQuestions/Records が書き換え可能 |
| **S3** | D-04 | CSP / HSTS / Referrer-Policy / Permissions-Policy 未設定（W1-02） | XSS 時の被害拡大・Browsers の缓解なし（HTTPS は未使用） |
| **S3** | D-05 | セキュリティヘッダがアプリ層ではなく Apache 層のみ（W1-01） | アプリ層のテスト／別デプロイで挙動が再現しない |
| **S3** | D-06 | Prelock がユーザー名単位（W1-07） | 既知のユーザー名を狙ったロックアウト DoS が可能（VR-AUTH-044） |
| **要判定** | D-07 | Cookie `Secure` 不在（W1-04） | HTTP 環境では判定不能。HTTPS 環境で再計測が必要 |

---

## 3. 参照

- 検証計画: `Docs/verification/04-items-api-security-nfr.md`（VR-SEC / VR-API）、`02-items-product.md`（VR-AUTH）
- リスク: `06-risk-coverage-automation.md` RK-01/02/05/13/17
- 手順: `09-verification-procedure.md` §2

## 4. 次の工程

- W1 残: 濫用シナリオ AB-01〜09/13/14（`05-e2e-exploratory.md`）— 人間判断主体のため手動実施
- W2: P0 API / MCP / データ（採点・集計・CSV）
- D-01〜D-03 は P0 として修正判断待ち（本記録では修整していない）
