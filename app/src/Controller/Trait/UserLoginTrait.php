<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Controller\Trait;

use App\Service\LdapAuthService;
use Cake\Core\Configure;
use Cake\Http\Response;
use Exception;

/**
 * ログイン共通ロジックTrait
 *
 * UsersController（受講者画面）と Admin\UsersController（管理画面）
 * の両方で使用するログイン関連のロジックを提供する。
 */
trait UserLoginTrait
{
    /**
     * LDAPユーザー同期処理
     *
     * LDAP認証でログイン成功後、ローカルDBにユーザーが存在しない場合は作成する。
     * ユーザーロールは 'user'（受講者）とし、管理者がLDAPで認証しても
     * 標準的な受講者権限とする。
     *
     * @param array $identity LDAP認証から取得した identity データ
     * @return \App\Model\Entity\User|false 同期されたユーザーエンティティ、または失敗時は false
     */
    protected function syncLdapUser(array $identity): mixed
    {
        $username = (string)($identity['username'] ?? '');
        if ($username === '') {
            return false;
        }

        // DBカラム長（varchar 50）に合わせて切り詰め
        $username = mb_substr($username, 0, 50);

        $usersTable = $this->fetchTable('Users');

        // is_active は「明示的に 0（無効）」のみ除外し、NULL は許容する。
        // カラム未整備の環境で全ユーザーがログイン不能になる事故を防ぐ。
        $user = $usersTable->find()
            ->where(['username' => $username, 'deleted IS NULL'])
            ->andWhere(function ($exp) {
                return $exp->or(['is_active IS NULL', 'is_active' => true]);
            })
            ->first();

        $attributes = [];
        if (!empty($identity['name'])) {
            $attributes['name'] = mb_substr((string)$identity['name'], 0, 50);
        }
        if (!empty($identity['email'])) {
            $attributes['email'] = mb_substr((string)$identity['email'], 0, 50);
        }

        if ($user) {
            if ($attributes !== []) {
                $user = $usersTable->patchEntity($user, $attributes);
            }
            $user->last_logined = date('Y-m-d H:i:s');
            if (!$usersTable->save($user)) {
                return false;
            }

            return $user;
        }

        // 新規作成: LDAP認証専用ユーザ（パスワードは空のまま）
        // LDAPのユーザ名はアプリ標準バリデーション（英数字のみ等）に合わない場合があるため、
        // 外部認部認証で検証済みのユーザ情報としてバリデーションをスキップして保存する。
        $newUser = $usersTable->newEntity([
            'username' => $username,
            'name' => $attributes['name'] ?? $username,
            'email' => $attributes['email'] ?? '',
            'role' => 'user',
            'password' => '',
        ], ['validate' => false]);

        if (!$usersTable->save($newUser)) {
            return false;
        }

        $newUser->last_logined = date('Y-m-d H:i:s');
        $usersTable->save($newUser, ['checkRules' => false]);

        return $newUser;
    }

    /**
     * ログイン処理の共通ロジック
     *
     * @return \Cake\Http\Response|null
     */
    protected function performLogin(): ?Response
    {
        $username = '';
        $password = '';

        // 旧方式（平文パスワード Cookie）は破棄
        if ($this->hasCookie('Auth')) {
            $this->deleteCookie('Auth');
        }

        // トークン方式の自動ログイン
        if ($this->hasCookie('CookieAuth')) {
            $cookie_value = $this->readCookie('CookieAuth');
            $user = null;
            try {
                $user = $this->fetchTable('UserTokens')->authenticateRememberCookie($cookie_value);
            } catch (Exception $e) {
                // ib_user_tokens 未作成（/update 前）など
            }

            // Remember Me は権限 user のみ許可
            if ($user && isset($user['role']) && $user['role'] === 'user') {
                $this->Authentication->setIdentity($user);

                // 最終ログイン日時を保存
                $usersTable = $this->fetchTable('Users');
                $userEntity = $usersTable->get((int)$this->readAuthUser('id'));
                $userEntity->last_logined = date('Y-m-d H:i:s');
                $usersTable->save($userEntity);

                $this->writeLog('user_logined', '');
                $this->writeCookie('LoginStatus', 'logined');
                if ($this->isAdminPage()) {
                    return $this->redirect(['controller' => 'Users', 'action' => 'index', 'prefix' => 'Admin']);
                }

                return $this->redirect(['controller' => 'UsersCourses', 'action' => 'index']);
            }

            // 失敗時は Cookie を削除（不正・期限切れ・テーブル未作成など）
            $this->deleteCookie('CookieAuth');
        }

        // 通常ログイン処理
        if ($this->request->is('post')) {
            // HTTPS 以外ではログイン状態の保持を無効化
            if (!$this->isHTTPS()) {
                $this->request = $this->request->withData('remember_me', null);
            }

            // ログインID、パスワードの形式が正しくない場合、エラーを表示
            $inputUsername = (string)($this->request->getData('username') ?? '');
            $inputPassword = (string)($this->request->getData('password') ?? '');

            if (
                $inputUsername === '' ||
                $inputPassword === '' ||
                !preg_match('/^[a-zA-Z0-9@_.+-]+$/', $inputUsername) ||
                strlen($inputUsername) > 100 ||
                !preg_match('/^[a-zA-Z0-9@_.+-]+$/', $inputPassword) ||
                strlen($inputPassword) > 100
            ) {
                $this->set(compact('username', 'password'));
                $this->Flash->error(__('ログインID、もしくはパスワードの形式が正しくありません'));

                return null;
            }

            // ログイン試行回数制限チェック（同一ユーザ名で1時間以内に10回以上失敗していたらブロック）
            if ($this->_isLoginBlocked($inputUsername)) {
                $this->writeLog('login_blocked', $inputUsername);
                $this->Flash->error(__('ログイン試行回数が上限に達しました。1時間後に再度お試しください。'));
                $this->set(compact('username', 'password'));

                return null;
            }

            if ($this->_login()) {
                // Remember Me は権限 user のみ発行
                if (!empty($this->request->getData('remember_me'))) {
                    if ($this->readAuthUser('role') === 'user') {
                        $days = (int)Configure::read('remember_token_expired_days');
                        if ($days <= 0) {
                            $days = 14;
                        }

                        // /update 前（ib_user_tokens 未作成）でもログイン自体は成功させる
                        try {
                            $token = $this->fetchTable('UserTokens')->issueRememberToken((int)$this->readAuthUser('id'));
                            if ($token) {
                                $this->writeCookie('CookieAuth', $token, false, '+' . $days . ' days');
                            }
                        } catch (Exception $e) {
                            // テーブル未作成・モデル未配置時は Remember Me のみスキップ
                        }
                    } else {
                        $this->Flash->error(__('ログイン状態の保持は受講者のみ利用できます'));
                    }
                }

                // 最終ログイン日時を保存
                $usersTable = $this->fetchTable('Users');
                $userEntity = $usersTable->get((int)$this->readAuthUser('id'));
                $userEntity->last_logined = date('Y-m-d H:i:s');
                $usersTable->save($userEntity);

                $this->writeLog('user_logined', '');
                $this->writeCookie('LoginStatus', 'logined');
                $this->deleteSession('Auth.redirect');
                if ($this->isAdminPage()) {
                    return $this->redirect(['controller' => 'Users', 'action' => 'index', 'prefix' => 'Admin']);
                }

                return $this->redirect(['controller' => 'UsersCourses', 'action' => 'index']);
            } else {
                // ブルートフォース速度低下のため、失敗時のみランダムスリープ（1.5〜2.5秒）
                usleep(rand(1500000, 2500000));

                $this->writeLog('login_error', $inputUsername);
                $this->Flash->error(__('ログインID、もしくはパスワードが正しくありません'));
            }
        } else {
            // デモモードの場合、ログインID、パスワードの初期値を指定
            if (Configure::read('demo_mode')) {
                $username = Configure::read('demo_login_id');
                $password = Configure::read('demo_password');
            }

            // 念のためログアウト
            $this->Authentication->logout();
        }

        $this->set(compact('username', 'password'));

        return null;
    }

