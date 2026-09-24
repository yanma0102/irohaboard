# 11 — 設定・起動設計

## 1. config/app.php：core.php の全設定移行

`Config/core.php`（413行）の全設定を CakePHP 5 の `config/app.php` に移行する。各設定キーの対応を以下に示す。

### 設定キー対応表

| 現行の設定（`Config/core.php`） | 移行先の設定（`config/app.php`） | 備考 |
|---|---|---|
| `debug`（コメントアウト: `:37-38`） | `'debug' => filter_var(env('CAKEPHP_DEBUG', false), FILTER_VALIDATE_BOOLEAN)` | 環境変数 `CAKEPHP_DEBUG` で制御 |
| `Error.handler`（`:55-59`） | `'ErrorTrap' => ['errorLevel' => E_ALL & ~E_DEPRECATED, 'log' => true]` | CakePHP 5 の `ErrorTrap` に変更 |
| `Error.level`（`:57`） | `'ErrorTrap.errorLevel'` | 上記に統合 |
| `Error.trace`（`:58`） | `'ErrorTrap.trace' => true` | — |
| `Exception.handler`（`:83-87`） | `'ExceptionTrap' => ['log' => true]` | CakePHP 5 の `ExceptionTrap` に変更 |
| `Exception.renderer`（`:85`） | `'ExceptionTrap.errorRenderer'` | デフォルトの `ConsoleExceptionRenderer` を使用 |
| `Exception.log`（`:86`） | `'ExceptionTrap.log' => true` | — |
| `App.encoding`（`:92`） | `'App.encoding' => 'UTF-8'` | 変更なし |
| `Routing.prefixes`（`:164`） | `config/routes.php` に移動 | `$routes->prefix('Admin', ...)` でスコープ定義 |
| `Cache.disable`（`:169`） | `'Cache.disable' => true` | 変更なし |
| `Session.defaults`（`:230-237`） | `'Session' => ['defaults' => 'php']` | 変更なし |
| `Session.cookie`（`:232`） | `'Session.cookie' => 'AppSession'` | 変更なし |
| `Session.cookieTimeout`（`:233`） | `'Session.timeout' => 1440` | CakePHP 5 では `timeout` に変更 |
| `Session.ini`（`:234-236`） | `'Session.ini' => ['session.cookie_path' => '/']` | 変更なし |
| `Security.salt`（`:243`） | `'Security.salt' => env('SECURITY_SALT', '...')` | 環境変数で管理 |
| `Security.formProtection`（`:250`） | 削除（`FormProtectionMiddleware` で代替） | CakePHP 5 の標準機能 |
| `Security.cipherSeed`（`:256`） | 削除（CakePHP 5 では未使用） | — |
| `Acl.classname`（`:289`） | 削除 | ACL 未使用（`Config/bootstrap.php:93-95` に Acl ロード無し） |
| `Acl.database`（`:290`） | 削除 | 上記同様 |
| `date_default_timezone_set`（`:297`） | `'App.defaultTimezone' => 'Asia/Tokyo'` | 環境変数で上書き可能 |
| `Cache._cake_core_`（コメントアウト: `:395-401`） | `'Cache._cake_core_' => [...]` | ファイルキャッシュ |
| `Cache._cake_model_`（コメントアウト: `:407-413`） | `'Cache._cake_model_' => [...]` | ファイルキャッシュ |

### 移行先 config/app.php の主要セクション

