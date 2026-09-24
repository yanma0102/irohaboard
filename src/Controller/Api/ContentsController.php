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

use Cake\Http\Response;

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
    public function index(): Response
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
    public function view(int $id): Response
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

    /**
     * コンテンツを追加する
     *
     * POST /api/v1/contents
     *
     * @return \Cake\Http\Response
     */
    public function add(): Response
    {
        $this->requireStaff();

        $input = $this->input();
        $contentsTable = $this->fetchTable('Contents');

        $allowedFields = ['course_id', 'title', 'kind', 'body', 'url', 'file_name', 'status', 'sort_no', 'comment'];
        $fields = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $fields[$field] = $input[$field];
            }
        }

        if (empty($fields['course_id'])) {
            $this->fail(400, 'course_id is required');
        }

        $courseId = (int)$fields['course_id'];
        $accessibleIds = $this->accessibleCourseIds($this->currentUserId());
        if (!in_array($courseId, $accessibleIds, true)) {
            $this->fail(403, 'You do not have access to this course');
        }

        $fields['user_id'] = $this->currentUserId();

        if (!isset($fields['sort_no']) || $fields['sort_no'] === '' || $fields['sort_no'] === null) {
            $fields['sort_no'] = $contentsTable->getNextSortNo($courseId);
        }

        if (!isset($fields['status'])) {
            $fields['status'] = 0;
        }

        $entity = $contentsTable->newEntity($fields);

        if ($contentsTable->save($entity)) {
            return $this->ok($entity->toArray(), 201);
        }

        $this->fail(400, 'Validation failed', $entity->getErrors());
    }

    /**
     * コンテンツを更新する
     *
     * PUT/PATCH /api/v1/contents/{id}
     *
     * @param int $id コンテンツID
     * @return \Cake\Http\Response
     */
    public function edit(int $id): Response
    {
        $this->requireStaff();

        $contentsTable = $this->fetchTable('Contents');

        if (!$contentsTable->exists(['id' => $id])) {
            $this->fail(404, 'Content not found');
        }

        $target = $contentsTable->get($id);

        $courseId = (int)$target->course_id;
        $accessibleIds = $this->accessibleCourseIds($this->currentUserId());
        if (!in_array($courseId, $accessibleIds, true)) {
            $this->fail(403, 'You do not have access to this course');
        }

        $input = $this->input();
        $allowedFields = ['course_id', 'title', 'kind', 'body', 'url', 'file_name', 'status', 'sort_no', 'comment'];
        $fields = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $fields[$field] = $input[$field];
            }
        }

        if (empty($fields)) {
            $this->fail(400, 'No updatable fields were provided');
        }

        if (isset($fields['course_id'])) {
            $newCourseId = (int)$fields['course_id'];
            if (!in_array($newCourseId, $accessibleIds, true)) {
                $this->fail(403, 'You do not have access to the target course');
            }
        }

        unset($fields['user_id']);

        $entity = $contentsTable->patchEntity($target, $fields);

        if ($contentsTable->save($entity)) {
            return $this->ok($entity->toArray());
        }

        $this->fail(400, 'Validation failed', $entity->getErrors());
    }

    /**
     * コンテンツを削除する
     *
     * DELETE /api/v1/contents/{id}
     *
     * Web 側 Admin/ContentsController::delete() の挙動に合わせる。
     * - ContentsQuestions のカスケード削除
     * - 物理削除
     *
     * @param int $id コンテンツID
     * @return \Cake\Http\Response
     */
    public function delete(int $id): Response
    {
        $this->requireStaff();

        $contentsTable = $this->fetchTable('Contents');

        if (!$contentsTable->exists(['id' => $id])) {
            $this->fail(404, 'Content not found');
        }

        $content = $contentsTable->get($id);

        $courseId = (int)$content->course_id;
        $accessibleIds = $this->accessibleCourseIds($this->currentUserId());
        if (!in_array($courseId, $accessibleIds, true)) {
            $this->fail(403, 'You do not have access to this course');
        }

        if ($contentsTable->delete($content)) {
            $this->fetchTable('ContentsQuestions')->deleteAll(['content_id' => $id]);

            return $this->ok(['id' => $id, 'deleted' => true]);
        }

        $this->fail(500, 'Failed to delete content');
    }
}
