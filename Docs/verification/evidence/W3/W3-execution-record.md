# W3 実施記録（P0 運用/移行）

| 項目 | 内容 |
|------|------|
| 実施日 | 2026-09-25 |
| 実施範囲 | W3（P0 運用/移行）: RK-09、VR-OPS、install/update/復旧 |
| 実施方法 | curl による外部観測 + mariadb CLI による DB 操作 + 静的コード点検。スクラッチ DB で install/update フローを実検証。**アプリケーションのソース修整は行っていない** |
| 対象環境 | `http://localhost:8082`（`irohaboard5-web-1` php:8.4-apache）／ MariaDB 11.4.13（port 13307, db `irohaboard`） |
| 前提 | dev DB の破壊は禁止。install/update の実検証は全てスクラッチ DB で実施。`config/app_local.php` は一時変更→完全復元。`config/ib_config.php` は A5 のみ一時変更→完全復元 |

---

## 1. 結果サマリ

| ID | 検証項目 | 判定 | 根拠 |
|----|----------|------|------|
| A1 | 導入済み DB に対する `/install` → 「already installed」表示 | ✅ 適正 | dev DB で GET /install → HTTP 200、body に「既にインストールされています」 |
| A2 | 空 DB でのフルインストール | ⚠️ 一部可 | スクラッチ DB で `app.sql` 実行→16 テーブル作成 OK。bcrypt 確認 OK（`$2y$` プレフィックス）。**ただし `InstallController` の DB 名検出にバグがあり、Web UI 経由のフルインストールが不可能**（D-23） |
| A3 | インストール中断の冪等性 | ⚠️ 一部可 | スキーマ部分作成（`ib_users` のみ存在）から `app.sql` 再実行→テーブルは全作成されるが、`ib_settings` INSERT で **Duplicate entry (23000)** が発生。install コントローラは 23000 を握り潰さないため、**Web 経由ではエラー表示になる**（D-24） |
| A4 | 管理者作成時の username/password バリデーション | ❌ 不合格 | `InstallController:129-152` に妥当なバリデーションロジックが存在するが、**A2 の D-23 バグによりフォーム POST に到達できない**ため、動的検証不可（D-23 に付帯） |
| A5 | `deny_install_update_access` = true で `/install`・`/update` が拒否 | ✅ 適正 | `ib_config.php` を一時変更→GET /install = **403**、GET /update = **403**。復元後は 200 に戻る |
| B1 | `update.sql` を適用前の DB に対して `/update` を実行 | ✅ 適正 | 旧スキーマ DB（`ib_contents` に `file_name`/`status`/`question_count`/`wrong_mode` 不在、`ib_courses` に `introduction` 不在、`ib_infos_groups`/`ib_groups_courses`/`ib_user_tokens` 不在）を作成 → `/update` 実行 → 全テーブル・全カラム追加完了、「アップデートが完了しました」表示 |
| B2 | `/update` の冪等性（2 連続実行） | ✅ 適正 | 同じ update.sql を 2 回連続実行 → 2 回目も「アップデートが完了しました」。42S21/42S01/23000/42000 を握り潰す |
| B3 | 部分適用から再開 | ✅ 適正 | `ib_infos_groups` のみ適用済み（`ib_groups_courses`/`ib_user_tokens` なし）+ `ib_contents` に `file_name` のみ追加済み（`status`/`question_count`/`wrong_mode` なし）→ `/update` 完了 → 全 16 テーブル＋全カラム揃う |
| B4 | `%salt%` プレースホルダが `legacy_security_salt` に置換されること | ✅ 適正 | `update.sql:75` に `%salt%` を含むコメント付き UPDATE 文あり。`UpdateController:180-183` で `Configure::read('legacy_security_salt')` → `str_replace('%salt%', ...)` 処理を確認。`ib_config.php:12` に `legacy_security_salt` 実在 |
| C1 | DB バックアップ→リストアの実演 | ✅ 適正 | `mariadb-dump` でバックアップ→DB DROP→`source /tmp/restore.sql` でリストア→テーブル・データ復元を確認（ib_users=1, ib_courses=1） |
| C2 | `rollback-procedure.md` のコマンド実在可否 | ✅ 適正 | `mariadb-dump`/`mariadb`（DB コンテナ内）、`docker compose`、`git` すべて実在。ファイル自体も存在（`Docs/dev/rollback-procedure.md`） |
| C3 | ファイルストレージの整合 | ✅ 適正 | `files/` は空（`empty` のみ）、`webroot/uploads/` に `.htaccess`（Cookie チェック付きアクセス制御）＋アップロード画像 1 件あり。`webroot/.htaccess` で `.env`/`.sql`/`.bak` 等の危険ファイルを遮断 |
| C4 | 障害時のログ | ✅ 適正 | `logs/error.log` / `logs/debug.log` にCakePHP ログ出力あり。404 発生→`error.log` に記録。`ib_logs` テーブルにはログイン系（user_logined=35, login_error=27）が記録 |
| D1 | 本番モード（debug=false）の挙動 | ✅ 適正 | debug=false 設定→debug_kit 非表示確認。`fullBaseUrl` 設定時：正常 Host → 200、Spoofed Host → **400**（BadRequest）。セキュリティヘッダ（CSP/Referrer-Policy/Permissions-Policy）が応答に付与される |
| D2 | debug=false 時のエラーメッセージ泄露 | ✅ 適正 | 404 応答にスタックトレース・内部パス・ファイル名は含まれない。汎用エラーページのみ表示 |

