# iroha Board 検証ナレッジ（Knowledge & Pitfalls）

| 項目 | 内容 |
|---|---|
| 目的 | 本プロジェクトで**実際に実施した強化検証**（W0〜W7、R2〜R4）で得られた「**再利用可能な知見・罠・裏手順**」を記録し、次回の検証者が同じ失敗を繰り返さないようにする |
| 位置づけ | `09-verification-procedure.md`（**手順書** = 何をどう実施するか）の**補集**。本書は**実行して分かったこと**（失敗の症状・切り分け・回避策）を扱う |
| 対象 | 実 HTTP 検証、スモークテスト、Docker 環境、自動テスト、証跡記録、コミット運用 |
| 出典 | `evidence/W0〜W7`、`evidence/R2〜R4`、`evidence/_orchestrator-corrections.md` および本セッションの実施ログ |
| 作成日 | 2026-09-26 |

> **原則**: 検証の**結果**（合格/不合格）は `evidence/` 側に記録し、本書には**手順そのものが再利用できない・誤判定を招く**という形の知見のみを収録する。

---

## 1. 実 HTTP 検証ハーネスの知識

検証は `tests/` の自動テストだけでは足りず、`http://localhost:8082` に対して実リクエストを飛ばすことが多い。その際に繰り返し踏んだ罠をまとめる。

### 1.1 ログインと CSRF トークン（**トークンは必ず同一ページから取得する**）

| 項目 | 内容 |
|---|---|
| GET | `/admin/users/login`（管理側）/ `/users/login`（受講者側） |
| POST フィールド | **`username` / `password`**（flat な名前。`data[User][username]` ではない） |
| 併送必須 | フォーム HTML の hidden を全て拾って送る: `_csrfToken`, `_Token[fields]`, `_Token[unlocked]`, `_Token[debug]` |
| 罠 | トークンは**そのページの GET で取得したもの**を使う。過去のセッションや他ページのトークンを流用すると 403/ログイン失敗になる |
| 罠 | `<input value="...">` の値は HTML エスケープされていることがある。**HTML entity を unescape してから送る**こと（`html.unescape` 等） |

### 1.2 multipart のファイル送信（標準ライブラリは boundary を作らない）

- `urllib` で multipart を投げる場合は自分で boundary を組み立てる（ハーネス側の実装）。
- CakePHP 5 は `ServerRequest::getUploadedFile()` で **`_FILES` 側**を見る。`getData()` では**実ファイルは取れない**（これは D-10 の直接原因、§1.5）。
- 送信するフィールド名はエンドポイントごとに異なる（§1.4）。

### 1.3 リダイレクトの観測

- 成功判定に HTTP 302 を使うことが多い。そのため **`HTTPRedirectHandler` を上書きしてリダイレクトを追跡しない**実装にする（追跡すると最終 200 しか見えず、送信先を判定できない）。
- 302 の `Location` を必ず確認する。ログイン失敗でも 302 でログイン画面に戻るため、**送信先 URL で成功/失敗を区別**する。

### 1.4 アップロード系エンドポイントの個別ガード

| エンドポイント | ファイル名 | 追加条件 | 期待応答 |
|---|---|---|---|
| `POST /admin/contents/upload/file`（配布資料） | `file` | — | HTTP 200、本文に `complete` |
| `POST /admin/contents/upload-image`（画像） | `file` | **ヘッダ `X-Requested-With: XMLHttpRequest` 必須**（`is('ajax')` ガード） | JSON 配列 `["http://…/contents/file-image/<生成名>"]`。失敗時 `[false]` |

- **罠**: `X-Requested-With` を忘れると**サイレントに失敗**する（`[false]` 相当）。
- 拡張子・サイズ上限は `upload_image_extensions` / `upload_image_maxsize` 設定値を参照。超过も `[false]`。

### 1.5 ユーザー CSV インポート

| 項目 | 内容 |
|---|---|
| フィールド名 | **`csvfile`**（`$this->request->getUploadedFile('csvfile')`。`getData()` ではない） |
| **文字コード** | **CP932（SJIS-Win）前提**。`Utils::getCsvData()` が `mb_convert_encoding($data, 'UTF-8', 'SJIS-Win')` している。**UTF-8 の CSV を渡すと文字化け・取り込み不正**になる |
| 最小列数 | **5 列未満は行ごとにスキップ**（`count($row) < 5`） |
| 列の意味 | `0` = ログインID、`1` = パスワード、`2` = 氏名、`3` = 権限（**設定値のラベル**。例: `受講者` → 内部キー `user`）、`4` = メールアドレス |
| 期待結果 | HTTP **302 → `/admin`** かつ `ib_users` に insert（**HTTP のみでなく DB 変化まで確認**する） |
| 罠（D-10） | `getData()` での取得を前提に書かれた実装は `is_array($file)` が常に false になり「インポートファイルが指定されていません」で恒久失敗する。「**コード上は取得処理がある**」だけで修正済みと判断しないこと |

