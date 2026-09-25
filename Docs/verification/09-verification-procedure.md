# iroha Board 検証実施手順書（Verification Procedure）

| 項目 | 内容 |
|---|---|
| 目的 | `01`〜`08` の検証計画（何を・どう観点付けするか）を、第三者が再現可能な**実施手順**に落とす |
| 版 | 1.0（2026-09-25 作成） |
| 上位文書 | `README.md`（総則）、`06-risk-coverage-automation.md`（ウェーブ・完了条件） |
| 実施記録 | `evidence/<wave>/<wave>-execution-record.md`（例: `evidence/W0/W0-execution-record.md`） |
| 対象 | 検証責任者・実施者・記録者 |

> 本手順書は**進め方・準備・記録・判定・復旧**を定める。個々の検証項目の内容は `02`〜`04`、探索は `05`、画面網羅は `07`/`08` を参照する（重複記載しない）。

---

## 1. 体制と役割

| 役割 | 責務 |
|---|---|
| 検証責任者 | 計画承認、ウェーブ編成、リリース判定、リスク受容の意思決定 |
| 実施者 | 項目の実行、実測、一次判定、証跡取得 |
| 記録者 | 結果票・セッションシート・欠陥票の記入と整合維持 |
| 修正担当 | NG の原因調査・修正・修正版の提供 |
| 観測者（任意） | 非機械検証の同席観察、バイアス低減 |

**独立性**: P0 のセキュリティ・権限項目は、可能な限り作成者と別の実施者が担当する。1 名運用時は、実施日を分け／別環境で再実行し、自己確認バイアスを記録に残す。

---

## 2. 前提環境と準備

### 2.1 環境起動

```bash
cd /root/project/irohaboard/docker
docker compose -f docker-compose.cakephp5.yml up -d --build
docker compose -f docker-compose.cakephp5.yml ps
```

初回のみ、ブラウザで `http://localhost:8082/install` にアクセスし、スキーマ作成と管理者アカウントを作成する。

**テスト DB**: テストは MariaDB（Docker port 13307）で実行する（既定で個別キー接続、`url=null`）。`DATABASE_TEST_URL` 環境変数で上書き可。**テスト DB に Seeder 等の残留データがあると偽の失敗（例: API 契約テストの誤検知）を招くため、`scripts/test-fresh.sh` で `irohaboard_test` を作り直してから実行する。**

### 2.2 ヘルスチェック（W0）

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8082/          # 302/200 を期待
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8082/users/login
docker exec irohaboard5-db-1 mariadb -uroot -prootpass -e 'SELECT VERSION();'
```

失敗時は §10「環境障害時」に進む。

### 2.3 Seeder データ投入

DS-0〜DS-3/DS-5/DS-7 はSeeder 化済み。`seed-ds.sh` で一括投入可能:

```bash
# 全 Seeder を順に投入（デフォルト: DB_NAME=irohaboard, DB_PORT=13307）
DB_NAME=irohaboard_test DB_PORT=13307 ./scripts/seed-ds.sh

# 個別投入
bin/cake seeds run Ds0BaselineSeed --source Seeds
bin/cake seeds run Ds1UsersSeed --source Seeds
# ... 以下 DS-2/DS-3/DS-5/DS-7 を同様に
```

> **注意**: DS-4（学習記録）、DS-6（旧 SHA1 ユーザー）は未セーダー化。手動または SQL で投入する。

### 2.4 スモークテスト

```bash
# API スモーク（起動中のアプリに対して実行）
bash scripts/smoke-api.sh

