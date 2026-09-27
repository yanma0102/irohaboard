# W3 検出項目 原因解析（Root Cause Analysis）

> 対象: `Docs/verification/evidence/W3/W3-execution-record.md` の D-23〜D-26
> 実施日: 2026-09-26 / 方法: 静的コード点検（file:line）＋ W3実測記録の参照
> 前提: コード修正は未実施。原因の特定と影響評価のみ。

---

## 総括

| ID | 事象 | 根本原因（要約） | 分類 | 重要度 |
|---|---|---|---|---|
| D-23 | `InstallController` の DB 名検出が常に既定値 `'irohaboard'` にフォールバックし、スクラッチ DB へのインストールが不可能 | `bootstrap.php:187` が `Configure::consume('Datasources')` でキーを除去した後、`InstallController:112` が `Configure::read('Datasources')` で読もうとするため常に `null` | 設計バグ（データフロー競合） | S2 |
| D-24 | `_executeSQLScript()` が 23000（Duplicate entry）を無視しない | `InstallController:271-276` は `42S21`/`42S01` のみ無視。`23000` のハンドリングが欠落（`UpdateController` には存在） | 実装バグ（漏れ） | S3 |
| D-25 | `bootstrap.php` が `App.fullBaseUrl` を `Configure` に書き込まない | bootstrap は `Router::fullBaseUrl()` に設定するが `Configure::write('App.fullBaseUrl', ...)` を省略。`HostHeaderMiddleware:38` は `Configure::read` で読むため未設定になる | 設計バグ（API 呼び出しの不整合） | S4 |
| D-26 | D-23 により install バリデーションの動的検証が不可 | D-23 によりフォーム POST に到達不能（常に「インストール済み」表示）のため、バリデーションコードが到達不可能 | 二次的欠陥（D-23 に依存） | S4 |

---

## D-23: `InstallController` の DB 名検出が `Configure::consume` により常に既定値にフォールバック

**根本原因: `bootstrap.php:187` の `Configure::consume('Datasources')` がリクエスト処理開始前に `Datasources` キーを Configure ストアから除去するため、`InstallController:112` の `Configure::read('Datasources')` が常に `null` を返し、ハードコードされた `'irohaboard'` にフォールバックする。**

- **発生メカニズム**:
  1. CakePHP の起動時、`Application::bootstrap()` が `config/bootstrap.php` を読み込む。
  2. `bootstrap.php:187`: `ConnectionManager::setConfig(Configure::consume('Datasources'))` — `consume` は Configure から値を**読み取り同時に除去**する（CakePHP 5 の API）。これにより `Datasources` キーはストアから消失する。
  3. リクエストが `InstallController::index()` にルーティングされる。
  4. `InstallController:112`: `$config = Configure::read('Datasources')` — 既に除去済みのため `null` を返す。
  5. `InstallController:113`: `$database = $config['default']['database'] ?? 'irohaboard'` — `$config` が `null` のため最終的に `?? 'irohaboard'` の既定値 `'irohaboard'` が採用される。
  6. `InstallController:114`: `SHOW TABLES FROM irohaboard LIKE 'ib_users'` が実行される。dev DB `irohaboard` に `ib_users` が存在するため `count($data) > 0` が真になり `installed` テンプレートが表示される。

- **根拠**:
  - `config/bootstrap.php:187` — `ConnectionManager::setConfig(Configure::consume('Datasources'))`
  - `src/Controller/InstallController.php:112` — `$config = Configure::read('Datasources')`
  - `src/Controller/InstallController.php:113` — `?? 'irohaboard'` のフォールバック
  - `config/app_local.php:53` 付近 — `'database' => env('DB_NAME', 'irohaboard')`（DB 名の設定ソース）

- **影響**: スクラッチ DB を作成しても、Web UI 経由のフルインストールが**不可能**。CLI による SQL 直接実行のみが代替手段。

- **分類**: 設計バグ。`consume`（読み取り+除去）と `read`（読み取りのみ）の API 選択ミス。`bootstrap.php` が `Datasources` を `ConnectionManager` に渡す必要があるのは正しいが、除去前にコントローラが必要な情報を取得できないアーキテクチャ上の問題。

---

## D-24: `_executeSQLScript()` が 23000（Duplicate entry）を無視しない

**根本原因: `InstallController:_executeSQLScript()` の例外ハンドリングが `42S21`（カラム重複）と `42S01`（テーブル重複）のみを `continue` でスキップし、`23000`（UNIQUE 制約違反・Duplicate entry）への対応が欠落している。**

- **発生メカニズム**:
  1. `config/schema/app.sql` は `INSERT INTO ib_settings` を 4 行含む（`id` 1〜4 を主キーに持つ）。
  2. インストール中断後に再実行すると、`CREATE TABLE IF NOT EXISTS` は `42S01` でスキップされる（安全）。
  3. しかし `INSERT INTO ib_settings VALUES ('1', ...)` は既存レコードと主キー `1` が衝突し、`SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1' for key 'PRIMARY'` が発生。
  4. `InstallController:271-276` で `42S21` と `42S01` は `continue` で握り潰されるが、`23000` は該当しないため `err_statements[]` に追加される。
  5. `InstallController` のエラー集計で `count($err_statements) > 0` となり、エラーテンプレートが表示される。

