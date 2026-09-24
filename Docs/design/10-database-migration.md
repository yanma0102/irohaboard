# 10 — DB 移行設計

## 1. 概要

MySQL 5.7 → MariaDB 11.4 LTS へのデータベース移行と、CakePHP 5.x との統合設計を定義する。

| 項目 | 現行 | 移行先 |
|---|---|---|
| DBMS | MySQL 5.7 | **MariaDB 11.4 LTS** |
| 文字セット | utf8（utf8mb3） | **utf8mb4** |
| カラム設定 | utf8mb3 | utf8mb4_unicode_ci |
| 認証方式 | `mysql_native_password` | `mysql_native_password`（MariaDB 前提に詳細は §2.1） |
| sql_mode | デフォルト | `ONLY_FULL_GROUP_BY` 対応が必要（MariaDB 10.2+ 既定有効） |

### MariaDB 11.4 LTS 概要

| 項目 | 値 |
|---|---|
| リリース | GA 2024-05-29 |
| Community EOL | 2029-05-29 |
| Docker イメージ | `mariadb:11.4` |
| CakePHP 5.x サポート | MariaDB 10.1+ を公式サポート |
| PDO ドライバ | `Cake\Database\Driver\Mysql`（MySQL と同一。設定は MySQL と同じ） |

根拠: `Docs/design/README.md:76-79`, `Docs/cakephp5-migration-spec.md:79-85`, `Config/database.php:15`

---

## 2. MySQL 5.7 → MariaDB 11.4 LTS 移行

### 2.1 認証方式

**MariaDB 11.4 LTS では `caching_sha2_password` は使用しない。** `caching_sha2_password` は MySQL 8.0 以降の固有機能であり、MariaDB には存在しない。

| 項目 | MySQL 8.x | MariaDB 11.4 |
|---|---|---|
| 既定認証プラグイン | `caching_sha2_password` | `unix_socket`（10.4+） |
| `mysql_native_password` | 使用可能（非推奨方向） | 使用可能（PHP/PDO 接続向けに利用可） |

MariaDB の既定認証は `unix_socket` であり、ソケット経由のローカル接続ではパスワード不要で接続可能。PHP/PDO による TCP 接続では `mysql_native_password` を使用する。MariaDB 前提では MySQL 8 で問題になった `caching_sha2_password` による接続失敗リスクは基本無い。

#### CakePHP 5 の config/app.php での設定

CakePHP 5 の Datasources 設定で特別な認証関連のフラグ指定は不要。MySQL と同じ `Cake\Database\Driver\Mysql` ドライバをそのまま使用する。

```php
// Config/database.php:7-16
'Datasources' => [
    'default' => [
        'className' => \Cake\Database\Connection::class,
        'driver' => \Cake\Database\Driver\Mysql::class,
        'host' => env('DB_HOST', 'localhost'),
        'username' => env('DB_USER', 'root'),
        'password' => env('DB_PASS', ''),
        'database' => env('DB_NAME', 'irohaboard'),
        'encoding' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => 'ib_',
        'persist' => true,
    ],
],
```

根拠: `Config/database.php:7-16`, `Docs/design/README.md:77-79`

### 2.2 `sql_mode` の変更 — `ONLY_FULL_GROUP_BY` 対応

MariaDB 10.2+ でも `ONLY_FULL_GROUP_BY` は既定で有効。MySQL 8 と同様に、`GROUP BY` 句に含まれないカラムを `SELECT` または `ORDER BY` で使用したクエリがエラーになる。

> **MariaDB と MySQL の違い**: MariaDB は関数従属性（functional dependency）の判定が MySQL より緩い場合がある。主キーを `GROUP BY` する場合、MySQL では追加カラムの参照が許容されるが、MariaDB では常にエラーになることがある。上記 §4.3 の Info.php 修正がこの例に該当する。

#### 影響範囲（7 箇所）

