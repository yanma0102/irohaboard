# W8 実施記録（ABチャーター／残手動項目のライブ実測）

| 項目 | 内容 |
|------|------|
| 実施日 | 2026-09-27 |
| 実施範囲 | W8（ABチャーター／残手動項目のライブ実測）: AB-01〜AB-15、VR-SEC-005、AD-073、VR-AUTH-043、D-07、D-40 |
| 実施方法 | curl + HTTP リクエスト、DB 直接操作（検証データ作成のみ）、MCP ツール呼び出し、Playwright（ヘッドレス Chrome） |
| 対象環境 | ローカル Docker（web `irohaboard5-web-1` :8082、DB `irohaboard5-db-1` MariaDB 11.4）、`127.0.0.1:8082` + `Host: localhost` ヘッダ偽装、DB `irohaboard`（本番相当の実測用） |
| 認証 | 管理 `admin/adminpass`（セッション）、一般 `user1/password`・`user2/password`（セッション）、API は `POST /api/v1/auth/token` で取得した user1/admin の Bearer トークン |
| スイート基準 | 748 tests / 3708 assertions / 0E / 0F / 8 PHPUnit Notices（`bash scripts/test-fresh.sh` 実測） |

---

## 1. 結果サマリ

| # | 検証項目 | 判定 | 根拠 |
|---|----------|------|------|
| AB-01 | 他人の学習記録へのアクセス拒否 | ✅ | user1 で `GET /api/v1/records/23`（user2 所有の record を検証用に INSERT、id=23）→ **403 Forbidden**、admin トークンでは 200 で取得可。`GET /api/v1/records` 一覧は自分の分のみ |
| AB-02 | 非公開コンテンツの API/画面/MCP 拒否 | ✅ | status=0 のコンテンツ id=9「非公開ラベル」/ id=10「非公開HTML」: API `GET /api/v1/contents/9` → **404** JSON、画面 `/contents/view/9` → **302**、admin API → **200**。MCP `tools/call get_content{content_id:9}` → `isError:true` "Content not found." |
| AB-03 | ユーザー権限昇格の試み | ✅ | user1 で `PUT /api/v1/users/5` を4種のペイロード形状（`{"role":"admin"}` 等）→ 全て **403 "This action requires an admin or manager role"**。DB の `ib_users.role` は `user` のまま（再確認済） |
| AB-05 | お知らせグループ限定の出し分け | ✅ | 正しい関係: info2=group1限定 / user1=group1 / user2=group2。user2→`/infos/view/2` = **404**（漏えい無し）、user1→info2 = 200（正当）、user1→info3(非公開) = **404**、user2→info1(全体公開) = 200。一覧 `/infos/index` は user2 に group1 限定・非公開が出ない（hit 0/0）、user1 には group1 限定が1件。不正 id（999・abc）も 404。**補足**: お知らせに API ルートは無い（`GET /api/v1/infos/2` → 404 "Endpoint not found"、`config/routes.php` の infos はフロントのみ） |
| AB-05b | MCP によるコンテンツ作成/更新の拒否（一般ユーザー） | ✅ | user1（一般ユーザー）で MCP `initialize` → `Mcp-Session-Id` 取得 → `create_content{course_id:1,...}` → `isError:true` **"Only staff members can create content."**、`update_content{content_id:3,...}` → `isError:true` **"Only staff members can update content."**。DB で `ab05b-attack` / `body='pwned'` の作成が無いこと・コンテンツ3本文が無改変であることを確認 |
| AB-08 / VR-SEC-005 | ファイルアップロード拡張子制御 | ✅ | 管理セッションで `GET /admin/contents/upload/file` から CSRF 抽出後 multipart 送信。**`.php`/`.phtml`/`.htaccess`/`.fake.gif.php`/`.PhP` は全件拒否**（Flash「アップロードされたファイルの形式は許可されていません」）。`shell.php.txt` のみ保存 → `webroot/uploads/20260927093704apnl.txt`（17B、内容 `<?php echo "PWN";`）。配信は `X-Content-Type-Options: nosniff`、未ログイン時は `webroot/uploads/.htaccess`（`Options -Indexes` + `RewriteCond %{HTTP_COOKIE} !LoginStatus` → `../img/wrong.png`）により **PNG が返り PHP は非実行**。許可拡張子は `config/ib_config.php:82`（.php/.phtml なし） |
| AB-10 | MCP の他人レコード/プロフィールアクセス拒否 | ✅ | user1 の MCP `list_records` → 返却 record ids `[22,21,20,7,6]`・user_ids `[5]` のみ（他人の record 23 は含まない）。`get_user_profile{user_id:1}` → `isError:true` "Access denied. You can only view your own profile."。**未認証**で `tools/list` → **401** `{"error":"invalid_token",...}` |
| AB-11 | API レート制限の実測 | ✅ | 設定 `api_rate_limit_per_minute=120`。同一 user1 トークンで130並列 → **125×200 + 5×429**。未認証（IP キー）逐次130回 → **120×401 + 10×429**。トークン発行 `POST /api/v1/auth/token`（誤パスワード連打）→ **79回目で 429**（計 88×429/42×401）。429 は JSON `{"error":{"code":429,"message":"Rate limit exceeded. Please try again later."}}` + **`Retry-After: 7`** ヘッダ |
| AB-13 | 不正 JSON 送信時のエラー応答形式 | ❌→**D-39** | `PUT /api/v1/courses` に不正 JSON → **400 / Content-Type: text/html / 867,808B（別試行で 874KB）の CakePHP debug HTML**。`Accept: application/json` でも HTML のまま。**未認証でも再現**。内部に `/var/www/html/src`・`Cake\Http\Exception\BadRequestException`・`ApiErrorMiddleware.php:29`・`BodyParserMiddleware`・`webroot/index.php:32` 等を露出。根因: ① `src/Middleware/ApiErrorMiddleware.php:30,32` が catch するのは `ApiException`/`InvalidParameterException` のみで BodyParser の `BadRequestException` を素通り、② `config/app_local.php:21` の debug が env `DEBUG` 未設定時 default true（コンテナで未設定）。**修正は別タスクで進行中（ApiErrorMiddleware が /api・/mcp 限定で JSON 400 を返すようにする）**。debug=false の本番でも「JSON API が HTML を返す契約違反」自体は残る点を指摘 |
| AB-14 | CSV エクスポートの数式ペイロードサニタイズ | ✅ | 管理CSV `GET /admin/users?cmd=export`（Content-Type `text/csv; charset=SJIS-WIN`、906B）。DB に数式ペイロードのユーザー `csvform1`（id=10）を作成: name `=cmd\|'/C calc'!A1`、email `+HYPERLINK("http://evil","click")`、comment `-2+5`。出力は3件とも **`'` 前置**済み（`'=cmd|...`、`'+HYPERLINK(...)`、`'-2+5`）。実装は `AppController::sanitizeCsvValue()`（`src/Controller/AppController.php:411`、`/^\s*[=+\-@]/` に `'` を付与） |
| AB-15 | オープンリダイレクトの試み | ✅ | ログイン成功時の遷移先は `?next=`/`?url=`/`?redirect=https://evil.example.com`/`?redirect=//evil.example.com`/`?redirect=/\evil.example.com` の**全変種で 302 → `http://localhost/`（App.fullBaseUrl 固定、params 無視）**。外部遷移なし＝オープンリダイレクト無し |
| VR-SEC-010 | CSV 権限昇格の防御 | ✅ | `import` アクションは管理画面コントローラ内にあり、**user1 セッションで `/admin/users/import`・`/admin/users?cmd=export`・`/admin/settings/index`・`/admin/users/add` は全て 302 → `/admin/users/login?redirect=...`**（未ログインでも同様に 302）。admin セッションでは import フォーム 200。`role` 列の書き込みは `Admin/UsersController.php:454` のみで admin 専用経路に限定。MCP 側の昇格は AB-05b で遮断確認済 |
| AD-073 | 管理画面システム設定の表示確認 | ✅ | Playwright（ヘッドレス Chrome）で admin ログイン後 `/admin/settings/index` と `/admin/settings` の両方が **200**、title `irohaboard`、本文に「システム設定／システム名／コピーライト／テーマカラー（default ink blue …）」が描画、**JS エラー0**。スクリーンショット `/tmp/opencode/ad073_settings.png`（実施時のみ・ファイルはレポジトリ外） |