# MCP スモーク（ツール数 9 を前提に検証）
bash scripts/smoke-mcp.sh
```

### 2.3 ツール

| 用途 | ツール |
|---|---|
| 実ブラウザ操作 | Chrome / Firefox / Edge + 開発者ツール、画面録画 |
| a11y | axe DevTools、Lighthouse、キーボードのみ、NVDA/VoiceOver（任意） |
| HTTP | `curl`、`jq` |
| MCP | `npx @modelcontextprotocol/inspector`（公式 Inspector） |
| DB | `docker exec ... mariadb`、任意の GUI クライアント |
| CSV | エンコーディング/BOM を確認できるエディタ、`iconv`、`nkf` |
| 記録 | 記事の記録シート（§6）、スクリーンショット保存先 |

### 2.4 証跡ディレクトリの初期化

```bash
mkdir -p /root/project/irohaboard/Docs/verification/evidence/{W0..W7}
```

---

## 3. データセット準備（DS-0〜DS-11）

`README.md` §7 の DS を、以下の順に投入する。DS-0〜DS-6 の詳細定義は既存 `Docs/test/README.md` §3 に従う。

| セット | 投入方法（要点） |
|---|---|
| DS-0 | 素の状態。`install` 直後（admin 1 件）。**復元の基準**。 |
| DS-1 | 管理者画面でグループ 2 件（公開/非公開）と各ロール（manager/editor/teacher/user）ユーザーを作成。 |
| DS-2 | コース 2 件と全 kind（label/html/markdown/movie/url/file/test/enquete）のコンテンツを作成。公開/非公開を混在。 |
| DS-3 | テスト問題（single/text、複数正解、score 0/100/-1）とアンケート問題を投入。 |
| DS-4 | 学習記録（合格/不合格/未完了、理解度 0〜5）。`ib_records.group_id` 不整合 1 件は SQL で直接投入。 |
| DS-5 | お知らせ 3 件（全体/グループ限定/非公開）と設定値一式。 |
| DS-6 | 旧 SHA1 ハッシュのユーザーを SQL で直接投入。 |
| DS-7 | Markdown コンテンツ（見出し/表/コード/リンク/画像/生 HTML/`<script>` 混入/GFM タスクリスト）。 |
| DS-8 | API トークン（有効/期限切れ/失効済/別ユーザー）と API Write 対象データ。`POST /api/v1/auth/token` で発行。 |
| DS-9 | MCP セッション（初期化済/未初期化/他ユーザー）。Inspector または `curl` で初期化。 |
| DS-10 | 境界/攻撃ペイロード（XSS、SQLi、パストラバーサル、巨大入力、不正拡張子、マルチバイト）。 |
| DS-11 | 受講者 100 名・履歴 10 万行・大容量ファイル（SQL/スクリプト生成）。 |

**復元**: 各ウェーブ終了時または破壊的検証後に、`Docs/dev/rollback-procedure.md` に従い DS-0 ベースラインへ戻し、次で一致を確認する。

```bash
docker exec irohaboard5-db-1 mariadb -uroot -prootpass irohaboard \
  -e "SELECT COUNT(*) AS users FROM ib_users; SELECT COUNT(*) AS records FROM ib_records;"
