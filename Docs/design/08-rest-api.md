# 08 — REST API 設計（CakePHP 2.10 → 5.x 移行）

## 1. ApiBaseController の移行

### 1.1 現行ファイル

- **現行**: `Controller/ApiBaseController.php`
- **移行先**: `src/Controller/Api/ApiBaseController.php`

> CakePHP 5 では API コントローラは `Api` サブディレクトリに配置し、`namespace App\Controller\Api;` を使用する。

### 1.2 基本構造の変更

**現行** (`Controller/ApiBaseController.php:20-26`):

```php
App::uses('Controller', 'Controller');
App::uses('ClassRegistry', 'Utility');

class ApiBaseController extends Controller
{
    public $components = [];
    public $uses = [];
    public $autoRender = false;
```

**移行先** (`src/Controller/Api/ApiBaseController.php`):

```php
namespace App\Controller\Api;

use App\Controller\AppController;
use Cake\Datasource\ConnectionManager;

class ApiBaseController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        // API では FormProtection 等の不要なコンポーネントをロードしない
        // $autoRender は false のまま維持
        $this->autoRender = false;
    }
```

> **根拠**: `Controller/ApiBaseController.php:17-38`。CakePHP 5 では `$components` プロパティが廃止され、`initialize()` で `$this->loadComponent()` を使用する。API コントローラでは認証関連のコンポーネントのみロードする。

### 1.3 22 メソッドの CakePHP 5 での置換え

ApiBaseController には以下のメソッドが定義されている (`Controller/ApiBaseController.php:40-477`):

#### 1.3.1 `$allowUnauthenticated` プロパティ

**現行** (`Controller/ApiBaseController.php:44`):

```php
protected $allowUnauthenticated = [];
```

**移行先**: 変更不要。CakePHP 5 でもプロパティとして維持する。

#### 1.3.2 `beforeFilter()`

**現行** (`Controller/ApiBaseController.php:69-79`):

```php
public function beforeFilter()
{
    parent::beforeFilter();
    $this->response->type('application/json');
    if(in_array($this->action, $this->allowUnauthenticated, true))
        return;
    $this->authenticateApiRequest();
}
```

**移行先**:

```php
public function beforeFilter(\Cake\Event\EventInterface $event): void
{
    parent::beforeFilter($event);

    $this->response = $this->response->withType('application/json');

    $action = $this->request->getParam('action');
    if (in_array($action, $this->allowUnauthenticated, true)) {
        return;
    }

    $this->authenticateApiRequest();
}
```

| 変更点 | 現行 | CakePHP 5 |
|---|---|---|
| シグネチャ | `beforeFilter()` | `beforeFilter(EventInterface $event): void` |
| レスポンスタイプ | `$this->response->type('application/json')` | `$this->response = $this->response->withType('application/json')` |
| アクション名取得 | `$this->action` | `$this->request->getParam('action')` |

#### 1.3.3 `getAuthorizationHeader()`

**現行** (`Controller/ApiBaseController.php:86-109`):

```php
protected function getAuthorizationHeader()
{
    if(!empty($_SERVER['HTTP_AUTHORIZATION']))
        return trim($_SERVER['HTTP_AUTHORIZATION']);
    if(!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
        return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    // ...
}
```

**移行先**: CakePHP 5 では `ServerRequest` でヘッダ取得が可能。

```php
protected function getAuthorizationHeader(): string
{
    $authorization = $this->request->getHeaderLine('Authorization');
    if ($authorization !== '') {
        return trim($authorization);
    }
    return '';
}
```

> CakePHP 5 の `ServerRequest::getHeaderLine()` は `$_SERVER` からのフォールバックも含むため、レガシーヘッダ（`REDIRECT_HTTP_AUTHORIZATION` 等）への対応は不要になる場合がある。Apache リバースプロキシ環境では引き続き `$_SERVER` 直参照を検討する。

#### 1.3.4 `authenticateApiRequest()`

**現行** (`Controller/ApiBaseController.php:116-137`):

```php
protected function authenticateApiRequest()
{
    $header = $this->getAuthorizationHeader();
    if($header === '')
        $this->fail(401, 'Authorization header is missing');
    if(!preg_match('/^Bearer\s+(.+)$/i', $header, $matches))
        $this->fail(401, 'Authorization header must use the Bearer scheme');
    $token = trim($matches[1]);
    $UserToken = ClassRegistry::init('UserToken');
    $result = $UserToken->authenticateApiToken($token);
    if(!$result || empty($result['user']))
        $this->fail(401, 'Invalid or expired API token');
    $this->apiUser = $result['user'];
    $this->apiTokenId = isset($result['token_id']) ? $result['token_id'] : null;
    $this->apiToken = $token;
}
```

**移行先**:

```php
protected function authenticateApiRequest(): void
{
    $header = $this->getAuthorizationHeader();
    if ($header === '') {
        $this->fail(401, 'Authorization header is missing');
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        $this->fail(401, 'Authorization header must use the Bearer scheme');
    }
    $token = trim($matches[1]);

    $userTokenTable = $this->fetchTable('UserToken');
    $result = $userTokenTable->authenticateApiToken($token);

    if (!$result || empty($result['user'])) {
        $this->fail(401, 'Invalid or expired API token');
    }

    $this->apiUser = $result['user'];
    $this->apiTokenId = isset($result['token_id']) ? (int)$result['token_id'] : null;
    $this->apiToken = $token;
}
```