### 1.6 受講者としてのコンテンツ到達

- フロント（`/contents/...`）は**コース受講登録が紐づくユーザー**でないと到達できない。検証では登録済みの `user1` / `password` を使用。
- 存在しない `content_id` や `url` 空のコンテンツは 404（D-38 修正後）になる。**実在 ID は直前の DB 確認で取得**する（Seeder 再実行で ID は変わりうる）。

### 1.7 セッションの扱い

- セッションは保存パス等が変わると有効期限切れ扱いになる（W3 で一度ランナーが停止した実例あり）。
- 複数ロール（admin / user1）の検証は**CookieJar を分離**する。失敗時はまず「再ログイン」が最も確実な復旧手段。

---

## 2. スモークテストの知識

### 2.1 実行コマンド

```bash
bash scripts/smoke-api.sh    # API スモーク
bash scripts/smoke-mcp.sh    # MCP スモーク（4/4 PASS / 9 ツール）
```

### 2.2 MCP の正しい手順（`scripts/smoke-mcp.sh` に実装済み）

1. **`initialize`** を `protocolVersion: "2025-03-26"` で送る。
2. **`Mcp-Session-Id` は同レスポンスのヘッダから拾う**。ヘッダ取得が安定しない場合に備え、**別リクエストで `curl -s -D - -o /dev/null` を叩いて捕捉**する方式になっている。
3. **`notifications/initialized`** を送る（MCP プロトコル上、initialize 後に**必須**。これを飛ばすと以降の呼び出しが失敗する）。
4. `tools/list`（**9 ツール**を期待: 読み取り 7 + 書き込み 2）→ `tools/call`（例: `list_courses`）。

- 認証: `API_USER` / `API_PASS`（既定 `admin` / `adminpass`）でトークン発行して `Authorization` に付与。
- **罠**: initialize だけ成功した状態で「MCP 動作確認済み」としない。**`notifications/initialized` まで通って初めてツール呼び出し可能**。

### 2.3 成功記録も残す

- スモーク成功（API 16/16・MCP 4/4 等）も `evidence/README.md` に追記する。失敗記録だけだと「実施済みか」が後から判断できない。

---

## 3. Docker・環境の知識

| 項目 | 内容 |
|---|---|
| compose | `docker/docker-compose.cakephp5.yml`（`docker/` 直下に置く） |
| **サービス名** | **`web` / `db`**。アプリ側コードコメントに書かれた `app` は存在しない → **`docker compose config` で都度確認**（当初1回間違えた） |
| コンテナ | `irohaboard5-web-1`（`http://localhost:8082`）、`irohaboard5-db-1`（port 13307、`root` / `rootpass`） |
| DB | 開発 `irohaboard` / テスト `irohaboard_test`。接続は `docker exec irohaboard5-db-1 mariadb -uroot -prootpass <db>` |
| 起動直後 | DB が用意できるまで数十秒かかり、参照系まで **500** になりうる。**一時的な 500 を不合格と判定せず、再現性を 2 度確認**する |
| マウント | `webroot/uploads` は **bind mount**（ホスト側と共有）、`files` は **named volume `cakephp5-files`**（実体はコンテナ内。ホスト表示が `root:root` でも中身は `www-data`） |

### 3.1 D-36 のように「起動時処理」を検証する方法（**制御実験**）

`docker-compose.yml` の `command:` など**コンテナ再起動に依存する修正**は、通常の HTTP 検証だけでは確認できない。

```
# 1) あえて壊した状態に作り直す
docker exec … chown -R root:root /var/www/html/webroot/uploads /var/www/html/files
# 2) 再作成
docker compose -f docker/docker-compose.cakephp5.yml up -d --force-recreate web
# 3) 自動修復を確認
docker exec … ls -ln /var/www/html/webroot/uploads   # → www-data:www-data なら成功
```

### 3.2 起動時 `chown` が成立する条件

- Dockerfile は **`CMD ["apache2-foreground"]` のみ（`ENTRYPOINT` を持たない）**。compose の `command:` は**最終コマンドを置換する**ため、`sh -c "chown -R … && exec apache2-foreground"` と書いて `apache2-foreground` を最後に渡す必要がある（`chown` だけで Apache が起動しなくなる事故に注意）。
- `ENTRYPOINT` を持つイメージでは `command:` の意味が変わるため、**Dockerfile を先に確認**する。

---

## 4. 自動テストの知識