| # | ファイル | 行 | クエリの概要 | 修正要否 |
|---|---|---|---|---|
| 1 | `Model/UsersCourse.php` | :71 | `GROUP BY h.course_id, h.user_id` | **不要**（全カラムが GROUP BY に含まれる） |
| 2 | `Model/UsersCourse.php` | :86 | `GROUP BY r.course_id, r.content_id` | **不要**（サブクエリ内の集計） |
| 3 | `Model/UsersCourse.php` | :87 | `GROUP BY course_id` | **不要**（`COUNT(*)` のみ） |
| 4 | `Model/UsersCourse.php` | :94 | `GROUP BY course_id` | **不要**（`COUNT(*)` のみ） |
| 5 | `Model/Content.php` | :138 | `GROUP BY h.content_id` | **不要**（サブクエリ内の集計） |
| 6 | `Model/Content.php` | :151 | `GROUP BY r.content_id` | **要検証**（`1 as is_complete` のみ。MySQL では不要だが MariaDB での判定を要確認） |
| 7 | `Model/Info.php` | :119 | `GROUP BY Info.id` + `ORDER BY Info.created desc` | **修正必要** |

根拠: `Model/UsersCourse.php:61-99`, `Model/Content.php:114-167`, `Model/Info.php:110-144`

### 2.3 `NO_ZERO_DATE` / `NO_ZERO_IN_DATE` の影響

MariaDB 10.2+ のデフォルト `sql_mode` には `NO_ZERO_DATE` と `NO_ZERO_IN_DATE` が含まれる。これにより `'0000-00-00 00:00:00'` や `'2024-00-00'` のような日付値を挿入/更新できなくなる。

#### 影響範囲

| テーブル | カラム | 現状の NULL 許容 | 影響 |
|---|---|---|---|
| `ib_users` | `last_logined`, `started`, `ended`, `deleted` | `DEFAULT NULL` | **影響なし**（NULL は許容される） |
| `ib_records` | `created` | `NOT NULL` | **影響なし**（`NOT NULL` だがデフォルト値の設定を要確認） |
| `ib_settings` | — | — | **影響なし**（日付カラムなし） |
| その他 | `created`, `modified` | `DEFAULT NULL` | **影響なし**（NULL は許容される） |

> **注意**: 現行の `app.sql` では `datetime` カラムはすべて `DEFAULT NULL` または `NOT NULL` で定義されており、ゼロ日付は使用されていない。ただし、既存データにゼロ日付が含まれる可能性があるため、移行前にデータ確認が必要。

根拠: `Config/Schema/app.sql:1-277`

---

## 3. utf8mb4 変換

### 3.1 MariaDB における文字セットの既定値

MariaDB 11.x では新規データベースの既定文字セットが `utf8mb4`。既存データ（MySQL 5.7 起点）は `utf8`（`utf8mb3`）で格納されているため、**utf8mb4 への変換は引き続き必要**。

| 項目 | MariaDB 11.4 の既定 |
|---|---|
| 新規 DB の既定文字セット | `utf8mb4` |
| 新規 DB の既定照合順序 | `utf8mb4_general_ci` |
| 移行時の変換先 | `utf8mb4_unicode_ci`（既存の照合順序を維持） |

### 3.2 変換対象テーブル（全 16 テーブル）

| # | テーブル名 | 現行の文字セット | 移行先 |
|---|---|---|---|
| 1 | `ib_users_groups` | utf8 | utf8mb4 |
| 2 | `ib_users_courses` | utf8 | utf8mb4 |
| 3 | `ib_users` | utf8 | utf8mb4 |
| 4 | `ib_settings` | utf8 | utf8mb4 |
| 5 | `ib_records_questions` | utf8 | utf8mb4 |
| 6 | `ib_records` | utf8 | utf8mb4 |
| 7 | `ib_infos` | utf8 | utf8mb4 |
| 8 | `ib_infos_groups` | utf8 | utf8mb4 |
| 9 | `ib_groups` | utf8 | utf8mb4 |
| 10 | `ib_groups_courses` | utf8 | utf8mb4 |
| 11 | `ib_courses` | utf8 | utf8mb4 |
| 12 | `ib_contents_questions` | utf8 | utf8mb4 |
| 13 | `ib_logs` | utf8 | utf8mb4 |
| 14 | `ib_contents` | utf8 | utf8mb4 |
| 15 | `ib_user_tokens` | utf8 | utf8mb4 |
| 16 | `ib_cake_sessions` | utf8 | utf8mb4 |

根拠: `Config/Schema/app.sql:1-277`（全テーブルが `DEFAULT CHARSET=utf8`）

### 3.3 変換 SQL

