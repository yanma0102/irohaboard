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

use Cake\Http\Response;

/**
 * UsersCourses Controller
 *
 * CakePHP 5 版
 */
class UsersCoursesController extends AppController
{
    /**
     * 受講コース一覧（ホーム画面）を表示
     *
     * @return \Cake\Http\Response|null
     */
    public function index(): ?Response
    {
        $user_id = $this->readAuthUser('id');

        // 全体のお知らせの取得
        $data = $this->fetchTable('Settings')->find()
            ->where(['setting_key' => 'information'])
            ->first();

        $info = $data ? (string)$data->setting_value : '';

        // お知らせ一覧を取得
        $infos = $this->fetchTable('Infos')->getInfos($user_id, 2);

        $no_info = '';

        // 全体のお知らせもお知らせも存在しない場合
        if (($info == '') && (count($infos) == 0)) {
            $no_info = __('お知らせはありません');
        }

        // 受講コース情報の取得
        $courses = $this->fetchTable('UsersCourses')->getCourseRecord($user_id);

        $no_record = '';

        if (count($courses) == 0) {
            $no_record = __('受講可能なコースはありません');
        }

        $this->set(compact('courses', 'no_record', 'info', 'infos', 'no_info'));

        return null;
    }
}