| 変更点 | 現行 | CakePHP 5 |
|---|---|---|
| モデル取得 | `ClassRegistry::init('UserToken')` | `$this->fetchTable('UserToken')` |

> **根拠**: `Controller/ApiBaseController.php:116-137`。`ClassRegistry::init()` は CakePHP 5 で廃止。`Controller::fetchTable()` に置換。

#### 1.3.5 `input()`

**現行** (`Controller/ApiBaseController.php:144-162`):

```php
protected function input()
{
    $data = $this->request->data;
    if(empty($data)) {
        $raw = $this->request->input();
        // ...
    }
    return is_array($data) ? $data : [];
}
```

**移行先**:

```php
protected function input(): array
{
    $data = $this->request->getData();

    if (empty($data)) {
        $raw = $this->request->getBody();
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }

    return is_array($data) ? $data : [];
}
```

| 変更点 | 現行 | CakePHP 5 |
|---|---|---|
| POST データ取得 | `$this->request->data` | `$this->request->getData()` |
| ボディ取得 | `$this->request->input()` | `$this->request->getBody()` |

#### 1.3.6 `queryParam()` / `wantsExact()`

**現行** (`Controller/ApiBaseController.php:170-193`):

```php
protected function queryParam($key) { ... $this->request->query($key); ... }
protected function wantsExact() { ... $this->request->query('exact'); ... }
```

**移行先**:

```php
protected function queryParam(string $key): ?string
{
    $value = $this->request->getQuery($key);
    if ($value === null || $value === '') {
        return null;
    }
    return $value;
}

protected function wantsExact(): bool
{
    $exact = $this->request->getQuery('exact');
    if ($exact === null || $exact === '') {
        return false;
    }
    return filter_var($exact, FILTER_VALIDATE_BOOLEAN);
}
```

| 変更点 | 現行 | CakePHP 5 |
|---|---|---|
| クエリ取得 | `$this->request->query($key)` | `$this->request->getQuery($key)` |

#### 1.3.7 `respond()` / `ok()` / `okList()` / `fail()`

**現行** (`Controller/ApiBaseController.php:202-277`):

```php
protected function respond($payload, $status = 200)
{
    $this->response->statusCode($status);
    $this->response->type('application/json');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $this->response->body($json);
    return $this->response;
}

protected function fail($status, $message, $errors = null)
{
    // ...
    $this->response->send();
    $this->_stop();
}
```

**移行先**:

```php
protected function respond(array $payload, int $status = 200): \Cake\Http\Response
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = json_encode(['error' => ['code' => 500, 'message' => 'Failed to encode response']]);
        $status = 500;
    }

    $this->response = $this->response
        ->withStatus($status)
        ->withType('application/json')
        ->withStringBody($json);

    return $this->response;
}

protected function fail(int $status, string $message, $errors = null): void
{
    $error = [
        'code' => (int)$status,
        'message' => (string)$message,
    ];
    if ($errors !== null && $errors !== []) {
        $error['errors'] = $errors;
    }

    $this->respond(['error' => $error], $status);
    // CakePHP 5 では _stop() の代わりに Response を返して終了
    throw new \Cake\Http\Exception\HttpException($message, $status);
}
```

| 変更点 | 現行 | CakePHP 5 |
|---|---|---|
| ステータスコード設定 | `$this->response->statusCode($status)` | `$this->response->withStatus($status)` |
| レスポンスタイプ | `$this->response->type('application/json')` | `$this->response->withType('application/json')` |
| ボディ設定 | `$this->response->body($json)` | `$this->response->withStringBody($json)` |
| レスポンス送信 | `$this->response->send()` | 不要（ミドルウェアが送信） |
| 処理停止 | `$this->_stop()` | `throw new HttpException()` or `return $this->response` |

> **根拠**: `Controller/ApiBaseController.php:202-277`。CakePHP 5 の Response はイミュータブル（`with*` メソッド）。`_stop()` は廃止。

#### 1.3.8 `currentUserId()` / `currentRole()` / `isStaff()` / `requireStaff()` / `requireManager()`

**現行** (`Controller/ApiBaseController.php:284-329`):

```php
protected function currentUserId() { return (int)$this->apiUser['id']; }
protected function currentRole()  { return (string)$this->apiUser['role']; }
protected function isStaff()      { return in_array($this->currentRole(), [...], true); }
protected function requireStaff() { if(!$this->isStaff()) $this->fail(403, ...); }
protected function requireManager() { if(!in_array(...)) $this->fail(403, ...); }
```

**移行先**: 変更不要。プロパティとメソッドはそのまま維持できる。

> ただし `$this->fail()` の内部実装が変更されるため、`fail()` の変更に依存する。

#### 1.3.9 `pagination()` / `paginatedList()`

**現行** (`Controller/ApiBaseController.php:336-394`):

```php
protected function pagination() { ... $this->request->query('page') ... }
protected function paginatedList(Model $model, array $findOptions = []) {
    $model->alias  // CakePHP 2
    $model->primaryKey  // CakePHP 2
    $model->find('count', ...)
    $model->find('all', ...)
    $row[$model->alias]  // CakePHP 2 の find 結果形式
}
```

**移行先**:

