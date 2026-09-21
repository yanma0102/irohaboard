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
use Cake\Datasource\ConnectionManager;
use Cake\Http\Exception\ForbiddenException;

/**
 * Update Controller
 *
 * CakePHP 5 版
 */
class UpdateController extends Controller
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
    public ?\Cake\Database\Connection $db = null;

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
    public function beforeFilter(\Cake\Event\EventInterface $event): void
    {
        parent::beforeFilter($event);

        if (Configure::read('deny_install_update_access')) {
            throw new ForbiddenException();
        }

        $this->Authentication->allowUnauthenticated(['index', 'error']);
    }

    /**
     * アップデート
     *
     * @return void
     */
    public function index(): void
    {
        try {
            $this->db = ConnectionManager::get('default');

            // パッケージアップデート用クエリ
            $this->path = ROOT . DS . 'config' . DS . 'schema' . DS . 'update.sql';
            $err_update = $this->_executeSQLScript();

            // カスタマイズ用クエリ
            $this->path = ROOT . DS . 'config' . DS . 'custom.sql';

            if (file_exists($this->path)) {
                $err_custom = $this->_executeSQLScript();
                $err_statements = array_merge($err_update, $err_custom);
            } else {
                $err_statements = $err_update;
            }

            if (count($err_statements) > 0) {
                $this->err_msg = 'クエリの実行中にエラーが発生しました。詳細はエラーログ(tmp/logs/error.log)をご確認ください。';

                $log = '';
                foreach ($err_statements as $err) {
                    $log .= $err . "\n";
                }

                $this->log($log);
                $this->error();
                $this->viewBuilder()->setTemplate('error');
                return;
            }
        } catch (\Exception $e) {
            $this->err_msg = 'データベースへの接続に失敗しました。設定ファイル(config/app_local.php)をご確認ください。';
            $this->error();
            $this->viewBuilder()->setTemplate('error');
        }
    }

    /**
     * アップデートエラーメッセージを表示
     *
     * @return void
     */
    public function error(): void
    {
        $this->set('body', $this->err_msg);
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
    private function _getSqlErrorInfo(\Exception $e): array
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
            if (trim($statement) === '') {
                continue;
            }

            // %salt% を置換
            // %salt% を置換（旧 SHA1 パスワード互換のため CakePHP 2 時代の salt を使用）
            $salt = (string)(Configure::read('legacy_security_salt') ?? Configure::read('Security.salt') ?? '');
            $statement = str_replace('%salt%', $salt, $statement);

            try {
                $this->db->execute($statement);
            } catch (\Exception $e) {
                $errorInfo = $this->_getSqlErrorInfo($e);

                // レコード重複追加エラー
                if (($errorInfo[0] ?? '') === '23000') {
                    continue;
                }
                // カラム重複追加エラー
                if (($errorInfo[0] ?? '') === '42S21') {
                    continue;
                }
                // ビュー重複追加エラー
                if (($errorInfo[0] ?? '') === '42S01') {
                    continue;
                }
                // インデックス重複追加エラー
                if (($errorInfo[0] ?? '') === '42000') {
                    continue;
                }

                $error_msg = sprintf("%s\n[Error Code]%s\n[Error Code2]%s\n[SQL]%s",
                    $errorInfo[2] ?? $e->getMessage(),
                    $errorInfo[0] ?? '',
                    $errorInfo[1] ?? '',
                    $statement
                );
                $err_statements[] = $error_msg;
            }
        }

        return $err_statements;
    }
}
