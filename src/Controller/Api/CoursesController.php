<?php
declare(strict_types=1);

/**
 * iroha Board REST API コースコントローラ
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
 * ApiCourses Controller
 * コースの一覧・詳細・追加・削除を提供する
 */
class CoursesController extends BaseController
{
    /**
     * コース一覧を取得する
     *
     * @return \Cake\Http\Response
     */
    public function index(): \Cake\Http\Response
    {
        $conditions = ['deleted IS NULL'];
        $coursesTable = $this->fetchTable('Courses');

        if ($this->isStaff()) {
            // スタッフは全件参照可能
            $title = $this->queryParam('title');
            if ($title !== null) {
                if ($this->wantsExact()) {
                    $conditions['title'] = $title;
                } else {
                    $conditions['title LIKE'] = '%' . $title . '%';
                }
            }
        } else {
            // 一般ユーザは受講可能なコースのみ
            $courseIds = $this->accessibleCourseIds($this->currentUserId());

            if (empty($courseIds)) {
                return $this->okList([], ['page' => 1, 'limit' => 0, 'total' => 0, 'count' => 0]);
            }

            $conditions['id IN'] = $courseIds;

            $title = $this->queryParam('title');
            if ($title !== null) {
                if ($this->wantsExact()) {
                    $conditions['title'] = $title;
                } else {
                    $conditions['title LIKE'] = '%' . $title . '%';
                }
            }
        }

        $fields = [
            'id', 'title', 'introduction',
            'opened', 'sort_no', 'comment',
            'user_id', 'created', 'modified',
        ];

        $order = ['sort_no' => 'asc', 'id' => 'asc'];

        [$rows, $meta] = $this->paginatedList($coursesTable, [
            'conditions' => $conditions,
            'fields' => $fields,
            'order' => $order,
        ]);

        return $this->okList($rows, $meta);
    }

    /**
     * コース詳細を取得する
     *
     * @param int $id コースID
     * @return \Cake\Http\Response
     */
    public function view(int $id): \Cake\Http\Response
    {
        $coursesTable = $this->fetchTable('Courses');

        // 存在チェック
        if (!$coursesTable->exists(['id' => $id])) {
            $this->fail(404, 'Course not found');
        }

        // 一般ユーザは受講可能なコースのみ参照可能
        if (!$this->isStaff()) {
            $courseIds = $this->accessibleCourseIds($this->currentUserId());

            if (!in_array($id, $courseIds, true)) {
                $this->fail(404, 'Course not found');
            }
        }

        $course = $coursesTable->get($id);

        return $this->ok($course->toArray());
    }

    /**
     * コースを追加する
     *
     * @return \Cake\Http\Response
     */
    public function add(): \Cake\Http\Response
    {
        $this->requireManager();

        $input = $this->input();
        $coursesTable = $this->fetchTable('Courses');

        $allowedFields = ['title', 'introduction', 'opened', 'comment', 'sort_no'];
        $fields = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $fields[$field] = $input[$field];
            }
        }

        // sort_no 未指定なら最大値+1 を設定
        if (!isset($fields['sort_no']) || $fields['sort_no'] === '' || $fields['sort_no'] === null) {
            $maxSortNo = (int)$coursesTable->find()
                ->where(['deleted IS NULL'])
                ->select(['max_sort_no' => $coursesTable->find()->func()->max('sort_no')])
                ->first()
                ->max_sort_no;
            $fields['sort_no'] = $maxSortNo + 1;
        }

        // 作成者を設定
        $fields['user_id'] = $this->currentUserId();

        $entity = $coursesTable->newEntity($fields);

        if ($coursesTable->save($entity)) {
            $data = $entity->toArray();
            $data['id'] = (int)$data['id'];
            $data['user_id'] = (int)$data['user_id'];
            $data['sort_no'] = (int)$data['sort_no'];
            return $this->ok($data, 201);
        }

        $this->fail(400, 'Validation failed', $entity->getErrors());
    }

    /**
     * コースを削除する
     *
     * @param int $id コースID
     * @return \Cake\Http\Response
     */
    public function delete(int $id): \Cake\Http\Response
    {
        $this->requireManager();

        $coursesTable = $this->fetchTable('Courses');

        if (!$coursesTable->exists(['id' => $id])) {
            $this->fail(404, 'Course not found');
        }

        $coursesTable->deleteCourse($id);

        return $this->ok(['id' => $id, 'deleted' => true]);
    }
}
