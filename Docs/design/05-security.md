# 05 — セキュリティ設計（CakePHP 2.10 → 5.x 移行）

## 概要

本ドキュメントでは、CakePHP 2.10 のセキュリティ機能（`SecurityComponent` / `AppSecurityComponent` / `FormToken` / `Session` コンポーネント）を、CakePHP 5 の組み込みセキュリティ機能に置き換える設計を定義する。

---

## 1. CSRF 保護

### 1.1 CakePHP 5 での導入

`Application::middleware()` で `CsrfProtectionMiddleware` を追加する。

```php
// app/src/Application.php
use Cake\Http\Middleware\CsrfProtectionMiddleware;

public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
{
    $middlewareQueue
        // ... 既存ミドルウェア ...
        ->add(new CsrfProtectionMiddleware([
            'httponly' => true,
        ]))
    ;

    return $middlewareQueue;
}
```

### 1.2 現行の blackHoleCallback の代替

**現行**: `AppController.php:152-170` の `blackHole()` メソッドが CSRF/セキュリティエラーをハンドリング。

```php
// AppController.php:152-170
public function blackHole($type)
{
    if($type === 'csrf')
    {
        $this->Session->setFlash(__('トークンの有効期限が切れました。もう一度ログインしてください。'));
        return $this->redirect(array('controller' => 'users', 'action' => 'login'));
    }
    if($type === 'secure')
    {
        $this->Session->setFlash(__('セッションの有効期限が切れました。もう一度ログインしてください。'));
        return $this->redirect(array('controller' => 'users', 'action' => 'login'));
    }
    throw new BadRequestException(__('不正なリクエストです。'));
}
```

**CakePHP 5**: `CsrfProtectionMiddleware` は不正なリクエストを `403 Forbidden` で応答する。`AppController` の `initialize()` で例外ハンドリングを追加するか、`ErrorMiddleware` の設定で対応する。

```php
// app/src/Controller/AppController.php
public function initialize(): void
{
    parent::initialize();

    // CSRF エラー時のリダイレクト処理はミドルウェア層で実行される
    // 必要に応じて ErrorHandler でカスタムハンドリングを追加
}
```

**根拠**: `AppController.php:152-170` — `blackHole()` メソッドは `SecurityComponent` 専用のコールバックであり、CakePHP 5 では不要。

### 1.3 API エンドポイントからの CSRF 除外設定

API エンドポイントはステートレスであり、CSRF 保護を除外する必要がある。

```php
// app/src/Application.php
public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
{
    $csrf = new CsrfProtectionMiddleware([
        'httponly' => true,
    ]);

    // API ルートは CSRF 除外
    $csrf->skipCheckCallback(function ($request) {
        return str_starts_with($request->getUri()->getPath(), '/api/');
    });

    $middlewareQueue
        ->add($csrf)
    ;

    return $middlewareQueue;
}
```

**根拠**: `Config/routes.php:37-85` — 全 API ルートは `/api/v1/` プレフィクスを使用。CSRF トークンはセッションベースのため、ステートレス API では不要。

---

## 2. フォーム改ざん防止

### 2.1 `FormProtectionComponent` の導入

`AppController::initialize()` で `FormProtectionComponent` を読み込む。

```php
// app/src/Controller/AppController.php
public function initialize(): void
{
    parent::initialize();

    $this->loadComponent('FormProtection');
}
```

### 2.2 現行の AppSecurityComponent / FormToken / AppBoostCakeFormHelper の廃止

| 現行ファイル | 理由 | CakePHP 5 での対応 |
|---|---|---|
| `Controller/Component/AppSecurityComponent.php` | `SecurityComponent` を継承。CakePHP 5 では `SecurityComponent` が完全削除 | `FormProtectionComponent` に置換 |
| `Lib/FormToken.php` | CakePHP 4 方式の HMAC ハッシュを自前実装 | CakePHP 5 の `FormProtectionMiddleware` が同等機能を提供 |
| `View/Helper/AppBoostCakeFormHelper.php` | CakePHP 4 方式の `_Token` フィールドを自前生成 | CakePHP 5 の `FormHelper` が自動生成 |

### 2.3 `FormProtectionComponent` の動作

CakePHP 5 の `FormProtectionComponent` は以下の機能を提供する：

1. **`_Token` hidden フィールドの自動生成**: `FormHelper::create()` 時に `_Token` フィールドを自動挿入
2. **送信時検証**: POST リクエストの `_Token` フィールドを検証
3. **改ざん検出**: フォームフィールドの追加・削除を検出
4. **URL 検証**: 送信先 URL の一致を検証

