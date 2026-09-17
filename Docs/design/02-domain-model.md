# 02 — ドメインモデル設計

> CakePHP 2.10 → 5.x 移行における Table / Entity / アソシエーション / バリデーション / コールバック / 検索ロジックの設計書

---

## 目次

1. [テーブル一覧と CakePHP 5 Table/Entity 対応](#1-テーブル一覧と-cakephp-5-tableentity-対応)
2. [アソシエーション設計](#2-アソシエーション設計)
3. [バリデーション設計](#3-バリデーション設計)
4. [コールバック設計](#4-コールバック設計)
5. [検索ロジック設計](#5-検索ロジック設計)
6. [UserToken テーブル設計](#6-usertoken-テーブル設計)

---

## 1. テーブル一覧と CakePHP 5 Table/Entity 対応

### 1.1 テーブルプレフィックス `ib_` の扱い

CakePHP 5 にはグローバルなテーブルプレフィックス設定がない（`Docs/design/README.md:70-74`）。

**方針**: 各 `Table` クラスの `initialize()` で `$this->setTable('ib_xxx')` を明示する。

### 1.2 全テーブルマッピング

ソース: `Config/Schema/app.sql:1-273`

| # | テーブル名 (SQL) | CakePHP 5 Table クラス | Entity クラス | SQL ファイル行 |
|---|---|---|---|---|
| 1 | `ib_users` | `UsersTable` | `User` | app.sql:35-51 |
| 2 | `ib_groups` | `GroupsTable` | `Group` | app.sql:134-146 |
| 3 | `ib_courses` | `CoursesTable` | `Course` | app.sql:166-178 |
| 4 | `ib_contents` | `ContentsTable` | `Content` | app.sql:219-240 |
| 5 | `ib_contents_questions` | `ContentsQuestionsTable` | `ContentsQuestion` | app.sql:183-199 |
| 6 | `ib_records` | `RecordsTable` | `Record` | app.sql:82-99 |
| 7 | `ib_records_questions` | `RecordsQuestionsTable` | `RecordsQuestion` | app.sql:67-77 |
| 8 | `ib_users_courses` | `UsersCoursesTable` | `UsersCourse` | app.sql:19-30 |
| 9 | `ib_users_groups` | `UsersGroupsTable` | `UsersGroup` | app.sql:5-14 |
| 10 | `ib_groups_courses` | `GroupsCoursesTable` | `GroupsCourse` | app.sql:151-161 |
| 11 | `ib_infos` | `InfosTable` | `Info` | app.sql:104-114 |
| 12 | `ib_infos_groups` | `InfosGroupsTable` | `InfosGroup` | app.sql:120-128 |
| 13 | `ib_settings` | `SettingsTable` | `Setting` | app.sql:56-62 |
| 14 | `ib_logs` | `LogsTable` | `Log` | app.sql:204-213 |
| 15 | `ib_user_tokens` | `UserTokensTable` | `UserToken` | app.sql:245-262 |
| 16 | `ib_cake_sessions` | `CakeSessionsTable` | `CakeSession` (Entity 不要) | app.sql:267-272 |

> `ib_cake_sessions` は CakePHP 5 では `DatabaseSession` が自動管理するため、Table クラスは不要な場合がある。必要に応じて定義。

### 1.3 各テーブルのカラム定義

#### `ib_users`（app.sql:35-51 / Model: `Model/User.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(20) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `username` | varchar(50) NOT NULL | NO | `''` | UNIQUE KEY `login_id` |
| `password` | varchar(200) NOT NULL | NO | `''` | bcrypt ハッシュ |
| `name` | varchar(50) NOT NULL | NO | `''` | 氏名 |
| `role` | varchar(20) NOT NULL | NO | `''` | admin / manager / user |
| `email` | varchar(50) NOT NULL | NO | `''` | |
| `comment` | text | YES | NULL | |
| `last_logined` | datetime | YES | NULL | |
| `started` | datetime | YES | NULL | |
| `ended` | datetime | YES | NULL | |
| `created` | datetime | YES | NULL | |
| `modified` | datetime | YES | NULL | |
| `deleted` | datetime | YES | NULL | ソフトデリート |

#### `ib_groups`（app.sql:134-146 / Model: `Model/Group.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(8) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `title` | varchar(200) NOT NULL | NO | `''` | |
| `comment` | text | YES | NULL | |
| `created` | datetime NOT NULL | NO | - | |
| `modified` | datetime | YES | NULL | |
| `deleted` | datetime | YES | NULL | ソフトデリート |
| `status` | int(1) NOT NULL | NO | `1` | 0:無効, 1:有効 |
| `logo` | varchar(200) | YES | NULL | |
| `copyright` | varchar(200) | YES | NULL | |
| `module` | varchar(50) | YES | `'00000000'` | |

#### `ib_courses`（app.sql:166-178 / Model: `Model/Course.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(8) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `title` | varchar(200) NOT NULL | NO | `''` | |
| `introduction` | text | YES | NULL | |
| `opened` | datetime | YES | NULL | |
| `created` | datetime NOT NULL | NO | - | |
| `modified` | datetime | YES | NULL | |
| `deleted` | datetime | YES | NULL | ソフトデリート |
| `sort_no` | int(8) NOT NULL | NO | `0` | |
| `comment` | text | YES | NULL | |
| `user_id` | int(8) NOT NULL | NO | - | 作成者 |

#### `ib_contents`（app.sql:219-240 / Model: `Model/Content.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(8) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `course_id` | int(8) NOT NULL | NO | `0` | FK → ib_courses |
| `user_id` | int(8) NOT NULL | NO | - | 作成者 |
| `title` | varchar(200) NOT NULL | NO | `''` | |
| `url` | varchar(200) | YES | NULL | URL系コンテンツ |
| `file_name` | varchar(200) | YES | NULL | ファイル系コンテンツ |
| `kind` | varchar(20) NOT NULL | NO | `''` | text/html/movie/url/test/label/file |
| `body` | text | YES | NULL | HTML本文 |
| `timelimit` | int(8) | YES | NULL | 制限時間（分） |
| `pass_rate` | int(8) | YES | NULL | 合格率 |
| `question_count` | int(8) | YES | NULL | 出題数 |
| `wrong_mode` | int(1) NOT NULL | NO | `1` | |
| `status` | int(1) NOT NULL | NO | `1` | 0:非公開, 1:公開 |
| `opened` | datetime | YES | NULL | |
| `created` | datetime NOT NULL | NO | - | |
| `modified` | datetime | YES | NULL | |
| `deleted` | datetime | YES | NULL | ソフトデリート |
| `sort_no` | int(8) NOT NULL | NO | `0` | |
| `comment` | text | YES | NULL | |

#### `ib_contents_questions`（app.sql:183-199 / Model: `Model/ContentsQuestion.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(8) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `content_id` | int(8) NOT NULL | NO | `0` | FK → ib_contents |
| `question_type` | varchar(20) NOT NULL | NO | `''` | |
| `title` | varchar(200) NOT NULL | NO | `''` | |
| `body` | text NOT NULL | NO | - | |
| `image` | varchar(200) | YES | NULL | |
| `options` | varchar(2000) | YES | NULL | 選択肢 JSON |
| `correct` | varchar(200) NOT NULL | NO | `''` | 正解 |
| `score` | int(8) NOT NULL | NO | `0` | |
| `explain` | text | YES | NULL | 解説 |
| `comment` | text | YES | NULL | |
| `created` | datetime NOT NULL | NO | - | |
| `modified` | datetime | YES | NULL | |
| `sort_no` | int(8) NOT NULL | NO | `0` | |

#### `ib_records`（app.sql:82-99 / Model: `Model/Record.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(8) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `course_id` | int(8) NOT NULL | NO | `0` | FK → ib_courses |
| `user_id` | int(8) NOT NULL | NO | `0` | FK → ib_users |
| `content_id` | int(8) NOT NULL | NO | - | FK → ib_contents |
| `full_score` | int(3) | YES | `0` | |
| `pass_score` | int(3) | YES | NULL | |
| `score` | int(3) | YES | NULL | |
| `is_passed` | smallint(1) | YES | `0` | |
| `is_complete` | smallint(1) | YES | NULL | |
| `progress` | smallint(1) | YES | `0` | |
| `understanding` | smallint(1) | YES | NULL | |
| `study_sec` | int(3) | YES | NULL | |
| `created` | datetime NOT NULL | NO | - | |

インデックス: `idx_course_user_content_id`, `idx_created`（app.sql:97-98）

#### `ib_records_questions`（app.sql:67-77 / Model: `Model/RecordsQuestion.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(8) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `record_id` | int(8) NOT NULL | NO | `0` | FK → ib_records |
| `question_id` | int(8) NOT NULL | NO | `0` | FK → ib_contents_questions |
| `answer` | varchar(2000) | YES | NULL | |
| `correct` | varchar(200) | YES | NULL | |
| `is_correct` | smallint(1) | YES | `0` | |
| `score` | int(8) NOT NULL | NO | `0` | |
| `created` | datetime | YES | NULL | |

#### `ib_users_courses`（app.sql:19-30 / Model: `Model/UsersCourse.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(8) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `user_id` | int(8) NOT NULL | NO | `0` | FK → ib_users |
| `course_id` | int(8) NOT NULL | NO | `0` | FK → ib_courses |
| `started` | date | YES | NULL | |
| `ended` | date | YES | NULL | |
| `created` | datetime | YES | NULL | |
| `modified` | datetime | YES | NULL | |
| `comment` | text | YES | NULL | |

インデックス: `idx_user_course_id`（app.sql:29）

#### `ib_users_groups`（app.sql:5-14 / Model: `Model/UsersGroup.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(8) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `user_id` | int(8) NOT NULL | NO | `0` | FK → ib_users |
| `group_id` | int(8) NOT NULL | NO | `0` | FK → ib_groups |
| `created` | datetime | YES | NULL | |
| `modified` | datetime | YES | NULL | |
| `comment` | text | YES | NULL | |

インデックス: `idx_user_group_id`（app.sql:13）

#### `ib_groups_courses`（app.sql:151-161 / Model: `Model/GroupsCourse.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(8) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `group_id` | int(8) NOT NULL | NO | `0` | FK → ib_groups |
| `course_id` | int(8) NOT NULL | NO | `0` | FK → ib_courses |
| `started` | date | YES | NULL | |
| `ended` | date | YES | NULL | |
| `created` | datetime | YES | NULL | |
| `modified` | datetime | YES | NULL | |
| `comment` | text | YES | NULL | |

#### `ib_infos`（app.sql:104-114 / Model: `Model/Info.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(8) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `title` | varchar(200) NOT NULL | NO | - | |
| `body` | text | YES | NULL | |
| `opened` | datetime | YES | NULL | |
| `closed` | datetime | YES | NULL | |
| `created` | datetime | YES | NULL | |
| `modified` | datetime NOT NULL | NO | - | |
| `user_id` | int(8) NOT NULL | NO | - | 作成者 |

#### `ib_infos_groups`（app.sql:120-128 / Model: `Model/InfosGroup.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(8) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `info_id` | int(8) NOT NULL | NO | `0` | FK → ib_infos |
| `group_id` | int(8) NOT NULL | NO | `0` | FK → ib_groups |
| `created` | datetime | YES | NULL | |
| `modified` | datetime | YES | NULL | |
| `comment` | text | YES | NULL | |

#### `ib_settings`（app.sql:56-62 / Model: `Model/Setting.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(11) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `setting_key` | varchar(100) NOT NULL | NO | - | |
| `setting_name` | varchar(100) NOT NULL | NO | - | |
| `setting_value` | varchar(1000) NOT NULL | NO | - | |

#### `ib_logs`（app.sql:204-213 / Model: `Model/Log.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(11) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `log_type` | varchar(50) | YES | NULL | |
| `log_content` | varchar(1000) | YES | NULL | |
| `user_id` | int(11) | YES | NULL | FK → ib_users |
| `user_ip` | varchar(50) | YES | NULL | |
| `user_agent` | varchar(1000) | YES | NULL | |
| `created` | datetime | YES | NULL | |

#### `ib_user_tokens`（app.sql:245-262 / Model: `Model/UserToken.php`）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | int(11) NOT NULL AUTO_INCREMENT | NO | - | PK |
| `user_id` | int(11) NOT NULL | NO | - | FK → ib_users |
| `token_type` | varchar(20) NOT NULL | NO | - | remember / api / trusted_device |
| `token_selector` | varchar(32) NOT NULL | NO | - | UNIQUE KEY `uk_token_selector` |
| `token_hash` | varchar(255) NOT NULL | NO | - | validator の password_hash |
| `expired` | datetime NOT NULL | NO | - | |
| `last_used` | datetime | YES | NULL | |
| `revoked` | datetime | YES | NULL | NULL = 有効 |
| `user_ip` | varchar(50) | YES | NULL | |
| `user_agent` | varchar(1000) | YES | NULL | |
| `created` | datetime | YES | NULL | |
| `modified` | datetime | YES | NULL | |

インデックス: `uk_token_selector`, `idx_user_type`, `idx_expired`（app.sql:259-261）

#### `ib_cake_sessions`（app.sql:267-272）

| カラム | 型 | NULL | デフォルト | 備考 |
|---|---|---|---|---|
| `id` | varchar(255) NOT NULL | NO | `''` | PK |
| `data` | text NOT NULL | NO | - | |
| `expires` | int(11) | YES | NULL | |

### 1.4 CakePHP 5 各 Table クラスの `initialize()` 実装例

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
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }
}
```

> 全 15 Table クラス（`ib_cake_sessions` を除く）で同様に `setTable('ib_xxx')` を実装する。
> Entity クラスは空の `App\Entity\Xxx extends Entity` で十分。

---

## 2. アソシエーション設計

### 2.1 現行 (CakePHP 2) のアソシエーション定義一覧

#### User（Model/User.php:85-112）

| 種別 | ターゲット | joinTable | FK | associationFK |
|---|---|---|---|---|
| hasAndBelongsToMany | Course | users_courses | user_id | course_id |
| hasAndBelongsToMany | Group | users_groups | user_id | group_id |

#### Content（Model/Content.php:87-102）

| 種別 | ターゲット | FK |
|---|---|---|
| belongsTo | Course | course_id |
| belongsTo | User | user_id |

#### ContentsQuestion（Model/ContentsQuestion.php:64-72）

| 種別 | ターゲット | FK |
|---|---|---|
| belongsTo | Content | content_id |

#### Course（Model/Course.php:41-55）

| 種別 | ターゲット | FK |
|---|---|---|
| hasMany | Content | course_id |

> ※ `dependent` => false（app.sql:46）。手動 DELETE で連鎖削除を行わない設計（`deleteCourse()` で手動削除）。

#### Group（Model/Group.php:41-55）

| 種別 | ターゲット | joinTable | FK | associationFK |
|---|---|---|---|---|
| hasAndBelongsToMany | Course | groups_courses | group_id | course_id |

#### GroupsCourse（Model/GroupsCourse.php:36-51）

| 種別 | ターゲット | FK |
|---|---|---|
| belongsTo | Group | group_id |
| belongsTo | Course | course_id |

#### Info（Model/Info.php:36-50）

| 種別 | ターゲット | joinTable | FK | associationFK |
|---|---|---|---|---|
| hasAndBelongsToMany | Group | infos_groups | info_id | group_id |

#### InfosGroup（Model/InfosGroup.php:34-35）

> アソシエーション定義は空（`$belongsTo = []`）。

#### Log（Model/Log.php:15-23）

| 種別 | ターゲット | FK |
|---|---|---|
| belongsTo | User | user_id |

#### Record（Model/Record.php:39-80）

| 種別 | ターゲット | FK | 備考 |
|---|---|---|---|
| hasMany | RecordsQuestion | record_id | order: RecordsQuestion.id |
| belongsTo | Course | course_id | type: inner |
| belongsTo | User | user_id | type: inner |
| belongsTo | Content | content_id | type: inner |

#### RecordsQuestion（Model/RecordsQuestion.php:40-55）

| 種別 | ターゲット | FK |
|---|---|---|
| belongsTo | Record | record_id |
| belongsTo | ContentsQuestion | question_id |

#### Setting（Model/Setting.php）

> アソシエーション定義なし。

#### UsersCourse（Model/UsersCourse.php:36-51）

| 種別 | ターゲット | FK |
|---|---|---|
| belongsTo | User | user_id |
| belongsTo | Course | course_id |

#### UsersGroup（Model/UsersGroup.php:36-51）

| 種別 | ターゲット | FK |
|---|---|---|
| belongsTo | User | user_id |
| belongsTo | Group | group_id |

#### UserToken（Model/UserToken.php）

> アソシエーション定義なし。

### 2.2 CakePHP 5 への変換設計

#### belongsTo → `Table::belongsTo()`

CakePHP 2 の `$belongsTo` 配列は CakePHP 5 の `$this->belongsTo()` メソッド呼び出しに変換する。

```php
// CakePHP 2（Content.php:87-102）
public $belongsTo = [
    'Course' => [
        'className' => 'Course',
        'foreignKey' => 'course_id',
    ],
    'User' => [
        'className' => 'User',
        'foreignKey' => 'user_id',
    ]
];

// CakePHP 5（src/Model/Table/ContentsTable.php）
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->setTable('ib_contents');
    $this->setPrimaryKey('id');

    $this->belongsTo('Courses', [
        'className' => 'Courses',
        'foreignKey' => 'course_id',
    ]);
    $this->belongsTo('Users', [
        'className' => 'Users',
        'foreignKey' => 'user_id',
    ]);
}
```

> **注意**: CakePHP 5 ではアソシエーション名は **複数形**（`Courses`, `Users`, `Groups` など）に変更する。CakePHP 2 の単数形名を `className` で指定していたが、CakePHP 5 では `className` にも複数形テーブル名を指定する。

#### hasMany → `Table::hasMany()`

```php
// CakePHP 2（Course.php:41-55）
public $hasMany = [
    'Content' => [
        'className' => 'Content',
        'foreignKey' => 'course_id',
        'dependent' => false,
    ]
];

// CakePHP 5（src/Model/Table/CoursesTable.php）
$this->hasMany('Contents', [
    'className' => 'Contents',
    'foreignKey' => 'course_id',
    'dependent' => false,
]);
```

#### hasAndBelongsToMany → `Table::belongsToMany()`

CakePHP 2 の `hasAndBelongsToMany` は CakePHP 5 の `belongsToMany` に対応する。

```php
// CakePHP 2（User.php:85-112）
public $hasAndBelongsToMany = [
    'Course' => [
        'className' => 'Course',
        'joinTable' => 'users_courses',
        'foreignKey' => 'user_id',
        'associationForeignKey' => 'course_id',
        'unique' => 'keepExisting',
    ],
    'Group' => [
        'className' => 'Group',
        'joinTable' => 'users_groups',
        'foreignKey' => 'user_id',
        'associationForeignKey' => 'group_id',
        'unique' => 'keepExisting',
    ]
];

// CakePHP 5（src/Model/Table/UsersTable.php）
$this->belongsToMany('Courses', [
    'className' => 'Courses',
    'joinTable' => 'ib_users_courses',
    'foreignKey' => 'user_id',
    'associationForeignKey' => 'course_id',
    'saveStrategy' => 'replace',
]);
$this->belongsToMany('Groups', [
    'className' => 'Groups',
    'joinTable' => 'ib_users_groups',
    'foreignKey' => 'user_id',
    'associationForeignKey' => 'group_id',
    'saveStrategy' => 'replace',
]);
```

> **注意**: `joinTable` にはプレフィックス付き `ib_users_courses` を指定する。`unique` => `keepExisting` は `saveStrategy` => `replace` で代替。

### 2.3 アソシエーション対応マトリクス（全モデル）

| Table クラス | belongsTo | hasMany | belongsToMany |
|---|---|---|---|
| `UsersTable` | - | - | Courses, Groups |
| `GroupsTable` | - | - | Courses |
| `CoursesTable` | - | Contents | - |
| `ContentsTable` | Courses, Users | - | - |
| `ContentsQuestionsTable` | Contents | - | - |
| `RecordsTable` | Courses, Users, Contents | RecordsQuestions | - |
| `RecordsQuestionsTable` | Records, ContentsQuestions | - | - |
| `UsersCoursesTable` | Users, Courses | - | - |
| `UsersGroupsTable` | Users, Groups | - | - |
| `GroupsCoursesTable` | Groups, Courses | - | - |
| `InfosTable` | - | - | Groups |
| `InfosGroupsTable` | - | - | - |
| `SettingsTable` | - | - | - |
| `LogsTable` | Users | - | - |
| `UserTokensTable` | - | - | - |

---

## 3. バリデーション設計

### 3.1 現行バリデーションルール一覧

#### User（Model/User.php:29-78）

| フィールド | ルール | メッセージ |
|---|---|---|
| `username` | isUnique | ログインIDが重複しています |
| `username` | alphaNumericMB | ログインIDは英数字で入力して下さい |
| `username` | between(4, 32) | ログインIDは4文字以上32文字以内で入力して下さい |
| `name` | notBlank | 氏名が入力されていません |
| `role` | notBlank | 権限が指定されていません |
| `password` | alphaNumericMB | パスワードは英数字で入力して下さい |
| `password` | between(4, 32) | パスワードは4文字以上32文字以内で入力して下さい |
| `new_password` | alphaNumericMB (allowEmpty) | パスワードは英数字で入力して下さい |
| `new_password` | between(4,32) (allowEmpty) | パスワードは4文字以上32文字以内で入力して下さい |

#### Content（Model/Content.php:28-80）

| フィールド | ルール | メッセージ |
|---|---|---|
| `course_id` | numeric | - |
| `user_id` | numeric | - |
| `title` | notBlank | - |
| `status` | notBlank | - |
| `timelimit` | range(0, 101), allowEmpty | 1-100の整数で入力して下さい。 |
| `pass_rate` | range(0, 101), allowEmpty | 1-100の整数で入力して下さい。 |
| `question_count` | range(0, 101), allowEmpty | 1-100の整数で入力して下さい。 |
| `kind` | notBlank | - |
| `sort_no` | numeric | - |

#### ContentsQuestion（Model/ContentsQuestion.php:26-57）

| フィールド | ルール | メッセージ |
|---|---|---|
| `content_id` | numeric | - |
| `question_type` | notBlank | - |
| `body` | notBlank | - |
| `score` | range(-1, 101) | 0-100の整数で入力して下さい。 |
| `sort_no` | numeric | - |
| `option_list` | multiple(min=1) | 正解を選択してください |

#### Course（Model/Course.php:31-34）

| フィールド | ルール | メッセージ |
|---|---|---|
| `title` | notBlank | - |
| `sort_no` | numeric | - |

#### Group（Model/Group.php:31-34）

| フィールド | ルール | メッセージ |
|---|---|---|
| `title` | notBlank | - |
| `status` | numeric | - |

#### GroupsCourse（Model/GroupsCourse.php:26-29）

| フィールド | ルール | メッセージ |
|---|---|---|
| `group_id` | numeric | - |
| `course_id` | numeric | - |

#### Info（Model/Info.php:26-29）

| フィールド | ルール | メッセージ |
|---|---|---|
| `title` | notBlank | - |
| `user_id` | numeric | - |

#### Record（Model/Record.php:28-32）

| フィールド | ルール | メッセージ |
|---|---|---|
| `course_id` | numeric | - |
| `user_id` | numeric | - |
| `content_id` | numeric | - |

#### RecordsQuestion（Model/RecordsQuestion.php:28-33）

| フィールド | ルール | メッセージ |
|---|---|---|
| `record_id` | numeric | - |
| `question_id` | numeric | - |
| `score` | numeric | - |
| `answer` | maxLength(1000) | - |

#### Setting（Model/Setting.php:23-26）

| フィールド | ルール | メッセージ |
|---|---|---|
| `setting_key` | notBlank | - |
| `setting_value` | notBlank | - |

#### UsersCourse（Model/UsersCourse.php:26-29）

| フィールド | ルール | メッセージ |
|---|---|---|
| `user_id` | numeric | - |
| `course_id` | numeric | - |

#### UsersGroup（Model/UsersGroup.php:26-29）

| フィールド | ルール | メッセージ |
|---|---|---|
| `user_id` | numeric | - |
| `group_id` | numeric | - |

#### Log / InfosGroup / UserToken

> バリデーション定義なし。

### 3.2 CakePHP 5 `validationDefault()` 変換設計

CakePHP 2 の `$validate` 配列は、CakePHP 5 の `validationDefault(Validator $validator)` メソッドに変換する。

```php
// src/Model/Table/UsersTable.php
public function validationDefault(Validator $validator): Validator
{
    $validator
        // username
        ->requirePresence('username', 'create')
        ->notEmptyString('username', 'ログインIDが入力されていません')
        ->add('username', 'unique', [
            'rule' => 'validateUnique',
            'provider' => 'table',
            'message' => 'ログインIDが重複しています',
        ])
        ->add('username', 'alphanumeric', [
            'rule' => '/^[a-zA-Z0-9]+$/',
            'message' => 'ログインIDは英数字で入力して下さい',
        ])
        ->add('username', 'between', [
            'rule' => ['between', 4, 32],
            'message' => 'ログインIDは4文字以上32文字以内で入力して下さい',
        ])
        // name
        ->requirePresence('name', 'create')
        ->notEmptyString('name', '氏名が入力されていません')
        // role
        ->requirePresence('role', 'create')
        ->notEmptyString('role', '権限が指定されていません')
        // password
        ->add('password', 'alphanumeric', [
            'rule' => '/^[a-zA-Z0-9]+$/',
            'message' => 'パスワードは英数字で入力して下さい',
        ])
        ->add('password', 'between', [
            'rule' => ['between', 4, 32],
            'message' => 'パスワードは4文字以上32文字以内で入力して下さい',
        ])
        // new_password（空を許可）
        ->add('new_password', 'alphanumeric', [
            'rule' => '/^[a-zA-Z0-9]+$/',
            'message' => 'パスワードは英数字で入力して下さい',
            'allowEmpty' => true,
        ])
        ->add('new_password', 'between', [
            'rule' => ['between', 4, 32],
            'message' => 'パスワードは4文字以上32文字以内で入力して下さい',
            'allowEmpty' => true,
        ]);

    return $validator;
}
```

> **注意点**:
> - CakePHP 2 の `alphaNumericMB` カスタムルール（AppModel.php:28-34）は `'/^[a-zA-Z0-9]+$/'` の正規表現ルールに変換。
> - `isUnique` は CakePHP 5 の `validateUnique` ルール（`'provider' => 'table'`）に変換。
> - `between` は CakePHP 5 でもそのまま使用可能。
> - `option_list` の `multiple` ルール（ContentsQuestion.php:53-56）は CakePHP 5 では Entity の associates フィールドに対して `->add('option_list', 'multiple', ['rule' => ['multiple', ['min' => 1]]])` で実装。
> - バリデーション不要なモデル（Log, InfosGroup, UserToken）は `validationDefault()` で空の Validator を返す。

---

## 4. コールバック設計

### 4.1 現行コールバック一覧

現行コードベースで使用されているコールバックは 1 ヶ所のみ:

#### `User::beforeSave()`（Model/User.php:114-129）

```php
// 現行コード
public function beforeSave($options = [])
{
    if (isset($this->data[$this->alias]['password']))
    {
        $password = $this->data[$this->alias]['password'];
        if (substr($password, 0, 1) !== '$')
        {
            $this->data[$this->alias]['password'] = password_hash($password, PASSWORD_BCRYPT);
        }
    }
    return true;
}
```

### 4.2 CakePHP 5 変換設計

CakePHP 2 のモデルコールバックは CakePHP 5 では **Table のイベントリスナー** に変換する。

```php
// src/Model/Table/UsersTable.php
namespace App\Model\Table;

use Cake\Event\EventInterface;
use Cake\ORM\Table;
use ArrayObject;

class UsersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('ib_users');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }

    /**
     * beforeSave イベント
     * パスワードが平文の場合、bcrypt ハッシュに変換
     *
     * @param EventInterface $event
     * @param ArrayObject $entity
     * @param ArrayObject $options
     * @return void
     */
    public function beforeSave(EventInterface $event, ArrayObject $entity, ArrayObject $options): void
    {
        // CakePHP 5 では Entity オブジェクトでアクセス
        if ($entity->has('password') && !empty($entity->get('password')))
        {
            $password = $entity->get('password');

            // 既に bcrypt ハッシュ（$2y$ 等）の場合は再ハッシュしない
            if (substr($password, 0, 1) !== '$')
            {
                $entity->set('password', password_hash($password, PASSWORD_BCRYPT));
            }
        }
    }
}
```

> **変更点**:
> - `$this->data[$this->alias]` → `$entity->get('password')` / `$entity->has('password')`
> - `return true` は不要（イベントリスナーは void 戻り値）
> - CakePHP 5 の Table イベントは `$this->getEventManager()` で自動登録されるか、`buildRules()` / `beforeSave()` を直接オーバーライド可能

---

## 5. 検索ロジック設計

### 5.1 現行の Search プラグイン定義

CakePHP 2 では `CakeDC/Search` プラグイン（`Search.Searchable` ビヘイビア）を使用している。

#### User（Model/User.php:134-151）

```php
public $actsAs = ['Search.Searchable'];
public $filterArgs = [
    'username' => ['type' => 'like', 'field' => 'User.username'],
    'name'     => ['type' => 'like', 'field' => 'User.name'],
];
```

#### Record（Model/Record.php:85-110）

```php
public $actsAs = ['Search.Searchable'];
public $filterArgs = [
    'course_id'      => ['type' => 'value', 'field' => 'course_id'],
    'content_title'  => ['type' => 'like',  'field' => 'Content.title'],
    'username'       => ['type' => 'like',  'field' => 'User.username'],
    'name'           => ['type' => 'like',  'field' => 'User.name'],
];
```

### 5.2 コントローラでの使用箇所

| コントローラ | ファイル:行 | 使用方法 |
|---|---|---|
| UsersController | `Controller/UsersController.php:260-263` | `$this->Prg->commonProcess()` → `$this->User->parseCriteria($this->Prg->parsedParams())` |
| RecordsController | `Controller/RecordsController.php:41-45` | `$this->Prg->commonProcess()` → `$this->Record->parseCriteria($this->Prg->parsedParams())` |

### 5.3 CakePHP 5 への変換設計

CakePHP 5 では `CakeDC/Search` プラグインは非推奨（CakePHP 5 対応版が未リリースの可能性あり）。以下の 2 つのアプローチから選択する。

#### アプローチ A（推奨）: コントローラで QueryBuilder 条件組み立て

`$filterArgs` の定義をコントローラに移動し、リクエストパラメータから直接 QueryBuilder で検索条件を組み立てる。

```php
// src/Controller/Admin/UsersController.php
public function index(): void
{
    $query = $this->Users->find();

    // ユーザ名検索（like）
    $username = $this->request->getQuery('username');
    if (!empty($username)) {
        $query->where(['Users.username LIKE' => '%' . $username . '%']);
    }

    // 氏名検索（like）
    $name = $this->request->getQuery('name');
    if (!empty($name)) {
        $query->where(['Users.name LIKE' => '%' . $name . '%']);
    }

    // グループ絞り込み（推測: UsersController.php:273-274 の既存ロジックから）
    $groupId = $this->request->getQuery('group_id');
    if (!empty($groupId)) {
        $userIds = $this->fetchTable('UsersGroups')
            ->find()
            ->select(['user_id'])
            ->where(['group_id' => $groupId])
            ->toArray();
        $query->where(['Users.id IN' => $userIds]);
    }

    $users = $this->paginate($query);
    $groups = $this->fetchTable('Groups')->find('list');
    $this->set(compact('users', 'groups'));
}
```

```php
// src/Controller/Admin/RecordsController.php
public function index(): void
{
    $query = $this->Records->find()
        ->contain(['Users', 'Courses', 'Contents']);

    // コースID（value 型）
    $courseId = $this->request->getQuery('course_id');
    if (!empty($courseId)) {
        $query->where(['Records.course_id' => $courseId]);
    }

    // コンテンツタイトル（like）
    $contentTitle = $this->request->getQuery('content_title');
    if (!empty($contentTitle)) {
        $query->where(['Contents.title LIKE' => '%' . $contentTitle . '%']);
    }

    // ユーザ名（like）
    $username = $this->request->getQuery('username');
    if (!empty($username)) {
        $query->where(['Users.username LIKE' => '%' . $username . '%']);
    }

    // 氏名（like）
    $name = $this->request->getQuery('name');
    if (!empty($name)) {
        $query->where(['Users.name LIKE' => '%' . $name . '%']);
    }

    // グループ絞り込み
    $groupId = $this->request->getQuery('group_id');
    if (!empty($groupId)) {
        $userIds = $this->fetchTable('UsersGroups')
            ->find()
            ->select(['user_id'])
            ->where(['group_id' => $groupId])
            ->toArray();
        $query->where(['Records.user_id IN' => $userIds]);
    }

    // コンテンツ種別フィルタ
    $contentCategory = $this->request->getQuery('content_category');
    if (!empty($contentCategory)) {
        if ($contentCategory === 'study') {
            $query->where(['Contents.kind IN' => ['text', 'html', 'movie', 'url']]);
        } else {
            $query->where(['Contents.kind' => $contentCategory]);
        }
    }

    $records = $this->paginate($query);
    $this->set(compact('records'));
}
```

#### アプローチ B: Search コンポーネントの自作

`Search.Searchable` ビヘイビアと `Search.Prg` コンポーネントを独自に再実装する。ただし、上記アプローチ A が CakePHP 5 の標準的な使い方であるため、推奨しない。

### 5.4 削除対象

- `Model/User.php:134-151`: `$actsAs` / `$filterArgs` 定義 → **削除**
- `Model/Record.php:85-110`: `$actsAs` / `$filterArgs` 定義 → **削除**
- `Controller/UsersController.php:260`: `$this->Prg->commonProcess()` → **削除**
- `Controller/UsersController.php:263`: `$this->User->parseCriteria()` → **削除**
- `Controller/RecordsController.php:41`: `$this->Prg->commonProcess()` → **削除**
- `Controller/RecordsController.php:45`: `$this->Record->parseCriteria()` → **削除**
- `Controller/UsersController.php:23`: `'Search.Prg'` コンポーネント登録 → **削除**
- `Controller/RecordsController.php:25`: `'Search.Prg'` コンポーネント登録 → **削除**

---

## 6. UserToken テーブル設計

### 6.1 概要

`Model/UserToken.php`（487 行）は Remember Me 用トークンと API Bearer トークンの両方を管理する。

ソース: `Model/UserToken.php:1-487`

### 6.2 テーブル構造

`ib_user_tokens`（app.sql:245-262）。カラム定義は [1.3 ib_user_tokens](#ib_user_tokensappsql245-262--model-modelusertokenphp) を参照。

### 6.3 主要メソッド一覧

| メソッド | 行 | 機能 | CakePHP 5 での対応 |
|---|---|---|---|
| `isAvailable()` | 37-68 | テーブル存在チェック（リクエスト内キャッシュ） | Table の `getSchema()` で存在確認。キャッシュは Table プロパティで保持 |
| `getDataSource()` | 75-81 | テーブル未作成時の MissingTableException 防止 | CakePHP 5 では不要（Table 存在チェックに変更） |
| `parseCookie()` | 89-110 | Cookie 文字列 `selector:validator` を分解 | 変更不要（純粋なヘルパー） |
| `issueRememberToken()` | 118-161 | Remember Me トークン発行 | Table クラスのメソッドとして保持。`$this->newEntity()` + `$this->save()` に変更 |
| `authenticateRememberCookie()` | 169-217 | Remember Me Cookie 認証 | Table クラスのメソッド。`ClassRegistry::init()` → TableLocator 経由に変更 |
| `revokeByCookie()` | 225-254 | Cookie 対応トークン無効化 | Table クラスのメソッド。`$this->patchEntity()` に変更 |
| `revokeAllForUser()` | 262-286 | ユーザの全 Remember トークン無効化 | Table クラスのメソッド。`$this->query()->update()->set()->where()->execute()` に変更 |
| `issueApiToken()` | 296-355 | API トークン発行 | Table クラスのメソッド。`$this->newEntity()` + `$this->save()` に変更 |
| `authenticateApiToken()` | 363-416 | API Bearer トークン認証 | Table クラスのメソッド。`ClassRegistry::init()` → TableLocator に変更 |
| `revokeApiToken()` | 424-454 | API トークン無効化 | Table クラスのメソッド |
| `revokeAllApiForUser()` | 462-486 | ユーザの全 API トークン無効化 | Table クラスのメソッド |

### 6.4 CakePHP 5 変換設計

```php
// src/Model/Table/UserTokensTable.php
namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\I18n\FrozenTime;

class UserTokensTable extends Table
{
    /**
     * テーブルが利用可能かのキャッシュ
     * @var bool|null
     */
    protected ?bool $_tableReady = null;

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('ib_user_tokens');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        // ユニックキー制約
        $this->addBehavior('Cake\Backup\Table\Backup', []); // 例
    }

    /**
     * テーブルが利用可能か確認
     */
    public function isAvailable(): bool
    {
        if ($this->_tableReady !== null) {
            return $this->_tableReady;
        }

        try {
            $schema = $this->getSchema();
            $this->_tableReady = true;
        } catch (\Exception $e) {
            $this->_tableReady = false;
        }

        return $this->_tableReady;
    }

    /**
     * Remember Me トークン発行
     */
    public function issueRememberToken(int $userId): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        if ($userId <= 0) {
            return null;
        }

        $days = (int)Configure::read('remember_token_expired_days');
        if ($days <= 0) {
            $days = 14;
        }

        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));

        $token = $this->newEntity([
            'user_id' => $userId,
            'token_type' => 'remember',
            'token_selector' => $selector,
            'token_hash' => password_hash($validator, PASSWORD_DEFAULT),
            'expired' => (new FrozenTime())->addDays($days),
            'last_used' => new FrozenTime(),
            'revoked' => null,
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);

        if (!$this->save($token)) {
            return null;
        }

        return $selector . ':' . $validator;
    }

    /**
     * Remember Me Cookie 認証
     *
     * @return array|null ['id' => int, ...] User データ（password なし）
     */
    public function authenticateRememberCookie(string $cookieValue): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $parsed = $this->parseCookie($cookieValue);
        if ($parsed === null) {
            return null;
        }

        $token = $this->find()
            ->where([
                'token_type' => 'remember',
                'token_selector' => $parsed['selector'],
                'revoked IS NULL' => true,
                'expired >=' => new FrozenTime(),
            ])
            ->first();

        if (!$token) {
            return null;
        }

        if (!password_verify($parsed['validator'], $token->token_hash)) {
            // 不正な Cookie → トークン無効化
            $token->revoked = new FrozenTime();
            $this->save($token);
            return null;
        }

        $usersTable = TableRegistry::get('Users');
        $user = $usersTable->get($token->user_id);

        if (!$user) {
            return null;
        }

        $token->last_used = new FrozenTime();
        $this->save($token);

        // password を除外して返す
        $userData = $user->toArray();
        unset($userData['password']);
        return $userData;
    }

    /**
     * Cookie に対応するトークンを無効化
     */
    public function revokeByCookie(string $cookieValue): void
    {
        // ... similar pattern
    }

    /**
     * ユーザの Remember Me トークンをすべて無効化
     */
    public function revokeAllForUser(int $userId): void
    {
        if (!$this->isAvailable() || $userId <= 0) {
            return;
        }

        $this->query()
            ->update()
            ->set(['revoked' => new FrozenTime()])
            ->where([
                'user_id' => $userId,
                'token_type' => 'remember',
                'revoked IS NULL' => true,
            ])
            ->execute();
    }

    /**
     * API トークン発行
     */
    public function issueApiToken(int $userId, ?int $days = null, bool $permanent = false): ?string
    {
        // ... issueRememberToken と同等ロジック
        // expired の算出ロジックを $permanent / $days パラメータに従うように変更
    }

    /**
     * API トークン認証
     *
     * @return array|null ['id' => int, ...] User データ（password なし）
     */
    public function authenticateApiToken(string $tokenString): ?array
    {
        // ... authenticateRememberCookie と同等ロジック
        // token_type => 'api' を使用
    }

    /**
     * API トークン無効化
     */
    public function revokeApiToken(string $tokenString): bool
    {
        // ...
    }

    /**
     * ユーザの全 API トークン無効化
     */
    public function revokeAllApiForUser(int $userId): void
    {
        // revokeAllForUser と同等ロジック（token_type => 'api'）
    }

    /**
     * Cookie 文字列を分解する
     */
    public function parseCookie(string $cookieValue): ?array
    {
        // 変更不要
    }
}
```

### 6.5 主要な変更点サマリー

| 現行 (CakePHP 2) | CakePHP 5 |
|---|---|
| `$this->create(); $this->save($data);` | `$entity = $this->newEntity([...]); $this->save($entity);` |
| `$this->find('first', ['conditions' => [...]])` | `$this->find()->where([...])->first()` |
| `$this->saveField('revoked', ...)` | `$entity->revoked = ...; $this->save($entity);` |
| `$this->updateAll([...], [...])` | `$this->query()->update()->set([...])->where([...])->execute()` |
| `$this->id = $id` | `$entity->set('id', $id)` または `newEntity` で処理 |
| `ClassRegistry::init('User')` | `TableRegistry::get('Users')` |
| `Configure::read(...)` | `Cake\Utility\Configure::read(...)`（同一） |
| `$this->query($sql, $params)` | `$this->find()` または `$this->query()->execute()` |

### 6.6 既存の脆弱性に関する注記

- `UserToken::isAvailable()` は未作成テーブルでも例外を出さない設計（UserToken.php:37-68）。
- CakePHP 5 移行後もこの設計は維持する。`$this->_tableReady` キャッシュは Table インスタンスのプロパティで保持。
- `getDataSource()` のオーバーライド（UserToken.php:75-81）は CakePHP 5 では不要。`isAvailable()` で Table の存在確認が可能。

---

## 付録: 参照ファイル一覧

| ファイル | 内容 |
|---|---|
| `Config/Schema/app.sql` | 全 16 テーブルの DDL |
| `Model/AppModel.php` | 基底モデル（メソッドチェーン、queryList、alphaNumericMB） |
| `Model/User.php` | バリデーション、HABTM、beforeSave、filterArgs、deleteUserRecords |
| `Model/Content.php` | バリデーション、belongsTo、getContentRecord（raw SQL）、setOrder（raw SQL） |
| `Model/ContentsQuestion.php` | バリデーション、belongsTo、setOrder（raw SQL） |
| `Model/Course.php` | バリデーション、hasMany、setOrder（raw SQL）、hasRight（raw SQL）、deleteCourse（raw SQL） |
| `Model/Group.php` | バリデーション、HABTM、getUserIdByGroupID（raw SQL） |
| `Model/GroupsCourse.php` | バリデーション、belongsTo |
| `Model/Info.php` | バリデーション、HABTM、getInfos、getInfoOption、getInfoIdList（raw SQL） |
| `Model/InfosGroup.php` | 空定義 |
| `Model/Log.php` | belongsTo のみ |
| `Model/Record.php` | バリデーション、hasMany、belongsTo、filterArgs |
| `Model/RecordsQuestion.php` | バリデーション、belongsTo |
| `Model/Setting.php` | バリデーション、getSettings（raw SQL）、setSettings（raw SQL） |
| `Model/UsersCourse.php` | バリデーション、belongsTo、getCourseRecord（raw SQL） |
| `Model/UsersGroup.php` | バリデーション、belongsTo |
| `Model/UserToken.php` | 487 行。Remember Me / API トークン管理全ロジック |