```php
<?php
/**
 * CakePHP 5.x Configuration
 */
return [
    /**
     * Debug Level:
     * - false: Production mode (no error output)
     * - true: Development mode (full error output)
     */
    'debug' => filter_var(env('CAKEPHP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),

    /**
     * Error Trap Configuration
     * ErrorHandler::handleError() / handleException() の代替
     * 根拠: Config/core.php:55-87
     */
    'ErrorTrap' => [
        'errorLevel' => E_ALL & ~E_DEPRECATED,
        'log' => true,
        'trace' => true,
    ],

    /**
     * Exception Trap Configuration
     * 根拠: Config/core.php:83-87
     */
    'ExceptionTrap' => [
        'log' => true,
        'trace' => true,
    ],

    /**
     * App Configuration
     * 根拠: Config/core.php:92, :297
     */
    'App' => [
        'encoding' => 'UTF-8',
        'defaultTimezone' => env('DEFAULT_TIMEZONE', 'Asia/Tokyo'),
        'defaultLocale' => 'en_US',
        'dir' => 'src',
        'webroot' => 'webroot',
        'wwwRoot' => WWW_ROOT,
        'paths' => [
            'plugins' => [ROOT . DS . 'plugins' . DS],
            'templates' => [ROOT . DS . 'templates' . DS],
            'locales' => [RESOURCES . 'locales' . DS],
        ],
    ],

    /**
     * Security Configuration
     * 根拠: Config/core.php:243
     */
    'Security' => [
        'salt' => env('SECURITY_SALT', '397110e45242a23e5802e78f4eec95a7bd39e0f0'),
    ],

    /**
     * Session Configuration
     * 根拠: Config/core.php:230-237
     */
    'Session' => [
        'defaults' => 'php',
        'cookie' => 'AppSession',
        'timeout' => 1440,
        'ini' => [
            'session.cookie_path' => '/',
        ],
    ],

    /**
     * Cache Configuration
     * 根拠: Config/core.php:169, :380-413
     */
    'Cache' => [
        'default' => [
            'className' => File::class,
            'duration' => '+999 days',
            'path' => CACHE,
            'prefix' => 'irohaboard_',
        ],
        '_cake_core_' => [
            'className' => File::class,
            'duration' => '+999 days',
            'path' => CACHE . 'persistent' . DS,
            'prefix' => 'irohaboard_cake_core_',
            'serialize' => true,
        ],
        '_cake_model_' => [
            'className' => File::class,
            'duration' => '+999 days',
            'path' => CACHE . 'models' . DS,
            'prefix' => 'irohaboard_cake_model_',
            'serialize' => true,
        ],
    ],

    /**
     * Log Configuration
     * 根拠: Config/bootstrap.php:128-138
     */
    'Log' => [
        'debug' => [
            'className' => File::class,
            'path' => LOGS,
            'file' => 'debug',
            'types' => ['notice', 'info', 'debug'],
            'scopes' => false,
        ],
        'error' => [
            'className' => File::class,
            'path' => LOGS,
            'file' => 'error',
            'types' => ['warning', 'error', 'critical', 'alert', 'emergency'],
            'scopes' => false,
        ],
    ],

    /**
     * Datasource Configuration
     * 根拠: Config/database.php:7-16
     */
    'Datasources' => [
        'default' => [
            'className' => Mysql::class,
            'driver' => Mysql::class,
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '3306'),
            'username' => env('DB_USER', 'root'),
            'password' => env('DB_PASS', ''),
            'database' => env('DB_NAME', 'irohaboard'),
            'encoding' => 'utf8mb4',
            'timezone' => 'Asia/Tokyo',
            'tablePrefix' => 'ib_',
            'persistent' => true,
        ],
    ],

    /**
     * Plugin Configuration
     * 根拠: Config/bootstrap.php:93-95
     */
    'Plugins' => [
        // DebugKit は環境別にロード（Application::bootstrap() で制御）
    ],
];
```

---

## 2. bootstrap.php の移行

`Config/bootstrap.php`（148行）の各処理を CakePHP 5 の `config/bootstrap.php` と `src/Application.php` の `bootstrap()` メソッドに移行する。

### 移行対応表

| # | 現行の処理 | 移行先 | 移行方法 |
|---|---|---|---|
| 1 | `Cache::config('default', ['engine' => 'File'])`（`:26`） | `config/app.php` の `Cache.default` | `Cache::config()` → `Cache::setConfig()`（app.php 内に移動） |
| 2 | `App::build()` 17パス（`:55-75`） | PSR-4 autoload | `composer.json` の `autoload.psr-4` で代替 |
| 3 | `CakePlugin::load('DebugKit')`（`:93`） | `Application::bootstrap()` | `$this->addPlugin('DebugKit')` |
| 4 | `CakePlugin::load('Search')`（`:94`） | 削除 | プラグイン削除、自作検索で置換 |
| 5 | `CakePlugin::load('BoostCake')`（`:95`） | 削除 | プラグイン削除、bootstrap-ui で置換 |
| 6 | `Configure::write('Dispatcher.filters', ...)`（`:120-123`） | `Application::middleware()` | ミドルウェアに変更 |
| 7 | `CakeLog::config('debug', ...)`（`:129-133`） | `config/app.php` の `Log.debug` | 設定ファイルに移動 |
| 8 | `CakeLog::config('error', ...)`（`:134-138`） | `config/app.php` の `Log.error` | 設定ファイルに移動 |
| 9 | `Configure::load('ib_config')`（`:141`） | `config/bootstrap.php` | `Configure::load('ib_config')` で維持 |
| 10 | `Configure::load('config')`（`:147-148`） | `config/bootstrap.php` | カスタム設定の読み込みを維持 |

