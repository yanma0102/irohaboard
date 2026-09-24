<?php
/**
 * iroha Board Project - CakePHP 5 Entry Point
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

// 最小限の定数を定義（Application 初期化に必要）
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}
if (!defined('CONFIG')) {
    define('CONFIG', ROOT . DS . 'config' . DS);
}

// Composer の autoloader をロード
require ROOT . DS . 'vendor' . DS . 'autoload.php';

use App\Application;
use Cake\Http\Server;

// アプリケーションを起動
$app = new Application(CONFIG);
$server = new Server($app);

$server->emit($server->run());
