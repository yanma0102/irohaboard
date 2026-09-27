# 07 — Controller 設計（CakePHP 2.10 → 5.x 移行）

## 1. AppController の移行

### 1.1 現行ファイル

- **現行**: `Controller/AppController.php`
- **移行先**: `src/Controller/AppController.php`

### 1.2 `$components` の変更

**現行** (`Controller/AppController.php:24-40`):

```php
public $components = [
    'DebugKit.Toolbar',
    'Session',
    'Cookie' => ['httpOnly' => true],
    'Flash',
    'Auth' => [
        'loginRedirect' => ['controller' => 'users_courses', 'action' => 'index'],
        'logoutRedirect' => ['controller' => 'users','action' => 'login','home'],
        'authError' => false
    ],
    'Security' => ['className' => 'AppSecurity'],
];
```

**移行先** (`src/Controller/AppController.php`):

```php
use Cake\Controller\Controller;

class AppController extends Controller
{
    public function initialize(): void
    {
        parent::initialize();

        // Flash のみ（Session/Cookie は Middleware に移行）
        $this->loadComponent('Flash');

        // FormProtection コンポーネント（CakePHP 5 の Security 代替）
        $this->loadComponent('FormProtection');

        // AppSecurity（カスタムコンポーネントを維持する場合）
        // $this->loadComponent('AppSecurity');

        // Authentication プラグイン（CakePHP 5 の Auth 代替）
        // ※ 認証設定は Middleware で行うため、ここでは loadComponent のみ
    }
}
```

| CakePHP 2 コンポーネント | CakePHP 5 での対応 |
|---|---|
| `DebugKit.Toolbar` | `DebugKit.Toolbar`（APP_DEBUG=true 時に自動ロード） |
| `Session` | **不要**（`SessionMiddleware` が担当） |
| `Cookie` | **不要**（`Authentication` プラグイン + CookieAuthenticator が担当） |
| `Flash` | `$this->loadComponent('Flash')` |
| `Auth` | **不要**（`Authentication` プラグイン + `ApplicationMiddleware` が担当） |
| `Security` | `$this->loadComponent('FormProtection')` |

> **根拠**: `Controller/AppController.php:24-40`。CakePHP 5 では `Auth` コンポーネントが廃止され、`cakephp/authentication` プラグインに統合される。`Security` コンポーネントは `FormProtectionComponent` に置き換えられる。

### 1.3 `$helpers` の変更

**現行** (`Controller/AppController.php:46-51`):

```php
public $helpers = [
    'Session',
    'Html' => ['className' => 'BoostCake.BoostCakeHtml'],
    'Form' => ['className' => 'AppBoostCakeForm'],
    'Paginator' => ['className' => 'BoostCake.BoostCakePaginator'],
];
```

**移行先** (`src/View/AppView.php` の `initialize()`):

```php
// src/View/AppView.php
public function initialize(): void
{
    parent::initialize();

    $this->loadHelper('Html', ['className' => 'BootstrapUI.Html']);
    $this->loadHelper('Form', ['className' => 'BootstrapUI.Form']);
    $this->loadHelper('Paginator', ['className' => 'BootstrapUI.Paginator']);
    $this->loadHelper('Session');
}
```

> **根拠**: `Controller/AppController.php:46-51`。CakePHP 5 では `$helpers` プロパティが廃止され、`View::initialize()` 内で `$this->loadHelper()` を使用する。BoostCake → Bootstrap UI に置換。

### 1.4 `$uses` の廃止と `$viewClass` の変更

**現行** (`Controller/AppController.php:53-54`):

```php
public $uses = ['Setting', 'Group'];
public $viewClass = 'App';
```

**移行先**:

- `$uses` は CakePHP 5 で廃止。各アクションで `$this->fetchTable()` を使用。
- `$viewClass = 'App'` は不要（`AppView` が自動使用される）。

> **根拠**: `Controller/AppController.php:53-54`。CakePHP 5 では `$uses` プロパティが廃止。`fetchTable()` メソッドは `AppController.php:363-369` に既に定義済み。

### 1.5 `beforeFilter()` の変更

**現行** (`Controller/AppController.php:59-142`):

CakePHP 5 では `beforeFilter` はイベントリストナーとして登録する。

```php
// CakePHP 5 移行先
public function beforeFilter(\Cake\Event\EventInterface $event): void
{
    // 認証済みユーザ情報のセット
    // ※ Authentication プラグインで Identity から取得

    // 管理画面判定・ロールチェック
    if ($this->isAdminPage()) {
        // role チェックは Authentication プラグインの Authorization で実装
    }

    // ログインリダイレクト設定
    // ※ Authentication プラグインの middleware 設定で実装
}
```

