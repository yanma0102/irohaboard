<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Exception;

/**
 * Install Controller
 *
 * CakePHP 5 版
 * Note: extends Controller directly (not AppController) since auth DB may not exist yet.
 */
class InstallController extends Controller
{
    /**
     * err_msg
     *
     * @var string
     */
    public string $err_msg = '';

    /**
     * db
     *
     * @var \Cake\Database\Connection|null
     */
    public ?Connection $db = null;

    /**
     * path
     *
     * @var string
     */
    public string $path = '';

    /**
     * 初期化
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');
    }

    /**
     * beforeFilter
     *
     * @param \Cake\Event\EventInterface $event
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        if (Configure::read('deny_install_update_access')) {
            throw new ForbiddenException();
        }

        // インストール操作は認証不要
        $this->Authentication->allowUnauthenticated(['index', 'installed', 'complete', 'error', 'add']);
    }

    /**
     * インストール
     *
     * @return void
     */
    public function index(): void
    {
        try {
            // mbstring 存在チェック
            if (!extension_loaded('mbstring')) {
                $this->err_msg = 'PHP モジュール mbstring がロードされていません';
                $this->error();
                $this->viewBuilder()->setTemplate('error');

                return;
            }

            // pdo_mysql 存在チェック
            if (!extension_loaded('pdo_mysql')) {
                $this->err_msg = 'PHP モジュール pdo_mysql がロードされていません';
                $this->error();
                $this->viewBuilder()->setTemplate('error');

                return;
            }
        } catch (Exception $e) {
            $this->err_msg = '各種モジュールチェック中にエラーが発生いたしました。';
            $this->error();
            $this->viewBuilder()->setTemplate('error');

            return;
        }

        try {
            $this->db = ConnectionManager::get('default');

            // D-23: Configure::consume('Datasources') により bootstrap 時に除去済みのため、
            // 実際に登録済みの接続設定から DB 名を取得する。
            // getConfig() が例外を投げた場合はフォールバック値 'irohaboard' を使用。
            try {
                $dbConfig = ConnectionManager::getConfig('default');
                $database = $dbConfig['database'] ?? 'irohaboard';
            } catch (Exception $e) {
                $database = 'irohaboard';
            }
            $sql = 'SHOW TABLES FROM `' . $database . "` LIKE 'ib_users'";
            $data = $this->db->execute($sql)->fetchAll('assoc');

            $this->set('username', '');

            if (count($data) > 0) {
                $this->viewBuilder()->setTemplate('installed');
            } else {
                if ($this->request->is('post')) {
                    // D-35: テンプレートの入力 name は data[User][...] だが、
                    // CakePHP 5 の ServerRequest::getData() はトップレベルの
                    // キー（'data'）を起点に解釈するため、'User.username' では
                    // 一致せず常に既定値 '' になっていた（インストール不能）。
                    $userData = (array)$this->request->getData('data.User', []);
                    $username = (string)($userData['username'] ?? '');
                    $password = (string)($userData['password'] ?? '');
                    $password2 = (string)($userData['password2'] ?? '');

                    $this->set('username', $username);

                    if (strlen($username) < 4 || strlen($username) > 32) {
                        $this->Flash->error('ログインIDは4文字以上32文字以内で入力して下さい');

                        return;
                    }

                    if (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
                        $this->Flash->error('ログインIDは英数字で入力して下さい');

                        return;
                    }

                    if (strlen($password) < 4 || strlen($password) > 32) {
                        $this->Flash->error('パスワードは4文字以上32文字以内で入力して下さい');

                        return;
                    }

                    if ($password !== $password2) {
                        $this->Flash->error('パスワードと確認用パスワードが一致しません');

                        return;
                    }

                    if (!preg_match('/^[a-zA-Z0-9]+$/', $password)) {
                        $this->Flash->error('パスワードは英数字で入力して下さい');

                        return;
                    }

                    $this->_install();
                    $this->_createRootAccount($username, $password);
                }
            }
        } catch (Exception $e) {
            $this->err_msg = 'データベースへの接続に失敗しました。設定ファイル(config/app_local.php)をご確認ください。';
            $this->error();
            $this->viewBuilder()->setTemplate('error');
        }
    }

    /**
     * インストール済みメッセージを表示
     *
     * @return void
     */
    public function installed(): void
    {
    }

