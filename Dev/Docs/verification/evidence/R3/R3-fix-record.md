# R3-fix 修正記録（D-10／D-36／D-37／D-38）

| 項目 | 内容 |
|------|------|
| 実施日 | 2026-09-26 |
| 目的 | R2／R3 の再試験で残存した P0（**D-10**・**D-36**）の修正と、その過程で新規発見した **D-37**・**D-38** の修正 |
| 実施許可 | ユーザー m0388「修正を許可して実施（推奨）」により、検査係の修整禁止を解除して実施 |
| 対象環境 | `http://localhost:8082`（`irohaboard5-web-1` php:8.4-apache）／ MariaDB 11.4.13（port 13307） |
| 検証方法 | 実 HTTP（Python / curl）＋ `bash scripts/test-fresh.sh`（自動テスト） |

---

## 1. 修正一覧

| ID | 内容 | 修正 | 対象 file:line |
|----|------|------|----------------|
| **D-10** | CSV インポートが HTTP 500（`Class "Cake\Utility\Configure" not found`） | `use Cake\Utility\Configure;` → `use Cake\Core\Configure;`（CakePHP 5 では `Cake\Core` に移動している） | `src/Utility/Utils.php:11` |
| **D-36** | `www-data` が `webroot/uploads`・`files` に書けず全アップロードが失敗 | (1) 即時 `chown -R www-data:www-data`。(2) compose の web サービスに `command:` を追加し、起動の度に chown してから `exec apache2-foreground` | `docker/docker-compose.cakephp5.yml`（web サービス） |
| **D-37** | 画像・ファイル配信が到達不能（`Missing Method` 404） | snake_case アクションを camelCase へリネームし、ルート・テンプレート・返却 URL を統一 | `src/Controller/ContentsController.php:140,189,242`（`fileDownload`/`fileMovie`/`fileImage`）、`config/routes.php:100,104,108`、`src/Controller/Admin/ContentsController.php:361`、`templates/Contents/view.php:52,64`、`templates/Contents/index.php:138`、テスト 2 ファイル |
| **D-38** | `fileDownload`/`fileMovie` が `content.url` 空で PHP TypeError → 500 | kind チェック直後に空 URL ガードを追加し `NotFoundException`（404）へ変換 | `src/Controller/ContentsController.php`（`fileDownload`/`fileMovie`） |

### 補足（D-36 の構造）
- `webroot/uploads` は**ホスト bind**（`../:/var/www/html/`）、`files` は **named volume `cakephp5-files`**。両者を Apache 実行ユーザに合わせる必要があった。
- Dockerfile の CMD は `apache2-foreground` のため、compose の `command:` で「chown → exec」に置き換えて永続化した。

### 補足（D-37 の原因）
`config/routes.php:22` の `DashedRoute` は controller/action を自動で inflect する。ルート側の `'action' => 'file_download'` が `fileDownload` に camelize される一方、コントローラのメソッド名は underscore のままだったため `Missing Method` となっていた。アプリ全体で複数語 underscore の public アクションはこの 3 つのみだった。

### 補足（D-38 の原因）
`fileDownload`/`fileMovie` は `content.url` を前提に `basename()` を呼ぶ。DS の content6 は `kind=file` かつ `url=NULL` のため `basename(null)` が TypeError となり 500 を返していた。

---

## 2. 検証結果（実 HTTP）

| 対象 | 結果 |
|------|------|
| D-10 CSV 取込 | CP932 の CSV（5 列）を `POST /admin/users/import`（field=`csvfile`）→ **HTTP 302 → /admin**、fatal なし、ユーザー `r3fixA`（role=`user`）が `ib_users` に作成 |
| D-36 `upload/file` | field=`file`、トークンは `/admin/contents/upload/file` の GET から → **HTTP 200（本文に `complete`）**、`webroot/uploads/<ts>.txt` が生成（107→108 件） |
| D-36 `uploadImage` | **AJAX ヘッダ `X-Requested-With: XMLHttpRequest` が必須**（無いと `is('ajax')` が false で空本文）。付与時 → **HTTP 200 `["http://localhost:8082/contents/file-image/<ts>.png"]`**、ファイル生成（108→109 件、`www-data` 所有） |
| D-37 画像配信 | `/contents/file-image/<実在する画像>`（user1）→ **200 image/png** |
| D-38 空 URL | `/contents/file-download/6` → **404「File not found」**（修正前 500）、`/contents/file-movie/4` → **404** |
| 自動テスト | `bash scripts/test-fresh.sh` → **744 tests / 3689 assertions / 0 errors / 0 failures / 0 deprecations / PHPUnit Notices 8**（修正前後で同一） |

> 注記: エラー経路でも MCP／API の HTTP ステータスは仕様どおり 200／404 を返す。`uploadImage` の非 AJAX 空応答は異常ではなく `is('ajax')` 条件による仕様。

---

## 3. 後片付けと過失

- dev DB: D-10 検証で作成したユーザー（`r3fixA` 等）を削除、`ib_user_tokens`／`ib_logs` を全削除 → 基準値一致（`users=6 courses=2 contents=11 questions=5 records=2 records_questions=3 tokens=0 logs=0`）。
- **過失**: アップロードの清掃で `find ... -user www-data -delete` を実行した際、D-36 の `chown -R www-data` により**追跡ファイル `webroot/uploads/.htaccess` まで削除**してしまった。`git checkout -- webroot/uploads/.htaccess` で**復元済み**（`git status` に追跡ファイルの削除 ` D` は無し）。また gitignore 対象の既存アップロード PNG（テスト残骸）も削除した。実ユーザーデータではないが、以後は**自分が生成したファイルのみを対象に清掃**する。
- 復元後の権限確認: `webroot/uploads` は `www-data:www-data`、`files` はコンテナ実体（named volume）が `www-data` で、いずれも `www-data` から WRITABLE（D-36 修正は維持）。

---

## 4. 判定

**✅ 修正済** — D-10／D-36／D-37／D-38 を修正し、実 HTTP と自動テストで検証。未修正の P0／S2 は **0 件**。

### 学び（記録規約 §4 へ追加候補）
- **ルーティングの inflect とメソッド命名のズレは、単体テストでは検出しにくい**（D-37: DK のルート解決を通す実 HTTP が必要。既存テストが「既知のバグ」として 404 を assert していたため、緑のまま見逃されていた）。
- **正常系のテストだけでは「未登録データ」の耐性欠落を検知できない**（D-38: `url=NULL` の `file` コンテンツが DS に含まれていたが、テストは実ファイルのあるケースのみを検証していた）。
- **環境権限はアプリのテストでは検知できない**（D-36: PHPUnit は root 実行のため書込みに成功する。Apache 経由のアップロード確認を検証工程に恒常的に組み込む）。