    /**
     * bcrypt 基本・既存 SHA1 互換ログイン
     *
     * SHA1 で認証成功した場合は bcrypt へ自動再ハッシュする
     *
     * @return bool 認証成功时 true
     */
    protected function _login(): bool
    {
        // POSTデータにログインID・パスワードが含まれていない場合、認証失敗とする
        $username = $this->request->getData('username');
        $password = $this->request->getData('password');

        if ($username === null || $password === null) {
            return false;
        }

        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->find()->where(['username' => $username])->first();

        // 1. ローカルDB認証
        //    パスワードが設定されている既存ユーザは、ローカルDBのパスワードでのみ認証する。
        if ($user && (string)$user->password !== '') {
            $hash = $user->password;

            // 先頭文字で bcrypt ハッシュかどうか判定
            if (substr($hash, 0, 1) === '$') {
                if (!password_verify($password, $hash)) {
                    return false;
                }

                $this->Authentication->setIdentity($user->toArray());

                return true;
            }

            // 既存 SHA1 での認証（CakePHP 2 の Security::hash($password, null, true) 互換）
            $legacySalt = (string)Configure::read('legacy_security_salt');
            if ($hash !== sha1($legacySalt . $password) && $hash !== sha1($password)) {
                return false;
            }

            // 認証成功
            $this->Authentication->setIdentity($user->toArray());

            // 次回以降は bcrypt で認証できるようアップグレード
            $user->password = $password;
            $usersTable->save($user);

            return true;
        }

        // 2. LDAP外部認証
        //    ローカルにユーザが存在しない、またはパスワード未設定（LDAP連携ユーザ）の場合に試行する。
        $ldapIdentity = (new LdapAuthService())->authenticate((string)$username, (string)$password);
        if ($ldapIdentity !== null) {
            $syncedUser = $this->syncLdapUser($ldapIdentity);
            if ($syncedUser) {
                $this->Authentication->setIdentity($syncedUser->toArray());

                return true;
            }
        }

        return false;
    }

    /**
     * ログインブロック判定
     *
     * 直近1時間以内に同一ユーザ名＋同一IPで10回以上ログイン失敗している場合、ブロック対象とする。
     * IPを鍵に含めることで、攻撃者が他人のユーザ名でロックアウトする DoS を防止する。
     *
     * @param string $username 試行されたユーザ名
     * @return bool true: ブロック対象, false: 通常処理続行
     */
    protected function _isLoginBlocked(string $username): bool
    {
        // ユーザ名が空の場合は後続のバリデーションで弾かれるため、ここではチェックしない
        if ($username === '') {
            return false;
        }

        $ip = $this->_getClientIp();

        $threshold_time = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $max_attempts = 10;

        $count = $this->fetchTable('Logs')->find()
            ->where([
                'log_type' => 'login_error',
                'log_content' => $username,
                'user_ip' => $ip,
                'created >=' => $threshold_time,
            ])
            ->count();

        return $count >= $max_attempts;
    }

    /**
     * クライアントIPアドレスを取得する
     *
     * X-Forwarded-For ヘッダーが存在する場合は先頭のIPを使用し、
     * それ以外は REMOTE_ADDR を返す（AppController::writeLog と同じロジック）
     *
     * @return string IPアドレス文字列
     */
    protected function _getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);

            return trim($ips[0]);
        }

        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}