```

---

## 4. 実施フロー

### 4.1 全体（ウェーブ）

`06-risk-coverage-automation.md` §4 の W0〜W7 の順で実施する。W0（環境＋スモーク）→ W1〜W3（P0）→ W4（ジャーニー探索）→ W5〜W6（P1/P2・探索拡張）→ W7（回帰・復元・報告）。

### 4.2 1 項目の実施サイクル

1. **前提構築** — 項目の「前提」列どおりにロール・ログイン状態・DS を整える。
2. **実行** — 「手順」列の番号どおりに操作する。逸脱した場合は逸脱内容を記録する。
3. **実測** — 画面・HTTP ステータス・リダイレクト・DB 変化・ログを取得する。
4. **判定** — `OK` / `NG` / `N/A`。NG は「製品 / 既存 / 環境」を切り分ける（§7）。
5. **証跡** — §5 の規約で保存し、結果票にパスを記す。
6. **後片付け** — 作成したデータを戻す。状態を変えた場合はベースラインへ復元する。
7. **記録** — §6 の結果票に記入する。

### 4.3 非機械（探索）セッションの進め方

`05-e2e-exploratory.md` のチャーター単位で、**SBTM** として実施する。

1. **チャーター選定** — 対象チャーター（CH-xx）と時間枠（タイムボックス）を決める。
2. **準備** — 必要な DS・アカウント・環境を整え、開始状態を記録する。
3. **探索** — ヒューリスティクス（SFDPOT、FEW HICCUPPS、境界、状態遷移等）に沿って操作し、気づきを随時メモする。
4. **記録** — §6.2 のセッションシートに、バグ／質問／アイデアを時刻付きで残す。
5. **デブリーフ** — セッション要約・カバレッジ感（%目安）・次アクションを記す。
6. **トリアージ** — 見つけた問題を §8 で起票・判定する。

### 4.4 破壊的検証の扱い

- 原則**非本番**。DS-10（攻撃）や削除・初期化を伴う項目は、隔離環境またはスナップショット取得後に行う。
- 実行前に DB スナップショットを取得する:

```bash
docker exec irohaboard5-db-1 mariadb-dump -uroot -prootpass irohaboard > /tmp/irohaboard_before.sql
```

- 実行後は復元し、ベースライン一致を確認する。

---

## 5. 証跡取得の規約

| 種別 | 取得例 |
|---|---|
| 画面 | スクリーンショット / 画面録画（操作と結果が分かる範囲） |
| HTTP | `curl -i` の生ログ、ステータス・ヘッダ・body |
| データ | 操作前後の該当行（SELECT 結果） |
| ログ | `logs/error.log`、`ib_logs` の関連行 |
| 非機械 | セッションシート、観察メモ、a11y レポート |

- **命名**: `EV-<項目ID>-<連番>-<要約>.<ext>`（例: `EV-VR-AUTH-043-01-session-before.png`）。
- **保存先**: `Docs/verification/evidence/<wave>/<項目ID>/`。
- **PII**: 実在個人情報・パスワード・トークン値はマスクする。
- **メタデータ**: 実施日時・実施者・環境（コミットハッシュ）・DS 状態を結果票に必ず記す。

---

## 6. 記録シート

### 6.1 項目別結果票

| 列 | 内容 |
|---|---|
| 項目ID / 版 | `VR-*` / 計画の版 |
| 実施日 / 実施者 | 日時と担当 |
| 環境 | コミットハッシュ、URL、DS 状態 |
| 判定 | OK / NG / N/A |
| 実測 | 実際に観測した内容（文言・ステータス・DB 値） |
| 証跡 | `evidence/...` へのパス |
| 起票 | NG 時の欠陥 ID（§8） |
| 備考 | 逸脱・気づき |

### 6.2 E2E/探索セッションシート（`05` と整合）

| 欄 | 内容 |
|---|---|
| チャーター / 時間枠 | CH-xx、開始〜終了 |
| エリア / データ | 対象画面・DS |
| 実施内容メモ | 操作と気づき（時刻付き） |
| バグ / 質問 / アイデア | 分類して列挙 |
| カバレッジ感 | %目安と根拠 |
| 要約 / 次アクション | デブリーフ |

### 6.3 欠陥票

| 欄 | 内容 |
|---|---|
| タイトル / ID | 一行要約 |
| 重大度 | S1〜S4（§7） |
| 再現手順 | 最小手順、前提データ |
| 期待 / 実際 | 差分 |
| 証跡 | パス |
| 推定原因 | `file:line`（可能なら） |
| 影響 | 機能・データ・セキュリティ |
| 状態 | 新規 / 確認済 / 修正中 / 修正済 / 再検証済 / 却下 |

---

## 7. 判定と重大度

- **合否・リリース判定**: `README.md` §8 に従う（P0 製品起因 NG が 1 件でもあればリリース不可）。
- **重大度**: `05-e2e-exploratory.md` の S1〜S4 を用いる。優先度（P0〜P2）は計画上の重要度、重大度（S1〜S4）は実害の大きさであり、両者を併記する。

**切り分け**: NG は必ず「製品バグ / 既存由来 / 環境・設定起因 / テスト不備」に分類する。環境起因は証跡に環境情報を付して再現性を確認する。

---

## 8. 欠陥トリアージと再検証

1. **起票** — §6.3 の欠陥票を作成し、重大度・優先度を付す。
2. **トリアージ** — 検証責任者が重複・環境起因・仕様確認要を判定する。
3. **修正** — 修正担当が対応し、修正コミットを記録する。
4. **再検証** — 起票した項目を再実行し、期待結果を確認する。
5. **回帰** — 修正の影響範囲と、`Docs/test/` の該当 P0 を再実行する。
6. **クローズ** — 再検証 OK でクローズ。リスク受容の場合はその判断を残す。

---

## 9. 自動化の実行手順

```bash
cd /root/project/irohaboard
composer test                 # PHPUnit（MariaDB Docker port 13307。現在 587 テスト / 2961 アサーション）
composer cs-check             # コードスタイル（非ブロッキング）
vendor/bin/phpstan analyse    # 静的解析 level 8
vendor/bin/psalm              # 静的解析 level 2
composer coverage             # カバレッジ計測（pcov/xdebug 要。HTML レポート → build/coverage/）
```

- 失敗時は、テスト名と `file:line` を欠陥票に転記する。
- API/MCP スモーク（起動コンテナ相手）: `scripts/smoke-api.sh` / `scripts/smoke-mcp.sh`。
- **CI**: `.github/workflows/ci.yml` が push/PR で PHPUnit（ブロッキング）+ phpcs（非ブロッキング）を実行。PHPUnit は MariaDB 11.4 コンテナ（port 3306）で実行。
- **既知 deprecation 3 件**: `ContentsControllerTest` の `Query::order`、`AppController::beforeFilter` の return-value、`findList` の options array。

---

## 10. 中断・再開・復旧

| 事象 | 対応 |
|---|---|
| セッション中断 | セッションシートに中断理由・進捗・再開前提を記す |
| データ汚染 | `/tmp/irohaboard_before.sql` または `rollback-procedure.md` で復元し、ベースライン確認 |
| DB 破損 | `install` 再実行、またはマイグレーション再適用。手順は `rollback-procedure.md` |
| 環境障害 | `docker compose ... down` → `up -d`。改善しなければログ（`logs/`、`docker logs`）を取得して起票 |
| 検証不能 | `N/A` と理由を記録し、代替の証跡手段の有無を検討する |

---

## 11. 完了条件（Exit Criteria）と承認

`06-risk-coverage-automation.md` §5 を満たすこと。加えて:

- 全 P0 の結果が `OK` / `NG` / `N/A` のいずれかで記録されている。
- P0 の製品起因 NG が 0（または修正・再検証済み）。
- 証跡が §5 の規約で保存され、結果票から参照可能。
- DS がベースラインへ復元され、一致を確認済み。
- 未実施・N/A・リスク受容が明示されている。

| 承認 | 役割 | 日付 | 署名/記録 |
|---|---|---|---|
| 検証責任者 | | | |
| 記録者 | | | |

---

## 付録 A. コマンド集

```bash
# 環境
cd docker && docker compose -f docker-compose.cakephp5.yml up -d --build
docker compose -f docker-compose.cakephp5.yml ps