```php
protected function pagination(): array
{
    $page = (int)$this->request->getQuery('page');
    if ($page < 1) $page = 1;

    $limit = (int)$this->request->getQuery('limit');
    if ($limit < 1) $limit = 50;
    if ($limit > 200) $limit = 200;

    return [
        'page' => $page,
        'limit' => $limit,
        'offset' => ($page - 1) * $limit,
    ];
}

protected function paginatedList(\Cake\ORM\Table $table, array $findOptions = []): array
{
    $paging = $this->pagination();
    $conditions = $findOptions['conditions'] ?? [];

    $total = (int)$table->find('all', ['conditions' => $conditions])->count();

    $query = $table->find()
        ->where($conditions)
        ->limit($paging['limit'])
        ->offset($paging['offset']);

    if (isset($findOptions['fields'])) {
        $query->select($findOptions['fields']);
    }
    if (isset($findOptions['order'])) {
        $query->order($findOptions['order']);
    }

    $rows = [];
    if ($total > 0) {
        $results = $query->all();
        foreach ($results as $row) {
            $rows[] = $row->toArray();
        }
    }

    $meta = [
        'page' => $paging['page'],
        'limit' => $paging['limit'],
        'total' => $total,
        'count' => count($rows),
    ];

    return [$rows, $meta];
}
```

| 変更点 | 現行 | CakePHP 5 |
|---|---|---|
| クエリ取得 | `$this->request->query('page')` | `$this->request->getQuery('page')` |
| モデル型 | `Model` | `\Cake\ORM\Table` |
| テーブルエイリアス | `$model->alias` | `$table->getAlias()` |
| プライマリキー | `$model->primaryKey` | `$table->getPrimaryKey()` |
| 件数取得 | `$model->find('count', ...)` | `$table->find('all', ...)->count()` |
| find 結果 | `$row[$model->alias]` | `$row->toArray()` |
| クエリ構築 | `$model->find('all', $options)` | `$table->find()->where()->limit()->offset()` |

#### 1.3.10 `accessibleCourseIds()` / `currentUserGroupIds()`

**現行** (`Controller/ApiBaseController.php:404-448`):

```php
protected function accessibleCourseIds($userId) {
    $Course = ClassRegistry::init('Course');
    $sql = '...';
    $rows = $Course->query($sql, ['user_id' => $userId]);
    return $this->_collectIds($rows, 'course_id');
}

protected function currentUserGroupIds($userId) {
    $Group = ClassRegistry::init('Group');
    $sql = '...';
    $rows = $Group->query($sql, ['user_id' => $userId]);
    return $this->_collectIds($rows, 'group_id');
}
```

**移行先**:

```php
protected function accessibleCourseIds(int $userId): array
{
    if ($userId <= 0) return [];

    $table = $this->fetchTable('Course');
    $connection = $table->getConnection();

    $sql = 'SELECT course_id FROM ib_users_courses WHERE user_id = :user_id
            UNION
            SELECT gc.course_id FROM ib_groups_courses gc
            INNER JOIN ib_users_groups ug ON gc.group_id = ug.group_id
            WHERE ug.user_id = :user_id';

    $statement = $connection->execute($sql, ['user_id' => $userId]);
    $rows = $statement->fetchAll('assoc');

    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int)$row['course_id'];
    }
    return array_values(array_unique($ids));
}

protected function currentUserGroupIds(int $userId): array
{
    if ($userId <= 0) return [];

    $table = $this->fetchTable('Group');
    $connection = $table->getConnection();

    $sql = 'SELECT group_id FROM ib_users_groups WHERE user_id = :user_id';
    $statement = $connection->execute($sql, ['user_id' => $userId]);
    $rows = $statement->fetchAll('assoc');

    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int)$row['group_id'];
    }
    return array_values(array_unique($ids));
}
```

| 変更点 | 現行 | CakePHP 5 |
|---|---|---|
| モデル取得 | `ClassRegistry::init('Course')` | `$this->fetchTable('Course')` |
| SQL 実行 | `$model->query($sql, $params)` | `$connection->execute($sql, $params)` |
| 結果取得 | `$rows` (CakePHP 2 形式) | `$statement->fetchAll('assoc')` |

#### 1.3.11 `_collectIds()`

**現行** (`Controller/ApiBaseController.php:457-477`):

CakePHP 2 の `query()` 結果の入れ子構造を処理するが、CakePHP 5 では `fetchAll('assoc')` でフラットな配列が返るため、このメソッドは不要になる。

**移行先**: `accessibleCourseIds()` / `currentUserGroupIds()` 内で直接フラットな配列から ID を抽出する形に統合。

### 1.4 `$allowUnauthenticated` プロパティの維持

**現行** (`Controller/ApiBaseController.php:44`):

```php
protected $allowUnauthenticated = [];
```

**移行先**: 変更不要。CakePHP 5 でもプロパティとして維持し、`beforeFilter()` 内で `$this->request->getParam('action')` と比較する。

> **根拠**: `Controller/ApiBaseController.php:44`, `Controller/ApiAuthController.php:29`, `Controller/ApiErrorsController.php:27`。`ApiAuthController` は `issueToken` を、`ApiErrorsController` は `notFound` を認証不要として定義している。

### 1.5 `authenticateApiRequest()` のカスタム Authenticator への移行

CakePHP 5 では認証を `Authentication` プラグインの `Middleware` で実装する。ただし、API のカスタム Bearer 認証は以下のいずれかで実現する:

**案 A: カスタム Authenticator（推奨）**

```php
// src/Authentication/ApiAuthenticator.php
namespace App\Authentication;

use Authentication\Authenticator\AbstractAuthenticator;
use Authentication\Result;
use Psr\Http\Message\ServerRequestInterface;

class ApiAuthenticator extends AbstractAuthenticator
{
    public function authenticate(ServerRequestInterface $request): Result
    {
        $header = $request->getHeaderLine('Authorization');
        // Bearer トークンの検証ロジック
        // 成功: new Result(Result::SUCCESS, $userArray)
        // 失敗: new Result(Result::FAILURE, null, [...])
    }
}
```