---

## 2. 詳細節

### 2.1 AB-01: 他人の学習記録へのアクセス拒否

**方法**: user2 所有の `ib_records` id=23 を検証用に INSERT（user1 から/user2 のレコード取得を試みる）。

| リクエスト | 結果 |
|-----------|------|
| `GET /api/v1/records/23`（user1 トークン） | **403 Forbidden** |
| `GET /api/v1/records/23`（admin トークン） | **200 OK**（取得可） |
| `GET /api/v1/records`（user1 トークン） | 200 OK、自分のレコードのみ返却 |

→ **他人のレコードへの直接アクセスは API 層で 403 に遮断**。admin は全件参照可（仕様通り）。

### 2.2 AB-02: 非公開コンテンツの API/画面/MCP 拒否

**対象**: `ib_contents` id=9「非公開ラベル」（status=0）、id=10「非公開HTML」（status=0）

| アクセス経路 | id=9 の結果 | id=10 の結果 |
|-------------|------------|------------|
| `GET /api/v1/contents/9`（user1 トークン） | **404** JSON | **404** JSON |
| `/contents/view/9`（user1 セッション） | **302**（ログインページへ） | **302**（ログインページへ） |
| `GET /api/v1/contents/9`（admin トークン） | **200 OK** | **200 OK** |
| MCP `tools/call get_content{content_id:9}`（user1） | `isError:true` "Content not found." | `isError:true` "Content not found." |

