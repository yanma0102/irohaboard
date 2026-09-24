# 04 — 認証・認可設計（CakePHP 2.10 → 5.x 移行）

## 概要

本ドキュメントでは、CakePHP 2.10 の `AuthComponent` / `CookieComponent` による認証フローを、CakePHP 5 の `authentication` プラグイン（`cakephp/authentication` 4.x）および手動ロール判定に置き換える設計を定義する。

---

## 1. CakePHP 5 での認証アーキテクチャ

### 1.1 ミドルウェア設定（`Application::middleware()`）

`app/src/Application.php` の `middleware()` メソッドで `AuthenticationMiddleware` を追加する。

```php
// app/src/Application.php
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;

public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
{
    $middlewareQueue
        // ... 既存ミドルウェア ...
        ->add(new AuthenticationMiddleware($this))
        // ... CsrfProtectionMiddleware 等 ...
    ;

    return $middlewareQueue;
}
```

**根拠**: `AppController.php:31-35` の `Auth` コンポーネント設定を置き換える。CakePHP 5 では認証はミドルウェア層で処理する。

### 1.2 `AuthenticationService` の設定

`AuthenticationServiceProviderInterface` を `Application` クラスに実装し、`getAuthenticationService()` を定義する。

```php
// app/src/Application.php
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Identifier\PasswordIdentifier;
use Authentication\Authenticator\SessionAuthenticator;
use Authentication\Authenticator\CookieAuthenticator;

public function getAuthenticationService(ServerRequestInterface $request): AuthenticationService
{
    $service = new AuthenticationService([
        'unauthenticatedRedirect' => '/users/login',
        'queryParam' => 'redirect',
    ]);

    // Identifier: ユーザ名＋パスワード
    $service->loadIdentifier('Authentication.Password', [
        'fields' => [
            'username' => 'username',
            'password' => 'password',
        ],
        'resolver' => [
            'className' => 'Authentication.Orm',
            'userModel' => 'Users',
            'finder' => 'auth',
        ],
    ]);

    // Authenticator: Session + Cookie（RememberMe）
    $service->loadAuthenticator('Authentication.Session');
    $service->loadAuthenticator('Authentication.Cookie', [
        'cookie' => 'CookieAuth',
        'fields' => ['username' => 'username', 'password' => 'password'],
        'resolver' => [
            'className' => 'Authentication.Orm',
            'userModel' => 'Users',
        ],
    ]);

    return $service;
}
```

**根拠**: `AppController.php:31-35` の `Auth` コンポーネント設定、`UsersController.php:60-89` の Cookie 認証フロー。CakePHP 5 の `CookieAuthenticator` は平文パスワード Cookie を前提とするため、`UserToken` テーブルとの組み合わせによるカスタム実装が必要（後述）。

### 1.3 ログインユーザーの取得

- **CakePHP 2**: `$this->Auth->user()` → `AppController.php:261-267`
- **CakePHP 5**: `$this->Authentication->getIdentity()` / `$this->Authentication->getIdentityData()`

```php
// コントローラ内
$identity = $this->Authentication->getIdentity();
$userId = $identity->getIdentifier();
$userData = $identity->getOriginalData(); // ユーザ配列
```

**根拠**: `AppController.php:261-267` (`readAuthUser()`) を全面的に置き換える。

---

## 2. Web ログインフロー（`UsersController::login()` の CakePHP 5 再実装）

### 2.1 現行フローの分析

`Controller/UsersController.php:50-184` の login アクションは以下の処理を行う：

| 行 | 処理 |
|---|---|
| 56-57 | 旧方式 Cookie（`Auth`）の破棄 |
| 60-89 | RememberMe Cookie（`CookieAuth`）による自動ログイン |
| 92-169 | 通常 POST ログイン処理 |
| 99-111 | ユーザ名・パスワードの形式バリデーション |
| 114-122 | ログイン試行回数制限チェック |
| 124-159 | ログイン成功時の処理（RememberMe トークン発行、最終ログイン日時保存） |
| 163-168 | ログイン失敗時のランダムスリープ＋エラー表示 |
| 172-177 | デモモード時の初期値設定 |