```sql
-- 全テーブルの utf8mb4 変換
ALTER TABLE ib_users_groups    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_users_courses  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_users          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_settings       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_records_questions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_records        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_infos          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_infos_groups   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_groups         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_groups_courses CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_courses        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_contents_questions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_logs           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_contents       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_user_tokens    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_cake_sessions  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

> **注意**: `CONVERT TO CHARACTER SET` はテーブル全体と全インデックスを再構築する。大きなテーブルでは時間がかかる。`ib_records`（学習履歴）や `ib_contents`（コンテンツ）が最大のテーブルとなる可能性がある。

### 3.4 database.php / config/app.php の encoding 変更

```php
// 現行: Config/database.php:15
'encoding' => 'utf8'

// 移行先: config/app.php
'encoding' => 'utf8mb4'
```

根拠: `Config/database.php:15`, `Docs/design/README.md:77-79`

### 3.5 LIKE 演算子の BIGINT カラム

`utf8mb4` 変換後、`varchar` カラムのインデックス長が制限される可能性がある。CakePHP 5 では `utf8mb4` を使う場合、インデックスのプレフィックス長を指定する必要がある:

```sql
-- 例: ib_users.username のユニークキー
ALTER TABLE ib_users DROP INDEX login_id;
ALTER TABLE ib_users ADD UNIQUE KEY login_id (username(191));
```

現行の `app.sql` では `varchar(50)` 程度のカラムが多いため、191 文字の制限は問題にならない。ただし `varchar(2000)`（`ib_contents_questions.options` 等）のカラムはインデックスに含まれていないため影響なし。

根拠: `Config/Schema/app.sql:190`（`varchar(2000)`）、`Config/Schema/app.sql:50`（`varchar(50)`）

---

## 4. GROUP BY 修正（6 箇所 + 1 箇所）

### 4.1 UsersCourse.php（4 箇所）— 修正不要

**ファイル**: `Model/UsersCourse.php`

#### 行 71: Record サブクエリ

```sql
-- 現行（:71）
GROUP BY h.course_id, h.user_id
```

**分析**: `SELECT` するカラムは `h.course_id`, `h.user_id`, `MAX(...)`, `MIN(...)` のみ。`h.course_id` と `h.user_id` は `GROUP BY` に含まれる。

**結論**: MariaDB 11.4 でも **修正不要**。

#### 行 86-87: CompleteCount サブクエリ

```sql
-- 現行（:86-87）
GROUP BY r.course_id, r.content_id) as c
GROUP BY course_id
```

**分析**: 内側のサブクエリでは `r.course_id`, `r.content_id` のみを `GROUP BY` し、外側では `course_id` のみで `COUNT(*)` を集計。問題なし。

**結論**: **修正不要**。

#### 行 94: ContentCount サブクエリ

```sql
-- 現行（:94）
GROUP BY course_id
```

**分析**: `SELECT` は `course_id`, `COUNT(*)` のみ。`course_id` は `GROUP BY` に含まれる。

**結論**: **修正不要**。

### 4.2 Content.php（2 箇所）— 1 箇所は要検証

**ファイル**: `Model/Content.php`

#### 行 138: Record サブクエリ

```sql
-- 現行（:138）
GROUP BY h.content_id
```

**分析**: `SELECT` するカラムは `h.content_id`, `MAX(...)`, `MIN(...)`, `MAX(id)`, `SUM(...)`, `COUNT(*)` のみ。`h.content_id` は `GROUP BY` に含まれる。

**結論**: MariaDB 11.4 でも **修正不要**。

#### 行 151: CompleteRecord サブクエリ

```sql
-- 現行（:151）
GROUP BY r.content_id
```

**分析**: `SELECT` は `r.content_id`, `1 as is_complete` のみ。`r.content_id` は `GROUP BY` に含まれる。`1 as is_complete` は定数。

**結論**: MariaDB 11.4 では原則 **修正不要**（定数の参照は許容される）。ただし、MariaDB の関数従属性判定の微妙な差異が影響する可能性があるため、**移行後に動作確認が必要**。

### 4.3 Info.php（1 箇所）— **修正必要**

**ファイル**: `Model/Info.php:110-122`

```sql
-- 現行（:110-122）
SELECT
    Info.id