**主な変更点**:

| 現行 (CakePHP 2) | 移行先 (CakePHP 5) |
|---|---|
| `$this->Auth->user()` | `$this->Authentication->getIdentity()` |
| `$this->Auth->loginAction` | `Authentication` プラグインの `loginAction` 設定 |
| `$this->Auth->logout()` | `$this->Authentication->logout()` |
| `$this->Session->read()` | `$this->request->getSession()->read()` |
| `$this->Cookie->read()` | Cookie 直接アクセス or CookieAuthenticator |
| `$this->Security->blackHoleCallback` | `FormProtectionComponent` のエラーハンドリング |

> **根拠**: `Controller/AppController.php:59-142`。CakePHP 5 では `beforeFilter` のシグネチャが `EventInterface` パラメータ付きになる。

### 1.6 ヘルパーメソッドの変更

AppController に定義されたヘルパーメソッド群（`Controller/AppController.php:176-477`）:

| メソッド | 現行の実装 | CakePHP 5 での対応 |
|---|---|---|
| `readSession($key)` | `$this->Session->read()` | `$this->request->getSession()->read()` |
| `deleteSession($key)` | `$this->Session->delete()` | `$this->request->getSession()->delete()` |
| `hasSession($key)` | `$this->Session->check()` | `$this->request->getSession()->check()` |
| `writeSession($key, $value)` | `$this->Session->write()` | `$this->request->getSession()->write()` |
| `readCookie($key)` | `$this->Cookie->read()` | Cookie 直接アクセス |
| `deleteCookie($key)` | `$this->Cookie->delete()` | Cookie 削除ロジック |
| `hasCookie($key)` | `$this->Cookie->check()` | Cookie 存在チェック |
| `writeCookie($key, $value)` | `$this->Cookie->write()` | Cookie 書き込みロジック |
| `readAuthUser($key)` | `$this->Auth->user()` | `$this->Authentication->getIdentity()` |
| `isLogined()` | `$this->Auth->user()` | `$this->Authentication->getIdentity()` |
| `getQuery($key)` | `$this->request->query[$key]` | `$this->request->getQuery($key)` |
| `hasQuery($key)` | `isset($this->request->query[$key])` | `$this->request->getQuery($key) !== null` |
| `getParam($key)` | `$this->request->params[$key]` | `$this->request->getParam($key)` |
| `getData($key)` | `$this->request->data` | `$this->request->getData($key)` |
| `setData($key, $value)` | `$this->request->data[$key] = $value` | `$this->request = $this->request->withData($key, $value)` |
| `fetchTable($modelClass)` | `$this->loadModel()` | `$this->fetchTable()`（ CakePHP 5 でネイティブ対応） |
| `isAdminPage()` | `$this->request->params['admin']` | `$this->request->getParam('prefix') === 'Admin'` |
| `isEditPage()` | `$this->action == 'edit'` | `$this->request->getParam('action') === 'edit'` |
| `isRecordPage()` | `$this->action == 'record'` | `$this->request->getParam('action') === 'record'` |
| `isLoginPage()` | `$this->action == 'login'` | `$this->request->getParam('action') === 'login'` |
| `isHTTPS()` | `$_SERVER` 直接アクセス | `$this->request->is('https')` |
| `writeLog($type, $content)` | `$this->fetchTable('Log')` | 変更不要（fetchTable は CakePHP 5 でネイティブ対応） |

> **根拠**: `Controller/AppController.php:176-477`。CakePHP 5 では `$this->request` が `ServerRequest` オブジェクトに変更され、アクセサメソッドが異なる。

### 1.7 `AppView` の変更

**現行** (`View/AppView.php:1-148`):

CakePHP 5 での `AppView` の主な変更:

| メソッド | 現行 | CakePHP 5 |
|---|---|---|
| `readSession()` | `$this->Session->read()` | `$this->request->getSession()->read()` |
| `readAuthUser()` | `$this->Session->read('Auth.User')` | `$this->request->getAttribute('identity')` |
| `isAdminPage()` | `$this->request->params['admin']` | `$this->request->getParam('prefix') === 'Admin'` |
| `isEditPage()` | `$this->action == 'edit'` | `$this->request->getParam('action') === 'edit'` |
| `isRecordPage()` | `$this->action == 'record'` | `$this->request->getParam('action') === 'record'` |
| `isLoginPage()` | `$this->action == 'login'` | `$this->request->getParam('action') === 'login'` |
| `isHTTPS()` | `$_SERVER` 直接 | `$this->request->is('https')` |
| `isLocalIP()` | `$_SERVER['REMOTE_ADDR']` | `$this->request->clientIp()` |

