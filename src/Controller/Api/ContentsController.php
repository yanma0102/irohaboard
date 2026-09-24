<?php
declare(strict_types=1);

/**
 * iroha Board REST API コンテンツコントローラ
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
 * ApiContents Controller
 * コンテンツの一覧・詳細を提供する
 */
class ContentsController extends BaseController
{
    /**
     * コンテンツ一覧を取得する
     *
     * @return \Cake\Http\Response
     */
    public function index(): \Cake\Http\Response
    {
        $conditions = ['deleted IS NULL'];
        $contentsTable = $this->fetchTable('Contents');

        if ($this->isStaff()) {
            // スタッフは全件参照可能
            $courseId = $this->queryParam('course_id');
            if ($courseId !== null) {
                $conditions['course_id'] = (int)$courseId;
            }

            $kind = $this->queryParam('kind');
            if ($kind !== null) {
                $conditions['kind'] = $kind;
            }

            $status = $this->queryParam('status');
            if ($status !== null) {
                $conditions['status'] = (int)$status;
            }
        } else {
            // 一般ユーザは受講可能なコースの公開コンテンツのみ
            $courseIds = $this->accessibleCourseIds($this->currentUserId());

            if (empty($courseIds)) {
                return $this->okList([], ['page' => 1, 'limit' => 0, 'total' => 0, 'count' => 0]);
            }

            $conditions['course_id IN'] = $courseIds;
            // 一般ユーザは公開コンテンツのみ（status クエリパラメータは無視）
            $conditions['status'] = 1;

            $courseId = $this->queryParam('course_id');
            if ($courseId !== null) {
                $conditions['course_id'] = (int)$courseId;
            }

            $kind = $this->queryParam('kind');
            if ($kind !== null) {
                $conditions['kind'] = $kind;
            }
        }

        $fields = [
            'id', 'course_id', 'user_id',
            'title', 'url', 'file_name',
            'kind', 'body', 'timelimit',
            'pass_rate', 'question_count',
            'wrong_mode', 'status', 'opened',
            'sort_no', 'created', 'modified',
        ];

        $order = ['sort_no' => 'asc', 'id' => 'asc'];

        [$rows, $meta] = $this->paginatedList($contentsTable, [
            'conditions' => $conditions,
            'fields' => $fields,
            'order' => $order,
        ]);

        return $this->okList($rows, $meta);
    }

    /**
     * コンテンツ詳細を取得する
     *
     * @param int $id コンテンツID
     * @return \Cake\Http\Response
     */
    public function view(int $id): \Cake\Http\Response
    {
        $contentsTable = $this->fetchTable('Contents');

        // 存在チェック
        if (!$contentsTable->exists(['id' => $id])) {
            $this->fail(404, 'Content not found');
        }

        $content = $contentsTable->get($id);

        // 一般ユーザは受講可能なコースの公開コンテンツのみ参照可能
        if (!$this->isStaff()) {
            $courseIds = $this->accessibleCourseIds($this->currentUserId());
            $courseId = (int)$content->course_id;

            if (!in_array($courseId, $courseIds, true) || (int)$content->status !== 1) {
                $this->fail(404, 'Content not found');
            }
        }

        return $this->ok($content->toArray());
    }
}
