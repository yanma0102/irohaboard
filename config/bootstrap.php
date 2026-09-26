<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         0.10.8
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

/*
 * This file is loaded by your src/Application.php bootstrap method.
 * Feel free to extend/extract parts of the bootstrap process into your own files
 * to suit your needs/preferences.
 */

/*
 * Configure paths required to find CakePHP + general filepath constants
 */
require __DIR__ . DIRECTORY_SEPARATOR . 'paths.php';

/*
 * Bootstrap CakePHP
 * Currently all this does is initialize the router (without loading your routes)
 */
require CORE_PATH . 'config' . DS . 'bootstrap.php';

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;
use Cake\Datasource\ConnectionManager;
use Cake\Error\ErrorTrap;
use Cake\Error\ExceptionTrap;
use Cake\Http\ServerRequest;
use Cake\Log\Log;
use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;
use Cake\Routing\Router;
use Cake\Utility\Security;
use Detection\MobileDetect;
use function Cake\Core\env;

/*
 * Load global functions for collections, translations, debugging etc.
 */
require CAKE . 'functions.php';

/*
 * See https://github.com/josegonzalez/php-dotenv for API details.
 *
 * Uncomment block of code below if you want to use `.env` file during development.
 * You should copy `config/.env.example` to `config/.env` and set/modify the
 * variables as required.
 *
 * The purpose of the .env file is to emulate the presence of the environment
 * variables like they would be present in production.
 *
 * If you use .env files, be careful to not commit them to source control to avoid
 * security risks. See https://github.com/josegonzalez/php-dotenv#general-security-information
 * for more information for recommended practices.
*/
// if (!env('APP_NAME') && file_exists(CONFIG . '.env')) {
//     $dotenv = new \josegonzalez\Dotenv\Loader([CONFIG . '.env']);
//     $dotenv->parse()
//         ->putenv()
//         ->toEnv()
//         ->toServer();
// }

/*
 * Initializes default Config store and loads the main configuration file (app.php)
 *
 * CakePHP contains 2 configuration files after project creation:
 * - `config/app.php` for the default application configuration.
 * - `config/app_local.php` for environment specific configuration.
 */
try {
    Configure::config('default', new PhpConfig());
    Configure::load('app', 'default', false);
} catch (Exception $e) {
    exit($e->getMessage() . "\n");
}

/*
 * Load an environment local configuration file to provide overrides to your configuration.
 * Notice: For security reasons app_local.php **should not** be included in your git repo.
 */
if (file_exists(CONFIG . 'app_local.php')) {
    Configure::load('app_local', 'default');
}

/*
 * When debug = true the metadata cache should only last for a short time.
 */
if (Configure::read('debug')) {
    Configure::write('Cache._cake_model_.duration', '+1 minute');
    Configure::write('Cache._cake_translations_.duration', '+1 minute');
}

/*
 * Set the default server timezone. Using UTC makes time calculations / conversions easier.
 * Check https://php.net/manual/en/timezones.php for list of valid timezone strings.
 */
date_default_timezone_set(Configure::read('App.defaultTimezone'));

/*
 * Configure the mbstring extension to use the correct encoding.
 */
mb_internal_encoding(Configure::read('App.encoding'));

/*
 * Set the default locale. This controls how dates, number and currency is
 * formatted and sets the default language to use for translations.
 */
ini_set('intl.default_locale', Configure::read('App.defaultLocale'));

/*
 * Register application error and exception handlers.
 */
(new ErrorTrap(Configure::read('Error')))->register();
(new ExceptionTrap(Configure::read('Error')))->register();

/*
 * CLI/Command specific configuration.
 */
if (PHP_SAPI === 'cli') {
    // Set the fullBaseUrl to allow URLs to be generated in commands.
    // This is useful when sending email from commands.
    // Configure::write('App.fullBaseUrl', php_uname('n'));

    // Set logs to different files so they don't have permission conflicts.
    if (Configure::check('Log.debug')) {
        Configure::write('Log.debug.file', 'cli-debug');
    }
    if (Configure::check('Log.error')) {
        Configure::write('Log.error.file', 'cli-error');
    }
}

/*
 * Set the full base URL used for URL generation (Router::fullBaseUrl).
 *
 * NOTE: Controller::redirect() builds ABSOLUTE Location headers through
 * Router::url($url, true). This value therefore decides the host browsers are
 * sent to after login and on every other redirect, so it must follow the host
 * the client actually used — otherwise e.g. logging in via a LAN address
 * bounces the browser back to http://localhost.
 *
 * SECURITY: Configure('App.fullBaseUrl') (set via APP_FULL_BASE_URL) stays the
 * VALIDATION basis used by HostHeaderMiddleware. A host derived from HTTP_HOST
 * is written ONLY to Router and never back into Configure — writing an
 * attacker-controlled host into Configure would make HostHeaderMiddleware
 * compare the host against itself and disable Host Header Injection protection.
 *
 * When the request authority matches the configured one the configured URL is
 * kept as-is so its scheme/port stay canonical (avoids https -> http
 * downgrades behind proxies). When they differ, the request host is used: in
 * production (debug=false) HostHeaderMiddleware has already rejected such a
 * request with 400, so only debug mode / unconfigured setups reach that branch.
 *
 * Set APP_FULL_BASE_URL in your environment variables or configure App.fullBaseUrl
 * in config/app.php or config/app_local.php
 *
 * Example: APP_FULL_BASE_URL=https://example.com
 */