> **根拠**: `View/AppView.php:1-148`。CakePHP 5 では View も `RequestHandler` を使用し、`$this->request` が `ServerRequest` になる。

---

## 2. 全コントローラの移行マッピング

### 2.1 UsersController

- **現行**: `Controller/UsersController.php`
- **移行先**: `src/Controller/UsersController.php`
- **コンポーネント変更**: `$this->loadComponent('Paginator')`, `$this->loadComponent('FormProtection', [...])`

| # | アクション | 現行ファイル:行 | CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index()` | UsersController.php:42 | `$this->redirect("/users_courses")` | `$this->redirect('/users_courses')` — 変更不要 |
| 2 | `login()` | UsersController.php:50 | `$this->Auth->login()`, `$this->Auth->redirect()`, `$this->readCookie()`, `$this->writeCookie()`, `$this->fetchTable()` | `$this->Authentication->setIdentity()` or `AuthenticationComponent`、Cookie 直接操作 |
| 3 | `logout()` | UsersController.php:233 | `$this->Auth->logout()` | `$this->Authentication->logout()` |
| 4 | `admin_index()` | UsersController.php:257 | `$this->Prg->commonProcess()`, `$this->User->parseCriteria()`, `$this->paginate()` | SearchPlugin の CakePHP 5 対応、`$this->paginate($query)` |
| 5 | `admin_add()` | UsersController.php:317 | `$this->admin_edit()`, `$this->render()` | 変更不要（委譲パターン） |
| 6 | `admin_edit($user_id)` | UsersController.php:327 | `$this->User->exists()`, `$this->User->save()`, `$this->request->data` | `$table->exists()`, `$table->save()`, `$this->request->getData()` |
| 7 | `admin_delete($user_id)` | UsersController.php:391 | `$this->User->delete()`, `$this->request->allowMethod()` | `$table->delete()`, `$this->request->allowMethod()` — 変更不要 |
| 8 | `admin_clear($user_id)` | UsersController.php:422 | `$this->User->deleteUserRecords()` | `$table->deleteUserRecords()` — Model 側の実装に依存 |
| 9 | `setting()` | UsersController.php:433 | `$this->User->save()`, `$this->getData()` | `$table->save()`, `$this->request->getData()` |
| 10 | `admin_setting()` | UsersController.php:485 | `$this->setting()` | 変更不要（委譲） |
| 11 | `admin_login()` | UsersController.php:493 | `$this->login()` | 変更不要（委譲） |
| 12 | `admin_logout()` | UsersController.php:501 | `$this->logout()` | 変更不要（委譲） |
| 13 | `admin_import()` | UsersController.php:537 | `Utils::getCsvData()`, `$ds->begin()`, `$ds->commit()`, `$ds->rollback()` | `ConnectionManager` トランザクション、Utils の名前空間化 |
| — | `admin_export()` | UsersController.php:728 | `$this->response->type()`, `header()`, `$this->User->find()` | `$this->response = $this->response->withType()`, CakePHP 5 では `header()` → Response オブジェクト |
| — | `_login()` | UsersController.php:191 | `$this->Auth->login()`, `$this->User->findByUsername()` | `Authentication` プラグイン、`$table->findBy()` |
| — | `_isLoginBlocked()` | UsersController.php:514 | `$this->fetchTable('Log')->find('count')` | 変更不要（CakePHP 5 で fetchTable ネイティブ対応） |

**主な変更ポイント**:
- `App::uses()` → `use` ステートメント
- `$this->User->id = $id; $this->User->saveField()` → `$table->patchEntity()` + `$table->save()`
- `$this->Auth->login()` → `Authentication` プラグインの Identity 設定
- `$this->request->data['User']` → `$this->request->getData('User')`
- `$this->User->findByUsername()` → `$this->fetchTable('User')->findBy(['username' => $username])`
- `Configure::read()` → `Configure::read()` — 変更不要

### 2.2 CoursesController

- **現行**: `Controller/CoursesController.php`
- **移行先**: `src/Controller/CoursesController.php`

| # | アクション | 現行ファイル:行 | CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `admin_index()` | CoursesController.php:33 | `$this->Course->recursive = 0`, `$this->Course->find()->order()->all()` | `$table->find()->order()->all()` — `recursive` は不要 |
| 2 | `admin_add()` | CoursesController.php:47 | `$this->admin_edit()`, `$this->render()` | 変更不要 |
| 3 | `admin_edit($course_id)` | CoursesController.php:57 | `$this->Course->exists()`, `$this->Course->save()`, `$this->request->data` | `$table->exists()`, `$table->save()`, `$this->request->getData()` |
| 4 | `admin_delete($course_id)` | CoursesController.php:92 | `$this->Course->deleteCourse()` | `$table->deleteCourse()` — Model 側に依存 |
| 5 | `admin_order()` | CoursesController.php:115 | `$this->autoRender = FALSE`, `$this->data['id_list']` | `$this->autoRender = false`, `$this->request->getData('id_list')` |