FROM
    ib_infos AS Info
    LEFT OUTER JOIN ib_infos_groups AS InfoGroup ON ( Info.id = InfoGroup.info_id ) 
WHERE
    InfoGroup.group_id IS NULL 
    OR InfoGroup.group_id IN ( SELECT group_id FROM ib_users_groups WHERE user_id = :user_id ) 
GROUP BY
    Info.id
ORDER BY Info.created desc
```

**問題**: `SELECT Info.id` + `GROUP BY Info.id` は正しいが、`ORDER BY Info.created desc` が `ONLY_FULL_GROUP_BY` でエラーになる。`Info.created` は `GROUP BY` に含まれておらず、集計関数でもないため。MySQL 5.7 では主キーの関数従属性で許容されていたが、MariaDB 10.2+ では MySQL より厳格に判定する場合がある。

**修正案**:

```sql
-- 修正後
SELECT
    Info.id
FROM
    ib_infos AS Info
    LEFT OUTER JOIN ib_infos_groups AS InfoGroup ON ( Info.id = InfoGroup.info_id ) 
WHERE
    InfoGroup.group_id IS NULL 
    OR InfoGroup.group_id IN ( SELECT group_id FROM ib_users_groups WHERE user_id = :user_id ) 
GROUP BY
    Info.id, Info.created
ORDER BY Info.created desc
```

> **説明**: `GROUP BY` に `Info.created` を追加する。`Info.id` は主キーのため、`Info.created` を追加してもグループの粒度は変わらない（1 レコード = 1 グループ）。

根拠: `Model/Info.php:119`

### 4.4 CakePHP 5 移行後の修正

CakePHP 5 に移行後、raw SQL はそのまま `Connection::execute()` または `Table::query()` で実行するため（`Docs/design/README.md:119-122`）、GROUP BY の修正は SQL のみで対応可能。

---

## 5. コードベース互換性調査結果

### 5.1 そのまま動作するもの

以下の構文・機能は MariaDB 11.4 で完全に互換がある。アプリコードの修正は不要。

| 分類 | 対象 | 根拠 |
|---|---|---|
| カラム型 | `int`, `varchar`, `datetime`, `text`, `decimal` 等 | MariaDB は MySQL のカラム型をすべてサポート |
| ENGINE | `InnoDB`（`app.sql` で明示） | MariaDB 11.4 の既定は InnoDB |
| AUTO_INCREMENT | 全テーブルで使用 | 互換 |
| INDEX | 全インデックス定義 | 互換 |
| `SET FOREIGN_KEY_CHECKS` | `app.sql` で使用 | 互換 |
| `ALTER TABLE` | `app.sql`, `update.sql` で使用 | 互換 |
| `INSERT/UPDATE/DELETE` | 全クエリ | 互換 |
| `DATE_FORMAT()` | `UsersCourse.php` で使用 | 互換 |
| `IFNULL()` | `UsersCourse.php` で使用 | 互換 |
| `COUNT()`, `MIN()`, `MAX()`, `SUM()` | 全モデル | 互換 |
| `FIELD()` | `Content.php` で `ORDER BY FIELD()` 使用 | 互換 |
| `rand()` | `Content.php` で `ORDER BY rand()` 使用 | 互換 |
| `group_concat()` | `UsersCourse.php` で使用 | 互換 |
| サブクエリ | 全モデル | 互換 |
| `INNER JOIN` / `LEFT OUTER JOIN` | 全モデル | 互換 |
| `DELETE ... IN (SELECT ...)` | 未使用だが構文互換 | 互換 |
| `ORDER BY FIELD/rand` | `Content.php` で使用 | 互換 |

### 5.2 要検証（移行後に動作確認が必要）

| # | ファイル:行 | 内容 | 理由 |
|---|---|---|---|
| 1 | `Model/Info.php:119` | `GROUP BY Info.id` + `ORDER BY Info.created desc` | MariaDB の `ONLY_FULL_GROUP_BY` でエラーの可能性。§4.3 で修正済み |
| 2 | `Model/Content.php:151` | `GROUP BY r.content_id` + `SELECT 1 as is_complete` | 定数の参照は原則許容されるが、MariaDB での判定を要確認 |

### 5.3 修正不要（MySQL 固有機能が未使用）

以下の MySQL 8.x 固有機能は本コードベースで未使用のため、 MariaDB 移行で問題とならない。

| 機能 | 状況 |
|---|---|
| JSON 型 | 未使用 |
| ウィンドウ関数 | 未使用 |
| CTE（`WITH` 句） | 未使用 |
| `WITH ROLLUP` | 未使用 |
| `ON DUPLICATE KEY UPDATE` | 未使用 |
| パーティション | 未使用 |
| FULLTEXT インデックス | 未使用 |
| GTID | 未使用（MariaDB と MySQL の GTID は非互換だが本件では影響なし） |

### 5.4 予約語の扱い

| 予約語 | MySQL 8.x | MariaDB 11.4 | 影響 |
|---|---|---|---|
| `groups` | 予約語 | 予約語（10.2+、Window Functions 由来） | **実害なし** — ORM がバッククォートする |
| `comment` | 予約語 | **予約語ではない** | なし |
| `status` | 予約語 | **予約語ではない** | なし |
| `options` | 予約語 | **予約語ではない** | なし |

---

## 6. update.sql の不整合修正

### 6.1 不整合の内容

`Config/Schema/update.sql:37` に以下の INDEX 作成文がある:

```sql
ALTER TABLE ib_records ADD INDEX idx_group_course_user_content_id(group_id, course_id, user_id, content_id);
```

しかし、`ib_records` テーブルには `group_id` カラムが存在しない。

根拠: `Config/Schema/app.sql:82-99`（`ib_records` の定義に `group_id` なし）、`Config/Schema/update.sql:37`

### 6.2 修正方針（2択）

| 方案 | 内容 | 利点 | 欠点 |
|---|---|---|---|
| **A: app.sql に group_id カラムを追加** | `ALTER TABLE ib_records ADD COLUMN group_id int(8) DEFAULT NULL` | INDEX が正常に動作 | 不要なカラムが追加される |
| **B: update.sql の該当行を削除** | `update.sql:37` の行を削除 | 不要なカラムの追加を回避 | INDEX が作成されない |

**推奨**: 方案 B — `ib_records` に `group_id` が存在しないことは設計上の意図であるため（学習履歴はコース＋ユーザ＋コンテンツで特定）、INDEX の行を削除する。

### 6.3 修正後の update.sql

```sql
-- 削除する行:
-- ALTER TABLE ib_records ADD INDEX idx_group_course_user_content_id(group_id, course_id, user_id, content_id);