$httpHost = (string)env('HTTP_HOST');
$requestBaseUrl = null;
$requestAuthority = '';
if ($httpHost !== '') {
    $s = null;
    if (env('HTTPS') || env('HTTP_X_FORWARDED_PROTO') === 'https') {
        $s = 's';
    }
    $requestBaseUrl = 'http' . $s . '://' . $httpHost;
    $requestAuthority = strtolower($httpHost);
}

$configuredBaseUrl = Configure::read('App.fullBaseUrl');
$configuredAuthority = '';
$configuredPath = '';
if ($configuredBaseUrl) {
    $parsed = parse_url((string)$configuredBaseUrl);
    if (is_array($parsed)) {
        $configuredAuthority = strtolower((string)($parsed['host'] ?? ''));
        if (isset($parsed['port'])) {
            $configuredAuthority .= ':' . $parsed['port'];
        }
        // サブディレクトリ配置（例: https://example.com/irohaboard）のベースパスを保持する。
        $configuredPath = rtrim((string)($parsed['path'] ?? ''), '/');
    }
}

$fullBaseUrl = $configuredBaseUrl ?: ($requestBaseUrl . $configuredPath);
if ($configuredBaseUrl && $requestBaseUrl && $configuredAuthority !== $requestAuthority) {
    // リクエスト Host が設定値と異なる（未設定・ポート差・開発環境の別ホストアクセス）。
    // ホストとポートは実アクセスに追従させ、ベースパスは設定値から引き継ぐ
    // （サブディレクトリ配置でもリダイレクト先がルートに落ちないようにするため）。
    $fullBaseUrl = $requestBaseUrl . $configuredPath;
}

if ($fullBaseUrl) {
    Router::fullBaseUrl($fullBaseUrl);
}
unset(
    $httpHost,
    $requestBaseUrl,
    $requestAuthority,
    $configuredBaseUrl,
    $configuredAuthority,
    $configuredPath,
    $parsed,
    $s,
    $fullBaseUrl,
);

/*
 * Apply the loaded configuration settings to their respective systems.
 * This will also remove the loaded config data from memory.
 */
Cache::setConfig(Configure::consume('Cache'));
ConnectionManager::setConfig(Configure::consume('Datasources'));
TransportFactory::setConfig(Configure::consume('EmailTransport'));
Mailer::setConfig(Configure::consume('Email'));
Log::setConfig(Configure::consume('Log'));
Security::setSalt(Configure::consume('Security.salt'));

/*
 * Setup detectors for mobile and tablet.
 * If you don't use these checks you can safely remove this code
 * and the mobiledetect package from composer.json.
 */
ServerRequest::addDetector('mobile', function ($request) {
    $detector = new MobileDetect();

    return $detector->isMobile();
});
ServerRequest::addDetector('tablet', function ($request) {
    $detector = new MobileDetect();

    return $detector->isTablet();
});

/*
 * You can enable default locale format parsing by adding calls
 * to `useLocaleParser()`. This enables the automatic conversion of
 * locale specific date formats when processing request data. For details see
 * @link https://book.cakephp.org/5/en/core-libraries/internationalization-and-localization.html#parsing-localized-datetime-data
 */
// \Cake\Database\TypeFactory::build('time')->useLocaleParser();
// \Cake\Database\TypeFactory::build('date')->useLocaleParser();
// \Cake\Database\TypeFactory::build('datetime')->useLocaleParser();
// \Cake\Database\TypeFactory::build('timestamp')->useLocaleParser();
// \Cake\Database\TypeFactory::build('datetimefractional')->useLocaleParser();
// \Cake\Database\TypeFactory::build('timestampfractional')->useLocaleParser();
// \Cake\Database\TypeFactory::build('datetimetimezone')->useLocaleParser();
// \Cake\Database\TypeFactory::build('timestamptimezone')->useLocaleParser();

/*
 * Custom Inflector rules, can be set to correctly pluralize or singularize
 * table, model, controller names or whatever other string is passed to the
 * inflection functions.
 */
// \Cake\Utility\Inflector::rules('plural', ['/^(inflect)or$/i' => '\1ables']);
// \Cake\Utility\Inflector::rules('irregular', ['red' => 'redlings']);
// \Cake\Utility\Inflector::rules('uninflected', ['dontinflectme']);

// set a custom date and time format
// see https://book.cakephp.org/5/en/core-libraries/time.html#setting-the-default-locale-and-format-string
// and https://unicode-org.github.io/icu/userguide/format_parse/datetime/#datetime-format-syntax
// \Cake\I18n\Date::setToStringFormat('dd.MM.yyyy');
// \Cake\I18n\Time::setToStringFormat('dd.MM.yyyy HH:mm');