### 2.3 ContentsController

- **現行**: `Controller/ContentsController.php`
- **移行先**: `src/Controller/ContentsController.php`

| # | アクション | 現行ファイル:行 | CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index($course_id, $user_id)` | ContentsController.php:37 | `$this->fetchTable('Course')->get()`, `$this->Content->getContentRecord()` | `$this->fetchTable('Course')->get()`, `$table->getContentRecord()` |
| 2 | `view($content_id)` | ContentsController.php:74 | `$this->Content->exists()`, `$this->Content->get()`, `$this->layout = ''` | `$table->exists()`, `$table->get()`, `$this->viewBuilder()->setOption('fullPage', false)` |
| 3 | `admin_index($course_id)` | ContentsController.php:108 | `$this->Content->recursive = 0`, `$this->Content->find()` | `recursive` 不要、クエリは変更不要 |
| 4 | `admin_add($course_id)` | ContentsController.php:133 | `$this->admin_edit()`, `$this->render()` | 変更不要 |
| 5 | `admin_edit($course_id, $content_id)` | ContentsController.php:145 | `$this->Content->save()`, `$this->request->data` | `$table->save()`, `$this->request->getData()` |
| 6 | `admin_delete($content_id)` | ContentsController.php:194 | `$this->Content->delete()`, `$this->fetchTable('ContentsQuestion')->deleteAll()` | `$table->delete()`, `$table->deleteAll()` |
| 7 | `admin_preview()` | ContentsController.php:229 | `$this->autoRender = FALSE`, `$this->getData()` | `$this->autoRender = false`, `$this->request->getData()` |
| 8 | `admin_preview_movie($file_name)` | ContentsController.php:256 | `$this->response->file()` | `$this->response = $this->response->withFile()` |
| 9 | `preview()` | ContentsController.php:300 | `$this->layout = ''`, `$this->render('view')` | `$this->viewBuilder()->setOption('fullPage', false)` |
| 10 | `admin_upload($file_type)` | ContentsController.php:313 | `App::import('Vendor', 'FileUpload')`, `$this->getData()` | `use` によるクラス読み込み、`$this->request->getData()` |
| 11 | `admin_upload_image()` | ContentsController.php:439 | `$this->autoRender = FALSE`, `echo json_encode()` | `$this->autoRender = false`, `$this->response = $this->response->withType('json')->withStringBody()` |
| 12 | `admin_order()` | ContentsController.php:484 | `$this->Content->setOrder()`, `$this->data` | `$table->setOrder()`, `$this->request->getData()` |
| 13 | `admin_record($course_id, $user_id)` | ContentsController.php:500 | `$this->index()`, `$this->render()` | 変更不要 |
| 14 | `admin_copy($course_id, $content_id)` | ContentsController.php:511 | `$this->Content->get()`, `$this->Content->save()`, `$this->fetchTable('ContentsQuestion')` | `$table->get()`, `$table->save()` |
| 15 | `file_download($content_id)` | ContentsController.php:568 | `$this->response->file()` | `$this->response = $this->response->withFile()` |
| 16 | `file_movie($content_id)` | ContentsController.php:626 | `$this->response->file()` | `$this->response = $this->response->withFile()` |
| 17 | `file_image($file_name)` | ContentsController.php:687 | `$this->response->file()` | `$this->response = $this->response->withFile()` |

### 2.4 GroupsController

- **現行**: `Controller/GroupsController.php`
- **移行先**: `src/Controller/GroupsController.php`

| # | アクション | 現行ファイル:行 | CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `admin_index()` | GroupsController.php:33 | `$this->Group->recursive = 0`, `$this->Group->virtualFields`, `$this->Paginator->settings`, `$this->Paginator->paginate()` | `recursive` 不要、`$table->setVirtualField()` or QueryBuilder、`$this->paginate($query)` |
| 2 | `admin_add()` | GroupsController.php:55 | `$this->admin_edit()`, `$this->render()` | 変更不要 |
| 3 | `admin_edit($group_id)` | GroupsController.php:65 | `$this->Group->exists()`, `$this->Group->save()`, `$this->Group->Course->find('list')` | `$table->exists()`, `$table->save()`, アソシエーション経由のクエリ |
| 4 | `admin_delete($group_id)` | GroupsController.php:97 | `$this->Group->delete()` | `$table->delete()` |