### 2.2 CakePHP 5 での再実装

```php
// app/src/Controller/UsersController.php
public function login(): ?Response
{
    $this->request->allowMethod(['get', 'post']);

    $result = $this->Authentication->getResult();

    // POST かつ認証失敗
    if ($this->request->is('post') && !$result->isValid()) {
        $username = $this->request->getData('username');
        $password = $this->request->getData('password');

        // ユーザ名・パスワード形式バリデーション
        if (!$this->_validateLoginInput($username, $password)) {
            $this->Flash->error(__('ログインID、もしくはパスワードの形式が正しくありません'));
            return null;
        }

        // ログイン試行回数制限
        if ($this->_isLoginBlocked($username)) {
            $this->writeLog('login_blocked', $username);
            $this->Flash->error(__('ログイン試行回数が上限に達しました。1時間後に再度お試しください。'));
            return null;
        }

        // 認証失敗（Authentication プラグインが実行済み）
        usleep(rand(1500000, 2500000));
        $this->writeLog('login_error', $username);
        $this->Flash->error(__('ログインID、もしくはパスワードが正しくありません'));
        return null;
    }

    // POST かつ認証成功
    if ($this->request->is('post') && $result->isValid()) {
        $identity = $this->Authentication->getIdentity();
        $user = $identity->getOriginalData();

        // RememberMe（受講者のみ）
        if (!empty($this->request->getData('remember_me'))) {
            if ($user['role'] === 'user') {
                try {
                    $tokenTable = $this->fetchTable('UserTokens');
                    $token = $tokenTable->issueRememberToken($user['id']);
                    if ($token) {
                        $days = (int)Configure::read('remember_token_expired_days');
                        if ($days <= 0) $days = 14;
                        // Response に Cookie を設定
                        $cookie = (new Cookie('CookieAuth'))
                            ->withValue($token)
                            ->withExpiry(new \DateTimeImmutable("+$days days"))
                            ->withPath('/')
                            ->withHttpOnly(true);
                        $this->response = $this->response->withCookie($cookie);
                    }
                } catch (\Exception $e) {
                    // テーブル未作成時は RememberMe のみスキップ
                }
            } else {
                $this->Flash->error(__('ログイン状態の保持は受講者のみ利用できます'));
            }
        }

        // 最終ログイン日時保存
        $usersTable = $this->fetchTable('Users');
        $userEntity = $usersTable->get($user['id']);
        $userEntity->last_logined = date('Y-m-d H:i:s');
        $usersTable->saveOrFail($userEntity);

        $this->writeLog('user_logined', '');
        return $this->redirect(['controller' => 'UsersCourses', 'action' => 'index']);
    }

    // GET リクエスト
    if (Configure::read('demo_mode')) {
        $this->set('username', Configure::read('demo_login_id'));
        $this->set('password', Configure::read('demo_password'));
    }

    return null;
}
```

**根拠**:
- `UsersController.php:92-96` — HTTPS でない場合の `remember_me` 無効化は CakePHP 5 でも維持
- `UsersController.php:99-111` — 入力形式バリデーションは CakePHP 5 でも維持
- `UsersController.php:114-122` — ログイン試行回数制限は独立メソッドとして維持
- `UsersController.php:127-151` — RememberMe トークン発行は `UserToken::issueRememberToken()` を継続利用
- `UsersController.php:153-158` — 最終ログイン日時保存は Entity で実装
- `UsersController.php:163-168` — ブルートフォース対策のスリープは維持
- `UsersController.php:172-177` — デモモード初期値設定は維持

### 2.3 RememberMe Cookie の自動ログイン