-- 代わりに以下の INDEX を維持:
ALTER TABLE ib_records ADD INDEX idx_course_user_content_id(course_id, user_id, content_id);
ALTER TABLE ib_records ADD INDEX idx_created(created);
```

根拠: `Config/Schema/update.sql:37`, `Config/Schema/app.sql:82-99`

---

## 7. テーブルプレフィックス `ib_` の CakePHP 5 での設定

CakePHP 5 にはグローバルなテーブルプレフィックス設定がない。

### 7.1 設定方法（推奨案）

**各 Table クラスの `initialize()` で `$this->setTable('ib_xxx')` を明示する。**

```php
// src/Model/Table/UsersTable.php
namespace App\Model\Table;

use Cake\ORM\Table;

class UsersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('ib_users');
        // 他の設定...
    }
}
```

根拠: `Docs/design/README.md:70-74`

### 7.2 基底 AppTable でのプレフィックスロジック（検討）

CakePHP 5 の Table クラスはテーブル名を自動推定するため（`UsersTable` → `users`）、基底 `AppTable` でプレフィックスを付与するロジックを実装できる:

```php
// src/Model/Table/AppTable.php
namespace App\Model\Table;

use Cake\ORM\Table;

class AppTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        // テーブル名に ib_ プレフィックスを付与
        $defaultTable = $this->getAlias();
        $this->setTable('ib_' . $defaultTable);
    }
}
```

> **注意**: この方法はテーブル名の自動推論に依存するため、手動で `setTable()` を呼ぶ方法（推奨案）の方が確実である。

### 7.3 CakePHP 5 の Datasources prefix 設定

CakePHP 5 の `config/app.php` の `Datasources` には `prefix` キーが存在するが、これは **CakePHP 5.4 以降で非推奨** になりつつある。確実性のため、Table クラスでの `setTable()` を推奨する。

根拠: `Docs/design/README.md:70-74`

---

## 8. 移行手順

### 8.1 ステップバイステップ

#### Step 1: 現行 DB のバックアップ

```bash
# mysqldump でバックアップ（MySQL 5.7 側のツール）
mysqldump -u root -p irohaboard > /backup/irohaboard_before_migration.sql