### 2.5 RecordsController

- **現行**: `Controller/RecordsController.php`
- **移行先**: `src/Controller/RecordsController.php`

| # | アクション | 現行ファイル:行 | CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `admin_index()` | RecordsController.php:38 | `$this->Prg->commonProcess()`, `$this->Record->parseCriteria()`, `$this->paginate()`, `$this->response->type('csv')`, `header()`, `fputcsv()` | SearchPlugin 対応、`$this->paginate()`、`$this->response = $this->response->withType('csv')` |
| 2 | `add($content_id)` | RecordsController.php:317 | `$this->autoRender = FALSE`, `$this->Record->create()`, `$this->Record->save()` | `$this->autoRender = false`, `$table->newEmptyEntity()`, `$table->save($entity)` |

### 2.6 SettingsController

- **現行**: `Controller/SettingsController.php`
- **移行先**: `src/Controller/SettingsController.php`

| # | アクション | 現行ファイル:行 | CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `admin_index()` | SettingsController.php:28 | `$this->Setting->setSettings()`, `$this->Setting->getSettings()`, `$this->getData()` | `$table->setSettings()`, `$table->getSettings()`, `$this->request->getData()` |

### 2.7 InfosController

- **現行**: `Controller/InfosController.php`
- **移行先**: `src/Controller/InfosController.php`

| # | アクション | 現行ファイル:行 | CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index()` | InfosController.php:31 | `$this->fetchTable('Info')->getInfoOption()`, `$this->paginate()` | `$table->getInfoOption()`, `$this->paginate($query)` |
| 2 | `view($info_id)` | InfosController.php:45 | `$this->Info->exists()`, `$this->Info->hasRight()`, `$this->Info->get()` | `$table->exists()`, `$table->hasRight()`, `$table->get()` |
| 3 | `admin_index()` | InfosController.php:68 | `$this->Info->virtualFields`, `$this->Paginator->settings`, `$this->paginate()` | `setVirtualField()`、`$this->paginate()` |
| 4 | `admin_add()` | InfosController.php:91 | `$this->admin_edit()`, `$this->render()` | 変更不要 |
| 5 | `admin_edit($info_id)` | InfosController.php:101 | `$this->Info->exists()`, `$this->Info->save()`, `$this->request->data` | `$table->exists()`, `$table->save()`, `$this->request->getData()` |
| 6 | `admin_delete($info_id)` | InfosController.php:139 | `$this->Info->delete()` | `$table->delete()` |

### 2.8 UsersCoursesController

- **現行**: `Controller/UsersCoursesController.php`
- **移行先**: `src/Controller/UsersCoursesController.php`

| # | アクション | 現行ファイル:行 | CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index()` | UsersCoursesController.php:22 | `$this->fetchTable('Setting')->find()`, `$this->fetchTable('Info')->getInfos()`, `$this->UsersCourse->getCourseRecord()` | `$table->find()`, `$table->getInfos()`, `$table->getCourseRecord()` |

### 2.9 EnquetesQuestionsController

- **現行**: `Controller/EnquetesQuestionsController.php`
- **移行先**: `src/Controller/EnquetesQuestionsController.php`

| # | アクション | 現行ファイル:行 | CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index($content_id, $record_id)` | EnquetesQuestionsController.php:38 | `$this->fetchTable('ContentsQuestion')->recursive = 0`, `FIELD()` クエリ、`$this->Record->save()`, `$this->RecordsQuestion->save()` | `recursive` 不要、`$table->newEmptyEntity()`, `$table->save()` |
| 2 | `record($content_id, $record_id)` | EnquetesQuestionsController.php:186 | `$this->index()`, `$this->render()` | 変更不要 |
| 3 | `admin_record($content_id, $record_id)` | EnquetesQuestionsController.php:197 | `$this->record()` | 変更不要 |
| 4 | `admin_index($content_id)` | EnquetesQuestionsController.php:206 | `$this->fetchTable('ContentsQuestion')->find()` | `$table->find()` |
| 5 | `admin_add($content_id)` | EnquetesQuestionsController.php:227 | `$this->admin_edit()`, `$this->render()` | 変更不要 |
| 6 | `admin_edit($content_id, $question_id)` | EnquetesQuestionsController.php:238 | `$this->fetchTable('ContentsQuestion')->exists()`, `->validates()`, `->save()` | `$table->exists()`, `->validate()`, `$table->save()` |
| 7 | `admin_delete($question_id)` | EnquetesQuestionsController.php:291 | `$this->fetchTable('ContentsQuestion')->delete()` | `$table->delete()` |
| 8 | `admin_order()` | EnquetesQuestionsController.php:327 | `$this->fetchTable('ContentsQuestion')->setOrder()` | `$table->setOrder()` |

