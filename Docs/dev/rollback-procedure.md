# CakePHP 2.10 → 5.x 移行 ロールバック手順書

## 目次

1. [前提条件・バックアップ確認](#1-前提条件バックアップ確認)
2. [フェーズ別ロールバック手順表](#2-フェーズ別ロールバック手順表)
3. [DB ロールバック詳細手順](#3-db-ロールバック詳細手順)
4. [ブランチ切替による復元手順](#4-ブランチ切替による復元手順)
5. [緊急時（本番）ロールバック手順](#5-緊急時本番ロールバック手順)
6. [ロールバック後の検証チェックリスト](#6-ロールバック後の検証チェックリスト)

---

## 1. 前提条件・バックアップ確認

### 1.1 ロールバックの基本方針

本移行プロジェクトでは、**既存の CakePHP 2 コードに一切変更を加えない** 方針で進める。移行作業は全て新規 CakePHP 5 プロジェクト上で行われるため、ロールバックは主に以下の方法で実現する。

- **ブランチ切替**: 現行 CakePHP 2 コードは `main`（または `cakephp2`）ブランチに保持
- **git snapshot**: Phase 0 で移行開始前のコミットを作成
- **DB バックアップ**: `mysqldump` / `mariadb-dump` による完全バックアップ

### 1.2 移行開始前に確認するもの

| # | 確認項目 | 確認方法 | OK 条件 |
|---|---|---|---|
| 1 | git snapshot の存在 | `git log --oneline -5` | Phase 0 開始前のコミットが存在する |
| 2 | DB バックアップファイル | `ls -la /backup/` | `irohaboard_before_migration.sql` が存在する |
| 3 | バックアップファイルの整合性 | `wc -l /backup/irohaboard_before_migration.sql` | 行数が妥当（空ファイルでない） |
| 4 | バックアップファイルの内容確認 | `head -50 /backup/irohaboard_before_migration.sql` | CREATE TABLE 文が含まれる |
| 5 | Docker 環境の起動確認 | `docker compose ps` | DB コンテナが起動している |
| 6 | ブランチの一覧確認 | `git branch -a` | `main` / `cakephp2` ブランチが存在する |

### 1.3 バックアップ作成コマンド（Phase 0 で実行済みであること）

```bash
# DB バックアップ（MySQL 5.7 側で実行）
mysqldump -u root -p --default-character-set=utf8 irohaboard > /backup/irohaboard_before_migration.sql

# バックアップの検証
wc -l /backup/irohaboard_before_migration.sql
head -50 /backup/irohaboard_before_migration.sql

# git snapshot（移行開始前のコミット）
git add -A
git commit -m "Phase 0: CakePHP 2 snapshot before migration"
```

---

## 2. フェーズ別ロールバック手順表

| フェーズ | ロールバック不要要件 | ロールバック方法 | 想定時間 |
|---|---|---|---|
| **Phase 0** | 既存コードに変更なし | git snapshot からブランチを戻す | 即時 |
| **Phase 1** | CakePHP 5 の新規プロジェクトにのみ適用 | ブランチ切替で復元 | 即時 |
| **Phase 2** | 現行 Model ファイル変更なし | `src/Model/Table/` ディレクトリ削除 | 即時 |
| **Phase 3** | 現行 Controller ファイル変更なし | `src/Controller/` ディレクトリ削除 | 即時 |
| **Phase 4** | 現行 View ファイル変更なし | `templates/` ディレクトリ削除 | 即時 |
| **Phase 5** | DB 変更あり | MariaDB 11.4 コンテナ起動 → バックアップリストア | 10–30 分 |
| **Phase 6** | 本番デプロイ後 | Docker コンテナ旧バージョン切替 + DB ロールバック | 10–60 分 |

---

## 3. DB ロールバック詳細手順

### 3.1 Phase 5 の DB ロールバック（MariaDB 11.4 → MySQL 5.7 復元）

Phase 5 で MariaDB 11.4 にデータ移行を行った場合、元の MySQL 5.7 に戻す手順。

#### Step 1: 現在の MariaDB 11.4 の状態をバックアップ

```bash
# MariaDB 11.4 のデータをバックアップ（念のため）
mariadb-dump -u root -p irohaboard > /backup/irohaboard_rollback_mariadb.sql
```

#### Step 2: MariaDB コンテナを停止

```bash
docker compose down db
```

#### Step 3: MySQL 5.7 コンテナを起動

```bash
# docker-compose.yml の DB サービスを MySQL 5.7 に切り替えて起動
# または、別途 docker-compose.override.yml で上書き

# 方法 A: docker-compose.yml の image を一時的に変更
# services:
#   db:
#     image: mysql:5.7

docker compose up -d db
```

#### Step 4: MySQL 5.7 でデータベースを作成

```bash
# MySQL 5.7 のコンテナでデータベースを作成
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS irohaboard CHARACTER SET utf8 COLLATE utf8_general_ci;"
```

#### Step 5: 元のバックアップをリストア

```bash
# Phase 0 で作成したバックアップをリストア
mysql -u root -p irohaboard < /backup/irohaboard_before_migration.sql
```

#### Step 6: データ整合性の確認

```bash
# テーブル数の確認
mysql -u root -p -e "SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = 'irohaboard';"

# レコード数の確認（各テーブル）
mysql -u root -p -e "
SELECT 'ib_users' AS tbl, COUNT(*) AS cnt FROM ib_users
UNION ALL SELECT 'ib_courses', COUNT(*) FROM ib_courses
UNION ALL SELECT 'ib_contents', COUNT(*) FROM ib_contents
UNION ALL SELECT 'ib_records', COUNT(*) FROM ib_records
UNION ALL SELECT 'ib_groups', COUNT(*) FROM ib_groups;
"
```

### 3.2 Phase 5 の部分ロールバック（DB のみ復元、コードは維持）

Phase 5 のテストで問題が発生したが、Phase 1–4 のコードは正常な場合。

```bash
# Step 1: MariaDB のデータをバックアップ
mariadb-dump -u root -p irohaboard > /backup/irohaboard_phase5_rollback.sql

# Step 2: MariaDB コンテナを再起動（データクリア）
docker compose down -v db
docker compose up -d db

# Step 3: Phase 0 のバックアップをリストア（MySQL 5.7 ダンプを MariaDB にリストア）
# ※ MySQL 5.7 のダンプは MariaDB 11.4 にリストア可能（互換性あり）
mariadb -u root -p irohaboard < /backup/irohaboard_before_migration.sql

# Step 4: utf8mb4 変換を再実行（必要に応じて）
# ALTER TABLE ib_users_groups CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
# ...（全 16 テーブル）
```

### 3.3 utf8mb4 変換のロールバック

utf8mb4 変換は **元に戻せない**（4 バイト文字が含まれる場合、utf8 に変換するとデータが切り捨てられる）。utf8mb4 変換をロールバックする場合は、Phase 0 のバックアップ（utf8 の状態）からリストアする。

```bash
# utf8mb4 → utf8 の直接変換は避ける
# 代わりに Phase 0 のバックアップからリストア

# MariaDB コンテナを再起動（データクリア）
docker compose down -v db
docker compose up -d db

# Phase 0 のバックアップをリストア
mariadb -u root -p irohaboard < /backup/irohaboard_before_migration.sql
```

---

## 4. ブランチ切替による復元手順

### 4.1 ブランチ戦略

| ブランチ | 内容 | 用途 |
|---|---|---|
| `main` | 現行 CakePHP 2 コード（変更なし） | ロールバック時の復元元 |
| `cakephp5-migration` | CakePHP 5 移行作業ブランチ | 移行作業の実行 |
| `phase-N` | 各フェーズごとの作業ブランチ（任意） | 個別フェーズの切り出し |

### 4.2 ブランチ切替でのロールバック

```bash
# Phase 1–4 のロールバック（CakePHP 5 コードを破棄）

# Step 1: 作業ブランチを破棄（CakePHP 5 のコードが消える）
git checkout main
git branch -D cakephp5-migration

# Step 2: 新しい作業ブランチを作成
git checkout -b cakephp5-migration main

# Step 3: Phase 0 の snapshot から再開
git log --oneline -5  # Phase 0 のコミットを確認
git checkout <phase0-commit-hash>
git checkout -b cakephp5-migration-v2
```

### 4.3 特定フェーズからの再開

```bash
# Phase 2 から再開する場合（Phase 0–1 は正常）

# Step 1: Phase 1 完了時のコミットを確認
git log --oneline | grep "Phase 1"

# Step 2: Phase 1 完了時にブランチを作成
git checkout -b cakephp5-from-phase1 <phase1-complete-commit>

# Step 3: Phase 2 を再開
```

---

## 5. 緊急時（本番）ロールバック手順

### 5.1 ロールバック判断基準

| 判断基準 | ロールバック要否 |
|---|---|
| ログイン機能が動作しない | **即時ロールバック** |
| データ整合性に不整合がある | **即時ロールバック** |
| API が 5xx エラーを返す | **即時ロールバック** |
| UI の表示崩れ（致命的） | **即時ロールバック** |
| UI の表示崩れ（軽微） | 修正対応を検討 |
| パフォーマンス劣化（許容範囲内） | 修正対応を検討 |

### 5.2 本番環境のロールバック手順

#### Step 1: ロールバックの意思決定・連絡

```bash
# チームにロールバックを宣言
# チャット/メールでロールバック開始を通知
```

#### Step 2: アプリケーションの無効化（任意）

```bash
# 緊急時にアプリケーションを一時的に無効化
# Apache の設定でメンテナンスページを表示

# 方法 A: .htaccess でメンテナンスモードに切り替え
echo "RewriteEngine On\nRewriteRule .* /maintenance.html [R=503,L]" > .htaccess

# 方法 B: Docker コンテナを停止
docker compose down app
```

#### Step 3: Docker コンテナの旧バージョンへの切替

```bash
# Step 3-1: 現在の docker-compose.yml をバックアップ
cp docker-compose.yml docker-compose.yml.bak

# Step 3-2: docker-compose.yml を CakePHP 2 の設定に戻す
# （事前にバックアップしておいた旧 docker-compose.yml を復元）
cp docker-compose.yml.cakephp2 docker-compose.yml

# Step 3-3: コンテナを再起動
docker compose down
docker compose up -d
```

#### Step 4: DB のロールバック

```bash
# Phase 5 の DB ロールバック手順（§3.1）に従う

# Step 4-1: MariaDB コンテナを停止
docker compose down db

# Step 4-2: MySQL 5.7 コンテナを起動（docker-compose.yml の DB 設定を戻す）
docker compose up -d db

# Step 4-3: MySQL 5.7 でデータベースを作成
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS irohaboard CHARACTER SET utf8 COLLATE utf8_general_ci;"

# Step 4-4: 元のバックアップをリストア
mysql -u root -p irohaboard < /backup/irohaboard_before_migration.sql
```

#### Step 5: アプリケーションの起動・確認

```bash
# Step 5-1: アプリケーションコンテナを起動
docker compose up -d app

# Step 5-2: ログイン機能の確認
curl -I http://localhost/login

# Step 5-3: ヘルスチェック
docker compose ps
docker compose logs --tail=50 app
```

#### Step 6: ロールバック完了確認

```bash
# Step 6-1: チームにロールバック完了を通知
# Step 6-2: ロールバックの記録を作成
```

---

## 6. ロールバック後の検証チェックリスト

### 6.1 共通検証項目（全フェーズ共通）

| # | 検証項目 | 確認方法 | OK 条件 |
|---|---|---|---|
| 1 | git ブランチの状態 | `git branch -v` | `main` ブランチに復元されている |
| 2 | コードの状態 | `git diff HEAD` | 変更ファイルがない |
| 3 | DB の起動状態 | `docker compose ps` | DB コンテナが起動している |
| 4 | DB のバージョン | DB に接続して `SELECT VERSION()` | 期待するバージョンが返る |
| 5 | アプリケーションの起動 | ブラウザでアクセス | ログイン画面が表示される |
| 6 | ログイン機能 | ログイン操作 | 正常にログインできる |
| 7 | データ整合性 | `SELECT COUNT(*) FROM 各テーブル` | ロールバック前と一致する |

### 6.2 フェーズ別の追加検証項目

#### Phase 0 ロールバック後

| # | 検証項目 | 確認方法 | OK 条件 |
|---|---|---|---|
| P0-1 | CakePHP 5 の新規プロジェクトが存在しない | `ls app/` | `app/` ディレクトリがない（または空） |
| P0-2 | Docker 環境が停止している | `docker compose ps` | コンテナが停止している |

#### Phase 1 ロールバック後

| # | 検証項目 | 確認方法 | OK 条件 |
|---|---|---|---|
| P1-1 | `config/app.php` が存在しない | `ls config/app.php` | ファイルがない |
| P1-2 | `src/Application.php` が存在しない | `ls src/Application.php` | ファイルがない |
| P1-3 | `config/routes.php` が存在しない | `ls config/routes.php` | ファイルがない |

#### Phase 2 ロールバック後

| # | 検証項目 | 確認方法 | OK 条件 |
|---|---|---|---|
| P2-1 | `src/Model/Table/` が存在しない | `ls src/Model/Table/` | ディレクトリがない |
| P2-2 | 現行 Model ファイルが変更されていない | `git diff HEAD -- Model/` | 変更がない |

#### Phase 3 ロールバック後

| # | 検証項目 | 確認方法 | OK 条件 |
|---|---|---|---|
| P3-1 | `src/Controller/` が存在しない | `ls src/Controller/` | ディレクトリがない |
| P3-2 | 現行 Controller ファイルが変更されていない | `git diff HEAD -- Controller/` | 変更がない |

#### Phase 4 ロールバック後

| # | 検証項目 | 確認方法 | OK 条件 |
|---|---|---|---|
| P4-1 | `templates/` が存在しない | `ls templates/` | ディレクトリがない |
| P4-2 | 現行 View ファイルが変更されていない | `git diff HEAD -- View/` | 変更がない |

#### Phase 5 ロールバック後

| # | 検証項目 | 確認方法 | OK 条件 |
|---|---|---|---|
| P5-1 | DB のバージョンが MySQL 5.7 | `SELECT VERSION()` | `5.7.x` |
| P5-2 | テーブル数が 16 | `SHOW TABLES` | 16 テーブル |
| P5-3 | 文字セットが utf8 | `SHOW CREATE TABLE ib_users` | `utf8` |
| P5-4 | レコード数の整合 | `SELECT COUNT(*) FROM 各テーブル` | 移行前と一致 |
| P5-5 | GROUP BY の動作 | 各モデルのクエリを実行 | エラーなし |

#### Phase 6 ロールバック後（本番）

| # | 検証項目 | 確認方法 | OK 条件 |
|---|---|---|---|
| P6-1 | 本番環境でログイン可能 | ブラウザでログイン操作 | 正常にログインできる |
| P6-2 | 全画面が表示される | ブラウザで各画面を確認 | 表示崩れがない |
| P6-3 | API の動作確認 | curl / Postman で API テスト | 全エンドポイントが正常 |
| P6-4 | データ整合性 | 管理画面でデータ確認 | データが一致している |
| P6-5 | セキュリティヘッダー | ブラウザの開発者ツールで確認 | 正しいヘッダーが設定されている |
| P6-6 | ログの出力 | ログファイルを確認 | 正常にログが出力されている |

---

## 付録 A: ロールバックコマンド早見表

### A.1 git 関連

```bash
# Phase 0 の snapshot に戻る
git checkout main
git log --oneline -5  # Phase 0 のコミットを確認
git reset --hard <phase0-commit-hash>

# ブランチの削除（CakePHP 5 のコードを破棄）
git checkout main
git branch -D cakephp5-migration

# 新しいブランチの作成
git checkout -b cakephp5-migration-v2 main
```

### A.2 Docker 関連

```bash
# 全コンテナの停止・削除
docker compose down -v

# DB コンテナのみ停止
docker compose down db

# DB コンテナの再起動（データクリア）
docker compose down -v db
docker compose up -d db

# コンテナの状態確認
docker compose ps

# コンテナのログ確認
docker compose logs --tail=100 app
docker compose logs --tail=100 db
```

### A.3 DB 関連

```bash
# MySQL 5.7 にバックアップをリストア
mysql -u root -p irohaboard < /backup/irohaboard_before_migration.sql

# MariaDB 11.4 にバックアップをリストア
mariadb -u root -p irohaboard < /backup/irohaboard_before_migration.sql

# テーブル数の確認
mysql -u root -p -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'irohaboard';"

# レコード数の確認
mysql -u root -p -e "SELECT COUNT(*) FROM ib_users;"
mysql -u root -p -e "SELECT COUNT(*) FROM ib_courses;"
mysql -u root -p -e "SELECT COUNT(*) FROM ib_records;"
```

---

## 付録 B: ロールバック時の注意事項

| # | 注意事項 | 内容 |
|---|---|---|
| 1 | データ損失 | Phase 5 以降で追加されたデータは、Phase 0 のバックアップから復元すると失われる |
| 2 | utf8mb4 → utf8 | utf8mb4 のデータを utf8 に変換すると、4 バイト文字（絵文字等）が切り捨てられる |
| 3 | ツールの互換性 | MySQL 5.7 のダンプは MariaDB 11.4 にリストア可能。逆も基本可能だが、要確認 |
| 4 | コンテナの永続化 | `docker compose down -v` でボリュームが削除される。データはバックアップファイルから復元 |
| 5 | 設定ファイルの復元 | `docker-compose.yml`、`config/app.php` 等の設定ファイルも復元が必要 |
| 6 | セッションの破棄 | ロールバック後、ユーザーのセッションは無効になる。再ログインが必要 |
| 7 | キャッシュのクリア | CakePHP のキャッシュをクリア。`tmp/cache/` ディレクトリを削除 |

---

## 付録 C: ロールバック実行時の連絡事項テンプレート

```
【ロールバック実行通知】

■ 実行日時: YYYY/MM/DD HH:MM
■ ロールバック対象フェーズ: Phase N
■ ロールバック理由: [具体的な理由]
■ 影響範囲: [影響する機能・画面]
■ 予定復旧時刻: YYYY/MM/DD HH:MM
■ 担当者: [担当者名]

■ 実行手順:
1. [手順1]
2. [手順2]
3. [手順3]

■ 検証結果:
- [ ] ログイン機能: OK / NG
- [ ] 全画面表示: OK / NG
- [ ] API 動作: OK / NG
- [ ] データ整合性: OK / NG
```