**案 B: `beforeFilter()` での従来方式の維持（移行簡易）**

現行の `authenticateApiRequest()` メソッドを CakePHP 5 の API コントローラにそのまま移植し、Authentication プラグインと併用する。

> **推奨**: 案 A を採用する。Authentication プラグインの Authenticator インターフェースに従うことで、フレームワーク標準の認証フローに統合できる。ただし、移行工数の観点から、段階的に案 B → 案 A への移行も検討する。

---

## 2. 全 API コントローラ

### 2.1 ApiAuthController

- **現行**: `Controller/ApiAuthController.php`
- **移行先**: `src/Controller/Api/ApiAuthController.php`
- **使用モデル**: `User`, `UserToken`
- **認証不要**: `issueToken` のみ

| # | アクション | 現行ファイル:行 | 主な CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `issueToken()` | ApiAuthController.php:52 | `$this->User->findByUsername()`, `password_verify()`, `Security::hash()`, `ClassRegistry::init('Log')`, `$this->UserToken->issueApiToken()` | `$this->fetchTable('User')->findBy(['username' => ...])`, `$this->fetchTable('UserToken')->issueApiToken()` |
| 2 | `revokeToken()` | ApiAuthController.php:138 | `$this->UserToken->revokeApiToken($this->apiToken)` | `$this->fetchTable('UserToken')->revokeApiToken($this->apiToken)` |

**ロール判定**: なし（トークン発行は認証不要、トークン失効は認証済み即可）

**リクエスト/レスポンス**:

| アクション | メソッド | パス | 認証 | リクエスト | レスポンス |
|---|---|---|---|---|---|
| `issueToken` | POST | `/api/v1/auth/token` | 不要 | `{username, password, permanent?}` | `201 {data: {token, token_type, permanent, expires, user}}` |
| `revokeToken` | DELETE | `/api/v1/auth/token` | 要 | なし | `200 {data: {revoked: true}}` |

### 2.2 ApiUsersController

- **現行**: `Controller/ApiUsersController.php`
- **移行先**: `src/Controller/Api/ApiUsersController.php`
- **使用モデル**: `User`, `UserToken`, `UsersCourse`

| # | アクション | 現行ファイル:行 | 主な CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index()` | ApiUsersController.php:30 | `$this->User->find()`, `$this->paginatedList()` | `$this->fetchTable('User')->find()`, `$this->paginatedList()` |
| 2 | `view($id)` | ApiUsersController.php:92 | `$this->User->exists()`, `$this->User->findById()` | `$table->exists()`, `$table->findById($id)` |
| 3 | `add()` | ApiUsersController.php:116 | `$this->User->create()`, `$this->User->save()`, `$this->User->validationErrors` | `$table->newEmptyEntity()`, `$table->save($entity)`, `$entity->getErrors()` |
| 4 | `edit($id)` | ApiUsersController.php:150 | `$this->User->exists()`, `$this->User->findById()`, `$this->User->save()` | `$table->exists()`, `$table->findById($id)`, `$table->save()` |
| 5 | `changePassword($id)` | ApiUsersController.php:208 | `$this->User->save()`, `$this->UserToken->revokeAllForUser()`, `$this->UserToken->revokeAllApiForUser()` | `$table->save()`, `$table->revokeAllForUser()` |
| 6 | `delete($id)` | ApiUsersController.php:251 | `$this->User->exists()`, `$this->User->delete()` | `$table->exists()`, `$table->delete($id)` |
| 7 | `courses($id)` | ApiUsersController.php:275 | `ClassRegistry::init('Course')`, `$Course->query()` | `$this->fetchTable('Course')`, `$connection->execute()` |
| 8 | `assignCourse($id)` | ApiUsersController.php:330 | `ClassRegistry::init('Course')`, `$this->UsersCourse->find('first')`, `$this->UsersCourse->create()`, `$this->UsersCourse->save()` | `$this->fetchTable('Course')`, `$table->find('all')->where()->first()`, `$table->newEmptyEntity()`, `$table->save()` |
| 9 | `unassignCourse($id, $course_id)` | ApiUsersController.php:385 | `$this->UsersCourse->find('first')`, `$this->UsersCourse->delete()` | `$table->find('all')->where()->first()`, `$table->delete()` |

**ロール判定**:

| アクション | ロール | 条件 |
|---|---|---|
| `index` | 認証済み即可 | staff: 全件 / user: 自分のみ |
| `view` | 認証済み即可 | staff: 全件 / user: 自分のみ |
| `add` | `requireManager()` | admin / manager |
| `edit` | `requireManager()` | admin / manager（admin アカウント変更は admin のみ） |
| `changePassword` | `requireManager()` | admin / manager（admin パスワード変更は admin のみ） |
| `delete` | `requireManager()` | admin / manager（自分自身は削除不可） |
| `courses` | 認証済み即可 | staff: 全件 / user: 自分のみ |
| `assignCourse` | `requireManager()` | admin / manager |
| `unassignCourse` | `requireManager()` | admin / manager |

### 2.3 ApiCoursesController

- **現行**: `Controller/ApiCoursesController.php`
- **移行先**: `src/Controller/Api/ApiCoursesController.php`
- **使用モデル**: `Course`