### 2.10 ContentsQuestionsController

- **現行**: `Controller/ContentsQuestionsController.php`
- **移行先**: `src/Controller/ContentsQuestionsController.php`

| # | アクション | 現行ファイル:行 | CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index($content_id, $record_id)` | ContentsQuestionsController.php:38 | `$this->ContentsQuestion->recursive = 0`, `rand()` クエリ、`$this->Record->save()`, `$this->RecordsQuestion->save()`, セッション操作 | `recursive` 不要、`$table->newEmptyEntity()`, `$table->save()` |
| 2 | `record($content_id, $record_id)` | ContentsQuestionsController.php:259 | `$this->index()`, `$this->render()` | 変更不要 |
| 3 | `admin_record($content_id, $record_id)` | ContentsQuestionsController.php:273 | `$this->record()` | 変更不要 |
| 4 | `admin_index($content_id)` | ContentsQuestionsController.php:282 | `$this->ContentsQuestion->find()` | `$table->find()` |
| 5 | `admin_add($content_id)` | ContentsQuestionsController.php:304 | `$this->admin_edit()`, `$this->render()` | 変更不要 |
| 6 | `admin_edit($content_id, $question_id)` | ContentsQuestionsController.php:315 | `$this->ContentsQuestion->exists()`, `->validates()`, `->save()`, `$this->request->data` | `$table->exists()`, `->validate()`, `$table->save()`, `$this->request->getData()` |
| 7 | `admin_delete($question_id)` | ContentsQuestionsController.php:365 | `$this->ContentsQuestion->delete()` | `$table->delete()` |
| 8 | `admin_order()` | ContentsQuestionsController.php:400 | `$this->ContentsQuestion->setOrder()`, `$this->data` | `$table->setOrder()`, `$this->request->getData()` |

### 2.11 InstallController

- **現行**: `Controller/InstallController.php`
- **移行先**: `src/Controller/InstallController.php`

| # | アクション | 現行ファイル:行 | CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index()` | InstallController.php:52 | `App::import('Model','ConnectionManager')`, `ConnectionManager::getDataSource('default')`, `DATABASE_CONFIG` | `use Cake\Datasource\ConnectionManager`, `$connection = ConnectionManager::get('default')`, `app('config')` |
| 2 | `installed()` | InstallController.php:186 | `$this->set('loginedUser', $this->readAuthUser())` | `$this->set('loginedUser', $this->readAuthUser())` — 認証変更に依存 |
| 3 | `complete()` | InstallController.php:194 | 変更不要 | 変更不要 |
| 4 | `error()` | InstallController.php:202 | 変更不要 | 変更不要 |
| — | `__install()` | InstallController.php:212 | `APP.'Config'.DS.'Schema'.DS.'app.sql'` | `CONFIG.'Schema'.DS.'app.sql'` |
| — | `__executeSQLScript()` | InstallController.php:244 | `$this->db->query()` | `$connection->execute()` or `$connection->query()` |
| — | `__createRootAccount()` | InstallController.php:280 | `$this->fetchTable('User')->findByRole()`, `->save()` | `$table->findByRole()`, `$table->save()` |
| — | `__apache_module_loaded()` | InstallController.php:303 | `apache_get_modules()` | 変更不要（PHP 標準関数） |

**主な変更ポイント**:
- `App::uses('ForbiddenException', 'Exception')` → `use Cake\Http\Exception\ForbiddenException;`
- `App::import('Model','ConnectionManager')` → `use Cake\Datasource\ConnectionManager;`
- `ConnectionManager::getDataSource('default')` → `ConnectionManager::get('default')`
- `DATABASE_CONFIG` → `app('config')['Datasources']`
- `APP.'Config'.DS.'Schema'.DS.'app.sql'` → `CONFIG.'Schema'.DS.'app.sql'`

### 2.12 UpdateController

- **現行**: `Controller/UpdateController.php`
- **移行先**: `src/Controller/UpdateController.php`

| # | アクション | 現行ファイル:行 | CakePHP 2 API | CakePHP 5 置換え |
|---|---|---|---|---|
| 1 | `index()` | UpdateController.php:46 | `App::import('Model','ConnectionManager')`, `ConnectionManager::getDataSource('default')` | `use Cake\Datasource\ConnectionManager`, `ConnectionManager::get('default')` |
| 2 | `error()` | UpdateController.php:100 | `$this->set('loginedUser', $this->readAuthUser())` | 認証変更に依存 |
| — | `__executeSQLScript()` | UpdateController.php:109 | `$this->db->query()` | `$connection->execute()` or `$connection->query()` |