| 項目 | 内容 |
|---|---|
| 定常緑の基準 | **`bash scripts/test-fresh.sh`**（`irohaboard_test` を DROP/CREATE してから実行） |
| 現行値 | **744 tests / 3689 assertions / 0 errors / 0 failures / 0 deprecations** |
| 既知の許容 | **PHPUnit Notices 8 件**（vanilla のみで発生。検出なしはありえない） |
| 使い分け | `composer test` はテスト DB に残留データがあると**偽の失敗**を招くことがある（既知） |

### 4.1 【重要】並行実行による偽の大量失敗

| 症状 | `test-fresh.sh` の実行中に **`Tests: 744, Errors: 97, Failures: 103`** という全滅が発生した |
|---|---|
| 原因 | **別プロセス（並行作業・他セッション）と同時に同じテスト DB を使う**と、`DROP DATABASE` / `CREATE DATABASE` が競合し、走っているテストが全滅する。**コードの問題ではない** |
| 切り分け | ① 素の `vendor/bin/phpunit tests/TestCase/...` で**単一クラス**を実行 → 緑なら競合が原因<br>② `test-fresh.sh` を**単体で再実行** → 通常緑 |
| 教訓 | **大量失敗を見たら「直近で触った差分」を疑う前に再実行と単一クラス確認を行う**。判定を早めると無関係な修正に手を付けかねない |

### 4.2 テスト DB の残存

- `tests/bootstrap.php` の Migrator はテーブルを DROP しない。Seeder 残留で偽失敗するため、**常緑確認は必ず `test-fresh.sh`** で行う。

### 4.3 LSP（エディタ）警告の扱い

- `.slim/worktrees/**`（別ワークツール）下のファイルに大量の `Undefined variable` / `Undefined type` が表示されるが、**本体コードには無関係**。本体の該当ファイルが該当するかを確認してから向き合う。

---

## 5. 証跡・記録・ワークフローの知識

| # | 知見 |
|---|---|
| 1 | **成功した検証も記録する**。スモーク成功・A-01/A-02 の通過など「実施済みの成功」を `evidence/README.md` に載せないと、後から「やったか」が判別できない |
| 2 | **レーンが書いた記録は改変しない**。Orchestrator が再実測して違った場合は **`evidence/_orchestrator-corrections.md` に別ファイルで訂正**を書く（出所の追跡可能性のため） |
| 3 | **修正はコミットしてから記録する**（commit id で追跡できる）。記録が先だと「どの状態の検証か」が曖昧になる |
| 4 | **追跡ファイル（`webroot/uploads/.htaccess` 等）を絶対に削除しない**。検証用の生成物掃除で誤削除し、`git checkout -- <path>` で復元した実例あり（`R3-fix-record.md` §4） |
| 5 | 開発 DB（`irohaboard`）とテスト DB（`irohaboard_test`）を**用途で使い分ける**。検証後に削除してよいのは**自分が生成したデータのみ**（他者が置いたデータを消さない） |
| 6 | 検証終了時の **dev DB ベースライン**: `users=6 courses=2 contents=11 questions=5 records=2 records_questions=3 tokens=0 logs=0`。不一致なら残留あり |
| 7 | 検証で発行したトークン・ログ・テストユーザーは**終了時に必ず掃除**（`ib_user_tokens`, `ib_logs`, テスト用 `ib_users` 行、生成ファイル） |
| 8 | **計画時点の検証結果は、その時点でコードが動いていた期間のもの**。修正が入ったら再試験（R 系）を挟まないと古い結果を根拠にできない |

---

## 6. 判定・アプローチの知識

| 罠 | 内容 | 対処 |
|---|---|---|
| **「コード上は対策済み」** | D-09 / D-10 / D-14 で「実装はあるから直っている」と判定したが、**実 HTTP でやると失敗**した（`getData()` vs `getUploadedFile()`、`Configure` の名前空間など） | **コードレビューでの「対策あり」は判定根拠にしない。実 HTTP + DB 変化で確認**する |
| 一時的な環境障害 | 起動直後の DB 未接続で 500 が出たまま「不合格」と記録しそうになる | **再現性を 2 回確認**してから判定。環境起因は `環境` として切り分ける |
| ランダム ID の陳腐化 | Seeder 再実行でカード番号・ID が変わる | 検証直前に ID を再取得し、記録にも**取得時点**を書く |
| 観測範囲の不足 | 200 が返るだけで成功とみなす | **HTTP ステータス + Location + DB 変化 + ファイル生成**のうち該当するものを併記する |
| 手戻り | 修正後に同じ手順をやり直さず旧結果を引用 | 修正が出たら **再試験（R 系）を新規記録として作る**（`R3` → `R3-fix` → `R4` の流れ） |