CakePHP 5 の標準 `CookieAuthenticator` は平文パスワードを Cookie に保存する仕組みだが、現行システムは `selector:validator` 方式のハッシュベース Cookie を使用している。標準の `CookieAuthenticator` ではなく、カスタム認証処理で対応する。

```php
// app/src/Controller/UsersController.php（login() の冒頭）
// RememberMe Cookie による自動ログイン
$cookieValue = $this->request->getCookie('CookieAuth');
if ($cookieValue !== null && $this->Authentication->getIdentity() === null) {
    try {
        $tokenTable = $this->fetchTable('UserTokens');
        $user = $tokenTable->authenticateRememberCookie($cookieValue);

        if ($user && isset($user['role']) && $user['role'] === 'user') {
            // ログインセッションを開始
            $this->Authentication->setIdentity(new \Authentication\DefaultIdentity(
                $user['id'],
                'default',
                $user
            ));
            // ... 後続の処理（最終ログイン日時保存等）
        } else {
            // Cookie 削除
            $this->response = $this->response->withCookie(
                (new \Cake\Http\Cookie\Cookie('CookieAuth'))->withExpired()
            );
        }
    } catch (\Exception $e) {
        $this->response = $this->response->withCookie(
            (new \Cake\Http\Cookie\Cookie('CookieAuth'))->withExpired()
        );
    }
}
```

**根拠**: `UsersController.php:60-89` — 現行の `authenticateRememberCookie()` フローをそのまま移植。`UserToken` テーブルの `selector:validator` 方式は CakePHP 5 でも有効。

---

## 3. RememberMe トークンシステム

### 3.1 現行 `Model/UserToken.php` のメソッド一覧

`Model/UserToken.php` に定義されている全11メソッド：

| # | メソッド | 行 | 説明 |
|---|---|---|---|
| 1 | `isAvailable()` | 37-68 | `ib_user_tokens` テーブルの存在確認（リクエスト内キャッシュ） |
| 2 | `getDataSource()` | 75-81 | テーブル未作成時の `MissingTableException` 防止 |
| 3 | `parseCookie()` | 89-110 | `selector:validator` 文字列を分解 |
| 4 | `issueRememberToken()` | 118-161 | RememberMe トークン発行 |
| 5 | `authenticateRememberCookie()` | 169-217 | Cookie によるユーザ認証 |
| 6 | `revokeByCookie()` | 225-254 | Cookie に対応するトークンを無効化 |
| 7 | `revokeAllForUser()` | 262-286 | ユーザの RememberMe トークンを全無効化 |
| 8 | `issueApiToken()` | 296-355 | API トークン発行 |
| 9 | `authenticateApiToken()` | 363-416 | Bearer トークンによるユーザ認証 |
| 10 | `revokeApiToken()` | 424-454 | API トークン無効化 |
| 11 | `revokeAllApiForUser()` | 462-486 | ユーザの API トークンを全無効化 |

### 3.2 CakePHP 5 への Table メソッド変換

`Model/UserToken.php` → `src/Model/Table/UserTokensTable.php`

CakePHP 5 の Table クラスに変換する際の変更点：

| 現行 (CakePHP 2) | CakePHP 5 |
|---|---|
| `AppModel` 継承 | `Table` 継承 |
| `$this->useDbConfig` | `$this->defaultConnectionName()` または `initialize()` で `$this->setTable('ib_user_tokens')` |
| `ConnectionManager::getDataSource()` | `$this->getConnection()` |
| `$this->find('first', [...])` | `$this->find('first')->where([...])->first()` |
| `$this->create()` + `$this->save($data)` | `$this->newEntity($data)` + `$this->saveOrFail($entity)` |
| `$this->saveField('revoked', ...)` | `$entity->revoked = ...; $this->saveOrFail($entity)` |
| `$this->updateAll(...)` | `$this->query()->update()->set([...])->where([...])->execute()` |
| `Configure::read(...)` | `Configure::read(...)` （変更なし） |
| `ClassRegistry::init('User')` | `$this->getTableLocator()->get('Users')` |