**主な変更ポイント**:
- `App::uses('ForbiddenException', 'Exception')` → `use Cake\Http\Exception\ForbiddenException;`
- `APP.'Config'.DS.'Schema'.DS.'update.sql'` → `CONFIG.'Schema'.DS.'update.sql'`
- `APP.'Custom'.DS.'Config'.DS.'custom.sql'` → `ROOT . DS . 'Custom' . DS . 'Config' . DS . 'custom.sql'`（Custom ディレクトリは PSR-4 autoload 化を検討）

---

## 3. コントローラ別サマリ

| ファイル | 移行先 | アクション数 | 難易度 | 主な変更ポイント |
|---|---|---|---|---|
| `AppController.php` | `src/Controller/AppController.php` | —（基底） | ★★★★ | Auth→Authentication プラグイン、Session/Cookie→Middleware、$uses 廃止、beforeFilter シグネチャ変更、全ヘルパーメソッドのリファクタリング |
| `UsersController.php` | `src/Controller/UsersController.php` | 13 | ★★★★★ | ログイン/ログアウト認証フローの全面書き換え、RememberMe→CookieAuthenticator、CSV インポート/エクスポート、SearchPlugin 移行、$this->request->data 変更 |
| `CoursesController.php` | `src/Controller/CoursesController.php` | 5 | ★★ | Security→FormProtection、$this->data→getData() |
| `ContentsController.php` | `src/Controller/ContentsController.php` | 17 | ★★★★ | ファイルアップロード/ダウンロード、Vendor 依存(FileUpload)、App::import→use、response->file()→withFile()、autoRender |
| `GroupsController.php` | `src/Controller/GroupsController.php` | 4 | ★★ | virtualFields、Paginator 設定 |
| `RecordsController.php` | `src/Controller/RecordsController.php` | 2 | ★★★ | SearchPlugin、CSV エクスポート、response→withType() |
| `SettingsController.php` | `src/Controller/SettingsController.php` | 1 | ★ | $this->request->data→getData() |
| `InfosController.php` | `src/Controller/InfosController.php` | 6 | ★★ | virtualFields、Paginator、search join |
| `UsersCoursesController.php` | `src/Controller/UsersCoursesController.php` | 1 | ★ | 変更最小 |
| `EnquetesQuestionsController.php` | `src/Controller/EnquetesQuestionsController.php` | 8 | ★★★ | FIELD() クエリ、セッション操作、採点ロジック |
| `ContentsQuestionsController.php` | `src/Controller/ContentsQuestionsController.php` | 8 | ★★★ | ランダム出題(order rand())、セッション操作、採点ロジック |
| `InstallController.php` | `src/Controller/InstallController.php` | 4 | ★★★ | ConnectionManager 変更、DATABASE_CONFIG 廃止、SQL スクリプト実行 |
| `UpdateController.php` | `src/Controller/UpdateController.php` | 2 | ★★★ | ConnectionManager 変更、SQL スクリプト実行 |
| **Web 合計** | | **71** | | |

> **注意**: `admin_add` は実質的に `admin_edit` を委譲して `admin_edit` ビューをレンダリングするパターン。CakePHP 5 でも同様の委譲が可能だが、明示的なルート定義が必要になる場合がある。

---

## 4. 共通の変更パターン

### 4.1 `App::uses()` / `App::import()` → `use` ステートメント

```php
// CakePHP 2 (全コントローラ共通)
App::uses('AppController', 'Controller');
App::uses('ForbiddenException', 'Exception');
App::import('Vendor', 'Utils');
App::import('Vendor', 'FileUpload');
App::import('Model', 'ConnectionManager');

// CakePHP 5
use App\Controller\AppController;
use Cake\Http\Exception\ForbiddenException;
use App\Vendor\Utils;  // PSR-4 autoload 化
use App\Vendor\FileUpload;  // PSR-4 autoload 化
use Cake\Datasource\ConnectionManager;
```

### 4.2 `$this->request->data` → `$this->request->getData()`

```php
// CakePHP 2
$this->request->data['User']['username']
$this->request->data = $someData;
$data = $this->request->data;

// CakePHP 5
$this->request->getData('User.username')  // ドット記法
$this->request = $this->request->withData('User', $someData);  // Immutable
$data = $this->request->getData();  // 全体取得
```

### 4.3 `$this->data` → `$this->request->getData()`

```php
// CakePHP 2
$this->data['id_list']

// CakePHP 5
$this->request->getData('id_list')
```

### 4.4 `Model::find()` の戻り値フォーマット