| # | アクション | 現行ファイル:行 | 主な CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index()` | ApiCoursesController.php:30 | `$this->Course->find()`, `$this->paginatedList()`, `$this->accessibleCourseIds()` | `$this->fetchTable('Course')->find()`, `$this->paginatedList()` |
| 2 | `view($id)` | ApiCoursesController.php:89 | `$this->Course->exists()`, `$this->Course->findById()` | `$table->exists()`, `$table->findById($id)` |
| 3 | `add()` | ApiCoursesController.php:116 | `$this->Course->find('first')`, `$this->Course->create()`, `$this->Course->save()` | `$table->find('all')->where()->first()`, `$table->newEmptyEntity()`, `$table->save()` |
| 4 | `delete($id)` | ApiCoursesController.php:167 | `$this->Course->exists()`, `$this->Course->deleteCourse()` | `$table->exists()`, `$table->deleteCourse($id)` |

**ロール判定**:

| アクション | ロール |
|---|---|
| `index` | 認証済み即可（staff: 全件 / user: 受講可能のみ） |
| `view` | 認証済み即可（staff: 全件 / user: 受講可能のみ） |
| `add` | `requireManager()` |
| `delete` | `requireManager()` |

### 2.4 ApiContentsController

- **現行**: `Controller/ApiContentsController.php`
- **移行先**: `src/Controller/Api/ApiContentsController.php`
- **使用モデル**: `Content`

| # | アクション | 現行ファイル:行 | 主な CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index()` | ApiContentsController.php:30 | `$this->Content->find()`, `$this->paginatedList()` | `$this->fetchTable('Content')->find()`, `$this->paginatedList()` |
| 2 | `view($id)` | ApiContentsController.php:96 | `$this->Content->exists()`, `$this->Content->findById()` | `$table->exists()`, `$table->findById($id)` |

**ロール判定**:

| アクション | ロール |
|---|---|
| `index` | 認証済み即可（staff: 全件 / user: 受講可能かつ公開のみ） |
| `view` | 認証済み即可（staff: 全件 / user: 受講可能かつ公開のみ） |

### 2.5 ApiRecordsController

- **現行**: `Controller/ApiRecordsController.php`
- **移行先**: `src/Controller/Api/ApiRecordsController.php`
- **使用モデル**: `Record`

| # | アクション | 現行ファイル:行 | 主な CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index()` | ApiRecordsController.php:30 | `$this->Record->find()`, `$this->paginatedList()` | `$this->fetchTable('Record')->find()`, `$this->paginatedList()` |
| 2 | `view($id)` | ApiRecordsController.php:89 | `$this->Record->exists()`, `$this->Record->findById()` | `$table->exists()`, `$table->findById($id)` |

**ロール判定**:

| アクション | ロール |
|---|---|
| `index` | 認証済み即可（staff: 任意 / user: 自分のみ） |
| `view` | 認証済み即可（staff: 任意 / user: 自分のみ） |

### 2.6 ApiGroupsController

- **現行**: `Controller/ApiGroupsController.php`
- **移行先**: `src/Controller/Api/ApiGroupsController.php`
- **使用モデル**: `Group`, `UsersGroup`

| # | アクション | 現行ファイル:行 | 主な CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index()` | ApiGroupsController.php:30 | `$this->Group->find()`, `$this->paginatedList()`, `$this->currentUserGroupIds()` | `$this->fetchTable('Group')->find()`, `$this->paginatedList()` |
| 2 | `view($id)` | ApiGroupsController.php:97 | `$this->Group->exists()`, `$this->Group->findById()` | `$table->exists()`, `$table->findById($id)` |
| 3 | `users($id)` | ApiGroupsController.php:125 | `ClassRegistry::init('User')`, `$User->query()` | `$this->fetchTable('User')`, `$connection->execute()` |
| 4 | `assignUser($id)` | ApiGroupsController.php:180 | `ClassRegistry::init('User')`, `$this->UsersGroup->find('first')`, `$this->UsersGroup->create()`, `$this->UsersGroup->save()` | `$this->fetchTable('User')`, `$table->find('all')->where()->first()`, `$table->newEmptyEntity()`, `$table->save()` |
| 5 | `unassignUser($id, $user_id)` | ApiGroupsController.php:235 | `$this->UsersGroup->find('first')`, `$this->UsersGroup->delete()` | `$table->find('all')->where()->first()`, `$table->delete()` |

**ロール判定**:

| アクション | ロール |
|---|---|
| `index` | 認証済み即可（staff: 全件 / user: 所属のみ） |
| `view` | 認証済み即可（staff: 全件 / user: 所属のみ） |
| `users` | 認証済み即可（staff: 全件 / user: 所属のみ） |
| `assignUser` | `requireManager()` |
| `unassignUser` | `requireManager()` |

### 2.7 ApiErrorsController

- **現行**: `Controller/ApiErrorsController.php`
- **移行先**: `src/Controller/Api/ApiErrorsController.php`
- **使用モデル**: なし
- **認証不要**: `notFound` のみ

| # | アクション | 現行ファイル:行 | 主な CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `notFound()` | ApiErrorsController.php:34 | `$this->fail(404, ...)` | `$this->fail(404, ...)` — メソッド内の変更のみ |

---

## 3. API エンドポイント一覧（24 エンドポイント）

