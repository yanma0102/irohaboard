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

use App\Controller\Trait\UserLoginTrait;
use Cake\Core\Configure;

/**
 * Users Controller
 *
 * CakePHP 5 版
 */
class UsersController extends AppController
{
    use UserLoginTrait;

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
        ]);
    }

    /**
     * ホーム画面（受講コース一覧）へリダイレクト
     *
     * @return \Cake\Http\Response
     */
    public function index(): \Cake\Http\Response
    {
        return $this->redirect(['controller' => 'UsersCourses', 'action' => 'index']);
    }

    /**
     * ログイン
     *
     * @return \Cake\Http\Response|null
     */
    public function login(): ?\Cake\Http\Response
    {
        return $this->performLogin();
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
