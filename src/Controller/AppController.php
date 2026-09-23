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

use Authentication\Controller\Component\AuthenticationComponent;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Cookie\Cookie;

/**
 * Application Controller
 *
 * CakePHP 5 版 AppController
 * Auth / Security / Cookie コンポーネントは authentication プラグイン・middlrrlware 系に置き換える。
 *
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 * @property \Cake\Controller\Component\FlashComponent $Flash
 * @property \App\Controller\Component\RoleComponent $Role
 */
class AppController extends Controller
{
    /**
     * Initialization hook method.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');

        // Authentication プラグイン（CakePHP 5 の Auth 代替）
        $this->loadComponent('Authentication.Authentication', [
            'logoutRedirect' => ['controller' => 'Users', 'action' => 'login'],
        ]);

        // FormProtection（CakePHP 5 の Security / AppSecurityComponent 代替）
        // ログイン画面のみトークンチェックエラーのハンドリング
        $this->loadComponent('FormProtection', [
            'validationFailureCallback' => function (\Cake\Controller\Exception\FormProtectionException $exception) {
                return $this->blackHole();
            },
        ]);

        // ロール判定コンポーネント（04-authentication.md §5.2）
        $this->loadComponent('Role');
    }

    /**
     * コールバック（コントローラのアクションロジック実行前に実行）
     *
     * @param \Cake\Event\EventInterface $event イベント
     * @return \Cake\Http\Response|null
     */
    public function beforeFilter(EventInterface $event): ?\Cake\Http\Response
    {
        $this->set('loginedUser', $this->readAuthUser()); // ログインユーザ情報（旧バージョン用）

        // ログイン/ログアウトは認証不要
        $action = (string)$this->request->getParam('action');
        if (in_array($action, ['login', 'logout'], true)) {
            $this->Authentication->allowUnauthenticated([$action]);
        }

        // セッション内の設定情報が他サイトのものであれば、設定情報及びログイン情報をクリア
        if ($this->hasSession('Setting')) {
            if ($this->readSession('Setting.app_dir') != ROOT) {
                // セッション内の設定情報を削除
                $this->deleteSession('Setting');

                // 他のサイトとのログイン情報の混合を避けるため、強制ログアウト
                if ($this->readAuthUser()) {
                    $logoutRedirect = $this->Authentication->logout();
                    $url = $logoutRedirect ?? '/users/login';

                    return $this->redirect($url);
                }
            }
        }

        // データベース内に格納された設定情報をセッションに格納
        if (!$this->hasSession('Setting')) {
            $settings = $this->fetchTable('Settings')->getSettings();

// CakePHP 5 では APP_DIR が未定義のため ROOT を使用（レガシーとの混在防止に利用）
            $this->writeSession('Setting.app_dir', ROOT);

            foreach ($settings as $key => $value) {
                $this->writeSession('Setting.' . $key, $value);
            }
        }

        // 管理画面の場合、staff ロール以外は強制ログアウトする
        if ($this->isAdminPage() && $this->readAuthUser() && !$this->Role->isStaff()) {
            $this->deleteCookie('CookieAuth');

            $this->Flash->error(__('管理画面へのアクセス権限がありません'));

            $logoutRedirect = $this->Authentication->logout();
            $url = $logoutRedirect ?? '/users/login';

            return $this->redirect($url);
        }

        return null;
    }

    /**
     * トークンチェックエラーのハンドリング
     *
     * @return \Cake\Http\Response|null
     */
    protected function blackHole(): ?\Cake\Http\Response
    {
        // CSRFエラー（トークン切れ）
        $this->Flash->error(__('トークンの有効期限が切れました。もう一度ログインしてください。'), ['key' => 'flash']);

        return $this->redirect(['controller' => 'Users', 'action' => 'login']);
    }

    /**
     * セッションの取得
     *
     * @param string $key キー
     * @return mixed
     */
    protected function readSession(string $key): mixed
    {
        $val = $this->request->getSession()->read($key);

        if ($val == null) {
            return '';
        }

        return $val;
    }

    /**
     * セッションの削除
     *
     * @param string $key キー
     * @return void
     */
    protected function deleteSession(string $key): void
    {
        $this->request->getSession()->delete($key);
    }

    /**
     * セッションの存在確認
     *
     * @param string $key キー
     * @return bool
     */
    protected function hasSession(string $key): bool
    {
        return $this->request->getSession()->check($key);
    }

    /**
     * セッションの保存
     *
     * @param string $key キー
     * @param mixed $value 値
     * @return void
     */
    protected function writeSession(string $key, mixed $value): void
    {
        $this->request->getSession()->write($key, $value);
    }

    /**
     * クッキーの取得
     *
     * @param string $key キー
     * @return mixed
     */
    protected function readCookie(string $key): mixed
    {
        $val = $this->request->getCookie($key);

        if ($val == null) {
            return '';
        }

        return $val;
    }

    /**
     * クッキーの削除
     *
     * @param string $key キー
     * @return void
     */
    protected function deleteCookie(string $key): void
    {
        $cookie = (new Cookie($key))->withExpired();

        $this->response = $this->response->withCookie($cookie);
    }