### 詳細：各処理の移行

#### 2.1 Cache::config() → Cache::setConfig()（app.php 内）

現行: `Config/bootstrap.php:26`
```php
Cache::config('default', ['engine' => 'File']);
```

移行先: `config/app.php` の `Cache` キーに統合（上記 config/app.php の Cache セクション参照）

CakePHP 5 では `Cache::config()` は廃止。`config/app.php` 内の `Cache` キーで定義する。

#### 2.2 App::build() → PSR-4 autoload

現行: `Config/bootstrap.php:55-75`（17パス）
```php
App::build([
    'Model'                     => [APP.'Custom'.DS.'Model'.DS],
    'Model/Behavior'            => [APP.'Custom'.DS.'Model'.DS.'Behavior'.DS],
    // ... 全17パス
]);
```

移行先: `composer.json` の `autoload.psr-4`
```json
{
  "autoload": {
    "psr-4": {
      "App\\": "src/",
      "App\\Custom\\": "src/Custom/"
    }
  }
}
```

CakePHP 5 では `App::build()` は廃止。PSR-4 autoload で代替する。`Custom/` ディレクトリの17パスは `App\Custom\` 名前空間で吸収される。

#### 2.3 CakePlugin::load() → Application::bootstrap()

現行: `Config/bootstrap.php:93-95`
```php
CakePlugin::load('DebugKit');
CakePlugin::load('Search');
CakePlugin::load('BoostCake');
```

移行先: `src/Application.php` の `bootstrap()` メソッド
```php
public function bootstrap(): void
{
    parent::bootstrap();

    // DebugKit は開発環境のみロード
    if (PHP_SAPI !== 'cli') {
        $this->addPlugin('DebugKit');
    }
}
```

`Search` と `BoostCake` は削除。`DebugKit` は環境別にロード（本番では無効）。

根拠: `Controller/AppController.php:25`（DebugKit が本番でも常時ロードされている問題を修正）

#### 2.4 Dispatcher.filters → Application::middleware()

現行: `Config/bootstrap.php:120-123`
```php
Configure::write('Dispatcher.filters', [
    'AssetDispatcher',
    'CacheDispatcher'
]);
```

移行先: `src/Application.php` の `middleware()` メソッド

CakePHP 5 では `Dispatcher.filters` は廃止。`AssetDispatcher` は CakePHP 5 のデフォルトミドルウェアで対応済み。`CacheDispatcher` は `HttpCacheMiddleware` で代替可能（必要に応じて）。

#### 2.5 ib_config.php の読み込み

現行: `Config/bootstrap.php:141`
```php
Configure::load("ib_config");
```

移行先: `config/bootstrap.php`
```php
<?php
/**
 * Application Bootstrap
 */

// iroha Board 設定ファイルをロード
Configure::load('ib_config');
```

`config/ib_config.php` はそのまま維持。`Configure::load()` で読み込み。

根拠: `Config/ib_config.php:1-198`

#### 2.6 Custom Config 読み込み

現行: `Config/bootstrap.php:144-148`
```php
if(file_exists(APP.'Custom'.DS.'Config'.DS.'config.php'))
{
    Configure::config('default', new PhpReader(APP.'Custom'.DS.'Config'.DS));
    Configure::load("config");
}
```

移行先: `config/bootstrap.php`
```php
<?php
// カスタマイズ用設定ファイルをロード
if (file_exists(ROOT . DS . 'src' . DS . 'Custom' . DS . 'Config' . DS . 'config.php')) {
    $reader = new \Cake\Core\Configure\Engine\PhpConfig(ROOT . DS . 'src' . DS . 'Custom' . DS . 'Config' . DS);
    Configure::config('default', $reader);
    Configure::load('config');
}
```

---

## 3. Application.php

`src/Application.php` は CakePHP 5 アプリケーションのエントリポイント。`middleware()` メソッドと `bootstrap()` メソッドを定義する。

### 構成

```php
<?php
declare(strict_types=1);

