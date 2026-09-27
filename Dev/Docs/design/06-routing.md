# 06 — ルーティング設計（CakePHP 2.10 → 5.x 移行）

## 概要

本ドキュメントでは、`Config/routes.php` の全32ルートを CakePHP 5 の `$routes->scope()` パターンに変換する設計を定義する。

---

## 1. `routes.php` 全体設計

### 1.1 現行ルート一覧

`Config/routes.php` に定義されている全ルート：

| # | URL パターン | コントローラ | アクション | HTTP メソッド |
|---|---|---|---|---|
| 1 | `/` | `users_courses` | `index` | GET |
| 2 | `/admin` | `users` | `index` (admin) | GET |
| 3 | `/api/v1/auth/token` | `api_auth` | `issueToken` | POST |
| 4 | `/api/v1/auth/token` | `api_auth` | `revokeToken` | DELETE |
| 5 | `/api/v1/users` | `api_users` | `index` | GET |
| 6 | `/api/v1/users` | `api_users` | `add` | POST |
| 7 | `/api/v1/users/:id` | `api_users` | `view` | GET |
| 8 | `/api/v1/users/:id` | `api_users` | `edit` | PUT |
| 9 | `/api/v1/users/:id` | `api_users` | `edit` | PATCH |
| 10 | `/api/v1/users/:id` | `api_users` | `delete` | DELETE |
| 11 | `/api/v1/users/:id/password` | `api_users` | `changePassword` | PUT |
| 12 | `/api/v1/users/:id/password` | `api_users` | `changePassword` | PATCH |
| 13 | `/api/v1/users/:id/courses` | `api_users` | `courses` | GET |
| 14 | `/api/v1/users/:id/courses` | `api_users` | `assignCourse` | POST |
| 15 | `/api/v1/users/:id/courses/:course_id` | `api_users` | `unassignCourse` | DELETE |
| 16 | `/api/v1/courses` | `api_courses` | `index` | GET |
| 17 | `/api/v1/courses` | `api_courses` | `add` | POST |
| 18 | `/api/v1/courses/:id` | `api_courses` | `view` | GET |
| 19 | `/api/v1/courses/:id` | `api_courses` | `delete` | DELETE |
| 20 | `/api/v1/contents` | `api_contents` | `index` | GET |
| 21 | `/api/v1/contents/:id` | `api_contents` | `view` | GET |
| 22 | `/api/v1/records` | `api_records` | `index` | GET |
| 23 | `/api/v1/records/:id` | `api_records` | `view` | GET |
| 24 | `/api/v1/groups` | `api_groups` | `index` | GET |
| 25 | `/api/v1/groups/:id` | `api_groups` | `view` | GET |
| 26 | `/api/v1/groups/:id/users` | `api_groups` | `users` | GET |
| 27 | `/api/v1/groups/:id/users` | `api_groups` | `assignUser` | POST |
| 28 | `/api/v1/groups/:id/users/:user_id` | `api_groups` | `unassignUser` | DELETE |
| 29 | `/api/v1/*` | `api_errors` | `notFound` | * |
| 30 | `/api/v1` | `api_errors` | `notFound` | GET |
| 31 | `/api/*` | `api_errors` | `notFound` | * |
| 32 | `/api` | `api_errors` | `notFound` | GET |

**根拠**: `Config/routes.php:28-85`

### 1.2 CakePHP 5 への変換

CakePHP 5 の `config/routes.php` では、`$routes->scope()` を使用してルートをグループ化する。