### 2.4 `_Token` フィールドとの互換性

CakePHP 2 の `SecurityComponent` と CakePHP 5 の `FormProtectionComponent` は `_Token` フィールドの形式が異なるため、**移行期間中の互換性は不要**（完全移行を前提とする）。

| 項目 | CakePHP 2 (`SecurityComponent`) | CakePHP 5 (`FormProtectionComponent`) |
|---|---|---|
| フィールド名 | `_Token[fields]`, `_Token[unlocked]`, `_Token[key]` | `_Token`（単一 hidden フィールド） |
| ハッシュ方式 | `sha1` + `Security::salt` | `hash_hmac('sha256')` |
| トークン有効期限 | セッション有効期限内 | リクエスト単位（デフォルト） |

### 2.5 `unlockedFields` の設定方法

`FormProtectionComponent` で特定フィールドを改ざん検証から除外する場合：

```php
// 個別フォーム内
$this->FormProtection->setUnlockedFields(['cmd', 'token_type']);

// または AppController でデフォルト設定
public function initialize(): void
{
    parent::initialize();
    $this->loadComponent('FormProtection', [
        // 特定フィールドをデフォルトで除外（推奨: フォームごとに設定）
    ]);
}
```

**根拠**: `UsersController.php:27-28` — 現行の `Security` コンポーネント設定 `'unlockedFields' => ['cmd']` に相当。

---

## 3. パスワード

### 3.1 bcrypt ハッシュ（`User.php:114-129` の `beforeSave`）

**現行**: `Model/User.php:114-129` で `password_hash(PASSWORD_BCRYPT)` を手動実行。

```php
// Model/User.php:114-129
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

**CakePHP 5**: `DefaultPasswordHasher` に委譲する。

```php
// app/src/Model/Table/UsersTable.php
use Authentication\Password\DefaultPasswordHasher;

public function beforeSave(\Cake\Datasource\EntityInterface $entity, \ArrayAccess $options): void
{
    if ($entity->has('password') && $entity->get('password') !== '') {
        $hasher = new DefaultPasswordHasher();
        $entity->set('password', $hasher->hash($entity->get('password')));
    }
}
```

**根拠**: `Model/User.php:114-129` — `DefaultPasswordHasher` は内部で `password_hash(PASSWORD_BCRYPT)` を使用するため、動作は同一。CakePHP 5 の ORM では Entity ベースの `beforeSave` コールバックを採用。

### 3.2 旧 SHA1 パスワードの自動アップグレード

**現行**: `UsersController.php:219-226` で SHA1 → bcrypt の自動アップグレード。

```php
// UsersController.php:219-226
// 既存 SHA1（AuthComponent::password）での認証
if(!$this->Auth->login())
    return false;
// 次回以降は bcrypt で認証できるようアップグレード
$this->User->id = $user['User']['id'];
$this->User->saveField('password', $password);
```

**CakePHP 5**: `DefaultPasswordHasher::needsRehash()` で対応。

```php
// app/src/Controller/UsersController.php（login 成功後）
$identity = $this->Authentication->getIdentity();
$user = $identity->getOriginalData();
$hash = $user['password'];

// 旧ハッシュの場合は再ハッシュ
$hasher = new DefaultPasswordHasher();
if ($hasher->needsRehash($hash)) {
    $usersTable = $this->fetchTable('Users');
    $userEntity = $usersTable->get($user['id']);
    // パスワードは平文で渡す必要があるため、セッションに一時保存
    // または、カスタム Identifier でハッシュ比較時に自動アップグレード
}
```

CakePHP 5 の `PasswordIdentifier` は `DefaultPasswordHasher` を使用するため、旧 SHA1 パスワードは自動的に検証に失敗する。SHA1 互換が必要な場合は、カスタム Identifier を実装して `password_verify()` 直前に SHA1 チェックを追加する。

**推奨アプローチ**: 移行時にバッチスクリプトで全 SHA1 パスワードを bcrypt に変換し、SHA1 互換コードは削除する。

**根拠**: `UsersController.php:191-228` (`_login()` メソッド)、`ApiAuthController.php:77-81`（API の SHA1 チェック）

---

## 4. セッション

### 4.1 `Session` コンポーネントの廃止

**現行**: `AppController.php:26` で `Session` コンポーネントを読み込み、`AppController.php:176-212` で `readSession()` / `writeSession()` / `hasSession()` / `deleteSession()` メソッドを定義。

**CakePHP 5**: `Session` コンポーネントは存在しない。`$this->request->getSession()` に移行。

| 現行 | CakePHP 5 |
|---|---|
| `$this->Session->read($key)` | `$this->request->getSession()->read($key)` |
| `$this->Session->write($key, $value)` | `$this->request->getSession()->write($key, $value)` |
| `$this->Session->check($key)` | `$this->request->getSession()->check($key)` |
| `$this->Session->delete($key)` | `$this->request->getSession()->delete($key)` |

```php
// app/src/Controller/AppController.php
protected function readSession(string $key): mixed
{
    $val = $this->request->getSession()->read($key);
    return $val ?? '';
}