| # | メソッド | パス | アクション | 認証 | ロール | リクエスト | レスポンス形式 |
|---|---|---|---|---|---|---|---|
| 1 | POST | `/api/v1/auth/token` | `ApiAuth::issueToken` | 不要 | — | `{username, password, permanent?}` | `201 {data: {token, token_type, permanent, expires, user}}` |
| 2 | DELETE | `/api/v1/auth/token` | `ApiAuth::revokeToken` | 認証済 | — | なし | `200 {data: {revoked: true}}` |
| 3 | GET | `/api/v1/users` | `ApiUsers::index` | 認証済 | staff: 全件 / user: 自分 | `?username=&name=&role=&page=&limit=` | `200 {data: [...], meta: {page,limit,total,count}}` |
| 4 | GET | `/api/v1/users/{id}` | `ApiUsers::view` | 認証済 | staff: 全件 / user: 自分 | — | `200 {data: {user}}` |
| 5 | POST | `/api/v1/users` | `ApiUsers::add` | 認証済 | admin/manager | `{username, password, name, role, ...}` | `201 {data: {user}}` |
| 6 | PUT/PATCH | `/api/v1/users/{id}` | `ApiUsers::edit` | 認証済 | admin/manager | `{role?, name?, email?, ...}` | `200 {data: {user}}` |
| 7 | DELETE | `/api/v1/users/{id}` | `ApiUsers::delete` | 認証済 | admin/manager | — | `200 {data: {id, deleted: true}}` |
| 8 | PUT/PATCH | `/api/v1/users/{id}/password` | `ApiUsers::changePassword` | 認証済 | admin/manager | `{password}` | `200 {data: {id, password_changed: true}}` |
| 9 | GET | `/api/v1/users/{id}/courses` | `ApiUsers::courses` | 認証済 | staff: 全件 / user: 自分 | — | `200 {data: [...], meta: {count}}` |
| 10 | POST | `/api/v1/users/{id}/courses` | `ApiUsers::assignCourse` | 認証済 | admin/manager | `{course_id}` | `201 {data: {user_id, course_id, assigned, created}}` |
| 11 | DELETE | `/api/v1/users/{id}/courses/{course_id}` | `ApiUsers::unassignCourse` | 認証済 | admin/manager | — | `200 {data: {user_id, course_id, deleted}}` |
| 12 | GET | `/api/v1/courses` | `ApiCourses::index` | 認証済 | staff: 全件 / user: 受講可能 | `?title=&page=&limit=` | `200 {data: [...], meta: {page,limit,total,count}}` |
| 13 | GET | `/api/v1/courses/{id}` | `ApiCourses::view` | 認証済 | staff: 全件 / user: 受講可能 | — | `200 {data: {course}}` |
| 14 | POST | `/api/v1/courses` | `ApiCourses::add` | 認証済 | admin/manager | `{title, introduction?, ...}` | `201 {data: {course}}` |
| 15 | DELETE | `/api/v1/courses/{id}` | `ApiCourses::delete` | 認証済 | admin/manager | — | `200 {data: {id, deleted: true}}` |
| 16 | GET | `/api/v1/contents` | `ApiContents::index` | 認証済 | staff: 全件 / user: 受講可能かつ公開 | `?course_id=&kind=&status=&page=&limit=` | `200 {data: [...], meta: {page,limit,total,count}}` |
| 17 | GET | `/api/v1/contents/{id}` | `ApiContents::view` | 認証済 | staff: 全件 / user: 受講可能かつ公開 | — | `200 {data: {content}}` |
| 18 | GET | `/api/v1/records` | `ApiRecords::index` | 認証済 | staff: 任意 / user: 自分のみ | `?user_id=&course_id=&content_id=&from=&to=&page=&limit=` | `200 {data: [...], meta: {page,limit,total,count}}` |
| 19 | GET | `/api/v1/records/{id}` | `ApiRecords::view` | 認証済 | staff: 任意 / user: 自分のみ | — | `200 {data: {record}}` |
| 20 | GET | `/api/v1/groups` | `ApiGroups::index` | 認証済 | staff: 全件 / user: 所属のみ | `?title=&status=&page=&limit=` | `200 {data: [...], meta: {page,limit,total,count}}` |
| 21 | GET | `/api/v1/groups/{id}` | `ApiGroups::view` | 認証済 | staff: 全件 / user: 所属のみ | — | `200 {data: {group}}` |
| 22 | GET | `/api/v1/groups/{id}/users` | `ApiGroups::users` | 認証済 | staff: 全件 / user: 所属のみ | — | `200 {data: [...], meta: {count}}` |
| 23 | POST | `/api/v1/groups/{id}/users` | `ApiGroups::assignUser` | 認証済 | admin/manager | `{user_id}` | `201 {data: {group_id, user_id, assigned, created}}` |
| 24 | DELETE | `/api/v1/groups/{id}/users/{user_id}` | `ApiGroups::unassignUser` | 認証済 | admin/manager | — | `200 {data: {group_id, user_id, deleted}}` |

---

## 4. レスポンス形式

### 4.1 単一データ

```json
{
  "data": {
    "id": 1,
    "username": "admin",
    "name": "admin",
    "role": "admin"
  }
}
```

> **根拠**: `Controller/ApiBaseController.php:235-238`（`ok()` メソッド）。

### 4.2 一覧データ（ページング付き）

```json
{
  "data": [
    { "id": 1, "username": "admin" },
    { "id": 2, "username": "user1" }
  ],
  "meta": {
    "page": 1,
    "limit": 50,
    "total": 120,
    "count": 2
  }
}
```

> **根拠**: `Controller/ApiBaseController.php:248-254`（`okList()` メソッド）。

### 4.3 エラー