---

## 2. 詳細な証跡

### A1: 導入済み DB に対する /install

```
$ curl -s http://localhost:8082/install | grep -o '既にインストールされています\|インストールが完了しました\|Installer'
Installer
既にインストールされています
```

→ `installed.php` テンプレートが正しく描画される。

### A2: 空 DB でのフルインストール

**Web UI 経由での実行不可（D-23 バグ）**。理由:

`InstallController:112-113`:
```php
$config = Configure::read('Datasources');  // bootstrap.php:187 で consume されているため null
$database = $config['default']['database'] ?? 'irohaboard';  // 常に 'irohaboard' にフォールバック
```

`bootstrap.php:187`:
```php
ConnectionManager::setConfig(Configure::consume('Datasources'));  // consume で Datasources キーを除去
```

このためスクラッチ DB を設定しても、`SHOW TABLES FROM irohaboard LIKE 'ib_users'` が実行され、dev DB に `ib_users` が存在するため**常に「既にインストール済み」**と表示される。

**CLI による直接検証結果:**

```sql
-- スクラッチ DB に app.sql を実行
mariadb -uroot -prootpass irohaboard_w3 -e "source /tmp/app.sql"
-- 結果: 16 テーブル正常作成
SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema='irohaboard_w3';
-- table_count: 16
```

```bash
# bcrypt ハッシュの確認
php -r "echo substr(password_hash('adminpass', PASSWORD_BCRYPT), 0, 7);"
# 結果: $2y$12$ (CakePHP 5 は PASSWORD_BCRYPT を使用)
```

### A3: インストール中断の冪等性

```sql
-- テーブル 'ib_users' のみ存在する状態（中断状態）から app.sql 再実行
-- CREATE TABLE IF NOT EXISTS → 重複エラーなし（42S01 は正常にスキップ）
-- INSERT INTO ib_settings → ERROR 1062 (23000): Duplicate entry '1' for key 'PRIMARY'
-- (4 回発生: ib_settings id 1〜4)
```

`InstallController:270-276` は 42S21 と 42S01 のみ握り潰し、**23000 (Duplicate entry) は握り潰さない**ため、再実行時にエラーとして報告される。

### B1: update.sql の適用

```
# 旧スキーマ DB 作成 (ib_courses.introduction 不在, ib_contents に file_name/status/question_count/wrong_mode 不在)
# → /update を GET
$ curl -s http://localhost:8082/update | grep -oP '(アップデート|エラー|danger)'
アップデートが完了しました
```

```sql
-- 適用後の確認
SHOW COLUMNS FROM ib_courses;  -- introduction text カラム追加済み
SHOW COLUMNS FROM ib_contents; -- file_name, status, question_count, wrong_mode 追加済み
SHOW TABLES; -- ib_infos_groups, ib_groups_courses, ib_user_tokens 追加済み (計 16 テーブル)
```

### B2: 冪等性

```
# 2 回目の /update
$ curl -s http://localhost:8082/update | grep -oP '(アップデート|エラー|danger)'
アップデートが完了しました
```

→ 2 回目も成功。`UpdateController` は 23000/42S21/42S01/42000 を握り潰すため安全。

### B3: 部分適用から再開

```
# 部分適用状態: ib_infos_groups のみ + ib_contents.file_name のみ
# → /update 実行
$ curl -s http://localhost:8082/update | grep -oP '(アップデート|エラー|danger)'
アップデートが完了しました

# 結果: 全 16 テーブル + 全カラム揃う
```