namespace App;

use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\Middleware\EncodedUrlFormTrait;
use Cake\Http\MiddlewareQueue;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Application Middleware Configuration
 *
 * CakePHP 5 の Application クラス
 * 現行 Config/bootstrap.php の処理を bootstrap() / middleware() に移行
 */
class Application extends BaseApplication implements AuthenticationServiceProviderInterface
{
    /**
     * Application Bootstrap
     *
     * Config/bootstrap.php の処理をここに移行
     */
    public function bootstrap(): void
    {
        parent::bootstrap();

        // DebugKit は開発環境のみロード
        // 根拠: Controller/AppController.php:25（本番常時ロード問題の修正）
        if (PHP_SAPI !== 'cli') {
            $this->addPlugin('DebugKit');
        }

        // iroha Board 設定ファイルをロード
        // 根拠: Config/bootstrap.php:141
        \Cake\Core\Configure::load('ib_config');

        // カスタマイズ用設定ファイルをロード
        // 根拠: Config/bootstrap.php:144-148
        if (file_exists(ROOT . DS . 'src' . DS . 'Custom' . DS . 'Config' . DS . 'config.php')) {
            $reader = new \Cake\Core\Configure\Engine\PhpConfig(
                ROOT . DS . 'src' . DS . 'Custom' . DS . 'Config' . DS
            );
            \Cake\Core\Configure::config('default', $reader);
            \Cake\Core\Configure::load('config');
        }

        // Load more plugins here
        $this->addPlugin(\Friendsofcake\BootstrapUI\Plugin\BootstrapUI::class);
    }

    /**
     * Setup the middleware queue for your application.
     *
     * CakePHP 5 のミドルウェアキュー。
     * 現行 Config/bootstrap.php:120-123 の Dispatcher.filters を代替。
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The middleware queue to set up.
     * @return \Cake\Http\MiddlewareQueue The updated middleware queue.
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue
            // Cookie の暗号化・復号化
            // 根拠: Controller/AppController.php:27-29（Cookie コンポーネント）
            ->add(new \Cake\Http\Middleware\CookieDecryptionMiddleware())

            // セッションミドルウェア
            // 根拠: Config/core.php:230-237（Session 設定）
            ->add(new \Cake\Http\Middleware\SessionMiddleware())

            // 認証ミドルウェア
            // 根拠: Config/bootstrap.php で Auth コンポーネントを使用していた部分
            ->add(new AuthenticationMiddleware($this))

            // ボディパーサー（JSON リクエスト対応）
            ->add(new BodyParserMiddleware())

            // ルーティング
            // 根拠: Config/routes.php
            ->add(new RoutingMiddleware($this))

            // 静的ファイル配信
            // 根拠: Config/bootstrap.php:120-123（AssetDispatcher）
            ->add(new AssetMiddleware())

            // CSRF 保護
            // 根拠: Controller/Component/AppSecurityComponent.php（FormToken 自前実装の代替）
            ->add(new CsrfProtectionMiddleware([
                'httponly' => true,
            ]))

            // フォーム改ざん防止
            // 根拠: Lib/FormToken.php, Controller/Component/AppSecurityComponent.php
            ->add(new \Cake\Http\Middleware\FormProtectionMiddleware());

        return $middlewareQueue;
    }

    /**
     * Register an authentication service instance.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @param \Psr\Http\Message\ResponseInterface $response Response
     * @return \Authentication\AuthenticationServiceInterface
     */
    public function getAuthenticationService(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): AuthenticationServiceInterface {
        $service = new AuthenticationService([
            'unauthenticatedRedirect' => '/users/login',
            'queryParam' => 'redirect',
        ]);

        // 根拠: Controller/AppController.php:31-35（Auth 設定）
        $service->loadAuthenticator('Authentication.Session');
        $service->loadAuthenticator('Authentication.Cookie', [
            'cookie' => 'remember_me',
            'expires' => '+14 days',
        ]);

        return $service;
    }

