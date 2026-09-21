<?php
declare(strict_types=1);

/**
 * iroha Board REST API 学習記録コントローラ
 *
 * CakePHP 5 版
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Controller\Api;

/**
 * ApiRecords Controller
 * 学習記録の一覧・詳細を提供する
 */
class RecordsController extends BaseController
{
    /**
     * 学習記録一覧を取得する
     *
     * @return \Cake\Http\Response
     */
    public function index(): \Cake\Http\Response
    {
        $conditions = [];
        $recordsTable = $this->fetchTable('Records');

        if ($this->isStaff()) {
            // スタッフは指定条件で参照可能
            $userId = $this->queryParam('user_id');
            if ($userId !== null) {
                $conditions['user_id'] = (int)$userId;
            }

            $courseId = $this->queryParam('course_id');
            if ($courseId !== null) {
                $conditions['course_id'] = (int)$courseId;
            }

            $contentId = $this->queryParam('content_id');
            if ($contentId !== null) {
                $conditions['content_id'] = (int)$contentId;
            }
        } else {
            // 一般ユーザは自分のレコードのみ
            $conditions['user_id'] = $this->currentUserId();
        }

        // 日付範囲フィルタ
        $from = $this->queryParam('from');
        if ($from !== null) {
            $conditions['created >='] = $from;
        }

        $to = $this->queryParam('to');
        if ($to !== null) {
            $conditions['created <='] = $to;
        }

        $fields = [
            'id', 'course_id', 'user_id',
            'content_id', 'full_score', 'pass_score',
            'score', 'is_passed', 'is_complete',
            'progress', 'understanding', 'study_sec',
            'created',
        ];

        $order = ['id' => 'desc'];

        [$rows, $meta] = $this->paginatedList($recordsTable, [
            'conditions' => $conditions,
            'fields' => $fields,
            'order' => $order,
        ]);

        return $this->okList($rows, $meta);
    }

    /**
     * 学習記録詳細を取得する
     *
     * @param int $id 記録ID
     * @return \Cake\Http\Response
     */
    public function view(int $id): \Cake\Http\Response
    {
        $recordsTable = $this->fetchTable('Records');

        // 存在チェック
        if (!$recordsTable->exists(['id' => $id])) {
            $this->fail(404, 'Record not found');
        }

        $record = $recordsTable->get($id);

        // 一般ユーザは自分のレコードのみ参照可能
        if (!$this->isStaff()) {
            if ((int)$record->user_id !== $this->currentUserId()) {
                $this->fail(403, 'Forbidden');
            }
        }

        return $this->ok($record->toArray());
    }
}
