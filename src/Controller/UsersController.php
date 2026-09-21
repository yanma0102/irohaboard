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

namespace App\Controller;

use Cake\Core\Configure;
use Cake\Http\Exception\NotFoundException;

/**
 * Users Controller
 *
 * CakePHP 5 版
 */
class UsersController extends AppController
{
    /**
     * 初期化
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->FormProtection->unlockActions([
            'login',
            'admin_login',
            'admin_logout',
        ]);
    }

    /**
     * ホーム画面（受講コース一覧）へリダイレクト
     *
     * @return \Cake\Http\Response
     */
    public function index(): \Cake\Http\Response
    {
        return $this->redirect('/users_courses');
    }

    /**
     * ログイン
     *
     * @return \Cake\Http\Response|null
     */
    public function login(): ?\Cake\Http\Response
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
            } catch (\Exception $e) {
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
                return $this->redirect('/users_courses');
            }

            // 失敗時は Cookie を削除（不正・期限切れ・テーブル未作成など）
            $this->deleteCookie('CookieAuth');
        }

        // 通常ログイン処理
        if ($this->request->is('post')) {
            // HTTPS 以外ではログイン状態の保持を無効化
            if (!$this->isHTTPS()) {
                $this->request = $this->request->withData('User.remember_me', null);
            }

            // ログインID、パスワードの形式が正しくない場合、エラーを表示
            $inputUsername = $this->request->getData('User.username', '');
            $inputPassword = $this->request->getData('User.password', '');

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
                if (!empty($this->request->getData('User.remember_me'))) {
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
                        } catch (\Exception $e) {
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
                return $this->redirect('/users_courses');
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
    private function _login(): bool
    {
        // POSTデータにログインID・パスワードが含まれていない場合、認証失敗とする
        $username = $this->request->getData('User.username');
        $password = $this->request->getData('User.password');

        if ($username === null || $password === null) {
            return false;
        }

        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->findBy(['username' => $username])->first();

        // 指定したユーザが存在しない場合、認証失敗とする
        if (!$user) {
            return false;
        }

        $hash = $user->password;

        // 先頭文字で bcrypt ハッシュかどうか判定
        if (substr($hash, 0, 1) === '$') {
            if (!password_verify($password, $hash)) {
                return false;
            }

            $this->Authentication->setIdentity($user->toArray());
            return true;
        }

        // 既存 SHA1 での認証
        if (sha1($password) !== $hash) {
            return false;
        }

        // 認証成功
        $this->Authentication->setIdentity($user->toArray());

        // 次回以降は bcrypt で認証できるようアップグレード
        $user->password = $password;
        $usersTable->save($user);

        return true;
    }

    /**
     * ログアウト
     *
     * @return \Cake\Http\Response
     */
    public function logout(): \Cake\Http\Response
    {
        if ($this->hasCookie('CookieAuth')) {
            try {
                $this->fetchTable('UserTokens')->revokeByCookie($this->readCookie('CookieAuth'));
            } catch (\Exception $e) {
                // ib_user_tokens 未作成（/update 前）など
            }
            $this->deleteCookie('CookieAuth');
        }

        // 旧方式 Cookie も念のため削除
        $this->deleteCookie('Auth');
        $this->deleteCookie('LoginStatus');

        $logoutRedirect = $this->Authentication->logout();
        return $this->redirect($logoutRedirect ?? '/users/login');
    }

    /**
     * ユーザ一覧を表示
     *
     * @return \Cake\Http\Response|null
     */
    public function admin_index(): ?\Cake\Http\Response
    {
        $usersTable = $this->fetchTable('Users');
        $groupsTable = $this->fetchTable('Groups');

        // 検索条件の構築
        $conditions = [];

        // グループが指定されている場合、選択中のグループのグループIDをセッションに保存
        if ($this->hasQuery('group_id')) {
            $this->writeSession('Iroha.group_id', (int)$this->getQuery('group_id'));
        }

        // GETパラメータもしくはセッションから検索対象のグループIDを取得
        $group_id = $this->hasQuery('group_id')
            ? $this->getQuery('group_id')
            : $this->readSession('Iroha.group_id');

        // グループIDが指定されている場合、指定したグループに所属するユーザを検索
        if (($group_id !== '') && ($group_id != 0)) {
            $conditions[$usersTable->aliasField('id')] = $groupsTable->getUserIdByGroupID($group_id);
        }

        // CSV出力モードの場合
        if ($this->getQuery('cmd') === 'export') {
            return $this->_exportCsv($conditions);
        }

        // クエリの構築
        $query = $usersTable->find()
            ->where($conditions)
            ->order([$usersTable->aliasField('created') => 'DESC']);

        $query->select([
            'Users.*',
        ]);

        // 所属グループ一覧 ※パフォーマンス改善
        $query->select([
            'group_title' => $query->newExpr(
                "(SELECT group_concat(g.title ORDER BY g.id SEPARATOR ', ') FROM ib_users_groups ug INNER JOIN ib_groups g ON g.id = ug.group_id WHERE ug.user_id = Users.id)"
            ),
        ]);

        // 受講コース一覧 ※パフォーマンス改善
        $query->select([
            'course_title' => $query->newExpr(
                "(SELECT group_concat(c.title ORDER BY c.id SEPARATOR ', ') FROM ib_users_courses uc INNER JOIN ib_courses c ON c.id = uc.course_id WHERE uc.user_id = Users.id)"
            ),
        ]);

        $this->paginate = [
            'limit' => 20,
        ];

        try {
            $users = $this->paginate($query);
        } catch (\Exception $e) {
            // 指定したページが存在しなかった場合（主に検索条件変更時に発生）、1ページ目を設定
            $this->request = $this->request->withParam('page', 1);
            $users = $this->paginate($query);
        }

        // グループ一覧を取得
        $groups = $groupsTable->find('list');

        $this->set(compact('groups', 'users', 'group_id'));

        return null;
    }

    /**
     * ユーザを追加（編集画面へ）
     *
     * @return \Cake\Http\Response|null
     */
    public function admin_add(): ?\Cake\Http\Response
    {
        $this->admin_edit();
        $this->viewBuilder()->setOption('template', 'admin_edit');

        return null;
    }

    /**
     * ユーザ情報編集
     *
     * @param int|string|null $user_id 編集対象のユーザのID
     * @return \Cake\Http\Response|null
     */
    public function admin_edit($user_id = null): ?\Cake\Http\Response
    {
        $usersTable = $this->fetchTable('Users');

        if ($this->isEditPage() && !$usersTable->exists(['id' => $user_id])) {
            throw new NotFoundException(__('Invalid user'));
        }

        $username = '';

        if ($this->request->is(['post', 'put'])) {
            if (Configure::read('demo_mode')) {
                return null;
            }

            $userData = $this->request->getData('User', []);

            $password_changed = !empty($userData['new_password']);
            if ($password_changed) {
                $userData['password'] = $userData['new_password'];
            }
            unset($userData['new_password']);

            // 所属グループ・受講コースの割当
            $groupIds = $userData['Group'] ?? [];
            $courseIds = $userData['Course'] ?? [];
            unset($userData['Group'], $userData['Course']);

            $userData['groups'] = ['_ids' => $groupIds];
            $userData['courses'] = ['_ids' => $courseIds];

            if ($user_id !== null) {
                $entity = $usersTable->get((int)$user_id);
                $entity = $usersTable->patchEntity($entity, $userData);
            } else {
                $entity = $usersTable->newEntity($userData);
            }

            if ($usersTable->save($entity)) {
                if ($password_changed) {
                    // パスワード変更時は旧 Remember Me トークンを無効化
                    $target_user_id = !empty($userData['id'])
                        ? (int)$userData['id']
                        : (int)$user_id;
                    try {
                        $this->fetchTable('UserTokens')->revokeAllForUser($target_user_id);
                    } catch (\Exception $e) {
                        // ib_user_tokens 未作成（/update 前）など
                    }
                }

                $this->Flash->success(__('ユーザ情報が保存されました'));
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error(__('ユーザ情報が保存できませんでした'));
            }
        } else {
            $user = $usersTable->get((int)$user_id);
            $username = $user->username ?? '';
        }

        $courses = $this->fetchTable('Courses')->find('list');
        $groups = $this->fetchTable('Groups')->find('list');

        $this->set(compact('courses', 'groups', 'username'));

        if (isset($user)) {
            $this->set('user', $user);
        }

        return null;
    }

    /**
     * ユーザの削除
     *
     * @param int|string|null $user_id 削除するユーザのID
     * @return \Cake\Http\Response
     */
    public function admin_delete($user_id = null): \Cake\Http\Response
    {
        if (Configure::read('demo_mode')) {
            return $this->redirect(['action' => 'index']);
        }

        $usersTable = $this->fetchTable('Users');

        if (!$usersTable->exists(['id' => $user_id])) {
            throw new NotFoundException(__('Invalid user'));
        }

        $this->request->allowMethod(['post', 'delete']);

        $user = $usersTable->get((int)$user_id);

        if ($usersTable->delete($user)) {
            $this->Flash->success(__('ユーザが削除されました'));
        } else {
            $this->Flash->error(__('ユーザを削除できませんでした'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * ユーザの学習履歴のクリア
     *
     * @param int|string $user_id 学習履歴をクリアするユーザのID
     * @return \Cake\Http\Response
     */
    public function admin_clear($user_id): \Cake\Http\Response
    {
        $this->request->allowMethod(['post', 'delete']);
        $this->fetchTable('Users')->deleteUserRecords((int)$user_id);
        $this->Flash->success(__('学習履歴を削除しました'));
        return $this->redirect(['action' => 'edit', $user_id]);
    }

    /**
     * パスワード変更
     *
     * @return \Cake\Http\Response|null
     */
    public function setting(): ?\Cake\Http\Response
    {
        $usersTable = $this->fetchTable('Users');

        if ($this->request->is(['post', 'put'])) {
            if (Configure::read('demo_mode')) {
                return null;
            }

            $data = $this->getData('User');

            if (empty($data['new_password'])) {
                $this->Flash->error(__('パスワードを入力して下さい'));
                return null;
            }

            if ($data['new_password'] !== $data['new_password2']) {
                $this->Flash->error(__('入力された「パスワード」と「パスワード（確認用）」が一致しません'));
                return null;
            }

            $entity = $usersTable->get((int)$this->readAuthUser('id'));
            $entity = $usersTable->patchEntity($entity, [
                'password' => $data['new_password'],
            ]);

            if ($usersTable->save($entity)) {
                // パスワード変更時は旧 Remember Me トークンを無効化
                try {
                    $this->fetchTable('UserTokens')->revokeAllForUser((int)$this->readAuthUser('id'));
                } catch (\Exception $e) {
                    // ib_user_tokens 未作成（/update 前）など
                }
                $this->Flash->success(__('パスワードが変更されました'));
            } else {
                $this->Flash->error(__('パスワードを保存できませんでした'));
            }
        } else {
            $user = $usersTable->get((int)$this->readAuthUser('id'));
            $this->set(compact('user'));
        }

        return null;
    }

    /**
     * パスワード変更（管理画面）
     *
     * @return \Cake\Http\Response|null
     */
    public function admin_setting(): ?\Cake\Http\Response
    {
        return $this->setting();
    }

    /**
     * ログイン（管理画面）
     *
     * @return \Cake\Http\Response|null
     */
    public function admin_login(): ?\Cake\Http\Response
    {
        return $this->login();
    }

    /**
     * ログアウト（管理画面）
     *
     * @return \Cake\Http\Response
     */
    public function admin_logout(): \Cake\Http\Response
    {
        return $this->logout();
    }

    /**
     * ログインブロック判定
     *
     * 直近1時間以内に同一ユーザ名で10回以上ログイン失敗している場合、ブロック対象とする
     *
     * @param string $username 試行されたユーザ名
     * @return bool true: ブロック対象, false: 通常処理続行
     */
    private function _isLoginBlocked(string $username): bool
    {
        // ユーザ名が空の場合は後続のバリデーションで弾かれるため、ここではチェックしない
        if ($username === '') {
            return false;
        }

        $threshold_time = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $max_attempts   = 10;

        $count = $this->fetchTable('Logs')->find()
            ->where([
                'log_type'    => 'login_error',
                'log_content' => $username,
                'created >='  => $threshold_time,
            ])
            ->count();

        return ($count >= $max_attempts);
    }

    /**
     * ユーザ情報のインポート
     *
     * @return \Cake\Http\Response|null
     */
    public function admin_import(): ?\Cake\Http\Response
    {
        if (Configure::read('demo_mode')) {
            return null;
        }

        $group_count  = Configure::read('import_group_count');     // 所属グループの列数
        $course_count = Configure::read('import_course_count');     // 受講コースの列数

        //------------------------------//
        //	列番号の定義				//
        //------------------------------//
        define('COL_LOGINID',   0);
        define('COL_PASSWORD',  1);
        define('COL_NAME',      2);
        define('COL_ROLE',      3);
        define('COL_EMAIL',     4);
        define('COL_COMMENT',   5);
        define('COL_GROUP',     6);
        define('COL_COURSE',    6 + $group_count);

        $err_msg = '';

        if ($this->request->is(['post', 'put'])) {
            //------------------------------//
            //	CSVファイルの読み込み		//
            //------------------------------//
            // 制限時間を120秒に設定
            set_time_limit(120);

            $csvfile = $this->request->getData('User.csvfile');

            // インポートファイルが指定されていない場合、エラーメッセージを表示
            if (!is_array($csvfile) || $csvfile['error'] != 0) {
                $this->Flash->error(__('インポートファイルが指定されていません'));
                $this->set(compact('err_msg'));
                return null;
            }

            // CSVファイルの読み込み
            $csv = \Utils::getCsvData($csvfile['tmp_name']);

            $i = 0;

            $usersTable = $this->fetchTable('Users');
            $connection = $usersTable->getConnection();
            $connection->begin();

            try {
                $is_error = false;

                $group_list  = $this->fetchTable('Groups')->find('list');   // 所属グループ
                $course_list = $this->fetchTable('Courses')->find('list');  // 受講コース

                // 1行ごとにデータを登録
                foreach ($csv as $row) {
                    $i++;

                    if ($i < 2) {
                        continue;
                    }

                    if (count($row) < 5) {
                        continue;
                    }

                    $is_new = false;

                    //------------------------------//
                    //	ユーザ情報の作成			//
                    //------------------------------//
                    $existingUser = $usersTable->findBy(['username' => $row[COL_LOGINID]])->first();

                    // 指定したログインIDのユーザが存在しない場合、新規追加とする
                    if (!$existingUser) {
                        $is_new = true;
                    }

                    $saveData = [];

                    // ユーザ名
                    $saveData['username'] = $row[COL_LOGINID];

                    // パスワード
                    if ($row[COL_PASSWORD] !== '') {
                        $saveData['password'] = $row[COL_PASSWORD];
                    }

                    $saveData['name']    = $row[COL_NAME];                                     // 氏名
                    $saveData['role']    = \Utils::getKeyByValue('user_role', $row[COL_ROLE]); // 権限
                    $saveData['email']   = $row[COL_EMAIL];                                    // メールアドレス
                    $saveData['comment'] = \Utils::issetOr($row[COL_COMMENT]);                 // 備考

                    //----------------------------------//
                    //	所属グループ・受講コースの割当	//
                    //----------------------------------//
                    $groupIds  = [];
                    $courseIds = [];

                    // 所属グループの割当
                    for ($n = 0; $n < $group_count; $n++) {
                        $title = \Utils::issetOr($row[COL_GROUP + $n], '');

                        if ($title === '') {
                            continue;
                        }

                        $group = \Utils::getIdByTitle($group_list, $title);

                        if ($group === null) {
                            continue;
                        }

                        $groupIds[] = $group;
                    }

                    // 受講コースの割当
                    for ($n = 0; $n < $course_count; $n++) {
                        $title = \Utils::issetOr($row[COL_COURSE + $n], '');

                        if ($title === '') {
                            continue;
                        }

                        $course = \Utils::getIdByTitle($course_list, $title);

                        if ($course === null) {
                            continue;
                        }

                        $courseIds[] = $course;
                    }

                    $saveData['groups']   = ['_ids' => $groupIds];
                    $saveData['courses']  = ['_ids' => $courseIds];

                    //------------------------------//
                    //	保存						//
                    //------------------------------//
                    if ($is_new) {
                        $entity = $usersTable->newEntity($saveData);
                    } else {
                        $entity = $usersTable->patchEntity($existingUser, $saveData);
                    }

                    if (!$usersTable->save($entity)) {
                        // 保存時にエラーが発生した場合、エンティティからエラー情報を抽出
                        $errors = $entity->getErrors();

                        foreach ($errors as $field => $fieldErrors) {
                            foreach ((array)$fieldErrors as $err) {
                                $err_msg .= '<li>' . $i . '行目 : ' . $err . '</li>';
                                break;
                            }
                        }

                        $is_error = true;
                    }
                }

                //------------------------------//
                //	エラー処理					//
                //------------------------------//
                if ($is_error) {
                    $connection->rollback();
                    $this->Flash->error(__('インポートに失敗しました'));
                } else {
                    $connection->commit();
                    $this->Flash->success(__('インポートが完了しました'));
                    return $this->redirect(['action' => 'index']);
                }
            } catch (\Exception $e) {
                $connection->rollback();
                $this->Flash->error(__('インポートに失敗しました'));
            }
        }

        $this->set(compact('err_msg'));

        return null;
    }

    /**
     * ユーザ情報のエクスポート
     *
     * @param array $conditions 検索条件
     * @return \Cake\Http\Response
     */
    protected function _exportCsv(array $conditions): \Cake\Http\Response
    {
        $group_count  = Configure::read('import_group_count');     // 所属グループの列数
        $course_count = Configure::read('import_course_count');     // 受講コースの列数

        $this->autoRender = false;
        Configure::write('debug', 0);

        $usersTable = $this->fetchTable('Users');

        //------------------------------//
        //	ヘッダー行の作成			//
        //------------------------------//
        $header = [
            __('ログインID'),
            __('パスワード'),
            __('氏名'),
            __('権限'),
            __('メールアドレス'),
            __('備考'),
        ];

        for ($n = 0; $n < $group_count; $n++) {
            $header[] = __('グループ') . ($n + 1);
        }

        for ($n = 0; $n < $course_count; $n++) {
            $header[] = __('コース') . ($n + 1);
        }

        //------------------------------//
        //	ユーザ情報の取得			//
        //------------------------------//

        // パフォーマンスの改善の為、一定件数に分割してデータを取得
        $limit      = 500;
        $user_count = $usersTable->find()->where($conditions)->count(); // ユーザ数を取得
        $page_size  = (int)ceil($user_count / $limit);                 // ページ数（ユーザ数 / ページ単位）

        $fp = fopen('php://output', 'w');

        // ヘッダー行をCSV出力
        mb_convert_variables('SJIS-WIN', 'UTF-8', $header);
        fputcsv($fp, $header);

        // ページ単位でユーザを取得
        for ($page = 1; $page <= $page_size; $page++) {
            // ユーザ情報を取得（所属グループ・受講コースを含む）
            $rows = $usersTable->find()
                ->contain(['Groups', 'Courses'])
                ->where($conditions)
                ->limit($limit)
                ->page($page)
                ->all();

            foreach ($rows as $row) {
                //------------------------------//
                //	出力するデータを作成		//
                //------------------------------//
                $groups  = array_fill(0, $group_count, '');
                $courses = array_fill(0, $course_count, '');

                $i = 0;

                // 所属グループのリストを作成
                foreach ($row->groups as $group) {
                    $groups[$i] = $group->title;
                    $i++;
                }

                $i = 0;

                // 受講コースのリストを作成
                foreach ($row->courses as $course) {
                    $courses[$i] = $course->title;
                    $i++;
                }

                // 出力行を作成
                $line = [
                    $row->username,                                             // ユーザ名
                    '',                                                         // パスワード
                    $row->name,                                                 // 氏名
                    Configure::read('user_role.' . $row->role),                // 権限
                    $row->email,                                                // メールアドレス
                    $row->comment,                                              // 備考
                ];

                // 所属グループを出力
                for ($n = 0; $n < $group_count; $n++) {
                    $line[] = $groups[$n];
                }

                // 受講コースを出力
                for ($n = 0; $n < $course_count; $n++) {
                    $line[] = $courses[$n];
                }

                // CSV出力
                mb_convert_variables('SJIS-WIN', 'UTF-8', $line);
                fputcsv($fp, $line);
            }
        }

        fclose($fp);
        $this->writeLog('user_exported', ''); // ログを記録

        return $this->response
            ->withType('csv')
            ->withHeader('Content-Disposition', 'attachment; filename="users_' . date('Ymd') . '.csv"');
    }
}