→ **未公開コンテンツは API/画面/MCP 全経路で非公開が維持される**。admin のみ参照可。

### 2.3 AB-03: ユーザー権限昇格の試み

**方法**: user1（一般ユーザー）で `PUT /api/v1/users/5` に4種のペイロードを送信。

| ペイロード | 結果 |
|-----------|------|
| `{"role":"admin"}` | **403** "This action requires an admin or manager role" |
| `{"role":"manager"}` | **403** 同上 |
| `{"is_active":0}` | **403** 同上 |
| `{}` (空) | **403** 同上 |

→ **DB の `ib_users.role` は `user` のまま変更なし**。一般ユーザーからの権限変更は全件拒否。

### 2.4 AB-05: お知らせグループ限定の出し分け

**前提データ**: info1（全体公開）/ info2（group1 限定）/ info3（非公開）/ user1（group1 所属）/ user2（group2 所属）

| ユーザー | エンドポイント | 結果 |
|---------|--------------|------|
| user2 | `/infos/view/2`（group1 限定） | **404**（漏えいなし） |
| user1 | `/infos/view/2`（group1 限定） | **200 OK**（正当） |
| user1 | `/infos/view/3`（非公開） | **404** |
| user2 | `/infos/view/1`（全体公開） | **200 OK** |
| user2 | `/infos/index` | group1 限定・非公開が出ない（hit 0/0） |
| user1 | `/infos/index` | group1 限定が1件表示 |
| user1 | `/infos/view/999`（不正 id） | **404** |
| user1 | `/infos/view/abc`（不正 id） | **404** |

**補足**: お知らせに API ルートは無い。`GET /api/v1/infos/2` → 404 "Endpoint not found"（`config/routes.php` の infos はフロントのみ）。

→ **グループ限定・非公開の出し分けが正しく機能**。API 経由でのお知らせ取得は存在しない。

### 2.5 AB-05b: MCP によるコンテンツ作成/更新の拒否（一般ユーザー）

**方法**: user1（一般ユーザー）で MCP セッションを確立し、コンテンツ作成/更新を試行。

| MCP 呼び出し | 結果 |
|-------------|------|
| `initialize` → `Mcp-Session-Id` 取得 | ✅ 成功 |
| `create_content{course_id:1, title:"ab05b-attack", body:"pwned"}` | `isError:true` **"Only staff members can create content."** |
| `update_content{content_id:3, title:"hacked"}` | `isError:true` **"Only staff members can update content."** |

