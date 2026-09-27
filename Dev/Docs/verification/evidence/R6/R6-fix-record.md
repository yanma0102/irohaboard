# R6 修正記録（D-42：post_max_size 超過時の壊れた応答と動画アップロード不能）

| 項目 | 内容 |
|------|------|
| 実施日 | 2026-09-27 |
| 発端 | ユーザーからのバグ報告「動画アップロードにバグを発見」＋ 貼り付けられた警告（`PHP Request Startup: POST Content-Length of 94220036 bytes exceeds the limit of 67108864 bytes` / `Unable to emit headers` / `Cannot modify header information`） |
| 結果 | ✅ **修正済**。94MB の動画が実際にアップロード成功、上限超過時は正しい `HTTP 413` を返す |
| 実施許可 | ユーザーが修正範囲の選択肢から「**A+B：根本修正（推奨）**」を選択 |
| 対象環境 | `http://localhost:8082`（`irohaboard5-web-1` php:8.4-apache）／ MariaDB 11.4.13（port 13307） |
| 検証方法 | 実 HTTP（Python urllib / curl）＋ CLI 内蔵サーバでのガード単体確認 ＋ `bash scripts/test-fresh.sh` |

---

## 1. 症状と再現（修正前・実測）

`/admin/contents/upload/movie` へ multipart で送信した結果：

| 送信サイズ | HTTP | 応答の実体 |
|------------|------|------------|
| 1MB | 200 | 正常ページ（アップロード成功） |
| 20MB | 200 | 正常ページ＋Flash「ファイルサイズが上限を超えています」（アプリ上限 10MB） |
| 65MB | **200** | **警告＋1,263,280 バイトのデバッグエラーページ**（`InvalidCsrfTokenException`・内部パス・スタック露出） |
| 94MB（報告値） | **200** | 同上。本文先頭が `<br /><b>Warning</b>: PHP Request Startup: POST Content-Length of 94220979 bytes exceeds the limit of 67108864 bytes` |

一次証拠: `/tmp/up_bug.py`（再現ハーネス）、`/tmp/up_94mb_body.html`（94MB 時の応答本文）。

報告と同一の警告列（`ResponseEmitter.php, line 66 / 159 / 192`）が応答に混入しており、**HTTP ステータス自体も送出できないため 413/403 ではなく 200** になっていた。

---

## 2. 原因解析（3 層の積み重ね）

| 層 | 内容 | 根拠 |
|----|------|------|
| ① PHP | `post_max_size = 64M` を 94,220,036 バイトの POST が超過。PHP は**スクリプト実行前（Request Startup）に本文を丸ごと破棄**し、`display_errors = On` のため警告を即座に送信 → `headers_sent()` が true になり HTTP ステータスを送出できない | `docker/php.ini:14-15`（64M）、警告本文の `limit of 67108864` |
| ② アプリ | 本文破棄により **CSRF トークンも失われ**、`CsrfProtectionMiddleware` が `InvalidCsrfTokenException` を送出 → debug モードのエラーページ（1.26MB、内部パス・ミドルウェアスタック露出）が生成される | 応答本文中の `Cake\Http\Exception\InvalidCsrfTokenException` |
| ③ 設計 | アプリの動画上限は `10MB`（`config/ib_config.php:124`）だが、設定 GUI の上限は `1024MB`（`config/ib_config_schema.php:133-141`）。**php.ini（64M）＜ 設定可能値（1024MB）という矛盾**があり、GUI で上限を上げても必ず ①② に落ちる | `ib_config_overrides.upload_movie_maxsize = 10485760`、schema の `'max' => 1024` |
| 付帯 | `docker/php.ini` は `Dockerfile.cakephp5` の `COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini` で**イメージに焼き込まれている**ため、設定変更にはイメージ再ビルドが必要 | `docker/Dockerfile.cakephp5` |

---

## 3. 修正内容

| 区分 | 対象 | 内容 |
|------|------|------|
| **A-1** | `config/bootstrap.php:292-358`（追加） | **post_max_size 超過の事前検出ガード**。`CONTENT_LENGTH` を `ini_get('post_max_size')` と比較し、超過時は（a）`ob_clean()` で Request Startup 時の警告を破棄、（b）`Log::warning()` 記録、（c）`HTTP/1.1 413 Payload Too Large` ＋日本語の案内ページを出力して `exit`。ミドルウェア（CSRF）に到達する前に拒否するため、壊れた応答にならない |
| **A-2** | `src/Controller/Admin/ContentsController.php:271-279, 323-339` | `$file === null`（未指定）と `$file->getError() !== UPLOAD_ERR_OK`（PHP による拒否）を分離し、新設の `uploadErrorMessage()` で `UPLOAD_ERR_INI_SIZE` / `FORM_SIZE` / `PARTIAL` / `NO_FILE` を別々の日本語案内に変換。従来はサイズ超過も「ファイルが指定されていません」と誤案内していた |
| **B-1** | `docker/php.ini:14-27` | `upload_max_filesize` / `post_max_size` を **64M → 1024M**（設定 GUI の上限 1024MB と一致）。`max_input_time` を **300 → 3600**（大容量受信時間のため）。**`output_buffering = 4096` を新設**：これを無効にすると Request Startup の警告が即送信され、ガードが出す 413 のステータス行すら送出できない |
| **B-2** | `config/ib_config.php:124` | `upload_movie_maxsize` のデフォルトを **10MB → 1024MB**（php.ini 実効上限と一致） |
| **B-3** | DB `ib_config_overrides` | 旧デフォルト（10MB）をそのまま保持していた `upload_movie_maxsize` のオーバーライド行を **削除**し、新しいデフォルトを有効化。`upload_maxsize`(100MB) / `upload_image_maxsize`(2MB) の行は並行セッションの分として**変更していない** |
| — | イメージ再ビルド | `docker compose -f docker/docker-compose.cakephp5.yml build web && up -d web`（B-1 の反映には必須） |