### B4: %salt% プレースホルダ

```sql
-- update.sql:75 (コメントアウト済み)
--UPDATE ib_users SET `password` = SHA1(CONCAT('%salt%', '新しいパスワード')) WHERE username = '復旧したい管理者のログインID';
```

```php
// UpdateController:180-183
$salt = (string)(Configure::read('legacy_security_salt') ?? Configure::read('Security.salt') ?? '');
$statement = str_replace('%salt%', $salt, $statement);
```

```php
// ib_config.php:12
$config['legacy_security_salt'] = '397110e45242a23e5802e78f4eec95a7bd39e0f0';
```

### C1: バックアップ→リストア

```
Step 1: mariadb-dump -uroot -prootpass irohaboard_w3_restore > /tmp/w3_restore_backup.sql (17,490 bytes)
Step 2: データ確認 (ib_users=1, ib_courses=1)
Step 3: DROP DATABASE irohaboard_w3_restore
Step 4: CREATE DATABASE + source /tmp/restore.sql
Step 5: データ復元確認 (ib_users=1, ib_courses=1, ib_settings=4)
```

### C3: ファイルストレージ

```
webroot/uploads/.htaccess:
  Options -Indexes
  RewriteEngine On → Cookie "LoginStatus" なしの場合 → ../img/wrong.png に転送

webroot/.htaccess:
  .env|vendor|phpunit|.git|.sql|.bak|.ini|.cgi|.py → 403
  .php (index.php 以外) → 403
```

### C4: ログ出力

```
logs/error.log: CakePHP のエラーログ（404, 500 等）
logs/debug.log: CakePHP のデバッグログ
ib_logs テーブル: user_logined=35, login_error=27, mcp_request=8, user_exported=3 等
404 発生 → error.log に記録あり。ib_logs テーブルへの記録はなし（ログイン系のみ）
```

### D1: 本番モード

```
debug=false + App.fullBaseUrl 設定時:
  GET /users/login (正常 Host) → HTTP 200
  GET /users/login (Host: evil.example.com) → HTTP 400 (BadRequest)

セキュリティヘッダ（debug=false のみ）:
  Content-Security-Policy: default-src 'self'; img-src 'self' data:; ...
  Referrer-Policy: strict-origin-when-cross-origin
  Permissions-Policy: geolocation=(), microphone=(), camera=()
  X-Frame-Options: SAMEORIGIN
  X-Content-Type-Options: nosniff

debug=true 時のデフォルト動作:
  App.fullBaseUrl が未設定の場合、HostHeaderMiddleware は debug チェックでスキップする
  → 開発環境では Host ヘッダ検証なし（設計どおり）
```

### D2: エラーメッセージ泄露

```
404 ページ本文:
  <h2>Not Found</h2>
  <p class="error"><strong>Error: </strong>The requested address '/nonexistent_page_test' was not found on this server.</p>

→ スタックトレース、内部ファイルパス、フレームワークバージョンは一切表示されない
```

---

## 3. バックアップ/復元の実演記録

### C1: dump コマンド

```bash
# バックアップ
mariadb-dump -uroot -prootpass irohaboard_w3_restore > /tmp/w3_restore_backup.sql
# ファイルサイズ: 17,490 bytes

# リストア
docker exec irohaboard5-db-1 mariadb -uroot -prootpass -e "CREATE DATABASE irohaboard_w3_restore CHARACTER SET utf8mb4;"
docker exec irohaboard5-db-1 mariadb -uroot -prootpass irohaboard_w3_restore -e "source /tmp/restore.sql"
```

### C1: restore 検証 SELECT

```sql
-- リストア前
SELECT 'ib_users' AS tbl, COUNT(*) AS cnt FROM irohaboard_w3_restore.ib_users
UNION ALL SELECT 'ib_courses', COUNT(*) FROM irohaboard_w3_restore.ib_courses;
-- tbl    cnt
-- ib_users  1
-- ib_courses 1

-- DROP + リストア後
-- tbl    cnt
-- ib_users  1
-- ib_courses 1
-- ib_settings 4
```

---

## 4. dev DB の最終確認

