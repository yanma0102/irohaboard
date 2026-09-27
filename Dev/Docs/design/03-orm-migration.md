# 03 — ORM 移行設計

> CakePHP 2.10 → 5.x 移行における AppModel メソッドチェーン廃止・raw SQL 置換・Search 自作化の設計書

---

## 目次

1. [AppModel メソッドチェーン廃止の設計](#1-appmodel-メソッドチェーン廃止の設計)
2. [raw SQL の移行設計](#2-raw-sql-の移行設計)
3. [Search の自作化](#3-search-の自作化)

---

## 1. AppModel メソッドチェーン廃止の設計

### 1.1 現行 AppModel のメソッドチェーン概要

`Model/AppModel.php:51-180` で、CakePHP 2 の `find()` を上書きし、引数なしで呼び出すとメソッドチェーン用のインスタンスを返す自作チェーン機構を実装している。

| メソッド | 行 | 機能 |
|---|---|---|
| `find($type, $options)` | 51-60 | 引数なしで呼び出すと `$this` を返しメソッドチェーン開始。引数ありなら親の `find()` |
| `select($value)` | 65-69 | `fields` を設定 |
| `where($value)` | 74-78 | `conditions` を設定 |
| `order($value)` | 83-87 | `order` を設定 |
| `group($value)` | 92-96 | `group` を設定 |
| `limit($value)` | 101-105 | `limit` を設定 |
| `page($value)` | 110-114 | `page` を設定 |
| `all()` | 119-140 | `parent::find('all', $this->options)` を実行。`convert()` 時は配列→オブジェクト変換も |
| `first()` | 145-163 | `parent::find('first', $this->options)` を実行 |
| `toList()` | 169-172 | `parent::find('list', $this->options)` を実行 |
| `count()` | 177-180 | `parent::find('count', $this->options)` を実行 |
| `convert()` | 209-213 | 結果を stdClass オブジェクトに変換するモードを有効化 |
| `queryList($sql, $params, $table_name, $field_name)` | 191-203 | raw SQL 実行結果を配列リストに変換 |

### 1.2 呼び出し元一覧と変換パターン

_APP Model チェーン_ を使用している箇所は、Model と Controller の両方に存在する。CakePHP 5 では `$this->find()` が `Query` オブジェクトを返し、`= , <=` や `->select()`, `->where()` 等のクエリビルダーメソッドを直接鏈 walked できる。

#### 変換パターン一覧

| # | 現行パターン | CakePHP 5 パターン |
|---|---|---|
| A | `$model->find()->select(...)->where(...)->first()` | `$model->find()->select([...])->where([...])->first()` |
| B | `$model->find()->where(...)->order(...)->all()` | `$model->find()->where([...])->order([...])->all()` |
| C | `$model->find()->where(...)->order(...)->limit(...)->all()` | `$model->find()->where([...])->order([...])->limit(...)->all()` |
| D | `$model->find()->where(...)->count()` | `$model->find()->where([...])->count()` |
| E | `$model->find()->where(...)->limit(...)->page(...)->all()` | `$model->find()->where([...])->limit(...)->page(...)->all()` |

> CakePHP 5 の `find()` は `Query` オブジェクトを返すため、`select()`, `where()`, `order()` 等は QueryBuilder メソッドとしてそのままチェーン可能。`all()` と `first()` も Query メソッドとして存在する。根本的な違いは `find()` を引数なしで呼んだ場合の挙動のみ。

### 1.3 個別呼び出し元の変換設計

#### Model 内の呼び出し元

**① `Content::getNextSortNo()`（Model/Content.php:197-204）**

```php
// 現行（CakePHP 2 チェーン）
$data = $this->find()
    ->select('MAX(Content.sort_no) as sort_no')
    ->where(['Content.course_id' => $course_id])
    ->first();
$sort_no = $data[0]['sort_no'] + 1;

// CakePHP 5 移行後
$row = $this->find()
    ->select([$this->findQueryExpr('sort_no') => $this->findQueryExpr('MAX(sort_no) as sort_no')])
    ->where(['course_id' => $course_id])
    ->first();
$sort_no = $row->sort_no + 1;
```

> **注**: CakePHP 5 では `select()` のエイリアス指定や `func()` を使う。Entity へのアクセスはプロパティとして行う。具体的には以下のようにする:

```php
// 推奨パターン
$query = $this->find()
    ->select(['sort_no' => $query->func()->max('sort_no')])
    ->where(['course_id' => $course_id]);
$row = $query->first();
$sort_no = $row->sort_no + 1;
```

**② `ContentsQuestion::getNextSortNo()`（Model/ContentsQuestion.php:102-109）**

```php
// 現行
$data = $this->find()
    ->select('MAX(ContentsQuestion.sort_no) as sort_no')
    ->where(['ContentsQuestion.content_id' => $content_id])
    ->first();
$sort_no = $data[0]['sort_no'] + 1;

// CakePHP 5 移行後
$query = $this->find()
    ->select(['sort_no' => $query->func()->max('sort_no')])
    ->where(['content_id' => $content_id]);
$row = $query->first();
$sort_no = $row->sort_no + 1;
```

#### Controller 内の呼び出し元

**③ `RecordsController::admin_index()` CSV 出力（Controller/RecordsController.php:94-97）**

```php
// 現行
$rows = $this->Record->find()
    ->where($conditions)
    ->order('Record.created desc')
    ->all();

// CakePHP 5 移行後
$rows = $this->Records->find()
    ->where($conditions)
    ->order(['created' => 'DESC'])
    ->all();
```

**④ `ContentsController::admin_index()` コンテンツ一覧（Controller/ContentsController.php:117-120）**

```php
// 現行
$contents = $this->Content->find()
    ->where(['Content.course_id' => $course_id])
    ->order('Content.sort_no asc')
    ->all();

// CakePHP 5 移行後
$contents = $this->Contents->find()
    ->where(['course_id' => $course_id])
    ->order(['sort_no' => 'ASC'])
    ->all();
```

**⑤ `ContentsController::admin_edit()` 最大 ID 取得（Controller/ContentsController.php:517-519）**

```php
// 現行
$row  = $this->Content->find()
    ->select(['MAX(Content.id) as max_id'])
    ->first();

// CakePHP 5 移行後
$row = $this->Contents->find()
    ->select(['max_id' => $this->Contents->findQueryExpr()->func()->max('id')])
    ->first();
```

**⑥ `ContentsController::admin_edit()` 問題一覧取得（Controller/ContentsController.php:532-535）**

```php
// 現行
$contentsQuestions = $this->fetchTable('ContentsQuestion')->find()
    ->where(['content_id' => $content_id])
    ->order('ContentsQuestion.sort_no asc')
    ->all();

// CakePHP 5 移行後
$contentsQuestions = $this->fetchTable('ContentsQuestions')->find()
    ->where(['content_id' => $content_id])
    ->order(['sort_no' => 'ASC'])
    ->all();
```

**⑦ `ContentsController::admin_edit()` 最大問題 ID 取得（Controller/ContentsController.php:541-543）**

```php
// 現行
$row = $this->fetchTable('ContentsQuestion')->find()
    ->select('MAX(ContentsQuestion.id) as max_id')
    ->first();

// CakePHP 5 移行後
$row = $this->fetchTable('ContentsQuestions')->find()
    ->select(['max_id' => $this->fetchTable('ContentsQuestions')->findQueryExpr()->func()->max('id')])
    ->first();
```

**⑧ `CoursesController::admin_index()` コース一覧（Controller/CoursesController.php:38-40）**

```php
// 現行
$courses = $this->Course->find()
    ->order('Course.sort_no asc')
    ->all();

// CakePHP 5 移行後
$courses = $this->Courses->find()
    ->order(['sort_no' => 'ASC'])
    ->all();
```

**⑨ `UsersController::admin_index()` ユーザ数カウント（Controller/UsersController.php:796）**

```php
// 現行
$user_count = $this->User->find()->where($conditions)->count();

// CakePHP 5 移行後
$user_count = $this->Users->find()->where($conditions)->count();
```

**⑩ `UsersController::admin_export()` ユーザページ取得（Controller/UsersController.php:804-808）**

```php
// 現行
$rows = $this->User->find()
    ->where($conditions)
    ->limit($limit)
    ->page($page)
    ->all();

// CakePHP 5 移行後
$rows = $this->Users->find()
    ->where($conditions)
    ->limit($limit)
    ->page($page)
    ->all();
```

**⑪ `UsersController::admin_edit()` インポート時の重複チェック（Controller/UsersController.php:608-610）**

```php
// 現行
$data = $this->User->find()
    ->where(['User.username' => $row[COL_LOGINID]])
    ->first();

// CakePHP 5 移行後
$data = $this->Users->find()
    ->where(['username' => $row[COL_LOGINID]])
    ->first();
```

**⑫ `UsersCoursesController::admin_index()` 設定取得（Controller/UsersCoursesController.php:27-29）**

```php
// 現行
$data = $this->fetchTable('Setting')->find()
    ->where(['Setting.setting_key' => 'information'])
    ->first();

// CakePHP 5 移行後
$data = $this->fetchTable('Settings')->find()
    ->where(['setting_key' => 'information'])
    ->first();
```

**⑬ `ContentsQuestionsController` 各所（Controller/ContentsQuestionsController.php:94-97, 106-109, 114-118, 134-137, 287-290）**

```php
// 現行パターン（全て同型）
$contentsQuestions = $this->ContentsQuestion->find()
    ->where([...])
    ->order('...')
    ->all();

// CakePHP 5 移行後（全て同型）
$contentsQuestions = $this->ContentsQuestions->find()
    ->where([...])
    ->order([...])
    ->all();
```

> **特に 114-118 の `->order('rand())` は `RAND()` 関数を使用。CakePHP 5 でも `$query->order($query->func()->rand())` または `->order('RAND()')` で対応可能。MariaDB でも `RAND()` は同じ構文で動作する。**

**⑭ `EnquetesQuestionsController` 各所（Controller/EnquetesQuestionsController.php:92-95, 102-105, 212-215）**

```php
// 同上パターン
$contentsQuestions = $this->fetchTable('ContentsQuestions')->find()
    ->where([...])
    ->order([...])
    ->all();
```

**⑮ `ApiBaseController::apiPaginate()` 汎用ページネーション（Controller/ApiBaseController.php:367, 380）**

```php
// 現行（CakePHP 2 の find('count') / find('all') を使用）
$total = (int)$model->find('count', ['conditions' => $conditions]);
$result = $model->find('all', $options);

// CakePHP 5 移行後
$total = $model->find()->where($conditions)->count();
$result = $model->find()->where($options['conditions'])->all();
// または
$query = $model->find();
if (!empty($options['conditions'])) {
    $query->where($options['conditions']);
}
if (!empty($options['fields'])) {
    $query->select($options['fields']);
}
if (!empty($options['order'])) {
    $query->order($options['order']);
}
$total = $query->count();
$result = $query->limit($limit)->page($page)->all();
```

**⑯ `ApiGroupsController` / `ApiUsersController` / `ApiCoursesController` / `ApiAuthController` の `find('first')` / `find('count')` / `find('list')` 各所**

```php
// 現行（CakePHP 2 形式の find 引数指定）
$existing = $this->UsersGroup->find('first', ['conditions' => [...]]);

// CakePHP 5 移行後
$existing = $this->UsersGroups->find()->where([...])->first();
```

### 1.4 AppModel の廃止対象メソッドと代替

| AppModel メソッド | 行 | 廃止理由 | CakePHP 5 代替 |
|---|---|---|---|
| `find()` override | 51-60 | Query オブジェクトに統合 | 不要（デフォルトの `Table::find()` を使用） |
| `select()` | 65-69 | QueryBuilder の `select()` | `$query->select([...])` |
| `where()` | 74-78 | QueryBuilder の `where()` | `$query->where([...])` |
| `order()` | 83-87 | QueryBuilder の `order()` | `$query->order([...])` |
| `group()` | 92-96 | QueryBuilder の `group()` | `$query->group([...])` |
| `limit()` | 101-105 | QueryBuilder の `limit()` | `$query->limit(N)` |
| `page()` | 110-114 | QueryBuilder の `page()` | `$query->page(N)` |
| `all()` | 119-140 | Query の `all()` | `$query->all()` |
| `first()` | 145-163 | Query の `first()` | `$query->first()` |
| `toList()` | 169-172 | Query の `all()` + 配列化 | `$query->find('list')->toArray()` |
| `count()` | 177-180 | Query の `count()` | `$query->count()` |
| `convert()` | 209-213 | オブジェクト変換は不要 | Entity が自動的にオブジェクト |
| `queryList()` | 191-203 | raw SQL + リスト化 | `Table::query()` + 手動変換、または QueryBuilder |
| `_arrayToObject()` | 218-237 | Entity がオブジェクト | 不要 |
| `alphaNumericMB()` | 28-34 | カスタムバリデーションルール | 正規表現ルール `'/^[a-zA-Z0-9]+$/'` |
| `get()` | 39-42 | `findById()` ラッパー | CakePHP 5 の `Table::get($id)` |

### 1.5 AppModel の移行後設計

```php
// src/Model/Table/AppTable.php
namespace App\Model\Table;

use Cake\ORM\Table;

class AppTable extends Table
{
    /**
     * alphaNumericMB バリデーションルール
     * コントローラ等からも利用可能な場合、Behavior に分離を検討
     *
     * @deprecated 正規表現バリデーションに置換推奨
     */
    public function alphaNumericMB($check): bool
    {
        $value = array_values($check);
        $value = $value[0];
        return (bool)preg_match('/^[a-zA-Z0-9]+$/', $value);
    }
}
```

> **残すもの**:
> - `alphaNumericMB()` はカスタムバリデーションルールとして CakePHP 5 の `Validator::add()` に渡せる。AppTable に残すか、各 Table の `validationDefault()` で正規表現ルールに置換する。
> - 他のメソッドチェーン系メソッドは **全て廃止**。

### 1.6 `get()` → `Table::get()` の対応

CakePHP 2 の AppModel.get()（Model/AppModel.php:39-42）は `findById()` のラッパー。CakePHP 5 の `Table::get($id)` は実質同等だが、見つからない場合は `NotFoundException` を投げる。

コントローラ内の `->get($id)` 呼び出し（29 箇所）はそのまま CakePHP 5 の `Table::get()` に継承可能。ただし、例外ハンドリングの確認が必要（CakePHP 5 では自動で 404 になる）。

### 1.7 `$this->recursive` の廃止

CakePHP 2 の `$this->recursive = 0` / `$this->recursive = 1` は CakePHP 5 では `contain()` で代替する。

| 使用箇所 | 現行 | CakePHP 5 |
|---|---|---|
| `Controller/UsersController.php:803` | `$this->User->recursive = 1` | `$this->Users->find()->contain(['Groups', 'Courses'])` |
| `Controller/RecordsController.php:92` | `$this->Record->recursive = 0` | デフォルト（contain なし） |

### 1.8 `$this->paginate` の変換

CakePHP 2 の `$this->paginate` 配列は CakePHP 5 でも使用可能だが、`fields` のサブクエリ構文は変更が必要。

```php
// 現行（Controller/UsersController.php:276-286）
$this->paginate = [
    'fields' => ['*',
        '(SELECT group_concat(g.title order by g.id SEPARATOR \', \') as group_title ...)',
        '(SELECT group_concat(c.title order by c.id SEPARATOR \', \') as course_title ...)',
    ],
    'conditions' => $conditions,
    'limit' => 20,
    'order' => 'created desc',
];

// CakePHP 5 移行後
$query = $this->Users->find()
    ->where($conditions)
    ->order(['created' => 'DESC']);
// サブクエリフィールドは CakePHP 5 の Expression タプルで実現
$groupSubQuery = $this->Users->find()
    ->select([$this->Users->findQueryExpr()->func()->concat([
        $this->Users->Groups->find()->select('title'),
        // ... complex subquery
    ])]);
// ※ サブクエリの具体実装は別途検討が必要（詳細は 07-controllers.md で扱う）
$this->set($this->paginate($query));
```

> **注**: サブクエリの `group_concat` + `SELECT` in `fields` は CakePHP 5 での移行が複雑。`QueryExpression` を使用するか、raw SQL ヒント（`->select(['field' => $expression])`）で対応する。

---

## 2. raw SQL の移行設計

### 2.1 raw SQL 使用箇所一覧

ソースコードから抽出した `$this->query($sql, $params)` の全使用箇所:

| # | ファイル:行 | メソッド | SQL 種別 | 分類 |
|---|---|---|---|---|
| 1 | `Model/Content.php:114-156,164` | `getContentRecord()` | SELECT (複雑) | **raw SQL で保持** |
| 2 | `Model/Content.php:178,185` | `setOrder()` | UPDATE | QueryBuilder に変更 |
| 3 | `Model/ContentsQuestion.php:83,90` | `setOrder()` | UPDATE | QueryBuilder に変更 |
| 4 | `Model/Course.php:66,73` | `setOrder()` | UPDATE | QueryBuilder に変更 |
| 5 | `Model/Course.php:93-98,99` | `hasRight()` #1 | SELECT | QueryBuilder に変更 |
| 6 | `Model/Course.php:104-109,110` | `hasRight()` #2 | SELECT | QueryBuilder に変更 |
| 7 | `Model/Course.php:130-131` | `deleteCourse()` #1 | DELETE | QueryBuilder に変更 |
| 8 | `Model/Course.php:134-135` | `deleteCourse()` #2 | DELETE | QueryBuilder に変更 |
| 9 | `Model/Course.php:138-139` | `deleteCourse()` #3 | DELETE | QueryBuilder に変更 |
| 10 | `Model/Group.php:65,68` | `getUserIdByGroupID()` | SELECT | QueryBuilder に変更 |
| 11 | `Model/Info.php:110-122,130` | `getInfoIdList()` | SELECT | QueryBuilder に変更 |
| 12 | `Model/Setting.php:36` | `getSettings()` | SELECT | QueryBuilder に変更 |
| 13 | `Model/Setting.php:59` | `setSettings()` | UPDATE | QueryBuilder に変更 |
| 14 | `Model/User.php:161,167` | `deleteUserRecords()` #1 | DELETE | **raw SQL で保持** |
| 15 | `Model/User.php:170,171` | `deleteUserRecords()` #2 | DELETE | **raw SQL で保持** |
| 16 | `Model/UsersCourse.php:61-105` | `getCourseRecord()` | SELECT (複雑) | **raw SQL で保持** |
| 17 | `Model/AppModel.php:191-203` | `queryList()` | ヘルパー | 廃止（各呼び出し元で個別対応） |
| 18 | `Controller/ApiBaseController.php:413-424` | `currentUserCourseIds()` | SELECT | QueryBuilder に変更 |
| 19 | `Controller/ApiBaseController.php:444` | `currentUserGroupIds()` | SELECT | QueryBuilder に変更 |
| 20 | `Controller/ApiUsersController.php:289-297` | user courses list | SELECT | QueryBuilder に変更 |
| 21 | `Controller/ApiGroupsController.php:144-151` | group users list | SELECT | QueryBuilder に変更 |

### 2.2 分類結果

#### A. QueryBuilder に変更可能（13 箇所）

| # | メソッド | 根拠 |
|---|---|---|
| 2 | `Content::setOrder()` | 単純な UPDATE（1 行ずつ） |
| 3 | `ContentsQuestion::setOrder()` | 単純な UPDATE（1 行ずつ） |
| 4 | `Course::setOrder()` | 単純な UPDATE（1 行ずつ） |
| 5-6 | `Course::hasRight()` | 単純な SELECT COUNT + JOIN |
| 7-9 | `Course::deleteCourse()` | 単純な DELETE（サブクエリ付き） |
| 10 | `Group::getUserIdByGroupID()` | 単純な SELECT |
| 11 | `Info::getInfoIdList()` | SELECT + LEFT JOIN + OR 条件 |
| 12 | `Setting::getSettings()` | 単純な SELECT 全件 |
| 13 | `Setting::setSettings()` | 単純な UPDATE（ループ内） |
| 18 | `ApiBaseController::currentUserCourseIds()` | UNION を含む SELECT |
| 19 | `ApiBaseController::currentUserGroupIds()` | 単純な SELECT |
| 20 | `ApiUsersController` user courses list | INNER JOIN + WHERE |
| 21 | `ApiGroupsController` group users list | INNER JOIN + WHERE |

#### B. raw SQL で保持（5 箇所）

| # | メソッド | 保持理由 |
|---|---|---|
| 1 | `Content::getContentRecord()` | 複雑なサブクエリ 2 つを含むレポート系 SQL。QueryBuilder への変換は可能だが可読性が大幅に低下 |
| 14-15 | `User::deleteUserRecords()` | 2 段階の DELETE（FK 制約付き）。CakePHP 5 でも `$this->query()` で実行可能 |
| 16 | `UsersCourse::getCourseRecord()` | 複雑なサブクエリ 3 つを含むレポート系 SQL |

> **raw SQL の CakePHP 5 での実行方法**:
> - `Table::query()` は CakePHP 5 で削除された。
> - 代替: `$this->getConnection()->execute($sql, $params)` または `$this->getConnection()->prepare($sql)` で実行。

### 2.3 QueryBuilder への個別変換設計

#### ②-④ `setOrder()` — コンテンツ/問題/コースの並べ替え

```php
// 現行（Content.php:174-187 / ContentsQuestion.php:79-92 / Course.php:62-75）
for ($i = 0; $i < count($id_list); $i++) {
    $sql = "UPDATE ib_contents SET sort_no = :sort_no WHERE id = :id";
    $params = ['sort_no' => ($i + 1), 'id' => $id_list[$i]];
    $this->query($sql, $params);
}

// CakePHP 5 移行後（Content Table 例）
public function setOrder(array $idList): void
{
    $connection = $this->getConnection();
    foreach ($idList as $index => $id) {
        $connection->execute(
            'UPDATE ib_contents SET sort_no = :sort_no WHERE id = :id',
            ['sort_no' => $index + 1, 'id' => $id]
        );
    }
}
```

> **推奨**: `setOrder` はループ内で 1 行ずつ UPDATE するため、CakePHP 5 の `Query` オブジェクトではループが残る。`Connection::execute()` を直接使用してパラメータ化クエリを実行するのが最も明確。

#### ⑤-⑥ `Course::hasRight()` — アクセス権チェック

```php
// 現行（Course.php:84-116）
// SQL #1: ユーザ個人の受講登録チェック
$sql = "SELECT count(*) as cnt FROM ib_users_courses WHERE course_id = :course_id AND user_id = :user_id";
// SQL #2: グループ経由の受講登録チェック
$sql = "SELECT count(*) as cnt FROM ib_groups_courses gc
        INNER JOIN ib_users_groups ug ON gc.group_id = ug.group_id AND ug.user_id = :user_id
        WHERE gc.course_id = :course_id";

// CakePHP 5 移行後
public function hasRight(int $userId, int $courseId): bool
{
    // 個人受講登録チェック
    $personalCount = $this->getConnection()->execute(
        'SELECT COUNT(*) as cnt FROM ib_users_courses WHERE course_id = :course_id AND user_id = :user_id',
        ['course_id' => $courseId, 'user_id' => $userId]
    )->fetch('assoc');

    if ((int)($personalCount['cnt'] ?? 0) > 0) {
        return true;
    }

    // グループ経由チェック
    $groupCount = $this->getConnection()->execute(
        'SELECT COUNT(*) as cnt FROM ib_groups_courses gc
         INNER JOIN ib_users_groups ug ON gc.group_id = ug.group_id AND ug.user_id = :user_id
         WHERE gc.course_id = :course_id',
        ['course_id' => $courseId, 'user_id' => $userId]
    )->fetch('assoc');

    return (int)($groupCount['cnt'] ?? 0) > 0;
}
```

> **MariaDB 対応**: このクエリは `GROUP BY` を使用していないため、MariaDB 11.4 の `ONLY_FULL_GROUP_BY` でも問題なし。

#### ⑦-⑨ `Course::deleteCourse()` — コース削除

```php
// 現行（Course.php:123-140）
// 3 段階の DELETE（FK の順序に注意）

// CakePHP 5 移行後
public function deleteCourse(int $courseId): void
{
    $connection = $this->getConnection();

    // テスト問題の削除（FK: contents_questions → contents → courses）
    $connection->execute(
        'DELETE FROM ib_contents_questions WHERE content_id IN (SELECT id FROM ib_contents WHERE course_id = :course_id)',
        ['course_id' => $courseId]
    );

    // コンテンツの削除
    $connection->execute(
        'DELETE FROM ib_contents WHERE course_id = :course_id',
        ['course_id' => $courseId]
    );

    // コースの削除
    $connection->execute(
        'DELETE FROM ib_courses WHERE id = :course_id',
        ['course_id' => $courseId]
    );
}
```

> **MariaDB 対応**: `DELETE ... WHERE ... IN (SELECT ...)` は MariaDB 11.4 でも動作。ただし `FOREIGN_KEY_CHECKS=0` が必要な場合がある（app.sql:1 で設定済み）。CakePHP 5 の Migration で永続的に設定するか、各 DELETE 前に設定を検討。

#### ⑩ `Group::getUserIdByGroupID()` — グループユーザ ID 取得

```php
// 現行（Group.php:63-71）
$sql = "SELECT user_id FROM ib_users_groups WHERE group_id = :group_id";
$list = $this->queryList($sql, $params, 'ib_users_groups', 'user_id');

// CakePHP 5 移行後
public function getUserIdByGroupID(int $groupId): array
{
    $usersGroupsTable = TableRegistry::get('UsersGroups');

    return $usersGroupsTable->find()
        ->select(['user_id'])
        ->where(['group_id' => $groupId])
        ->find('list')
        ->toArray();
}
```

#### ⑪ `Info::getInfoIdList()` — 閲覧可能なお知らせ ID リスト

```php
// 現行（Info.php:108-144）
// SQL: ib_infos LEFT JOIN ib_infos_groups WHERE group_id IS NULL OR group_id IN (...)

// CakePHP 5 移行後
public function getInfoIdList(int $userId, ?int $limit = null): array
{
    $infosGroupsTable = TableRegistry::get('InfosGroups');
    $usersGroupsTable = TableRegistry::get('UsersGroups');

    // ユーザが所属するグループ ID リスト
    $userGroupIds = $usersGroupsTable->find()
        ->select(['group_id'])
        ->where(['user_id' => $userId])
        ->find('list')
        ->toArray();

    $query = $this->find()
        ->select(['Infos.id'])
        ->leftJoinWith('Groups')
        ->where([
            'OR' => [
                'InfosGroups.group_id IS NULL' => true,
                'InfosGroups.group_id IN' => $userGroupIds,
            ]
        ])
        ->group(['Infos.id'])
        ->order(['Infos.created' => 'DESC']);

    if ($limit) {
        $query->limit($limit);
    }

    $infoIds = $query->find('list')->toArray();

    if (empty($infoIds)) {
        $infoIds = [0]; // ダミー ID（app.sql:140-141 のロジック維持）
    }

    return $infoIds;
}
```

> **注**: 現行の raw SQL は `LEFT OUTER JOIN` + `OR` + サブクエリ。CakePHP 5 の `leftJoinWith()` と `OR` 条件で同等を実現可能。

#### ⑫ `Setting::getSettings()` — 設定値取得

```php
// 現行（Setting.php:32-44）
$settings = $this->query("SELECT setting_key, setting_value FROM ib_settings");

// CakePHP 5 移行後
public function getSettings(): array
{
    $result = [];
    $settings = $this->find()
        ->select(['setting_key', 'setting_value'])
        ->toArray();

    foreach ($settings as $setting) {
        $result[$setting->setting_key] = $setting->setting_value;
    }

    return $result;
}
```

#### ⑬ `Setting::setSettings()` — 設定値保存

```php
// 現行（Setting.php:50-61）
foreach ($settings as $key => $value) {
    $params = ['setting_key' => $key, 'setting_value' => $value];
    $this->query("UPDATE ib_settings SET setting_value = :setting_value WHERE setting_key = :setting_key", $params);
}

// CakePHP 5 移行後
public function setSettings(array $settings): void
{
    $connection = $this->getConnection();

    foreach ($settings as $key => $value) {
        $connection->execute(
            'UPDATE ib_settings SET setting_value = :setting_value WHERE setting_key = :setting_key',
            ['setting_key' => $key, 'setting_value' => $value]
        );
    }
}
```

#### ⑱ `ApiBaseController::currentUserCourseIds()` — ユーザのコース ID リスト

```php
// 現行（Controller/ApiBaseController.php:413-424）
$sql = "SELECT course_id FROM ib_users_courses WHERE user_id = :user_id
        UNION
        SELECT gc.course_id FROM ib_groups_courses gc
        INNER JOIN ib_users_groups ug ON gc.group_id = ug.group_id
        WHERE ug.user_id = :user_id";
$rows = $Course->query($sql, ['user_id' => $userId]);

// CakePHP 5 移行後
// UNION は QueryBuilder で直接表現が難しいため、2 回クエリして結合
public function currentUserCourseIds(int $userId): array
{
    $usersCoursesTable = TableRegistry::get('UsersCourses');
    $groupsCoursesTable = TableRegistry::get('GroupsCourses');
    $usersGroupsTable = TableRegistry::get('UsersGroups');

    // 個人受講
    $personalCourseIds = $usersCoursesTable->find()
        ->select(['course_id'])
        ->where(['user_id' => $userId])
        ->find('list')
        ->toArray();

    // グループ経由受講
    $groupCourseIds = $groupsCoursesTable->find()
        ->select(['GroupsCourses.course_id'])
        ->innerJoinWith('UsersGroups')
        ->where(['UsersGroups.user_id' => $userId])
        ->find('list')
        ->toArray();

    return array_unique(array_merge($personalCourseIds, $groupCourseIds));
}
```

> **または raw SQL で保持**: UNION を含むため、可読性を優先する場合は `$this->getConnection()->execute()` で raw SQL を維持。

#### ⑲ `ApiBaseController::currentUserGroupIds()` — ユーザのグループ ID リスト

```php
// 現行（Controller/ApiBaseController.php:444）
$sql = "SELECT group_id FROM ib_users_groups WHERE user_id = :user_id";

// CakePHP 5 移行後
public function currentUserGroupIds(int $userId): array
{
    return TableRegistry::get('UsersGroups')
        ->find()
        ->select(['group_id'])
        ->where(['user_id' => $userId])
        ->find('list')
        ->toArray();
}
```

#### ⑳ `ApiUsersController` — ユーザ別コース一覧

```php
// 現行（Controller/ApiUsersController.php:289-297）
$sql = 'SELECT Course.id, Course.title, ... FROM ib_courses Course
        INNER JOIN ib_users_courses uc ON uc.course_id = Course.id
        WHERE uc.user_id = :user_id AND Course.deleted IS NULL
        ORDER BY Course.sort_no asc';

// CakePHP 5 移行後
public function getUserCourses(int $userId): array
{
    return TableRegistry::get('Courses')
        ->find()
        ->select(['id', 'title', 'introduction', 'opened', 'sort_no', 'user_id', 'created', 'modified'])
        ->innerJoinWith('UsersCourses')
        ->where([
            'UsersCourses.user_id' => $userId,
            'Courses.deleted IS NULL' => true,
        ])
        ->order(['sort_no' => 'ASC'])
        ->toArray();
}
```

#### ㉑ `ApiGroupsController` — グループ別ユーザ一覧

```php
// 現行（Controller/ApiGroupsController.php:144-151）
$sql = 'SELECT User.id, User.username, User.name, User.role, User.email
        FROM ib_users User
        INNER JOIN ib_users_groups ug ON ug.user_id = User.id
        WHERE ug.group_id = :group_id AND User.deleted IS NULL
        ORDER BY User.id asc';

// CakePHP 5 移行後
public function getGroupUsers(int $groupId): array
{
    return TableRegistry::get('Users')
        ->find()
        ->select(['id', 'username', 'name', 'role', 'email'])
        ->innerJoinWith('UsersGroups')
        ->where([
            'UsersGroups.group_id' => $groupId,
            'Users.deleted IS NULL' => true,
        ])
        ->order(['id' => 'ASC'])
        ->toArray();
}
```

### 2.4 raw SQL で保持するもの — MariaDB 11.4 対応

#### ① `Content::getContentRecord()`（Model/Content.php:112-167）

**MariaDB 11.4 対応の注意点**:
- **GROUP BY**: サブクエリ内 `GROUP BY h.content_id`（app.sql:138）は MariaDB 11.4 の `ONLY_FULL_GROUP_BY` でも問題なし（`content_id` で GROUP BY しているが、SELECT も `content_id` のみなので OK）。MariaDB は関数従属性の判定が MySQL より緩い場合があるが、本件では影響なし。
- **サブクエリ**: MariaDB 11.4 でも FROM 句内のサブクエリ（デリベーテッドテーブル）は動作。
- **CAST/DATE_FORMAT**: `DATE_FORMAT(created, '%Y/%m/%d')` は MariaDB 11.4 でも動作。
- **使用関数の互換性**: `DATE_FORMAT`/`IFNULL`/`COUNT`/`MIN`/`MAX`/`SUM`/`FIELD()`/`group_concat()` 等はすべて MariaDB で利用可能。サブクエリ・`INNER JOIN`/`LEFT OUTER JOIN`・`DELETE ... IN (SELECT)` も MariaDB 互換。

**CakePHP 5 での実行方法**:
```php
$connection = $this->getConnection();
$data = $connection->execute($sql, $params)->fetchAll('assoc');
```

> **推測**: 現行の `$this->query($sql, $params)` の戻り値形式（`$data[0]['Content']['field']`）と CakePHP 5 の `execute()->fetchAll('assoc')` の形式が異なる。コントローラからのアクセス形式の修正が必要。

#### ⑯ `UsersCourse::getCourseRecord()`（Model/UsersCourse.php:59-108）

**MariaDB 11.4 対応の注意点**:
- サブクエリ `Record`（app.sql:66-73）の `GROUP BY h.course_id, h.user_id` は `ONLY_FULL_GROUP_BY` で問題なし。
- サブクエリ `CompleteCount`（app.sql:75-87）の `GROUP BY r.course_id, r.content_id` → 外部の `GROUP BY course_id` はサブクエリ内なので問題なし。
- サブクエリ `ContentCount`（app.sql:90-94）の `GROUP BY course_id` は問題なし。

**MariaDB 11.4 で問題となる可能性**: なし。この SQL は `ONLY_FULL_GROUP_BY` に適合。

#### ⑭-⑮ `User::deleteUserRecords()`（Model/User.php:158-172）

```sql
-- SQL #1（app.sql:161）
DELETE FROM ib_records_questions WHERE record_id IN (SELECT id FROM ib_records WHERE user_id = :user_id)
-- SQL #2（app.sql:170）
DELETE FROM ib_records WHERE user_id = :user_id
```

**MariaDB 11.4 対応**: `DELETE ... WHERE ... IN (SELECT ...)` は MariaDB 11.4 でも動作。FK 制約がある場合は `FOREIGN_KEY_CHECKS=0` が必要（app.sql:1 で設定済み）。

**CakePHP 5 での実行方法**:
```php
$connection = $this->getConnection();
$connection->execute($sql, ['user_id' => $userId]);
```

### 2.5 raw SQL 結果の受け取り方の変更

CakePHP 2 の `$this->query($sql, $params)` は連想配列を返すが、CakePHP 5 では `Connection::execute()` の戻り値は `Statement` オブジェクト。

| 現行 (CakePHP 2) | CakePHP 5 |
|---|---|
| `$data = $this->query($sql, $params)` | `$statement = $this->getConnection()->execute($sql, $params)` |
| `$data[0]['Model']['field']` | `$row = $statement->fetch('assoc'); $row['field']` |
| `$data[0][0]['cnt']` | `$row = $statement->fetch('assoc'); $row['cnt']` |
| 全行取得 | `$rows = $statement->fetchAll('assoc')` |

---

## 3. Search の自作化

### 3.1 現行の Search プラグイン構成

| 要素 | ファイル | 役割 |
|---|---|---|
| `Search.Searchable` ビヘイビア | `Model/User.php:134-136` | `$filterArgs` を解析し検索条件を生成 |
| `Search.Searchable` ビヘイビア | `Model/Record.php:85-87` | 同上 |
| `$filterArgs` | `Model/User.php:142-151` | フィールドごとの検索型定義 |
| `$filterArgs` | `Model/Record.php:93-110` | 同上 |
| `Search.Prg` コンポーネント | `Controller/UsersController.php:23` | GET/POST パラメータの処理とセッション保存 |
| `Search.Prg` コンポーネント | `Controller/RecordsController.php:25` | 同上 |
| `$this->Prg->commonProcess()` | `Controller/UsersController.php:260` | パラメータの解析とセットアップ |
| `$this->User->parseCriteria()` | `Model/User.php:263` | `$filterArgs` 基準でクエリ条件を生成 |
| `$this->Record->parseCriteria()` | `Controller/RecordsController.php:45` | 同上 |

### 3.2 CakePHP 5 への変換方針

`CakeDC/Search` プラグインは CakePHP 5 への非互換性が高いため、以下のように手動で QueryBuilder 条件組み立てに変換する。

### 3.3 User 検索の変換設計

#### 現行の filterArgs 定義

```php
// Model/User.php:142-151
$filterArgs = [
    'username' => ['type' => 'like',  'field' => 'User.username'],
    'name'     => ['type' => 'like',  'field' => 'User.name'],
];
```

#### 現行のコントローラ使用箇所

```php
// Controller/UsersController.php:257-310
$this->Prg->commonProcess();
$conditions = $this->User->parseCriteria($this->Prg->parsedParams());
// グループ絞り込みの追加条件
if (($group_id != '') && ($group_id != 0))
    $conditions['User.id'] = $this->fetchTable('Group')->getUserIdByGroupID($group_id);

$this->paginate = [
    'fields' => ['*', /* サブクエリフィールド */],
    'conditions' => $conditions,
    'limit' => 20,
    'order' => 'created desc',
];
$users = $this->paginate();
```

#### CakePHP 5 移行後

```php
// src/Controller/Admin/UsersController.php
public function index(): Response
{
    $query = $this->Users->find();

    // --- ユーザ名検索（like 型） ---
    $username = $this->request->getQuery('username');
    if (!empty($username)) {
        $query->where(['Users.username LIKE' => '%' . $username . '%']);
    }

    // --- 氏名検索（like 型） ---
    $name = $this->request->getQuery('name');
    if (!empty($name)) {
        $query->where(['Users.name LIKE' => '%' . $name . '%']);
    }

    // --- グループ絞り込み ---
    $groupId = $this->request->getQuery('group_id');
    if (!empty($groupId) && $groupId != 0) {
        $userGroupIds = $this->fetchTable('UsersGroups')
            ->find()
            ->select(['user_id'])
            ->where(['group_id' => $groupId])
            ->find('list')
            ->toArray();
        $query->where(['Users.id IN' => $userGroupIds]);
    }

    // --- サブクエリフィールド（グループ名・コース名） ---
    // ※ CakePHP 5 では Expression レイヤーで実装（詳細は別途設計）
    $query->order(['created' => 'DESC']);

    $users = $this->paginate($query);
    $groups = $this->fetchTable('Groups')->find('list');

    $this->set(compact('users', 'groups'));
}
```

### 3.4 Record 検索の変換設計

#### 現行の filterArgs 定義

```php
// Model/Record.php:93-110
$filterArgs = [
    'course_id'      => ['type' => 'value', 'field' => 'course_id'],
    'content_title'  => ['type' => 'like',  'field' => 'Content.title'],
    'username'       => ['type' => 'like',  'field' => 'User.username'],
    'name'           => ['type' => 'like',  'field' => 'User.name'],
];
```

#### 現行のコントローラ使用箇所

```php
// Controller/RecordsController.php:38-97
$this->Prg->commonProcess();
$conditions = $this->Record->parseCriteria($this->Prg->parsedParams());

// 独自の検索条件追加
$group_id          = $this->getQuery('group_id');
$content_category  = $this->getQuery('content_category');

if ($group_id != '')
    $conditions['User.id'] = $this->Group->getUserIdByGroupID($group_id);

if ($content_category == 'study') {
    $conditions['Content.kind'] = ['text', 'html', 'movie', 'url'];
}

$rows = $this->Record->find()
    ->where($conditions)
    ->order('Record.created desc')
    ->all();
```

#### CakePHP 5 移行後

```php
// src/Controller/Admin/RecordsController.php
public function index(): Response
{
    $query = $this->Records->find()
        ->contain(['Users', 'Courses', 'Contents']);

    // --- コース ID 検索（value 型） ---
    $courseId = $this->request->getQuery('course_id');
    if (!empty($courseId)) {
        $query->where(['Records.course_id' => (int)$courseId]);
    }

    // --- コンテンツタイトル検索（like 型） ---
    $contentTitle = $this->request->getQuery('content_title');
    if (!empty($contentTitle)) {
        $query->where(['Contents.title LIKE' => '%' . $contentTitle . '%']);
    }

    // --- ユーザ名検索（like 型） ---
    $username = $this->request->getQuery('username');
    if (!empty($username)) {
        $query->where(['Users.username LIKE' => '%' . $username . '%']);
    }

    // --- 氏名検索（like 型） ---
    $name = $this->request->getQuery('name');
    if (!empty($name)) {
        $query->where(['Users.name LIKE' => '%' . $name . '%']);
    }

    // --- グループ絞り込み（独自条件） ---
    $groupId = $this->request->getQuery('group_id');
    if (!empty($groupId)) {
        $userGroupIds = $this->fetchTable('UsersGroups')
            ->find()
            ->select(['user_id'])
            ->where(['group_id' => $groupId])
            ->find('list')
            ->toArray();
        $query->where(['Records.user_id IN' => $userGroupIds]);
    }

    // --- コンテンツ種別フィルタ（独自条件） ---
    $contentCategory = $this->request->getQuery('content_category');
    if (!empty($contentCategory)) {
        if ($contentCategory === 'study') {
            $query->where(['Contents.kind IN' => ['text', 'html', 'movie', 'url']]);
        } else {
            $query->where(['Contents.kind' => $contentCategory]);
        }
    }

    // --- 日時フィルタ（独自条件） ---
    // 現行: from_date / to_date（RecordsController.php:70-71 推測）
    $fromDate = $this->request->getQuery('from_date');
    $toDate = $this->request->getQuery('to_date');
    if (!empty($fromDate)) {
        $query->where(['Records.created >=' => $fromDate]);
    }
    if (!empty($toDate)) {
        $query->where(['Records.created <=' => $toDate]);
    }

    $query->order(['Records.created' => 'DESC']);
    $records = $this->paginate($query);

    $this->set(compact('records'));
}
```

### 3.5 削除対象の一覧

| ファイル | 削除内容 | 行 |
|---|---|---|
| `Model/User.php` | `$actsAs = ['Search.Searchable']` | 134-136 |
| `Model/User.php` | `$filterArgs` 定義 | 142-151 |
| `Model/Record.php` | `$actsAs = ['Search.Searchable']` | 85-87 |
| `Model/Record.php` | `$filterArgs` 定義 | 93-110 |
| `Controller/UsersController.php` | `'Search.Prg'` コンポーネント | 23（推測: $components の一部） |
| `Controller/UsersController.php` | `$this->Prg->commonProcess()` | 260 |
| `Controller/UsersController.php` | `$this->User->parseCriteria()` | 263 |
| `Controller/RecordsController.php` | `'Search.Prg'` コンポーネント | 25（推測: $components の一部） |
| `Controller/RecordsController.php` | `$this->Prg->commonProcess()` | 41 |
| `Controller/RecordsController.php` | `$this->Record->parseCriteria()` | 45 |

### 3.6 検索条件のマッピング表

| filterArgs フィールド | 検索型 | CakePHP 5 QueryBuilder 条件 |
|---|---|---|
| `User.username` | like | `Users.username LIKE '%{value}%'` |
| `User.name` | like | `Users.name LIKE '%{value}%'` |
| `Record.course_id` | value | `Records.course_id = {value}` |
| `Content.title` | like | `Contents.title LIKE '%{value}%'` |
| `Record.username` → `User.username` | like | `Users.username LIKE '%{value}%'` |
| `Record.name` → `User.name` | like | `Users.name LIKE '%{value}%'` |

---

## 付録: 参照ファイル一覧

| ファイル | 移行対象内容 |
|---|---|
| `Model/AppModel.php:51-180` | メソッドチェーン機構（全廃止） |
| `Model/AppModel.php:191-203` | `queryList()` ヘルパー（廃止） |
| `Model/AppModel.php:28-34` | `alphaNumericMB()` カスタムルール |
| `Model/AppModel.php:39-42` | `get()` ラッパー |
| `Model/Content.php:112-167` | `getContentRecord()` raw SQL（保持） |
| `Model/Content.php:174-187` | `setOrder()` raw SQL（QueryBuilder 変換） |
| `Model/ContentsQuestion.php:79-92` | `setOrder()` raw SQL（QueryBuilder 変換） |
| `Model/Content.php:195-205` | `getNextSortNo()` チェーン |
| `Model/ContentsQuestion.php:100-110` | `getNextSortNo()` チェーン |
| `Model/Course.php:62-75` | `setOrder()` raw SQL（QueryBuilder 変換） |
| `Model/Course.php:84-116` | `hasRight()` raw SQL（QueryBuilder 変換） |
| `Model/Course.php:123-140` | `deleteCourse()` raw SQL（QueryBuilder 変換） |
| `Model/Group.php:63-71` | `getUserIdByGroupID()` raw SQL（QueryBuilder 変換） |
| `Model/Info.php:108-144` | `getInfoIdList()` raw SQL（QueryBuilder 変換） |
| `Model/Setting.php:32-44` | `getSettings()` raw SQL（QueryBuilder 変換） |
| `Model/Setting.php:50-61` | `setSettings()` raw SQL（QueryBuilder 変換） |
| `Model/User.php:158-172` | `deleteUserRecords()` raw SQL（保持） |
| `Model/User.php:134-151` | Search ビヘイビア / filterArgs（廃止） |
| `Model/Record.php:85-110` | Search ビヘイビア / filterArgs（廃止） |
| `Model/UsersCourse.php:59-108` | `getCourseRecord()` raw SQL（保持） |
| `Controller/ApiBaseController.php:413-424` | `currentUserCourseIds()` raw SQL（QueryBuilder 変換） |
| `Controller/ApiBaseController.php:444` | `currentUserGroupIds()` raw SQL（QueryBuilder 変換） |
| `Controller/ApiUsersController.php:289-297` | user courses list raw SQL（QueryBuilder 変換） |
| `Controller/ApiGroupsController.php:144-151` | group users list raw SQL（QueryBuilder 変換） |
| `Controller/UsersController.php:260-263` | Search Prg / parseCriteria（廃止） |
| `Controller/RecordsController.php:41-45` | Search Prg / parseCriteria（廃止） |