### 補足（なぜ 1100MB 送信時は Apache の 413 ページになるのか）
1100MB の POST では、ガードが 413 を返して即終了した結果、Apache が**未読のリクエストボディ**を残し、Apache 自身が既定の 413 ページで応答を置き換える。ステータスは `HTTP/1.1 413 Payload Too Large` で正しい（ガード実行の裏付けは `logs/error.log` に `POST body rejected: 1153433797 bytes exceeds post_max_size 1024M (1073741824 bytes)` として記録済み）。ガード自身のページは下記 §4.2 で確認した。

---

## 4. 検証結果（すべて実測）

### 4.1 上限引き上げ後のアップロード（Apache / 8082）

| 送信サイズ | HTTP | 結果 |
|------------|------|------|
| 1MB | 200 | ✅ 成功（`mode = 'complete'`） |
| 20MB | 200 | ✅ 成功（修正前は 10MB 上限で弾かれていた） |
| 65MB | 200 | ✅ 成功 |
| **94MB（報告値 94,220,036 bytes）** | 200 | ✅ **成功**。`webroot/uploads/20260927200209gdyw.mp4` が **94,220,036 バイト**で生成 |
| 3MB（プローブ） | 200 | ✅ `mode = 'complete'` / `file_url = 20260927200224plbh.mp4` / `setURL()` 呼び出し |

- 警告・デバッグページは一切出現せず、応答は全て 5,197〜5,198 バイトの正常ページ。
- アップロード画面の表示も `最大 : 1 GBバイト`（修正前は 10MB 相当）に更新されていることを確認。

### 4.2 上限超過時のガード（CLI 内蔵サーバ・`post_max_size=1M` で単体確認）

```
HTTP/1.1 413 Payload Too Large
Content-Type: text/html; charset=UTF-8
<body>...<h1>アップロードサイズが上限を超えています</h1>
送信されたデータ量 2,097,366 バイトは、サーバの受け取り上限 <code>post_max_size = 1M</code> を超えているため…
```

- `output_buffering` により Request Startup の警告は**応答に混入しない**（`ob_clean()` で破棄）。
- ステータス行・`Content-Type` が正しく送出されている。

### 4.3 実 Apache での 1100MB 超過

- `curl` 送信 → **`HTTP/1.1 413 Payload Too Large`**（Apache の既定 413 ページに置換されるが、ステータスは正しく、警告・スタック・内部パスは無し）。
- `logs/error.log:19283` にガードの記録: `2026-09-27 20:02:38 warning: POST body rejected: 1153433797 bytes exceeds post_max_size 1024M (1073741824 bytes)`

### 4.4 データ後始末

- 検証で生成した `webroot/uploads/*.mp4` 6 件（1/3/20/65/94MB）を削除済み。既存の png/txt は残置。
- `/tmp/huge.mp4`(1.1GB)・`two_mb.bin`・`one_mb.bin` 等の一時ファイルを削除済み（空き 67G で復帰）。

---

## 5. 回帰テスト

`bash scripts/test-fresh.sh`

```
Tests: 748, Assertions: 3709, Failures: 1, PHPUnit Notices: 8
```

唯一の失敗は `tests/TestCase/Migration/MigrationVerificationTest.php:73`（`ib_config_overrides` が期待テーブル一覧に無い）で、**修正前と完全に同一**。並行セッションの未コミット Config GUI 機能（`config/Migrations/20260927000000_CreateConfigOverrides.php`）が原因であり、本修正（テンプレート／bootstrap／php.ini／コントローラ）とは無関係。**本修正による回帰は 0 件。**

---

## 6. 残課題・注意

| 項目 | 内容 |
|------|------|
| php.ini の変更は再ビルド必須 | `Dockerfile.cakephp5` が `COPY` で焼き込むため。将来の変更も同様 |
| `display_errors = On`（開発用） | Request Startup の警告をユーザーに見せてしまう。本修正ではガード側で吸収しているが、本番では Off が望ましい |
| 1GB 超過時は Apache の 413 ページ | ガードの日本語ページではなく Apache 既定ページになる（ステータスは正しい）。完全に自ページを返すにはボディを消費する必要があり、非実用 |
| `upload_maxsize`（汎用）のデフォルトは 10MB のまま | 並行セッションがオーバーライドで 100MB に設定済み。動画経路（`upload_movie_maxsize`）のみ今回 1024MB に更新 |
| 画像アップロード（Summernote） | `uploadImage()` はサイズ超過時も `JSON [false]` を返す現状を維持（壊れた応答にはならない）。今回の大容量問題の対象外 |