### 3.3 `issueRememberToken()` の詳細

```php
// src/Model/Table/UserTokensTable.php
public function issueRememberToken(int $userId): ?string
{
    $days = (int)Configure::read('remember_token_expired_days');
    if ($days <= 0) $days = 14;

    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));

    $entity = $this->newEntity([
        'user_id' => $userId,
        'token_type' => 'remember',
        'token_selector' => $selector,
        'token_hash' => password_hash($validator, PASSWORD_DEFAULT),
        'expired' => date('Y-m-d H:i:s', strtotime("+$days days")),
        'last_used' => date('Y-m-d H:i:s'),
        'revoked' => null,
        'user_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);

    if ($this->saveOrFail($entity)) {
        return $selector . ':' . $validator;
    }
    return null;
}
```

**根拠**: `Model/UserToken.php:118-161` — 全ロジックを CakePHP 5 の Table/Entity パターンに変換。`password_hash()` / `random_bytes()` は PHP 組み込み関数のため変更不要。

### 3.4 `authenticateRememberCookie()` の詳細

```php
// src/Model/Table/UserTokensTable.php
public function authenticateRememberCookie(string $cookieValue): ?array
{
    $parsed = $this->parseCookie($cookieValue);
    if ($parsed === null) return null;

    $token = $this->find('first')
        ->where([
            'token_type' => 'remember',
            'token_selector' => $parsed['selector'],
            'revoked IS NULL',
            'expired >=' => date('Y-m-d H:i:s'),
        ])
        ->first();

    if (!$token) return null;

    if (!password_verify($parsed['validator'], $token->token_hash)) {
        // 不正な Cookie → トークン無効化
        $token->revoked = date('Y-m-d H:i:s');
        $this->saveOrFail($token);
        return null;
    }

    $usersTable = $this->getTableLocator()->get('Users');
    $user = $usersTable->get($token->user_id);

    if (!$user) return null;

    // last_used 更新
    $token->last_used = date('Y-m-d H:i:s');
    $this->saveOrFail($token);

    return $user->toArray();
}
```

**根拠**: `Model/UserToken.php:169-217` — `password_verify()` による検証フローはそのまま維持。

### 3.5 `revokeByCookie()` / `revokeAllForUser()`

```php
// src/Model/Table/UserTokensTable.php
public function revokeByCookie(string $cookieValue): void
{
    $parsed = $this->parseCookie($cookieValue);
    if ($parsed === null) return;

    $token = $this->find('first')
        ->where([
            'token_type' => 'remember',
            'token_selector' => $parsed['selector'],
            'revoked IS NULL',
        ])
        ->first();

    if ($token) {
        $token->revoked = date('Y-m-d H:i:s');
        $this->saveOrFail($token);
    }
}

public function revokeAllForUser(int $userId): void
{
    $this->query()
        ->update()
        ->set(['revoked' => date('Y-m-d H:i:s')])
        ->where([
            'user_id' => $userId,
            'token_type' => 'remember',
            'revoked IS NULL',
        ])
        ->execute();
}
```

**根拠**: `Model/UserToken.php:225-286`

### 3.6 Cookie 操作の移行

- **CakePHP 2**: `$this->Cookie->read()` / `$this->Cookie->write()` / `$this->Cookie->delete()`（`AppController.php:218-255`）
- **CakePHP 5**: `$this->request->getCookie()` / `$this->response->withCookie()` / `Cookie::withExpired()`

| 現行 | CakePHP 5 |
|---|---|
| `$this->Cookie->read('CookieAuth')` | `$this->request->getCookie('CookieAuth')` |
| `$this->Cookie->write('CookieAuth', $value, false, '+14 days')` | `$this->response = $this->response->withCookie((new Cookie('CookieAuth'))->withValue($value)->withExpiry(new \DateTimeImmutable('+14 days'))->withHttpOnly(true))` |
| `$this->Cookie->delete('CookieAuth')` | `$this->response = $this->response->withCookie((new Cookie('CookieAuth'))->withExpired())` |