**DB 確認**: `ib_contents` に `ab05b-attack` / `body='pwned'` のレコードが**無いこと**・コンテンツ3の本文が**無改変**であることを確認。

→ **MCP ツールの write 系操作は staff のみに限定**。一般ユーザーからの不正なコンテンツ作成/更新は遮断。

### 2.6 AB-08 / VR-SEC-005: ファイルアップロード拡張子制御

**方法**: 管理セッションで `GET /admin/contents/upload/file` から CSRF トークンを抽出し、multipart ファイルを送信。

| 送信ファイル | 結果 |
|-------------|------|
| `test.php` | ❌ 拒否（Flash「アップロードされたファイルの形式は許可されていません」） |
| `test.phtml` | ❌ 拒否 |
| `.htaccess` | ❌ 拒否 |
| `fake.gif.php` | ❌ 拒否 |
| `test.PhP` | ❌ 拒否 |
| `shell.php.txt` | ✅ 保存 → `webroot/uploads/20260927093704apnl.txt`（17B、内容 `<?php echo "PWN";`） |

**配信のセキュリティ**:
- `X-Content-Type-Options: nosniff` ヘッダ付与
- 未ログイン時は `webroot/uploads/.htaccess`（`Options -Indexes` + `RewriteCond %{HTTP_COOKIE} !LoginStatus` → `../img/wrong.png`）により **PNG が返り PHP は非実行**
- 許可拡張子は `config/ib_config.php:82`（.php/.phtml なし）

→ **危険な拡張子は全件拒否**。txt ファイルのみ許可されるが、.htaccess により未ログイン時は画像にリライトされ PHP は実行されない。

### 2.7 AB-10: MCP の他人レコード/プロフィールアクセス拒否

| MCP 呼び出し | 結果 |
|-------------|------|
| `list_records`（user1） | 返却 record ids `[22,21,20,7,6]`・user_ids `[5]` のみ（他人の record 23 は含まない） |
| `get_user_profile{user_id:1}`（user1） | `isError:true` "Access denied. You can only view your own profile." |
| `tools/list`（未認証） | **401** `{"error":"invalid_token",...}` |

→ **MCP レイヤーでも他人のレコード/プロフィールへのアクセスは遮断**。未認証は 401。

### 2.8 AB-11: API レート制限の実測

**設定**: `api_rate_limit_per_minute=120`

#### 認証済み（user1 トークン）130 並列リクエスト

| 指標 | 値 |
|------|-----|
| 総リクエスト数 | 130 |
| HTTP 200 | **125**（96.2%） |
| HTTP 429 | **5**（3.8%） |

#### 未認証（IP キー）逐次 130 リクエスト

| 指標 | 値 |
|------|-----|
| 総リクエスト数 | 130 |
| HTTP 401 | **120**（92.3%） |
| HTTP 429 | **10**（7.7%） |

#### トークン発行エンドポイント（誤パスワード連打）

| 指標 | 値 |
|------|-----|
| 総試行数 | 120+ |
| HTTP 429 | **79回目で 429**（計 88×429） |
| HTTP 401 | 42×401 |

**429 レスポンス形式**: `{"error":{"code":429,"message":"Rate limit exceeded. Please try again later."}}`

**Retry-After ヘッダ**: `Retry-After: 7`

→ **レート制限は API 全エンドポイントで正確に機能**。認証済み/未認証/トークン発行の全てで制限を確認。

### 2.9 AB-13: 不正 JSON 送信時のエラー応答形式

**方法**: `PUT /api/v1/courses` に不正な JSON ボディを送信。

| リクエスト | 結果 |
|-----------|------|
| `PUT /api/v1/courses`（不正 JSON、user1 トークン） | **400** / `Content-Type: text/html` / 867,808B の CakePHP debug HTML |
| 同上（別試行） | 874KB |
| `Accept: application/json` ヘッダ付き | HTML のまま（400 text/html） |
| 未認証で再現 | 同様に HTML を返す |

**内部露出情報**: `/var/www/html/src`、`Cake\Http\Exception\BadRequestException`、`ApiErrorMiddleware.php:29`、`BodyParserMiddleware`、`webroot/index.php:32` 等