```json
{
  "error": {
    "code": 400,
    "message": "Validation failed",
    "errors": {
      "username": ["ログインIDは4文字以上32文字以内で入力して下さい"],
      "name": ["氏名が入力されていません"]
    }
  }
}
```

> **根拠**: `Controller/ApiBaseController.php:264-277`（`fail()` メソッド）。`errors` はバリデーションエラー時のみ付与。

### 4.4 CakePHP 5 でのレスポンス構築の変更

```php
// CakePHP 2
$this->response->statusCode($status);
$this->response->type('application/json');
$this->response->body($json);
return $this->response;

// CakePHP 5
$this->response = $this->response
    ->withStatus($status)
    ->withType('application/json')
    ->withStringBody($json);
return $this->response;
```

> **根拠**: `Controller/ApiBaseController.php:202-226`。CakePHP 5 の Response はイミュータブルで、`with*` メソッドで新しいインスタンスを返す。

---

## 5. エラーハンドリング

### 5.1 ステータスコード対応表

| ステータス | 意味 | 発生ケース | 現行の実装箇所 |
|---|---|---|---|
| **200** | 成功 | 参照・更新・削除・割当済みの再登録 | `ApiBaseController.php:235`（`ok()`） |
| **201** | 成功（作成） | ユーザ/コース追加、新規割当 | `ApiBaseController.php:235`（`ok()` の第3引数） |
| **400** | パラメータ不正 | 必須フィールド未指定、バリデーション失敗 | `ApiBaseController.php:264`（`fail()`） |
| **401** | 未認証 | Authorization ヘッダなし、トークン不正/期限切れ | `ApiBaseController.php:121-124`, `ApiAuthController.php:60-87` |
| **403** | 権限不足 | staff 権限が必要なアクションに user がアクセス、admin アカウント変更等 | `ApiBaseController.php:317,328`（`requireStaff()`, `requireManager()`） |
| **404** | リソース未存在 | ユーザ/コース/コンテンツ/レコード/グループが存在しない、未定義エンドポイント | 各コントローラの `exists()` チェック、`ApiErrorsController.php:36` |
| **429** | レート制限 | ログイン試行回数が上限（1時間に10回）に達した | `ApiAuthController.php:64` |
| **500** | サーバ内部エラー | トークン発行失敗、コース割当失敗、JSON エンコード失敗 | `ApiBaseController.php:221`, `ApiAuthController.php:115` |

### 5.2 CakePHP 5 でのエラーハンドリングの変更

**現行** (`Controller/ApiBaseController.php:264-277`):

```php
protected function fail($status, $message, $errors = null)
{
    // ...
    $this->respond(['error' => $error], $status);
    $this->response->send();
    $this->_stop();
}
```

**移行先**:

```php
protected function fail(int $status, string $message, $errors = null): void
{
    $error = [
        'code' => (int)$status,
        'message' => (string)$message,
    ];
    if ($errors !== null && $errors !== []) {
        $error['errors'] = $errors;
    }

    $response = $this->respond(['error' => $error], $status);

    // CakePHP 5 では _stop() が廃止
    // 方法 A: HttpException を投げる
    throw new \Cake\Http\Exception\HttpException($message, $status);

    // 方法 B: Response を返して処理を終了（呼び出し元で return が必要）
    // return $response;
}
```

> **根拠**: `Controller/ApiBaseController.php:264-277`。CakePHP 5 では `$this->_stop()` が廃止。例外を投げるか、`return $this->response` で呼び出し元に制御を戻す。

### 5.3 429 レート制限の維持

**現行** (`Controller/ApiAuthController.php:152-168`):

```php
private function _isRateLimited($username)
{
    $threshold = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $Log = ClassRegistry::init('Log');
    $count = $Log->find('count', [
        'conditions' => [
            'Log.log_type' => 'api_login_error',
            'Log.log_content' => $username,
            'Log.created >=' => $threshold,
        ]
    ]);
    return ($count >= self::MAX_LOGIN_ATTEMPTS);
}
```

**移行先**:

```php
private function _isRateLimited(string $username): bool
{
    if ($username === '') return false;

    $threshold = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $logTable = $this->fetchTable('Log');

    $count = $logTable->find('all', [
        'conditions' => [
            'Log.log_type' => 'api_login_error',
            'Log.log_content' => $username,
            'Log.created >=' => $threshold,
        ]
    ])->count();

    return ($count >= self::MAX_LOGIN_ATTEMPTS);
}
```

| 変更点 | 現行 | CakePHP 5 |
|---|---|---|
| モデル取得 | `ClassRegistry::init('Log')` | `$this->fetchTable('Log')` |
| 件数取得 | `$Log->find('count', ...)` | `$logTable->find('all', ...)->count()` |

> **根拠**: `Controller/ApiAuthController.php:152-168`。`ClassRegistry::init()` は CakePHP 5 で廃止。

### 5.4 バリデーションエラーの形式

```php
// CakePHP 2
$this->fail(400, 'Validation failed', $this->User->validationErrors);

// CakePHP 5
$entity = $table->save($entity);
if (!$entity) {
    $this->fail(400, 'Validation failed', $entity->getErrors());
}
```

> **根拠**: `Controller/ApiUsersController.php:141`, `Controller/ApiCoursesController.php:158`。CakePHP 5 では Entity の `getErrors()` メソッドでバリデーションエラーを取得する。

---

## 6. 共通の変更パターン（API コントローラ共通）

### 6.1 `ClassRegistry::init()` → `$this->fetchTable()`