`Cookie` コンポーネントは CakePHP 5 に存在しないため、すべて `Response::withCookie()` / `Request::getCookie()` に移行する。

**根拠**: `AppController.php:27-29`（Cookie コンポーネント設定）、`AppController.php:218-255`（Cookie 操作メソッド群）

---

## 4. API Bearer 認証

### 4.1 現行フローの分析

`ApiBaseController.php:116-137` のフロー：

| 行 | 処理 |
|---|---|
| 118 | `Authorization` ヘッダー取得 |
| 120-121 | ヘッダー未送信 → 401 |
| 123-124 | `Bearer` スキームでない → 401 |
| 126-129 | `UserToken::authenticateApiToken()` で認証 |
| 131-132 | 認証失敗 → 401 |
| 134-136 | 認証成功 → `$this->apiUser` に格納 |

### 4.2 CakePHP 5 でのカスタム Authenticator

CakePHP 5 の `authentication` プラグインには `BearerTokenAuthenticator` が含まれているが、現行システムは `selector:validator` 方式のカスタムトークン形式を使用しているため、カスタム Authenticator を実装する。

```php
// app/src/Authentication/Authenticator/UserTokenAuthenticator.php
namespace App\Authentication\Authenticator;

use Authentication\Authenticator\AbstractAuthenticator;
use Authentication\Authenticator\Result;
use Psr\Http\Message\ServerRequestInterface;

class UserTokenAuthenticator extends AbstractAuthenticator
{
    public function authenticate(ServerRequestInterface $request, ResponseInterface $response): ?Result
    {
        $header = $request->getHeaderLine('Authorization');

        if (empty($header)) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        $userTokensTable = $this->getUserTokensTable();
        $result = $userTokensTable->authenticateApiToken($token);

        if (!$result || empty($result['user'])) {
            return new Result(null, Result::FAILURE_CREDENTIALS_MISSING, [
                'Invalid or expired API token',
            ]);
        }

        return new Result($result['user'], Result::SUCCESS);
    }

    protected function getUserTokensTable()
    {
        return $this->_tableLocator->get('UserTokens');
    }
}
```

### 4.3 `issueApiToken()` / `authenticateApiToken()` / `revokeApiToken()`

これらのメソッドは `UserTokensTable` に Table メソッドとして移行する（3.2 節と同様）。

- `issueApiToken()` — `Model/UserToken.php:296-355`
- `authenticateApiToken()` — `Model/UserToken.php:363-416`
- `revokeApiToken()` — `Model/UserToken.php:424-454`
- `revokeAllApiForUser()` — `Model/UserToken.php:462-486`

### 4.4 Authorization ヘッダー取得ロジック

CakePHP 2 では Apache 環境での `REDIRECT_HTTP_AUTHORIZATION` ヘッダー対応が必要だった（`ApiBaseController.php:86-109`）。

CakePHP 5 では PSR-7 リクエストが標準であり、`$request->getHeaderLine('Authorization')` で統一できる。Apache のリライト環境でも `getallheaders()` または `.htaccess` で対応済みの場合は不要。

**根拠**: `ApiBaseController.php:86-109` — CakePHP 5 ではこの複雑なフォールバックロジックが不要になる。

### 4.5 `ApiAuthController` の移行

`Controller/ApiAuthController.php` → `src/Controller/Api/ApiAuthController.php`

- `$this->User->findByUsername()` → `$this->fetchTable('Users')->findByUsername()`
- `Security::hash()` → `password_verify()`（旧 SHA1 互換）
- `$this->UserToken->issueApiToken()` → `$this->fetchTable('UserTokens')->issueApiToken()`
- `$this->input()` → `$this->request->getParsedBody()`（JSON リクエスト）

**根拠**: `Controller/ApiAuthController.php:52-128`

---

## 5. ロール判定

### 5.1 現行の判定方法