```php
// app/config/routes.php
use Cake\Routing\RouteBuilder;
use Cake\Routing\Router;

/**
 * デフォルトルートスコープ
 */
Router::scope('/', function (RouteBuilder $builder): void {
    // / → UsersCourses::index
    $builder->connect('/', [
        'controller' => 'UsersCourses',
        'action' => 'index',
    ]);

    /**
     * Admin プレフィクス
     */
    $builder->prefix('Admin', function (RouteBuilder $builder): void {
        // /admin → Admin/Users::index
        $builder->connect('/', [
            'controller' => 'Users',
            'action' => 'index',
        ]);

        // /admin/users/login → Admin/Users::login
        $builder->connect('/users/login', [
            'controller' => 'Users',
            'action' => 'login',
        ]);

        // ... 他の admin ルート ...
    });

    /**
     * API v1 ルート
     */
    $builder->scope('/api/v1', function (RouteBuilder $builder): void {
        // 認証
        $builder->post('/auth/token', [
            'controller' => 'Api/Auth',
            'action' => 'issueToken',
        ]);
        $builder->delete('/auth/token', [
            'controller' => 'Api/Auth',
            'action' => 'revokeToken',
        ]);

        // ユーザ
        $builder->get('/users', [
            'controller' => 'Api/ApiUsers',
            'action' => 'index',
        ]);
        $builder->post('/users', [
            'controller' => 'Api/ApiUsers',
            'action' => 'add',
        ]);
        $builder->get('/users/{id}', [
            'controller' => 'Api/ApiUsers',
            'action' => 'view',
        ], ['id' => '[0-9]+']);
        // ... 他の API ルート ...
    });

    /**
     * フォールバックルート
     */
    $builder->scope('/api', function (RouteBuilder $builder): void {
        $builder->connect('/*', [
            'controller' => 'Api/ApiErrors',
            'action' => 'notFound',
        ]);
    });
});
```

---

## 2. Admin プレフィクス

### 2.1 設定の変更

- **CakePHP 2**: `Config/core.php:164` で `Routing.prefixes = ['admin']` を設定
- **CakePHP 5**: `config/routes.php` で `$routes->prefix('Admin', ...)` を使用

```php
// Config/core.php:164
Configure::write('Routing.prefixes', ['admin']);

// config/app.php — CakePHP 5 では不要（routes.php で直接定義）
```

**根拠**: `Config/core.php:164` — CakePHP 5 では `Routing.prefixes` 設定は存在しない。

### 2.2 URL 変更

CakePHP 2 の admin プレフィクスでは `users/admin_login` のような URL が生成されたが、CakePHP 5 では prefix スコープ内で `UsersController::login()` を定義するため、URL が変更される。

| 現行 (CakePHP 2) | CakePHP 5 |
|---|---|
| `/users/admin_login` | `/admin/users/login` |
| `/users/admin_logout` | `/admin/users/logout` |
| `/users/admin_index` | `/admin/users/index` |
| `/users/admin_edit/123` | `/admin/users/edit/123` |

```php
// 現行 (UsersController.php:493-504)
public function admin_login()
{
    $this->login();
}

public function admin_logout()
{
    $this->logout();
}

// CakePHP 5 — Admin/UsersController として独立
// app/src/Controller/Admin/UsersController.php
namespace App\Controller\Admin;

use App\Controller\AppController;

class UsersController extends AppController
{
    public function login(): ?Response
    {
        // UsersController::login() のロジックを再利用
    }
}
```

**根拠**: `UsersController.php:493-504` — `admin_login()` / `admin_logout()` は `login()` / `logout()` を呼び出すだけ。CakePHP 5 では Admin プレフィクスコントローラとして独立させる。

---

## 3. API v1 ルーティング

### 3.1 `$routes->scope('/api/v1', ...)` での定義

CakePHP 5 では HTTP メソッド別のルーティングメソッドが提供される：