    /**
     * インストール完了メッセージを表示
     *
     * @return void
     */
    public function complete(): void
    {
    }

    /**
     * インストールエラーメッセージを表示
     *
     * @return void
     */
    public function error(): void
    {
        $this->set('body', $this->err_msg);
    }

    /**
     * インストールの実行
     *
     * @return void
     */
    private function _install(): void
    {
        $this->path = ROOT . DS . 'config' . DS . 'schema' . DS . 'app.sql';
        $err_statements = $this->_executeSQLScript();

        if (count($err_statements) > 0) {
            $this->err_msg = 'インストール実行中にエラーが発生しました。詳細はエラーログ(tmp/logs/error.log)をご確認ください。';

            $log = '';
            foreach ($err_statements as $err) {
                $log .= $err . "\n";
            }

            $this->log($log);
            $this->error();
            $this->viewBuilder()->setTemplate('error');
        } else {
            $this->complete();
            $this->viewBuilder()->setTemplate('complete');
        }
    }

    /**
     * 例外から SQLSTATE / ドライバエラーコードを取得する
     *
     * CakePHP 5 の DatabaseException は errorInfo を保持しないことがあるため、
     * 例外メッセージ（SQLSTATE[xxxxx]: ... : nnnn ...）からも抽出する。
     *
     * @param \Exception $e 例外
     * @return array<int, mixed> [SQLSTATE, ドライバエラーコード, メッセージ]
     */
    private function _getSqlErrorInfo(Exception $e): array
    {
        $errorInfo = $e->errorInfo ?? null;
        if (!is_array($errorInfo)) {
            $previous = $e->getPrevious();
            if ($previous !== null) {
                $errorInfo = $previous->errorInfo ?? null;
            }
        }
        if (is_array($errorInfo) && count($errorInfo) >= 3) {
            return array_values($errorInfo);
        }

        $message = $e->getMessage();
        if (preg_match('/SQLSTATE\[([0-9A-Z]+)\](?:[^0-9]*([0-9]+))?/', $message, $matches) === 1) {
            return [$matches[1], isset($matches[2]) ? (int)$matches[2] : 0, $message];
        }

        return ['', 0, $message];
    }

    /**
     * SQL スクリプトの実行
     *
     * @return array<string>
     */
    private function _executeSQLScript(): array
    {
        if (!file_exists($this->path)) {
            return [sprintf('SQLファイルが見つかりません: %s', $this->path)];
        }

        $statements = file_get_contents($this->path);
        $statements = explode(';', $statements);
        $err_statements = [];

        foreach ($statements as $statement) {
            if (trim($statement) !== '') {
                try {
                    $this->db->execute($statement);
                } catch (Exception $e) {
                    $errorInfo = $this->_getSqlErrorInfo($e);
                    if (($errorInfo[0] ?? '') === '42S21') {
                        continue;
                    }
                    if (($errorInfo[0] ?? '') === '42S01') {
                        continue;
                    }
                    // D-24: スキーマ・初期データ投入の再実行のため、
                    // UNIQUE/PRIMARY 制約違反（例: ib_settings の固定 PK 1〜4）を無視する。
                    // UpdateController:191 と挙動を統一。
                    // ※ ユーザデータ側の本物の重複違反は _createRootAccount() 経由で発生するため、
                    //    このスクリプト実行コンテキストでは握り潰しても安全。
                    if (($errorInfo[0] ?? '') === '23000') {
                        continue;
                    }
                    $error_msg = sprintf(
                        "%s\n[Error Code]%s\n[Error Code2]%s\n[SQL]%s",
                        $errorInfo[2] ?? $e->getMessage(),
                        $errorInfo[0] ?? '',
                        $errorInfo[1] ?? '',
                        $statement,
                    );
                    $err_statements[] = $error_msg;
                }
            }
        }

        return $err_statements;
    }

    /**
     * 管理者アカウントの作成
     *
     * @param string $username ユーザ名
     * @param string $password パスワード
     * @return void
     */
    private function _createRootAccount(string $username, string $password): void
    {
        $usersTable = $this->fetchTable('Users');

        $data = $usersTable->find()
            ->where(['role' => 'admin'])
            ->first();

        if (!$data) {
            $entity = $usersTable->newEntity([
                'username' => $username,
                'password' => $password,
                'name' => $username,
                'role' => 'admin',
            ]);

            $usersTable->save($entity);
        }
    }
}