# バックアップの検証
wc -l /backup/irohaboard_before_migration.sql
```

#### Step 2: update.sql の不整合修正

```bash
# update.sql:37 の group_id インデックス行を削除
# ファイルを編集してから適用
```

#### Step 3: mysqldump のエクスポート

```bash
# 現行の MySQL 5.7 からダンプ
mysqldump -u root -p --default-character-set=utf8 irohaboard > /backup/irohaboard_migration.sql
```

#### Step 4: ダンプファイルの編集

```bash
# 1. DEFAULT CHARSET=utf8 を utf8mb4 に変更
sed -i 's/DEFAULT CHARSET=utf8/DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci/g' /backup/irohaboard_migration.sql

# 2. update.sql の group_id インデックス行を削除（Step 2 で修正済みの場合を除く）
```

#### Step 5: MariaDB 11.4 にリストア

```bash
# MariaDB 11.4 のコンテナを起動
docker compose up -d db

# データベースを作成
mariadb -u root -p -e "CREATE DATABASE IF NOT EXISTS irohaboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# ダンプをリストア
mariadb -u root -p irohaboard < /backup/irohaboard_migration.sql
```

#### Step 6: utf8mb4 変換（ダンプで変更未能力な場合）

```sql
-- 各テーブルを utf8mb4 に変換
ALTER TABLE ib_users_groups    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_users_courses  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_users          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_settings       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_records_questions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_records        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_infos          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_infos_groups   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_groups         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_groups_courses CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_courses        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_contents_questions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_logs           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_contents       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_user_tokens    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ib_cake_sessions  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

#### Step 7: GROUP BY の修正

```sql
-- Info.php の GROUP BY 修正
-- CakePHP 5 の Table クラス経由で実行するため、SQL ファイルとしては不要
-- アプリケーションコード（src/Model/Table/InfosTable.php）で修正
```

#### Step 8: MariaDB の接続確認

```sql
-- MariaDB の認証プラグインを確認
SELECT user, host, plugin FROM mysql.user WHERE user = 'root';

-- unix_socket または mysql_native_password が使用されていることを確認
-- PHP/PDO 接続で問題がある場合のみ:
ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('password');
FLUSH PRIVILEGES;
```

> **注意**: MariaDB 11.4 ではデフォルトが `unix_socket` 認証のため、パスワードベースの認証に変更する必要がある場合がある。Docker 環境では `MYSQL_ROOT_PASSWORD` 環境変数が `MARIADB_ROOT_PASSWORD` でも動作する（両方対応）。

#### Step 9: データ整合性の確認

```sql
-- テーブル数の確認
SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = 'irohaboard';

-- カラムの文字セット確認
SELECT table_name, column_name, character_set_name, collation_name
FROM information_schema.columns
WHERE table_schema = 'irohaboard' AND character_set_name IS NOT NULL
ORDER BY table_name, column_name;

-- 全テーブルが utf8mb4 であることを確認
SELECT table_name, table_collation
FROM information_schema.tables
WHERE table_schema = 'irohaboard';
```

---

## 9. リバース手順

万が一のロールバック手順。

### 9.1 ロールバック手順

```bash
# Step 1: 現在の MariaDB 11.4 のデータをバックアップ
mariadb-dump -u root -p irohaboard > /backup/irohaboard_rollback.sql

# Step 2: MySQL 5.7 のコンテナを起動
# docker-compose.yml の MySQL バージョンを 5.7 に戻して起動

# Step 3: MySQL 5.7 のコンテナでデータベースを作成
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS irohaboard CHARACTER SET utf8 COLLATE utf8_general_ci;"

# Step 4: 元のバックアップをリストア
mysql -u root -p irohaboard < /backup/irohaboard_before_migration.sql
```

### 9.2 ロールバックの注意点

| 注意事項 | 内容 |
|---|---|
| データ損失 | 移行後に追加されたデータは失われる |
| 時間経過 | 移行作業中にデータが変更されている可能性がある |
| utf8mb4 → utf8 | utf8mb4 のデータを utf8 に変換すると、4 バイト文字が切り捨てられる |
| ツール名 | ロールバック時は MySQL 5.7 のツール（`mysqldump`）を使用 |