```php
$builder->scope('/api/v1', function (RouteBuilder $builder): void {
    // 認証（POST /api/v1/auth/token）
    $builder->post('/auth/token', [
        'controller' => 'Api/ApiAuth',
        'action' => 'issueToken',
    ]);

    // 認証（DELETE /api/v1/auth/token）
    $builder->delete('/auth/token', [
        'controller' => 'Api/ApiAuth',
        'action' => 'revokeToken',
    ]);

    // ユーザ一覧（GET /api/v1/users）
    $builder->get('/users', [
        'controller' => 'Api/ApiUsers',
        'action' => 'index',
    ]);

    // ユーザ追加（POST /api/v1/users）
    $builder->post('/users', [
        'controller' => 'Api/ApiUsers',
        'action' => 'add',
    ]);

    // ユーザ詳細（GET /api/v1/users/{id}）
    $builder->get('/users/{id}', [
        'controller' => 'Api/ApiUsers',
        'action' => 'view',
    ], ['id' => '[0-9]+']);

    // ユーザ更新（PUT /api/v1/users/{id}）
    $builder->put('/users/{id}', [
        'controller' => 'Api/ApiUsers',
        'action' => 'edit',
    ], ['id' => '[0-9]+']);

    // ユーザ更新（PATCH /api/v1/users/{id}）
    $builder->patch('/users/{id}', [
        'controller' => 'Api/ApiUsers',
        'action' => 'edit',
    ], ['id' => '[0-9]+']);

    // ユーザ削除（DELETE /api/v1/users/{id}）
    $builder->delete('/users/{id}', [
        'controller' => 'Api/ApiUsers',
        'action' => 'delete',
    ], ['id' => '[0-9]+']);

    // パスワード変更（PUT /api/v1/users/{id}/password）
    $builder->put('/users/{id}/password', [
        'controller' => 'Api/ApiUsers',
        'action' => 'changePassword',
    ], ['id' => '[0-9]+']);

    // パスワード変更（PATCH /api/v1/users/{id}/password）
    $builder->patch('/users/{id}/password', [
        'controller' => 'Api/ApiUsers',
        'action' => 'changePassword',
    ], ['id' => '[0-9]+']);

    // ユーザのコース一覧（GET /api/v1/users/{id}/courses）
    $builder->get('/users/{id}/courses', [
        'controller' => 'Api/ApiUsers',
        'action' => 'courses',
    ], ['id' => '[0-9]+']);

    // ユーザのコース割当（POST /api/v1/users/{id}/courses）
    $builder->post('/users/{id}/courses', [
        'controller' => 'Api/ApiUsers',
        'action' => 'assignCourse',
    ], ['id' => '[0-9]+']);

    // ユーザのコース解除（DELETE /api/v1/users/{id}/courses/{course_id}）
    $builder->delete('/users/{id}/courses/{course_id}', [
        'controller' => 'Api/ApiUsers',
        'action' => 'unassignCourse',
    ], ['id' => '[0-9]+', 'course_id' => '[0-9]+']);

    // コース一覧（GET /api/v1/courses）
    $builder->get('/courses', [
        'controller' => 'Api/ApiCourses',
        'action' => 'index',
    ]);

    // コース追加（POST /api/v1/courses）
    $builder->post('/courses', [
        'controller' => 'Api/ApiCourses',
        'action' => 'add',
    ]);

    // コース詳細（GET /api/v1/courses/{id}）
    $builder->get('/courses/{id}', [
        'controller' => 'Api/ApiCourses',
        'action' => 'view',
    ], ['id' => '[0-9]+']);

    // コース削除（DELETE /api/v1/courses/{id}）
    $builder->delete('/courses/{id}', [
        'controller' => 'Api/ApiCourses',
        'action' => 'delete',
    ], ['id' => '[0-9]+']);

    // コンテンツ一覧（GET /api/v1/contents）
    $builder->get('/contents', [
        'controller' => 'Api/ApiContents',
        'action' => 'index',
    ]);

    // コンテンツ詳細（GET /api/v1/contents/{id}）
    $builder->get('/contents/{id}', [
        'controller' => 'Api/ApiContents',
        'action' => 'view',
    ], ['id' => '[0-9]+']);

    // 学習履歴一覧（GET /api/v1/records）
    $builder->get('/records', [
        'controller' => 'Api/ApiRecords',
        'action' => 'index',
    ]);

    // 学習履歴詳細（GET /api/v1/records/{id}）
    $builder->get('/records/{id}', [
        'controller' => 'Api/ApiRecords',
        'action' => 'view',
    ], ['id' => '[0-9]+']);

    // グループ一覧（GET /api/v1/groups）
    $builder->get('/groups', [
        'controller' => 'Api/ApiGroups',
        'action' => 'index',
    ]);

    // グループ詳細（GET /api/v1/groups/{id}）
    $builder->get('/groups/{id}', [
        'controller' => 'Api/ApiGroups',
        'action' => 'view',
    ], ['id' => '[0-9]+']);

    // グループのユーザ一覧（GET /api/v1/groups/{id}/users）
    $builder->get('/groups/{id}/users', [
        'controller' => 'Api/ApiGroups',
        'action' => 'users',
    ], ['id' => '[0-9]+']);

    // グループのユーザ割当（POST /api/v1/groups/{id}/users）
    $builder->post('/groups/{id}/users', [
        'controller' => 'Api/ApiGroups',
        'action' => 'assignUser',
    ], ['id' => '[0-9]+']);

    // グループのユーザ解除（DELETE /api/v1/groups/{id}/users/{user_id}）
    $builder->delete('/groups/{id}/users/{user_id}', [
        'controller' => 'Api/ApiGroups',
        'action' => 'unassignUser',
    ], ['id' => '[0-9]+', 'user_id' => '[0-9]+']);

    // 未定義ルートへのフォールバック
    $builder->connect('/{url}', [
        'controller' => 'Api/ApiErrors',
        'action' => 'notFound',
    ], ['url' => '.*']);
});
```