`AppController.php:113-118` で手動判定を行っている：

```php
if(
    ($this->readAuthUser('role') != 'admin')&&
    ($this->readAuthUser('role') != 'manager')&&
    ($this->readAuthUser('role') != 'editor')&&
    ($this->readAuthUser('role') != 'teacher')
)
```

### 5.2 CakePHP 5 での方針

CakePHP 5 には `Authorization` プラグインがあるが、現行システムはシンプルなロール文字列判定のみであり、ACL は未使用（`Docs/design/README.md:89` で「**削除（未使用）**」と記載）。よって、**`Authorization` プラグインは導入せず、手動判定を Component として実装する**。

```php
// app/src/Controller/Component/RoleComponent.php
namespace App\Controller\Component;

use Cake\Controller\Component;
use Cake\Http\Session;

class RoleComponent extends Component
{
    protected array $staffRoles = ['admin', 'manager', 'editor', 'teacher'];

    public function isStaff(): bool
    {
        $role = $this->getRole();
        return in_array($role, $this->staffRoles, true);
    }

    public function isAdmin(): bool
    {
        return $this->getRole() === 'admin';
    }

    public function getRole(): ?string
    {
        $identity = $this->getController()->Authentication->getIdentity();
        return $identity ? $identity->getOriginalData()['role'] ?? null : null;
    }

    public function requireStaff(): void
    {
        if (!$this->isStaff()) {
            throw new \Cake\Http\Exception\ForbiddenException(
                __('管理画面へのアクセス権限がありません')
            );
        }
    }
}
```

**根拠**: `AppController.php:108-141` — admin ページでのロール判定ロジックを Component に集約。`AppController.php:375-378` の `isAdminPage()` も併せて移行する。

### 5.3 `isAdminPage()` の CakePHP 5 変換

```php
// 現行 (AppController.php:375-378)
protected function isAdminPage()
{
    return (isset($this->request->params['admin']));
}

// CakePHP 5
protected function isAdminPage(): bool
{
    return $this->request->getParam('prefix') === 'Admin';
}
```

**根拠**: `AppController.php:375-378` — CakePHP 5 では `Routing.prefixes` → `$routes->prefix('Admin')` となり、`admin` パラメータは `prefix` パラメータに変更される。

---

## 6. RememberMe と API Bearer の違い

| 項目 | RememberMe | API Bearer |
|---|---|---|
| **トークン種別** | `token_type = 'remember'` | `token_type = 'api'` |
| **使用場所** | Web ブラウザ（Cookie: `CookieAuth`） | 外部クライアント（Header: `Authorization: Bearer`） |
| **対象ユーザ** | `role = 'user'` のみ（`UsersController.php:74`） | 全ロール |
| **有効期限** | `remember_token_expired_days`（既定 14 日） | `api_token_expired_days`（既定 30 日）、永続トークン（`9999-12-31`） |
| **トークン発行時機** | ログイン時（`remember_me` チェック時） | POST `/api/v1/auth/token` |
| **セキュリティ** | `HttpOnly` Cookie、CSRF 保護あり | Authorization ヘッダー、CSRF 不要 |
| **認証方式** | `parseCookie()` → DB 検索 → `password_verify()` | `parseCookie()` → DB 検索 → `password_verify()` |
| **無効化** | ログアウト時 / パスワード変更時 | `DELETE /api/v1/auth/token` / パスワード変更時 |
| **Cookie/Header 操作** | `Request::getCookie()` / `Response::withCookie()` | `Request::getHeaderLine('Authorization')` |
| **CakePHP 5 認証** | カスタム処理（`CookieAuthenticator` は不適格） | カスタム `UserTokenAuthenticator` |

**根拠**: `Model/UserToken.php:118-161`（RememberMe）と `Model/UserToken.php:296-355`（API Token）の実装差分、`UsersController.php:74`（role による制限）、`Config/ib_config.php:135`（有効期限設定）