---

## 10. Docker 構成

### 10.1 docker-compose.yml の DB サービス

```yaml
services:
  db:
    image: mariadb:11.4
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:-rootpass}
      MYSQL_DATABASE: ${MYSQL_DATABASE:-irohaboard}
      # MariaDB でも MYSQL_* 環境変数はそのまま動作（MARIADB_* も可）
    ports:
      - "3306:3306"
    volumes:
      - mariadb_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s
    command: --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci

volumes:
  mariadb_data:
```

> **healthcheck の補足**: MariaDB イメージには `healthcheck.sh` が同梱されている。`mariadb-admin ping` を代替として使用することも可能。MySQL の `mysqladmin ping` とはツール名が異なる。

根拠: `docker/docker-compose.yml`

### 10.2 MariaDB 環境変数の互換性

| 環境変数 | MariaDB 11.4 での動作 |
|---|---|
| `MYSQL_ROOT_PASSWORD` | **動作する**（MySQL 互換） |
| `MYSQL_DATABASE` | **動作する**（MySQL 互換） |
| `MYSQL_USER` | **動作する**（MySQL 互換） |
| `MYSQL_PASSWORD` | **動作する**（MySQL 互換） |
| `MARIADB_ROOT_PASSWORD` | **動作する**（MariaDB 固有） |
| `MARIADB_DATABASE` | **動作する**（MariaDB 固有） |

> MariaDB Docker イメージは `MYSQL_*` と `MARIADB_*` の両方の環境変数をサポートする。既存の `docker-compose.yml` の環境変数は変更不要。

---

## 11. CakePHP Migrations プラグインの利用検討

### 11.1 有用性

| 項目 | 評価 |
|---|---|
| スキーマバージョン管理 | 有用。変更履歴をファイルで管理できる |
| 移行の自動化 | 部分的に有用。`CONVERT TO CHARACTER SET` 等の DDL は対応外 |
| ロールバック | 有用。`migrations rollback` で前バージョンに戻せる |
| チーム開発 | 有用。スキーマ変更を Git で管理できる |

### 11.2 制約

| 制約 | 内容 |
|---|---|
| DDL の制限 | `CONVERT TO CHARACTER SET` 等の一部 DDL は対応外 |
| データ移行 | `INSERT` / `UPDATE` のデータ移行は別途対応が必要 |
| プレフィックス | CakePHP 5 の Migrations プラグインはプレフィックス対応が限定的 |
| 学習コスト | チーム全体での習得が必要 |

### 11.3 推奨

**今回の移行では CakePHP Migrations プラグインは使用しない。**

理由:
1. 移行是一発の作業であるため、バージョン管理のメリットが限定的
2. `utf8mb4` 変換や `GROUP BY` 修正は SQL ファイルで管理する方が明確
3. 移行後のスキーマ変更が限定的であるため

移行完了後、将来のスキーマ変更が必要になった場合に、CakePHP Migrations プラグインの導入を検討する。

根拠: `Docs/cakephp5-migration-spec.md:281`

---

## 12. 移行前後の検証チェックリスト

| # | 検証項目 | 検証方法 | 期待結果 |
|---|---|---|---|
| 1 | テーブル数 | `SHOW TABLES` | 16 テーブル |
| 2 | 文字セット | `SHOW CREATE TABLE ib_users` | `utf8mb4` |
| 3 | レコード数の整合 | `SELECT COUNT(*) FROM 各テーブル` | 移行前と一致 |
| 4 | インデックス | `SHOW INDEX FROM 各テーブル` | 移行前と一致 |
| 5 | MariaDB バージョン | `SELECT VERSION()` | `11.4.x` |
| 6 | 認証方式 | `SELECT plugin FROM mysql.user` | `unix_socket` または `mysql_native_password` |
| 7 | GROUP BY の動作 | 各モデルのクエリを実行 | エラーなし |
| 8 | CakePHP 5 からの接続 | アプリケーション起動 | 正常接続 |
| 9 | utf8mb4 照合順序 | `SHOW COLLATION WHERE Collation = 'utf8mb4_unicode_ci'` | MariaDB で利用可能 |