    /**
     * Register an container service instance.
     *
     * @param \Psr\Container\ContainerInterface $container The DI container
     * @return void
     */
    public function services(ContainerInterface $container): void
    {
        $container->add(TableLocator::class, (new TableLocator())->setConfig('defaultTableClass', Table::class));
    }
}
```

### ミドルウェアの追加順序と根拠

| # | ミドルウェア | 現行の対応物 | 根拠 |
|---|---|---|---|
| 1 | `CookieDecryptionMiddleware` | `Cookie` コンポーネント | `Controller/AppController.php:27-29` |
| 2 | `SessionMiddleware` | `Session` コンポーネント | `Controller/AppController.php:26`, `Config/core.php:230-237` |
| 3 | `AuthenticationMiddleware` | `Auth` コンポーネント | `Controller/AppController.php:31-35` |
| 4 | `BodyParserMiddleware` | なし（CakePHP 2 では自動） | JSON API 対応 |
| 5 | `RoutingMiddleware` | `Dispatcher.filters` | `Config/bootstrap.php:120-123` |
| 6 | `AssetMiddleware` | `AssetDispatcher` | `Config/bootstrap.php:120-123` |
| 7 | `CsrfProtectionMiddleware` | `AppSecurityComponent` | `Controller/Component/AppSecurityComponent.php` |
| 8 | `FormProtectionMiddleware` | `FormToken.php` + `SecurityComponent` | `Lib/FormToken.php`, `Controller/Component/AppSecurityComponent.php` |

---

## 4. ib_config.php

`Config/ib_config.php`（198行）はそのまま利用する。CakePHP 5 の `Configure::load()` で読み込み。

### 読み込み方法

```php
// config/bootstrap.php 内
Configure::load('ib_config');
```

### 設定キー構造

変更不要。以下のキーが含まれる:

| キー | 用途 | 根拠 |
|---|---|---|
| `group_status` | グループの公開/非公開状態 | `Config/ib_config.php:11` |
| `course_status` | コースの有効/無効状態 | `Config/ib_config.php:12` |
| `content_status` | コンテンツの公開/非公開状態 | `Config/ib_config.php:13` |
| `content_kind` | コンテンツの種類 | `Config/ib_config.php:14-23` |
| `content_kind_comment` | コンテンツの種類の説明 | `Config/ib_config.php:25-34` |
| `content_category` | コンテンツカテゴリ | `Config/ib_config.php:36-40` |
| `question_type` | 問題の種類 | `Config/ib_config.php:42-45` |
| `wrong_mode` | 不正解時の表示モード | `Config/ib_config.php:47` |
| `record_result` | テスト結果 | `Config/ib_config.php:49` |
| `record_complete` | 完了状態 | `Config/ib_config.php:50` |
| `is_correct` | 正解/不正解 | `Config/ib_config.php:51` |
| `record_understanding` | 理解度ラベル | `Config/ib_config.php:54` |
| `record_understanding_pc` | PC向け理解度ボタン | `Config/ib_config.php:57-63` |
| `record_understanding_spn` | スマホ向け理解度ボタン | `Config/ib_config.php:66-72` |
| `user_role` | ユーザロール | `Config/ib_config.php:74` |
| `upload_extensions` | アップロード可能拡張子 | `Config/ib_config.php:77-100` |
| `upload_image_extensions` | 画像アップロード拡張子 | `Config/ib_config.php:102-107` |
| `upload_movie_extensions` | 動画アップロード拡張子 | `Config/ib_config.php:109-114` |
| `upload_maxsize` | アップロードサイズ上限 | `Config/ib_config.php:117` |
| `upload_image_maxsize` | 画像アップロードサイズ上限 | `Config/ib_config.php:118` |
| `upload_movie_maxsize` | 動画アップロードサイズ上限 | `Config/ib_config.php:119` |
| `close_on_select` | select2 自動クローズ | `Config/ib_config.php:122` |
| `use_upload_image` | リッチテキスト画像アップロード | `Config/ib_config.php:125` |
| `demo_mode` | デモモード | `Config/ib_config.php:128` |
| `remember_token_expired_days` | RememberMe 有効日数 | `Config/ib_config.php:135` |
| `form_defaults` | フォームスタイル基本設定 | `Config/ib_config.php:138-148` |
| `theme_colors` | テーマカラー一覧 | `Config/ib_config.php:163-184` |
| `import_group_count` | グループ一括登録上限 | `Config/ib_config.php:186` |
| `import_course_count` | コース一括登録上限 | `Config/ib_config.php:187` |
| `show_admin_link` | 管理者リンク表示 | `Config/ib_config.php:189` |
| `open_link_same_window` | 同一ウィンドウで開く | `Config/ib_config.php:190` |
| `deny_install_update_access` | インストーラー拒否 | `Config/ib_config.php:193` |

---

## 5. Custom ディレクトリの再実装

`Config/bootstrap.php:55-75` で定義されている `App::build()` による17パス登録を PSR-4 autoload で代替する。

### 現行の Custom ディレクトリ構成

```
Custom/
├── Config/
│   ├── config.php      # カスタム設定（実質運用される唯一のファイル）
│   └── custom.sql      # カスタム SQL（未使用）
├── Controller/
│   └── empty           # 空（プレースホルダ）
├── Model/
│   └── empty           # 空（プレースホルダ）
├── Vendor/
│   └── empty           # 空（プレースホルダ）
└── View/
    └── empty           # 空（プレースホルダ）