### 3.2 変換ルール

| CakePHP 2 | CakePHP 5 |
|---|---|
| `[method] => 'POST'` | `$builder->post(...)` |
| `[method] => 'GET'` | `$builder->get(...)` |
| `[method] => 'DELETE'` | `$builder->delete(...)` |
| `[method] => 'PUT'` | `$builder->put(...)` |
| `[method] => 'PATCH'` | `$builder->patch(...)` |
| `:id` | `{id}` |
| `'id' => '[0-9]+'`（正規表現制約） | `'id' => '[0-9]+'`（第二引数の配列で指定） |
| `'[method]' => 'DELETE'` | `$builder->delete()` メソッド呼び出し |

**根拠**: `Config/routes.php:37-85` — 全24エンドポイントのルート定義。

---

## 4. コントローラ名の変換

### 4.1 変換ルール

CakePHP 2 の `snake_case` コントローラ名は、CakePHP 5 では `CamelCase` に変換される。

| CakePHP 2 | CakePHP 5 | URL | 備考 |
|---|---|---|---|
| `users_courses` | `UsersCourses` | `/users-courses` | CakePHP 5 ではデフォルトで `users-courses` |
| `api_users` | `ApiUsers` | `/api-users` | ルーティングで `controller => 'ApiUsers'` を明示 |
| `api_courses` | `ApiCourses` | `/api-courses` | 同上 |
| `api_contents` | `ApiContents` | `/api-contents` | 同上 |
| `api_records` | `ApiRecords` | `/api-records` | 同上 |
| `api_groups` | `ApiGroups` | `/api-groups` | 同上 |
| `api_auth` | `ApiAuth` | `/api-auth` | 同上 |
| `api_errors` | `ApiErrors` | `/api-errors` | 同上 |

### 4.2 URL 互換性の維持

CakePHP 5 では URL は自動的に `CamelCase` → `kebab-case` に変換される。API の URL は `/api/v1/users` などを維持するため、ルーティングで `controller` を明示的に指定する。

```php
// /api/v1/users → Api/ApiUsers コントローラ
$builder->get('/users', [
    'controller' => 'Api/ApiUsers',  // パスディレクトリ指定
    'action' => 'index',
]);
```

**根拠**: `Docs/design/README.md:104` — 「コントローラ名: CakePHP 2 の `api_users` → CakePHP 5 の `ApiUsers`（URL は `api-users`）。ルーティングで明示的に `controller => 'ApiUsers'` を指定して URL 互換性を維持」

CakePHP 5 の API コントローラは `src/Controller/Api/` サブディレクトリに配置する（`Docs/design/README.md:49`）。ルーティングでは `controller => 'Api/ApiUsers'` のようにパスで指定する。

---

## 5. プラグインルート

### 5.1 `CakePlugin::routes()` の廃止

- **CakePHP 2**: `Config/routes.php:97` で `CakePlugin::routes()` を呼び出し、全プラグインのルートを読み込み
- **CakePHP 5**: `$this->addPlugin('DebugKit')` 等で自動ルーティング

```php
// 現行 (Config/routes.php:97)
CakePlugin::routes();

// CakePHP 5 — Application::bootstrap() でプラグインを登録
// app/src/Application.php
public function bootstrap(): void
{
    parent::bootstrap();
    $this->addPlugin('DebugKit');
    // ... 他のプラグイン ...
}
```