```php
// CakePHP 2
$user = $this->User->findByUsername($username);
$user['User']['password']  // 配列のネスト

// CakePHP 5
$user = $this->fetchTable('User')->findBy(['username' => $username])->first();
$user['password']  // ネストなし（Entity の場合 $user->password）
```

### 4.5 `$this->response->file()` → `$this->response->withFile()`

```php
// CakePHP 2
$this->response->file($path, ['download' => true, 'name' => $name]);
return $this->response;

// CakePHP 5
$this->response = $this->response->withFile($path, ['download' => true, 'name' => $name]);
return $this->response;
```

### 4.6 `Configure::read()` — 変更不要

```php
// CakePHP 2 と CakePHP 5 で同一
Configure::read('demo_mode');
Configure::read('remember_token_expired_days');
```

> **根拠**: `Controller/AppController.php:131`, `Controller/UsersController.php:131` 等。CakePHP 5 でも `Configure::read()` はそのまま使用可能。

### 4.7 `isAdminPage()` の判定方法

```php
// CakePHP 2 (Controller/AppController.php:375-378)
return (isset($this->request->params['admin']));

// CakePHP 5 — admin prefix に変更
return ($this->request->getParam('prefix') === 'Admin');
```

> **根拠**: `Controller/AppController.php:375-378`。CakePHP 5 では `admin` パラメータが廃止され、`prefix` パラメータに変更される。

### 4.8 `$this->layout = ''` → ViewBuilder

```php
// CakePHP 2
$this->layout = '';

// CakePHP 5
$this->viewBuilder()->setOption('fullPage', false);
// or
$this->viewBuilder()->setOption('layout', false);
```

> **根拠**: `Controller/ContentsController.php:84`, `Controller/EnquetesQuestionsController.php:38` 等。

### 4.9 `Paginator` の変更

```php
// CakePHP 2
$this->Paginator->settings = [...];
$records = $this->Paginator->paginate($this->Record);

// CakePHP 5
$this->paginate = [...];
$records = $this->paginate($query);
// or
$query = $this->fetchTable('Record')->find()->where([...]);
$records = $this->paginate($query);
```

> **根拠**: `Controller/GroupsController.php:38-49`, `Controller/RecordsController.php:288-299` 等。

### 4.10 `$this->autoRender = false` — 変更不要

```php
// CakePHP 2 と CakePHP 5 で同一
$this->autoRender = false;
```

> **根拠**: `Controller/ApiBaseController.php:38`, `Controller/RecordsController.php:319` 等。

---

## 5. 注意事項

### 5.1 `Session` コンポーネントの削除

CakePHP 5 では `Session` コンポーネントは存在しない。セッション操作は `SessionMiddleware` が担当し、コントローラ内では `$this->request->getSession()` でアクセスする。AppController のヘルパーメソッド（`readSession`、`writeSession` 等）は全て `$this->request->getSession()` を呼ぶよう書き換える必要がある。

### 5.2 `Auth` コンポーネントの置換え

`Auth` コンポーネントは `cakephp/authentication` プラグインに置き換わる。主な変更点:

- `$this->Auth->login($userData)` → `Authentication` プラグインの `Authenticator` で処理
- `$this->Auth->user()` → `$this->Authentication->getIdentity()` で認証済みユーザを取得
- `$this->Auth->logout()` → `$this->Authentication->logout()`
- `$this->Auth->loginAction` の設定は `ApplicationMiddleware` の `AuthenticationMiddleware` で設定

### 5.3 `Security` コンポーダントの置換え

`Security` コンポーネントは `FormProtectionComponent` に置き換わる。CSRF 保護は `CsrfProtectionMiddleware` で実現する。`blackHoleCallback` は `FormProtectionComponent` の `onCheckFail` コールバックに相当する。

### 5.4 `SEARCH` プラグインの移行

`Search.Prg` コンポーネントは CakePHP 5 で動作しないため、`Search` プラグインの CakePHP 5 対応版に更新するか、別途検索条件の構築ロジックを実装する必要がある。

### 5.5 Vendor クラスの autoloading

`App::import('Vendor', 'Utils')` や `App::import('Vendor', 'FileUpload')` は CakePHP 5 で動作しない。`Utils.php`（`Vendor/Utils.php`）と `FileUpload.php` を PSR-4 autoload 対応の名前空間に移動する必要がある。

### 5.6 `validationErrors` プロパティ

```php
// CakePHP 2
$this->User->validationErrors

// CakePHP 5
// Entity を使用する場合
$entity->getErrors();
// or Table の場合
$table->save($entity);  // false が返った場合、Entity のエラーを確認
```

> **根拠**: `Controller/UsersController.php:689`, `Controller/ApiUsersController.php:141` 等。