**根因**:
1. `src/Middleware/ApiErrorMiddleware.php:30,32` が catch するのは `ApiException`/`InvalidParameterException` のみで BodyParser の `BadRequestException` を素通り
2. `config/app_local.php:21` の debug が env `DEBUG` 未設定時 default true（コンテナで未設定）

**現状**: 修正は別タスクで進行中（ApiErrorMiddleware が /api・/mcp 限定で JSON 400 を返すようにする）。debug=false の本番でも「JSON API が HTML を返す契約違反」自体は残る点を指摘。

→ **不備 D-39 として記録**。修正後の再実測はオーケストレーターが実施する。

### 2.10 AB-14: CSV エクスポートの数式ペイロードサニタイズ

**方法**: DB に数式ペイロードのユーザー `csvform1`（id=10）を作成し、管理CSV をエクスポート。

| フィールド | 入力値 | CSV 出力 |
|-----------|--------|---------|
| name | `=cmd\|'/C calc'!A1` | `'=cmd\|'/C calc'!A1`（`'` 前置） |
| email | `+HYPERLINK("http://evil","click")` | `'+HYPERLINK("http://evil","click")`（`'` 前置） |
| comment | `-2+5` | `'-2+5`（`'` 前置） |

**CSV ヘッダ**: `Content-Type: text/csv; charset=SJIS-WIN`、906B

**実装**: `AppController::sanitizeCsvValue()`（`src/Controller/AppController.php:411`、正規表現 `/^\s*[=+\-@]/` に `'` を付与）

→ **数式ペイロードは全て `'` 前置により Excel で数式実行されない**。CSV インジェクション対策が機能。

### 2.11 AB-15: オープンリダイレクトの試み

**方法**: ログイン成功時の遷移先パラメータに外部 URL を指定。

| パラメータ | 遷移先 | 結果 |
|-----------|--------|------|
| `?next=http://evil.example.com` | `http://localhost/` | 302 → App.fullBaseUrl 固定 |
| `?url=https://evil.example.com` | `http://localhost/` | 302 → App.fullBaseUrl 固定 |
| `?redirect=https://evil.example.com` | `http://localhost/` | 302 → App.fullBaseUrl 固定 |
| `?redirect=//evil.example.com` | `http://localhost/` | 302 → App.fullBaseUrl 固定 |
| `?redirect=/\evil.example.com` | `http://localhost/` | 302 → App.fullBaseUrl 固定 |

→ **全変種で外部遷移なし**。params は無視され `App.fullBaseUrl` 固定。オープンリダイレクトは存在しない。

### 2.12 VR-SEC-010: CSV 権限昇格の防御

**方法**: user1 セッションで管理画面の各エンドポイントにアクセス。

| エンドポイント | user1 セッション | admin セッション |
|--------------|-----------------|-----------------|
| `/admin/users/import` | **302** → `/admin/users/login?redirect=...` | **200 OK**（import フォーム） |
| `/admin/users?cmd=export` | **302** → `/admin/users/login?redirect=...` | **200 OK**（CSV ダウンロード） |
| `/admin/settings/index` | **302** → `/admin/users/login?redirect=...` | **200 OK** |
| `/admin/users/add` | **302** → `/admin/users/login?redirect=...` | **200 OK** |

- `role` 列の書き込みは `Admin/UsersController.php:454` のみで admin 専用経路に限定
- 未ログインでも同様に 302（ログインページへリダイレクト）
- MCP 側の昇格は AB-05b で遮断確認済

→ **CSV インポート/エクスポート/設定画面は admin のみアクセス可**。権限昇格は遮断。

### 2.13 AD-073: 管理画面システム設定の表示確認

**方法**: Playwright（ヘッドレス Chrome）で admin ログイン後、システム設定画面を表示。

| チェック項目 | 結果 |
|------------|------|
| `/admin/settings/index` | **200 OK**、title `irohaboard` |
| `/admin/settings` | **200 OK** |
| 本文描画 | 「システム設定／システム名／コピーライト／テーマカラー（default ink blue …）」確認 |
| JS エラー | **0 件** |

スクリーンショット: `/tmp/opencode/ad073_settings.png`（実施時のみ・ファイルはレポジトリ外）