```php
// CakePHP 2
$UserToken = ClassRegistry::init('UserToken');
$Course = ClassRegistry::init('Course');
$Group = ClassRegistry::init('Group');
$Log = ClassRegistry::init('Log');

// CakePHP 5
$userTokenTable = $this->fetchTable('UserToken');
$courseTable = $this->fetchTable('Course');
$groupTable = $this->fetchTable('Group');
$logTable = $this->fetchTable('Log');
```

> 全 API コントローラで `ClassRegistry::init()` が使用されている箇所:
> - `ApiBaseController.php:128` — UserToken
> - `ApiBaseController.php:411` — Course
> - `ApiBaseController.php:442` — Group
> - `ApiAuthController.php:159,189` — Log
> - `ApiUsersController.php:287,347` — Course
> - `ApiGroupsController.php:142,197` — User

### 6.2 `$this->request->data` → `$this->request->getData()`

```php
// CakePHP 2
$data = $this->request->data;

// CakePHP 5
$data = $this->request->getData();
```

> **根拠**: `Controller/ApiBaseController.php:146`（`input()` メソッド）。

### 6.3 `$this->response->statusCode()` → `$this->response->withStatus()`

```php
// CakePHP 2
$this->response->statusCode($status);

// CakePHP 5
$this->response = $this->response->withStatus($status);
```

> **根拠**: `Controller/ApiBaseController.php:206`。

### 6.4 `$this->response->type()` → `$this->response->withType()`

```php
// CakePHP 2
$this->response->type('application/json');

// CakePHP 5
$this->response = $this->response->withType('application/json');
```

> **根拠**: `Controller/ApiBaseController.php:73,216`。

### 6.5 `$this->response->body()` → `$this->response->withStringBody()`

```php
// CakePHP 2
$this->response->body($json);

// CakePHP 5
$this->response = $this->response->withStringBody($json);
```

> **根拠**: `Controller/ApiBaseController.php:223`。

### 6.6 `$this->response->send()` + `$this->_stop()` → 例外

```php
// CakePHP 2
$this->response->send();
$this->_stop();

// CakePHP 5
throw new \Cake\Http\Exception\HttpException($message, $status);
// or
return $this->response;  // ミドルウェアが送信
```

> **根拠**: `Controller/ApiBaseController.php:275-276`。CakePHP 5 では `$this->_stop()` が廃止。

### 6.7 `$model->query()` → `$connection->execute()`

```php
// CakePHP 2
$rows = $model->query($sql, ['user_id' => $userId]);

// CakePHP 5
$connection = $table->getConnection();
$statement = $connection->execute($sql, ['user_id' => $userId]);
$rows = $statement->fetchAll('assoc');
```

> **根拠**: `Controller/ApiBaseController.php:424,445`, `Controller/ApiUsersController.php:297`, `Controller/ApiGroupsController.php:151`。

### 6.8 `$model->alias` / `$model->primaryKey`

```php
// CakePHP 2
$model->alias   // 例: 'User'
$model->primaryKey  // 例: 'id'

// CakePHP 5
$table->getAlias()   // 例: 'Users'
$table->getPrimaryKey()  // 例: 'id'
```

> **根拠**: `Controller/ApiBaseController.php:374`（`paginatedList()` メソッド内）。

### 6.9 `$model->find('first')` → `$table->find('all')->where()->first()`

```php
// CakePHP 2
$existing = $this->UsersCourse->find('first', [
    'conditions' => [...],
    'recursive' => -1,
]);

// CakePHP 5
$existing = $table->find('all', [
    'conditions' => [...],
])->first();
```

> **根拠**: `Controller/ApiUsersController.php:353-358`, `Controller/ApiCoursesController.php:134-138` 等。

---

## 7. 注意事項

### 7.1 `_stop()` の廃止

CakePHP 5 では `Controller::_stop()` が廃止。API の `fail()` メソッドは例外を投げるか、Response を返す形に変更する。例外を投げる場合、`ErrorHandler` や `ExceptionRenderer` で JSON エラーレスポンスを返すよう設定が必要。

### 7.2 `Model` 型の廃止

CakePHP 5 では `Model` クラスが `Table` クラスに統合。`paginatedList()` メソッドの型ヒントを `Model` → `\Cake\ORM\Table` に変更する。

### 7.3 `response` プロパティのイミュータビリティ

CakePHP 5 の Response はイミュータブル。`$this->response->type('json')` のように直接変更できず、`$this->response = $this->response->withType('json')` のように新しいインスタンスを代入する必要がある。

### 7.4 `query()` メソッドの戻り値

CakePHP 2 の `$model->query()` は配列の配列を返すが、CakePHP 5 の `$connection->execute()` は `Statement` オブジェクトを返す。`fetchAll('assoc')` で配列に変換する必要がある。

### 7.5 レスポンス形式の維持

API のレスポンス形式（`{ data: ... }` / `{ data: [...], meta: {...} }` / `{ error: {...} }`）は完全に維持する。`ApiBaseController` の `ok()`, `okList()`, `fail()` メソッドの内部実装は変わるが、出力形式は同一。

### 7.6 未定義エンドポイントのフォールバック

現行の `ApiErrorsController` とルーティング (`Config/routes.php:81-85`) は、未定義の `/api/v1/*` に対して JSON の 404 を返す。CakePHP 5 では `routes.php` の `$routes->scope()` 内で同等のルート定義を行い、`ApiErrorsController::notFound()` を維持する。