---

## 7. コミット・push の知識

1. コミット前必ず `git status` / `git diff` / `git log --oneline -10` を確認する。
2. **`git add -A` は使わない**。`.slim/` などのツール成果物・作業ディレクトリが混入するため、**対象ファイルを明示的に指定**する。
3. **別セッション／並行作業者が未コミットの変更を残していることがある**。差分を読んで内容を把握してからコミットする（内容不明のまま巻き込まない）。
4. **テストが失敗している状態ではコミットしない**。検証（`test-fresh.sh`）を通してからコミットする。
5. push は `git push origin master`。force push・config 変更・`--no-verify` は使わない。
6. コミットメッセージはリポジトリの慣例（`fix:` / `docs:` / `ci:` / `style:` + 日本語）に合わせる。

---

## 付録 A. 罠サマリ（新規実施者はまずこれを見る）

| 罠 | 症状 | 対処 | 出典 |
|---|---|---|---|
| CSRF トークンを別ページから持ってくる | 403 / ログイン失敗 | 直近 GET の hidden をそのまま送る | §1.1 |
| hidden の value を未デコードで送る | トークン不一致 | HTML entity を unescape | §1.1 |
| multipart を標準ライブラリ任せにする | ファイルが届かない | boundary を自前で組み立てる | §1.2 |
| リダイレクトを追跡してしまう | 成功/失敗が判別不能 | 追跡を止めて 302 と Location を見る | §1.3 |
| `uploadImage` に `X-Requested-With` を付けない | サイレントに失敗（`[false]`） | AJAX ヘッダを付与 | §1.4 |
| CSV を UTF-8 で渡す | 文字化け・取り込み不正 | **CP932 (SJIS-Win)** で渡す | §1.5 |
| CSV を 5 列未満で渡す | 行が丸ごとスキップされ 0 件 | 5 列以上を揃える | §1.5 |
| `getData()` でアップロードを取る | 「ファイルが指定されていません」で恒久失敗 | `getUploadedFile()` | §1.5 / D-10 |
| `notifications/initialized` を飛ばす | ツール呼び出しが失敗 | initialize → **initialized** → tools | §2.2 |
| compose サービス名を `app` と勘違い | `No such service` | `docker compose config` で確認 | §3 |
| 起動時処理を HTTP 検証だけで見る | 「確認できていない」 | 壊してから `--force-recreate`（§3.1） | §3.1 |
| 並行で `test-fresh.sh` を走らせる | **97 errors / 103 failures の偽全滅** | 単一クラス確認 → 単体で再実行 | §4.1 |
| 追跡ファイル（`.htaccess` 等）を掃除で削除 | git に ` D` が並ぶ | `git checkout -- <path>` で復元。以後意図したファイルのみ指定 | §5-4 |
| コードレビューだけで「修正済み」と判定 | 実は未修正（D-09/D-10/D-14） | 実 HTTP + DB 変化で確認 | §6 |
| `git add -A` | ツール成果物を巻き込む | 対象ファイルを明示 | §7-2 |

---

## 付録 B. コマンドクイックリファレンス

```bash
# 環境
cd docker && docker compose -f docker-compose.cakephp5.yml up -d --build
docker compose -f docker/docker-compose.cakephp5.yml up -d --force-recreate web
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8082/   # 起動直後は 302/200 を確認

# DB
docker exec irohaboard5-db-1 mariadb -uroot -prootpass irohaboard -e "SELECT COUNT(*) FROM ib_users;"

# テスト（定常緑の基準）
bash scripts/test-fresh.sh
bash scripts/test-fresh.sh tests/TestCase/Controller/Api/ApiContractTest.php  # 絞り込み

# スモーク
bash scripts/smoke-api.sh
bash scripts/smoke-mcp.sh

# コミット（対象を明示）
git status --short
git add <対象ファイル…>
git commit -F - <<'MSG'
…（fix:/docs:/ci: + 日本語）
MSG
git push origin master
```

---

## 関連ドキュメント

| ドキュメント | 役割 |
|---|---|
| [`09-verification-procedure.md`](09-verification-procedure.md) | 検証**手順書**（体制・証跡規約・判定・復旧） |
| [`evidence/README.md`](evidence/README.md) | 不備一覧と**各レコードの索引**（結果の一次情報） |
| [`evidence/_orchestrator-corrections.md`](evidence/_orchestrator-corrections.md) | レーン記録に対する**独立再実測と訂正** |
| [`../test/README.md`](../test/README.md) | 移行回帰ベースライン（626 項目） |
| [`../../scripts/README.md`](../../scripts/README.md) | 検証用スクリプトの解説 |