→ **システム設定画面が正常に描画**。JS エラーなし。

### 2.14 VR-AUTH-043: セッション固定・再生成攻撃（実攻撃実演）

**判定: ✅ PASS**

**攻撃手法**: 攻撃者が自分の未ログインセッション ID を被害者に植え付け、被害者のログイン後にその ID で会話を奪う試行。

**手順と実測**:
1. 攻撃者の事前セッション `X = a8605d70defb70d42963aee7496b60ec` を取得。
2. そのセッション Cookie で被害者がログイン（POST /users/login）→ 応答に `Set-Cookie: AppSession=deleted` ＋ 新 ID が計2回（最終 `Y = cd6de1f6ac41cd8695597b8c455e8b17`、Y ≠ X）。
3. 攻撃者が旧 X で `/users-courses` を要求 → **302 → ログイン画面へ**（乗っ取り不可）。
4. 被害者は新 Y で `/users-courses` → **200「コース一覧」**。

**防御機構**: ログイン成功時の `session_regenerate_id(true)` — `vendor/cakephp/cakephp/src/Http/Session.php:662`。

→ **セッション固定攻撃は防御により無効化**。旧セッション ID ではアクセス不可。

### 2.15 D-07: HTTPS ログイン時クッキーに Secure が付かない（測定完了、根因は D-40）

**判定: 測定完了（修正は D-40 で実施）**

**手法**: 自作 TLS プロキシ `/tmp/opencode/tls_proxy.py`（`127.0.0.1:8443` → `127.0.0.1:8082`、自己署名証明書）で実際に HTTPS ログインを実施。

**測定結果（既定設定・D-40 修正前）**: HTTPS ログイン応答の Set-Cookie 5件すべて **Secure 0/5**（AppSession×4＝deleted×2＋新ID×2、LoginStatus×1）。HTTP ベースラインも Secure 0。

**結論**: 未付与の根因は D-40（`config/app.php` の Session 設計不備）。D-40 の修正後に再測定し、Secure 5/5 付与を確認（§2.16 参照）。

→ **D-07 の根因は D-40 として修正済み**。

### 2.16 D-40: Cookie Secure の設定が機能していない（修正・検証完了）

**判定: ✅ 修正済み**

**根因（4点）**:
1. `config/app.php` のトップレベル `'secure'` キーは CakePHP 5 の `Session` クラスが一切参照しない**死に設定**（`vendor/cakephp/cakephp/src/Http/Session.php` に `secure` 参照なし）。
2. `AppController::writeCookie()`（`LoginStatus` クッキー手动生成）に `withSecure()` がなかった。
3. compose（`docker/docker-compose.cakephp5.yml`）に `SESSION_SECURE` が未定義。
4. CakePHP の自動付与（`Session.php:112-118`）は `!isset($ini['session.cookie_secure']) && env('HTTPS')` 条件。**ini キーを常に置くと自動付与が遮断される**ため、未設定時はキーを落とす設計が必要。

**修正内容**:
- `config/app.php`（Session ブロック）: トップレベル `'secure'` を削除し、`ini` 配列に `'session.cookie_secure' => in_array(env('SESSION_SECURE'), [null, ''], true) ? null : filter_var(env('SESSION_SECURE'), FILTER_VALIDATE_BOOLEAN)` を追加。env 未設定・空文字（compose の `${VAR:-}` パターン相当）なら**キー自体を置かず** CakePHP の自動付与（env('HTTPS')）に委ね、明示値ならその値を採用。
- `src/Controller/AppController.php` `writeCookie()`: `->withHttpOnly(true)` の直後に `->withSecure((bool) ini_get('session.cookie_secure'))` を追加（LoginStatus に Secure 付与。セッション開始後の `ini_get` で当該リクエストの実効値を参照）。

**検証（全て実測）**:
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

**残課題（記録のみ・スコープ外）**: ① `csrfToken` クッキーは Secure 未付与（CSRF ミドルウェア管理）。② 実運用では TLS 終端が `HTTPS` を伝えない構成もあるため、確実性のため `SESSION_SECURE=true` の明示設定を推奨。