```

根拠: `Custom/Config/config.php:1-35`, `Custom/Controller/empty`, `Custom/Model/empty`

### 移行先

```
src/Custom/
├── Config/
│   └── config.php      # カスタム設定（そのまま維持）
├── Controller/         # カスタムコントローラ（将来用）
├── Model/              # カスタムモデル（将来用）
├── Vendor/             # カスタムベンダーコード（将来用）
└── View/               # カスタムビュー（将来用）
```

### PSR-4 autoload 設定

```json
{
  "autoload": {
    "psr-4": {
      "App\\": "src/",
      "App\\Custom\\": "src/Custom/"
    }
  }
}
```

### 17パスと PSR-4 の対応

| # | 現行パス | PSR-4 名前空間 | 備考 |
|---|---|---|---|
| 1 | `Custom/Model/` | `App\Model\` にマージ | `$this->setTable('ib_xxx')` で区別 |
| 2 | `Custom/Model/Behavior/` | `App\Model\Behavior\` | カスタムビヘイビア |
| 3 | `Custom/Model/Datasource/` | `App\Model\Datasource\` | カスタムデータソース |
| 4 | `Custom/Model/Datasource/Database/` | `App\Model\Datasource\Database\` | カスタム DB ドライバ |
| 5 | `Custom/Model/Datasource/Session/` | `App\Model\Datasource\Session\` | カスタムセッションハンドラ |
| 6 | `Custom/Controller/` | `App\Controller\` にマージ | カスタムコントローラ |
| 7 | `Custom/Controller/Component/` | `App\Controller\Component\` | カスタムコンポーネント |
| 8 | `Custom/Controller/Component/Auth/` | `App\Controller\Component\Auth\` | カスタム認証 |
| 9 | `Custom/Controller/Component/Acl/` | 削除 | ACL 未使用 |
| 10 | `Custom/View/` | `App\View\` にマージ | カスタムビュー |
| 11 | `Custom/View/Helper/` | `App\View\Helper\` | カスタムヘルパー |
| 12 | `Custom/Console/` | `App\Shell\` → `src/Command/` | Console は CakePHP 5 で Shell → Command |
| 13 | `Custom/Console/Command/` | `App\Command\` | カスタムコマンド |
| 14 | `Custom/Lib/` | `App\Lib\` → `src/Utility/` | ライブラリ |
| 15 | `Custom/Locale/` | CakePHP 5 の Locale 機構 | ローカライズ |
| 16 | `Custom/Vendor/` | Composer autoload で対応 | ベンダーコード |
| 17 | `Custom/Plugin/` | autoload で対応 | プラグインオーバーライド |

### 注意点

- 現行の `Custom/` は実質 `Config/config.php` のみが運用されている（他は空のプレースホルダ）
- CakePHP 5 では `App::build()` が廃止されているため、PSR-4 autoload で代替
- オーバーライドしたいクラスがある場合は `App\Custom\` 名前空間で定義し、`use` 文で明示的にインポートする
- `Config/ib_config.php` の設定配列は CakePHP 5 の `Configure::load()` で継続ロード（変更不要）