protected function writeSession(string $key, mixed $value): void
{
    $this->request->getSession()->write($key, $value);
}

protected function hasSession(string $key): bool
{
    return $this->request->getSession()->check($key);
}

protected function deleteSession(string $key): void
{
    $this->request->getSession()->delete($key);
}
```

**根拠**: `AppController.php:176-212` — セッション操作メソッド群。CakePHP 5 では `Cake\Http\Session` クラスに相当する機能が `$request->getSession()` で提供される。

### 4.2 Session ヘルパー → Flash ヘルパー

**現行**: `AppController.php:47` で `'Session'` ヘルパーを読み込み、`$this->Session->setFlash()` を使用。

**CakePHP 5**: `Flash` ヘルパーに統合。

```php
// テンプレート内
<?= $this->Flash->render() ?>

// コントローラ内
$this->Flash->success(__('保存しました'));
$this->Flash->error(__('エラーが発生しました'));
```

**根拠**: `AppController.php:47`（Session ヘルパー設定）、`AppController.php:157`（`Session->setFlash()` 使用箇所）

### 4.3 Cookie 設定

**現行**: `Config/core.php:230-237` でセッション Cookie を設定。

```php
// Config/core.php:230-237
Configure::write('Session', [
    'defaults' => 'php',
    'cookie' => 'AppSession',
    'cookieTimeout' => 1440,
    'ini' => [
        'session.cookie_path' => '/'
    ]
]);
```

**CakePHP 5**: `config/app.php` の `Session` 設定に移行。

```php
// config/app.php
'Session' => [
    'defaults' => 'php',
    'cookie' => 'AppSession',
    'timeout' => 1440,
    'cookieTimeout' => 1440,
    'ini' => [
        'session.cookie_path' => '/',
    ],
],
```

**根拠**: `Config/core.php:230-237` — CakePHP 5 では `config/app.php` に統合。

---

## 5. AppSecurityComponent / FormToken / AppBoostCakeFormHelper 廃止の根拠

### 5.1 SecurityComponent の完全削除

CakePHP 5 では `SecurityComponent` が完全に削除されている。これに伴い、以下の自前実装も不要となる：

| ファイル | 現行の役割 | 廃止理由 |
|---|---|---|
| `Controller/Component/AppSecurityComponent.php` | `SecurityComponent` を継承し、CakePHP 4 方式のフォーム改ざん検証を実装 | `SecurityComponent` が CakePHP 5 に存在しない |
| `Lib/FormToken.php` | `hash_hmac('sha1')` によるフォームトークンの自前生成 | `FormProtectionMiddleware` が同等機能を提供 |
| `View/Helper/AppBoostCakeFormHelper.php` | `BoostCakeFormHelper` を継承し、CakePHP 4 方式の `_Token` フィールドを生成 | `FormHelper` が `FormProtectionComponent` と連携して `_Token` を自動生成 |

### 5.2 現行の 2 方式切り替え

`Config/core.php:250` で `Security.formProtection` を設定し、CakePHP 2 方式と CakePHP 4 方式を切り替えていた：

```php
// Config/core.php:250
Configure::write('Security.formProtection', 'cake4');
```

CakePHP 5 では `FormProtectionComponent`（CakePHP 4 方式相当）が唯一の方式であるため、切り替えロジックは不要。

### 5.3 移行時の注意点

1. **`AppSecurityComponent` の削除**: `AppController.php:37-39` の Security コンポーネント設定を削除し、`FormProtectionComponent` に置換
2. **`FormToken.php` の削除**: `Lib/FormToken.php` を完全削除
3. **`AppBoostCakeFormHelper` の削除**: `View/Helper/AppBoostCakeFormHelper.php` を削除し、`friendsofcake/bootstrap-ui` の `FormHelper` に統合
4. **`Security.salt` の廃止**: `Config/core.php:243` の `Security.salt` は CakePHP 5 では不要（`FormProtectionComponent` は `Security.salt` を使用しない）

**根拠**: `Config/core.php:243-256`（Security.salt / cipherSeed）、`Config/core.php:250`（formProtection 設定）、`Controller/AppController.php:37-39`（AppSecurity 設定）