CakePHP 5 では `addPlugin()` を呼び出すと、そのプラグインのルートが自動的に読み込まれる。`CakePlugin::routes()` のような明示的なルート読み込みは不要。

**根拠**: `Config/routes.php:97` — `CakePlugin::routes()` は CakePHP 2 専用のプラグインルート読み込みメソッド。

### 5.2 CakePHP デフォルトルートの廃止

- **CakePHP 2**: `Config/routes.php:103` で `require CAKE . 'Config' . DS . 'routes.php'` を呼び出し、CakePHP のデフォルトルート（`/pages/*` 等）を読み込み
- **CakePHP 5**: 不要（CakePHP 5 にはデフォルトルートファイルが存在しない）

```php
// 現行 (Config/routes.php:103)
require CAKE . 'Config' . DS . 'routes.php';

// CakePHP 5 — 不要。削除する。
```

**根拠**: `Config/routes.php:103` — CakePHP 5 ではこのようなデフォルトルートファイルは存在しない。

---

## 6. ルーティング設計のまとめ

### 6.1 スコープ構成

```
Router::scope('/')
├── /                           → UsersCourses::index
├── prefix('Admin')
│   ├── /                       → Admin/Users::index
│   ├── /users/login            → Admin/Users::login
│   └── ...                     → 他の admin ルート
├── scope('/api/v1')
│   ├── POST   /auth/token      → Api/ApiAuth::issueToken
│   ├── DELETE /auth/token      → Api/ApiAuth::revokeToken
│   ├── GET    /users           → Api/ApiUsers::index
│   ├── POST   /users           → Api/ApiUsers::add
│   ├── GET    /users/{id}      → Api/ApiUsers::view
│   ├── PUT    /users/{id}      → Api/ApiUsers::edit
│   ├── PATCH  /users/{id}      → Api/ApiUsers::edit
│   ├── DELETE /users/{id}      → Api/ApiUsers::delete
│   ├── PUT    /users/{id}/password  → Api/ApiUsers::changePassword
│   ├── PATCH  /users/{id}/password  → Api/ApiUsers::changePassword
│   ├── GET    /users/{id}/courses   → Api/ApiUsers::courses
│   ├── POST   /users/{id}/courses   → Api/ApiUsers::assignCourse
│   ├── DELETE /users/{id}/courses/{course_id} → Api/ApiUsers::unassignCourse
│   ├── GET    /courses          → Api/ApiCourses::index
│   ├── POST   /courses          → Api/ApiCourses::add
│   ├── GET    /courses/{id}     → Api/ApiCourses::view
│   ├── DELETE /courses/{id}     → Api/ApiCourses::delete
│   ├── GET    /contents         → Api/ApiContents::index
│   ├── GET    /contents/{id}    → Api/ApiContents::view
│   ├── GET    /records          → Api/ApiRecords::index
│   ├── GET    /records/{id}     → Api/ApiRecords::view
│   ├── GET    /groups           → Api/ApiGroups::index
│   ├── GET    /groups/{id}      → Api/ApiGroups::view
│   ├── GET    /groups/{id}/users → Api/ApiGroups::users
│   ├── POST   /groups/{id}/users → Api/ApiGroups::assignUser
│   ├── DELETE /groups/{id}/users/{user_id} → Api/ApiGroups::unassignUser
│   └── *      /{url}            → Api/ApiErrors::notFound
└── scope('/api')
    └── *      /*                → Api/ApiErrors::notFound
```

### 6.2 正規表現制約

| パラメータ | 正規表現 | 説明 |
|---|---|---|
| `{id}` | `[0-9]+` | ユーザID、コースID、グループID 等 |
| `{course_id}` | `[0-9]+` | コースID |
| `{user_id}` | `[0-9]+` | ユーザID |
| `{url}` | `.*` | 未定義ルートのフォールバック |

**根拠**: `Config/routes.php:44`（`'id' => '[0-9]+'`）、`Config/routes.php:56`（`'course_id' => '[0-9]+'`）、`Config/routes.php:79`（`'user_id' => '[0-9]+'`）