- **根拠**:
  - `src/Controller/InstallController.php:271` — `42S21` のみ `continue`
  - `src/Controller/InstallController.php:274` — `42S01` のみ `continue`
  - `src/Controller/UpdateController.php:191` — `23000` を `continue` する対比コード（**UpdateController には実装されている**）
  - `src/Controller/UpdateController.php:195,199` — 同じく `42S21`/`42S01` まで網羅
  - W3 実測記録（A3）: `ERROR 1062 (23000): Duplicate entry '1' for key 'PRIMARY'` が 4 回発生

- **影響**: インストール中断→再開の冪等性が損なわれる。完全に再インストールするには手動で DB を削除する必要がある。

- **分類**: 実装バグ（`UpdateController` との一貫性欠如）。同一ロジックの複製時に `23000` の分岐が漏れている。

---

## D-25: `bootstrap.php` が `App.fullBaseUrl` を `Configure` に書き込まない

**根本原因: `bootstrap.php:159-180` が `$fullBaseUrl` のフォールバック値を算出し `Router::fullBaseUrl()` に渡すが、`Configure::write('App.fullBaseUrl', $fullBaseUrl)` を実行しないため、`HostHeaderMiddleware` が `Configure::read('App.fullBaseUrl')` で値を取得できない。**

- **発生メカニズム**:
  1. `config/app.php`: `'fullBaseUrl' => env('APP_FULL_BASE_URL', false)` — 環境変数未設定時は `false`。
  2. `config/bootstrap.php:159`: `$fullBaseUrl = Configure::read('App.fullBaseUrl')` → `false` を取得。
  3. `bootstrap.php:160`: `if (!$fullBaseUrl)` → `true`。
  4. `bootstrap.php:173`: `HTTP_HOST` からフォールバック URL を算出（例: `http://localhost:8082`）。
  5. `bootstrap.php:178`: `Router::fullBaseUrl($fullBaseUrl)` — **ルーターには設定される**。
  6. `bootstrap.php:180`: `unset($fullBaseUrl)` — ローカル変数を破棄。
  7. **`Configure::write('App.fullBaseUrl', ...)` が存在しない**。`Configure` ストアには引き続き `false` が残る。
  8. `HostHeaderMiddleware:38`: `$fullBaseUrl = Configure::read('App.fullBaseUrl')` → `false` を取得。
  9. `HostHeaderMiddleware:39`: `if (!$fullBaseUrl)` → `true`。
  10. `HostHeaderMiddleware:41-43`: `InternalErrorException` 相当の例外（`SECURITY: App.fullBaseUrl is not configured...`）をスロー。

- **根拠**:
  - `config/bootstrap.php:159-180` — `Router::fullBaseUrl()` 呼び出しのみ。`Configure::write` の呼び出しなし
  - `config/bootstrap.php:136` — `Configure::write('App.fullBaseUrl', php_uname('n'))` は**コメントアウト済み**
  - `config/bootstrap.php:166` — コメントで `HostHeaderMiddleware will reject requests when fullBaseUrl is not configured.` と自認
  - `src/Middleware/HostHeaderMiddleware.php:38` — `Configure::read('App.fullBaseUrl')` で読み取り
  - `src/Middleware/HostHeaderMiddleware.php:39-43` — 未設定時に入力バリデーション例外をスロー
  - W3 実測記録（D1）: `debug=false` + 未設定時、`HostHeaderMiddleware` が動作確認済み（`debug=true` では early return で問題化しない）

- **影響**: 開発環境（debug=true）では影響なし。**本番環境（debug=false）で `APP_FULL_BASE_URL` 環境変数を忘れた場合、全リクエストが 500 となる**。ただし `bootstrap.php` が算出した値はルーターには適用されるため、URL 生成自体は正常（`HostHeaderMiddleware` のみが影響を受ける）。

- **分類**: 設計バグ。bootstrap のフォールバック処理がルーター（`Router::fullBaseUrl`）とミドルウェア（`Configure::read('App.fullBaseUrl')`）の 2 つの異なる API 経由で参照されるため、片方にしか設定されない不整合。

---

## D-26: D-23 により install バリデーションを動的検証できない（二次的欠陥）

**根本原因: D-23 により `InstallController::index()` の POST ハンドラに到達できないため、username/password のバリデーションロジックを Web UI 経由で動的に実行できない。**

- **発生メカニズム**:
  1. D-23 の結果、GET `/install` は常に「既にインストールされています」テンプレートを返す。
  2. POST `/install` にフォームを送信しても、既存テーブル判定で GET 側がテンプレートを返して終了し、POST 処理（`InstallController` のインストール本体）には到達しない。
  3. したがって管理者作成時の username/password バリデーション（長さ制約、パスワード一致チェック等）の動的検証が不可能。

- **根拠**:
  - `src/Controller/InstallController.php:112-120` — 既存テーブルチェックで `count($data) > 0` のとき installed 表示→return
  - `src/Controller/InstallController.php:122-156` — POST ハンドラ（バリデーション含む）はその Else 側
  - W3 実施記録 A4「管理者作成時の username/password バリデーション」が未検証である旨

- **影響**: W3 実施記録 A4 が検証できず。コードレビューのみで妥当性を判断するしかない。

- **分類**: 二次的欠陥。直接の修正は不要（D-23 が修正されれば解消）。ただしバリデーションロジックの correctness は静的コードレビューで別途担保が必要。

---

> 本解析は検査記録であり、コード修正は行っていない。