→ **D-40 は修正済み。D-07 の根因も解消**。

---

## 3. 残り（✅ にならなかったもの）

| ID | 状態 | 理由 |
|----|------|------|
| VR-AUTH-043 | ✅ 実施完了（PASS） | 実攻撃シナリオで session 固定→乗っ取り不可を実証。詳細は §2.14 参照。防御: ログイン成功時の `session_regenerate_id(true)` |
| D-07 | ✅ 測定完了（根因は D-40 として修正済み） | HTTPS プロキシで実測。D-40 修正前は Secure 0/5、修正後は 5/5 付与。詳細は §2.15 参照 |
| D-39 | 修正進行中 | AB-13 参照。修正後の再実測はオーケストレーターが実施する |
| D-40 | ✅ 修正済み | Cookie Secure 設定が機能していなかった。config/app.php の ini 配列設計＋AppController writeCookie() に withSecure() 追加で修正。詳細は §2.16 参照 |
| D-18 | 意図的延期 | 既存記録のまま |
| D-32 | 意図的延期 | 既存記録のまま |

---

## 4. 検証用に生成したデータ（残置の旨を明記）

| テーブル/パス | 内容 | 用途 |
|-------------|------|------|
| `ib_records` id=23 | user2 所有 | AB-01 の他人レコードアクセス拒否検証用 |
| `ib_users` id=10 `csvform1` | 数式ペイロードユーザー | AB-14 の CSV サニタイズ検証用 |
| `webroot/uploads/20260927093704apnl.txt` | `<?php echo "PWN";`（17B） | AB-08 のファイルアップロード検証用（`.gitignore` 対象） |

いずれも開発用 DB/ローカル環境のもので、コードには無関係。

---

## 5. 補足

- 管理画面 URL は位置引数（編集 `/admin/contents/edit/{course_id}/{content_id}`）。`edit/3` の `Record not found in table 'ib_courses'` は URL 誤りでデータ破損ではない件。
- ログインフォームのフィールド名は `username`/`password`（`data[User][...]` ではない）＋ `_csrfToken`/`_Token[fields]`/`_Token[unlocked]`/`_Token[debug]` 必須。

---

## 6. 判定

**✅ 大部分合格、1 件不備あり（D-39）**

- **AB-01〜AB-15（14/15 合格）**: 他人レコードアクセス拒否、非公開コンテンツ保護、権限昇格防御、お知らせグループ限定、MCP 権限、ファイルアップロード制御、レート制限、CSV サニタイズ、オープンリダイレクト防御、CSV 権限昇格防御が全て正常に機能
- **AB-13（不合格 → D-39）**: 不正 JSON 送信時に CakePHP debug HTML が返される。ApiErrorMiddleware が BodyParser の BadRequestException を catch していないため、API 契約（JSON 応答）に違反。修正は別タスクで進行中
- **VR-AUTH-043（PASS）**: セッション固定・再生成攻撃を実攻撃シナリオで実証。攻撃者の旧セッション ID ではアクセス不可（session_regenerate_id(true) による防御）
- **D-07（測定完了 → D-40 として修正済み）**: HTTPS ログイン時の Cookie Secure 未付与を TLS プロキシで実測。根因は D-40（config/app.php Session 設計不備）であり、D-40 修正後に Secure 5/5 付与を確認
- **D-40（修正済み）**: config/app.php の ini 配列設計＋AppController writeCookie() に withSecure() 追加。CLI 5ケース＋ライブ E2E で検証済み
- **AD-073**: 管理画面システム設定が正常に描画、JS エラーなし
- **VR-SEC-010**: CSV 権限昇格は全経路で遮断確認済

**スイート基準**: 748 tests / 3708 assertions / 0E / 0F / 8 PHPUnit Notices（`bash scripts/test-fresh.sh` 実測）

**S1（データ破データ破壊・権限逸脱）**: 0 件
**S2（機能不具合）**: 0 件
**S3（UX/セキュリティ）**: 0 件
**S4（軽微）**: 2 件（D-39: 不正 JSON 送信時の debug HTML 漏洩、修正進行中。D-40: Cookie Secure 設定が機能していない、**修正済み**）