    /**
     * クッキーの存在確認
     *
     * @param string $key キー
     * @return bool
     */
    protected function hasCookie(string $key): bool
    {
        return $this->request->getCookie($key) !== null;
    }

    /**
     * クッキーの保存
     *
     * @param string $key キー
     * @param string $value 値
     * @param bool $encrypt 暗号化（CakePHP 2 の互換引数。CakePHP 5 では使用しない）
     * @param string $expires 有効期限
     * @return void
     */
    protected function writeCookie(string $key, string $value, bool $encrypt = true, string $expires = '+2 weeks'): void
    {
        $cookie = (new Cookie($key))
            ->withValue($value)
            ->withPath(ini_get('session.cookie_path'))
            ->withHttpOnly(true)
            ->withExpiry(new \DateTimeImmutable($expires));

        $this->response = $this->response->withCookie($cookie);
    }

    /**
     * ログインユーザ情報の取得
     *
     * @param string|null $key キー
     * @return mixed
     */
    protected function readAuthUser(?string $key = null): mixed
    {
        $identity = $this->Authentication->getIdentity();

        if (!$identity instanceof \Authentication\IdentityInterface) {
            return null;
        }

        $user = $identity->getOriginalData();

        if ($key === null) {
            return $user;
        }

        return $user[$key] ?? null;
    }

    /**
     * ログイン確認
     *
     * @return bool true : ログイン済み, false : ログインしていない
     */
    protected function isLogined(): bool
    {
        return $this->Authentication->getIdentity() !== null;
    }

    /**
     * クエリストリングの取得
     *
     * @param string $key キー
     * @param string $default キーが存在しない場合に返す値
     * @return mixed
     */
    protected function getQuery(string $key, mixed $default = ''): mixed
    {
        return $this->request->getQuery($key) ?? $default;
    }

    /**
     * クエリストリングの存在確認
     *
     * @param string $key キー
     * @return bool
     */
    protected function hasQuery(string $key): bool
    {
        return $this->request->getQuery($key) !== null;
    }

    /**
     * ルートパラメータの取得
     *
     * @param string $key キー
     * @param mixed $default キーが存在しない場合に返す値
     * @return mixed
     */
    protected function getParam(string $key, mixed $default = ''): mixed
    {
        return $this->request->getParam($key) ?? $default;
    }

    /**
     * POSTデータの取得
     *
     * @param string|null $key キー
     * @param mixed $default キーが存在しない場合に返す値
     * @return mixed
     */
    protected function getData(?string $key = null, mixed $default = null): mixed
    {
        return $this->request->getData($key, $default);
    }

    /**
     * POSTデータの上書き
     *
     * @param string|null $key キー
     * @param mixed $value 値
     * @return void
     */
    protected function setData(?string $key, mixed $value): void
    {
        if ($key !== null) {
            $this->request = $this->request->withData($key, $value);
        } else {
            $this->request = $this->request->withParsedBody($value);
        }
    }

    /**
     * 管理画面へのアクセスかを確認
     *
     * @return bool true : 管理画面, false : 受講者画面
     */
    protected function isAdminPage(): bool
    {
        return $this->request->getParam('prefix') === 'Admin';
    }

    /**
     * 編集画面へのアクセスかを確認
     *
     * @return bool
     */
    protected function isEditPage(): bool
    {
        $action = $this->request->getParam('action');

        return $action === 'edit';
    }

    /**
     * テスト結果画面へのアクセスかを確認
     *
     * @return bool
     */
    protected function isRecordPage(): bool
    {
        $action = $this->request->getParam('action');

        return $action === 'record';
    }

    /**
     * ログイン画面へのアクセスかを確認
     *
     * @return bool
     */
    protected function isLoginPage(): bool
    {
        $action = $this->request->getParam('action');

        return $action === 'login';
    }

    /**
     * HTTPSかを確認
     *
     * @return bool
     */
    protected function isHTTPS(): bool
    {
        return $this->request->is('https');
    }

    /**
     * CSV 出力時の数式インジェクションを防止する
     *
     * Excel 等でセルが数式として解釈される先頭文字（= + - @）に
     * シングルクォートを付与して無害化する。
     *
     * @param string|null $value 対象の値
     * @return string 無害化後の値
     */
    protected function sanitizeCsvValue(?string $value): string
    {
        $value = (string)$value;
        if (preg_match('/^\s*[=\+\-@]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * ログの保存
     *
     * @param string $log_type ログの種類
     * @param string $log_content ログの内容
     * @return void
     */
    protected function writeLog(string $log_type, string $log_content): void
    {
        // ロードバランサー対応
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = $ips[0];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }

        $log = $this->fetchTable('Logs')->newEntity([
            'log_type' => $log_type,
            'log_content' => $log_content,
            'user_id' => $this->readAuthUser('id'),
            'user_ip' => $ip,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);

        $this->fetchTable('Logs')->save($log);
    }
}