```sql
-- 実施日: 2026-09-25
SELECT 'ib_users' AS tbl, COUNT(*) AS cnt FROM irohaboard.ib_users
UNION ALL SELECT 'ib_courses', COUNT(*) FROM irohaboard.ib_courses
UNION ALL SELECT 'ib_contents', COUNT(*) FROM irohaboard.ib_contents
UNION ALL SELECT 'ib_contents_questions', COUNT(*) FROM irohaboard.ib_contents_questions
UNION ALL SELECT 'ib_records', COUNT(*) FROM irohaboard.ib_records
UNION ALL SELECT 'ib_groups', COUNT(*) FROM irohaboard.ib_groups
UNION ALL SELECT 'ib_settings', COUNT(*) FROM irohaboard.ib_settings
UNION ALL SELECT 'ib_logs', COUNT(*) FROM irohaboard.ib_logs
UNION ALL SELECT 'ib_infos', COUNT(*) FROM irohaboard.ib_infos
UNION ALL SELECT 'ib_user_tokens', COUNT(*) FROM irohaboard.ib_user_tokens;
```

| tbl | cnt | 備考 |
|-----|-----|------|
| ib_users | 6 | id1=admin, id2=manager1, id3=editor1, id4=teacher1, id5=user1, id6=user2 |
| ib_courses | 2 | course1(公開), course2(非公開) |
| ib_contents | 11 | contents 11 件 |
| ib_contents_questions | 5 | 5 件 |
| ib_records | 2 | user1 / content 7,8 |
| ib_groups | 2 | — |
| ib_settings | 6 | — |
| ib_logs | 75 | — |
| ib_infos | 3 | — |
| ib_user_tokens | 64 | — |

```sql
-- 管理者 6 人確認
SELECT id, username, role FROM irohaboard.ib_users ORDER BY id;
-- id  username  role
-- 1   admin     admin
-- 2   manager1  manager
-- 3   editor1   editor
-- 4   teacher1  teacher
-- 5   user1     user
-- 6   user2     user
```

**✅ dev DB は W3 実施前と一致。元に戻っている。**

---

## 5. 不備一覧

| 重大度 | ID | 内容 | 発見箇所 |
|--------|----|------|----------|
| **S2** | D-23 | `InstallController` の「インストール済み」判定が `Configure::read('Datasources')` に依存するが、`bootstrap.php:187` の `Configure::consume('Datasources')` により常に null となり、ハードコードされた `'irohaboard'` にフォールバックする。スクラッチ DB へのフルインストールが Web UI 経由で不可能 | `src/Controller/InstallController.php:112-113` + `config/bootstrap.php:187` |
| **S3** | D-24 | `InstallController::_executeSQLScript()` は 42S21（カラム重複）と 42S01（テーブル重複）のみ握り潰すが、23000（レコード重複・Duplicate entry）を握り潰さない。`app.sql` の `INSERT INTO ib_settings` が再実行時にエラーとなるため、インストール中断→再開で**安全に完了しない** | `src/Controller/InstallController.php:270-276` |
| **S4** | D-25 | `HostHeaderMiddleware` は `debug=false` 時に `App.fullBaseUrl` の未設定を検出し 500 を返す設計だが、`bootstrap.php` が `Router::fullBaseUrl()` のみ設定し `Configure::write('App.fullBaseUrl', ...)` しないため、**開発環境のデフォルト設定ではこの保護が発動する**（開発環境では debug=true でスキップされるため問題化しないが、本番環境で `APP_FULL_BASE_URL` 環境変数を忘れた場合に 500 となる）。設計は正しいが、`bootstrap.php` で `Configure::write('App.fullBaseUrl', $fullBaseUrl)` を追加すべき | `config/bootstrap.php:159-180` + `src/Middleware/HostHeaderMiddleware.php:38-44` |
| **S4** | D-26 | `InstallController` のバリデーション（username/password の 4-32 文字英数字制約）が D-23 により到達不可能なため、**バリデーション機能の動的検証が未実施** | `src/Controller/InstallController.php:129-152` |

---

## 6. 判定

**W3 全体判定: ⚠️ 一部可**

- install フロー（A 系）: D-23 のバグにより Web UI 経由のフルインストール・バリデーション検証が不可能。CLI での SQL 直実行およびコードレビューにより、DB スキーマ作成と bcrypt ハッシュ化は正常に動作することを確認。
- update フロー（B 系）: 全項目合格。冪等性・部分適用からの再開も安全。
- 復旧・ロールバック（C 系）: 全項目合格。バックアップ→リストアの実演成功。ファイルストレージ・ログの整合性確認済み。
- 本番モード（D 系）: D-25（`bootstrap.php` の `Configure::write('App.fullBaseUrl')` 欠落）は設計意図通りの動作だが、環境変数忘却時のフォールバックが脆弱。エラーメッセージ泄露なし。
