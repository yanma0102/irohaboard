# reconcile-migrations.sh

ダンプ/復元で構築された本番DBの `cake_migrations` テーブルを既存スキーマと照合し、
適用済みマイグレーションの記録を整備するスクリプト。

## 問題

ダンプ/復元で構築されたDBでは `cake_migrations` が空のため、
`bin/cake migrations migrate` が既存テーブルを再作成しようとして失敗する。

## 解決策

各マイグレーションの**適用済みスキーマを検証**し、検証にパスした分のみ
`cake_migrations` に記録（mark as applied）する。

## 事前準備

```bash
# 1. DB バックアップ（推奨）
mysqldump -h DB_HOST -u root -pDB_PASS irohaboard > backup_$(date +%Y%m%d_%H%M%S).sql

# 2. スクリプトをコンテナ内にコピー（Docker環境の場合）
docker cp Docker/bin/reconcile-migrations.sh <web_container>:/var/www/html/Docker/bin/
# またはホストの mysql クライアントから直接実行する場合はコピー不要
```

## 使い方

### 1. Dry-run（確認モード）

まず dry-run で検証結果を確認する:

```bash
# ホストから直接実行（mysql クライアントが必要）
DB_HOST=127.0.0.1 DB_PORT=13307 DB_USER=root DB_PASS=rootpass \
  ./Docker/bin/reconcile-migrations.sh --dry-run

# Docker コンテナ内で実行する場合
docker exec irohaboard5-web-1 bash -c '
  DB_HOST=db DB_PORT=3306 DB_USER=root DB_PASS=rootpass \
  /var/www/html/Docker/bin/reconcile-migrations.sh --dry-run
'
```

出力例:

```
[INFO]  Host: 127.0.0.1:13307  DB: irohaboard  User: root
[OK]    DB接続成功

════════════════════════════════════════════════════════
  Step 1: マイグレーションスキーマ検証
════════════════════════════════════════════════════════

[INFO]  [20260921235832] InitialSchema
[OK]    -> スキーマ検証パス（適用済みと判定）

[INFO]  [20260927000000] CreateConfigOverrides
[OK]    -> スキーマ検証パス（適用済みと判定）

[INFO]  [20260928000000] AddUniqueIndexesToJunctionTables
[OK]    -> スキーマ検証パス（適用済みと判定）

[INFO]  [20260929000000] AddIsActiveToUsers
[OK]    -> スキーマ検証パス（適用済みと判定）

════════════════════════════════════════════════════════
  Step 2: サマリー
════════════════════════════════════════════════════════

  合計:       4 件
  スキップ:   0 件（既に記録済み）
  適用対象:   4 件（検証パス → 記録予定）

════════════════════════════════════════════════════════
  Step 3: マイグレーション記録
════════════════════════════════════════════════════════

[INFO]  === DRY-RUN モード: 実際の INSERT は行いません ===

[INFO]  [INSERT予定] version=20260921235832 migration_name=InitialSchema
[INFO]  [INSERT予定] version=20260927000000 migration_name=CreateConfigOverrides
[INFO]  [INSERT予定] version=20260928000000 migration_name=AddUniqueIndexesToJunctionTables
[INFO]  [INSERT予定] version=20260929000000 migration_name=AddIsActiveToUsers

[INFO]  実際の適用は --dry-run を外して再実行してください。
```

### 2. 実行

dry-run の結果を確認し、問題なければ実行:

```bash
DB_HOST=127.0.0.1 DB_PORT=13307 DB_USER=root DB_PASS=rootpass \
  ./Docker/bin/reconcile-migrations.sh
```

### 3. 結果確認

```bash
# CakePHP の migrations status で全件 up になることを確認
bin/cake migrations status
```

出力例:

```
| Status | Version   | Migration Name                  |
|--------|-----------|---------------------------------|
| up     | 20260921235832 | InitialSchema               |
| up     | 20260927000000 | CreateConfigOverrides       |
| up     | 20260928000000 | AddUniqueIndexesToJunctionTables |
| up     | 20260929000000 | AddIsActiveToUsers          |
```

## 検証内容

| マイグレーション | 検証項目 |
|-----------------|---------|
| InitialSchema | 全16テーブルの存在 + 主要カラムの存在確認 |
| CreateConfigOverrides | ib_config_overrides テーブル + 全カラム + uk_config_key インデックス |
| AddUniqueIndexesToJunctionTables | uk_user_course / uk_user_group / uk_setting_key の一意インデックス存在 |
| AddIsActiveToUsers | ib_users.is_active カラムの存在 |

## 安全性

- **冪等**: 既に記録済みのバージョンは自動スキップ
- **破壊的操作なし**: DROP / TRUNCATE / DELETE 一切含まない
- **検証付き**: スキーマが不整合な場合は記録しない（ fail で表示）
- **dry-run**: 本番実行前に確認可能

## 本番環境での実行手順（oa-docker03）

```bash
# 1. DB バックアップ
docker exec iroha_db.1.xxxxx mariadb-dump -uroot -prootpass irohaboard > \
  /path/to/backup/irohaboard_$(date +%Y%m%d_%H%M%S).sql

# 2. スクリプトをホストにコピー
# （リポジトリから取得済みの場合）
cp Docker/bin/reconcile-migrations.sh /tmp/

# 3. dry-run 確認
docker exec iroha_web.1.xxxxx bash -c '
  DB_HOST=db DB_PORT=3306 DB_USER=root DB_PASS=rootpass \
  /var/www/html/Docker/bin/reconcile-migrations.sh --dry-run
'

# 4. 実行（web コンテナ内で実行）
docker exec iroha_web.1.xxxxx bash -c '
  DB_HOST=db DB_PORT=3306 DB_USER=root DB_PASS=rootpass \
  /var/www/html/Docker/bin/reconcile-migrations.sh
'

# 5. 確認
docker exec iroha_web.1.xxxxx bin/cake migrations status
```

## 環境変数

| 変数 | 既定値 | 説明 |
|------|--------|------|
| DB_HOST | localhost | データベースホスト |
| DB_PORT | 3306 | データベースポート |
| DB_NAME | irohaboard | データベース名 |
| DB_USER | root | ユーザー名 |
| DB_PASS | rootpass | パスワード |

## 依存関係

- `mysql` クライアント（ホストまたはコンテナ内）
- 対象DBに接続可能なネットワーク環境