# ヘルスチェック
curl -si http://localhost:8082/users/login | head -20

# DB 操作
docker exec irohaboard5-db-1 mariadb -uroot -prootpass irohaboard -e 'SHOW TABLES;'

# API（トークン発行 → 利用）
curl -s -X POST http://localhost:8082/api/v1/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"..."}' | jq .
curl -s http://localhost:8082/api/v1/courses -H "Authorization: Bearer <selector:validator>" | jq .

# MCP（Inspector）
npx @modelcontextprotocol/inspector --server-url http://localhost:8082/mcp

# 証跡ディレクトリ
mkdir -p Docs/verification/evidence/{W0..W7}
```

## 付録 B. 実施前チェックリスト

- [ ] 環境が起動しヘルスチェックが通る
- [ ] 対象の DS が投入済みで、ベースラインを記録済み
- [ ] 必要なツール・アカウントが準備済み
- [ ] 証跡ディレクトリと結果票テンプレートを用意済み
- [ ] 破壊的項目ではスナップショットを取得済み
- [ ] 実施する項目 ID と期待結果をレビュー済み

## 付録 C. 報告フォーマット（要約）

1. 実施範囲（ウェーブ・項目数・実施率）
2. サマリ（OK / NG / N/A の件数、P0 の状況）
3. NG 一覧（重大度・影響・状態・証跡）
4. カバレッジと未実施理由
5. リスクと提言、リリース判定
