<?php
declare(strict_types=1);

/**
 * iroha Board REST API グループコントローラ
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
 * ApiGroups Controller
 * グループの一覧・詳細・追加・更新・削除・ユーザ割当を提供する
 */
class GroupsController extends BaseController
{
    /**
     * グループ一覧を取得する
     *
     * @return \Cake\Http\Response
     */
    public function index(): Response
    {
        $conditions = ['deleted IS NULL'];
        $groupsTable = $this->fetchTable('Groups');

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

            $status = $this->queryParam('status');
            if ($status !== null) {
                $conditions['status'] = (int)$status;
            }
        } else {
            // 一般ユーザは所属グループのみ
            $groupIds = $this->currentUserGroupIds($this->currentUserId());

            if (empty($groupIds)) {
                return $this->okList([], ['page' => 1, 'limit' => 0, 'total' => 0, 'count' => 0]);
            }

            $conditions['id IN'] = $groupIds;

            $title = $this->queryParam('title');
            if ($title !== null) {
                if ($this->wantsExact()) {
                    $conditions['title'] = $title;
                } else {
                    $conditions['title LIKE'] = '%' . $title . '%';
                }
            }

            $status = $this->queryParam('status');
            if ($status !== null) {
                $conditions['status'] = (int)$status;
            }
        }

        $fields = [
            'id', 'title', 'comment',
            'status', 'logo', 'copyright',
            'module', 'created', 'modified',
        ];

        $order = ['id' => 'asc'];

        [$rows, $meta] = $this->paginatedList($groupsTable, [
            'conditions' => $conditions,
            'fields' => $fields,
            'order' => $order,
        ]);

        return $this->okList($rows, $meta);
    }

    /**
     * グループを追加する
     *
     * @return \Cake\Http\Response
     */
    public function add(): Response
    {
        $this->requireManager();

        $input = $this->input();
        $groupsTable = $this->fetchTable('Groups');

        $allowedFields = ['title', 'comment', 'status', 'logo', 'copyright', 'module'];
        $fields = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $fields[$field] = $input[$field];
            }
        }

        $entity = $groupsTable->newEntity($fields);

        if ($groupsTable->save($entity)) {
            $data = $entity->toArray();
            $data['id'] = (int)$data['id'];

            return $this->ok($data, 201);
        }

        $this->fail(400, 'Validation failed', $entity->getErrors());
    }

    /**
     * グループを更新する
     *
     * @param int $id グループID
     * @return \Cake\Http\Response
     */
    public function edit(int $id): Response
    {
        $this->requireManager();

        $groupsTable = $this->fetchTable('Groups');

        if (!$groupsTable->exists(['id' => $id])) {
            $this->fail(404, 'Group not found');
        }

        $target = $groupsTable->get($id);
        $input = $this->input();
        $allowedFields = ['title', 'comment', 'status', 'logo', 'copyright', 'module'];
        $fields = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $fields[$field] = $input[$field];
            }
        }

        if (empty($fields)) {
            $this->fail(400, 'No updatable fields were provided');
        }

        $entity = $groupsTable->patchEntity($target, $fields);

        if (!$groupsTable->save($entity)) {
            $this->fail(400, 'Validation failed', $entity->getErrors());
        }

        $data = $entity->toArray();
        $data['id'] = (int)$data['id'];

        return $this->ok($data);
    }

    /**
     * グループを削除する
     *
     * @param int $id グループID
     * @return \Cake\Http\Response
     */
    public function delete(int $id): Response
    {
        $this->requireManager();

        $groupsTable = $this->fetchTable('Groups');

        if (!$groupsTable->exists(['id' => $id])) {
            $this->fail(404, 'Group not found');
        }

        $groupsTable->deleteGroup($id);

        return $this->ok(['id' => $id, 'deleted' => true]);
    }

    /**
     * グループ詳細を取得する
     *
     * @param int $id グループID
     * @return \Cake\Http\Response
     */
    public function view(int $id): Response
    {
        $groupsTable = $this->fetchTable('Groups');

        // 存在チェック
        if (!$groupsTable->exists(['id' => $id])) {
            $this->fail(404, 'Group not found');
        }

        // 一般ユーザは所属グループのみ参照可能
        if (!$this->isStaff()) {
            $groupIds = $this->currentUserGroupIds($this->currentUserId());

            if (!in_array($id, $groupIds, true)) {
                $this->fail(404, 'Group not found');
            }
        }

        $group = $groupsTable->get($id);

        return $this->ok($group->toArray());
    }

    /**
     * グループに所属するユーザ一覧を取得する
     *
     * @param int $id グループID
     * @return \Cake\Http\Response
     */
    public function users(int $id): Response
    {
        $groupsTable = $this->fetchTable('Groups');

        // グループ存在チェック
        if (!$groupsTable->exists(['id' => $id])) {
            $this->fail(404, 'Group not found');
        }

        // 一般ユーザは所属グループのみ参照可能
        if (!$this->isStaff()) {
            $groupIds = $this->currentUserGroupIds($this->currentUserId());

            if (!in_array($id, $groupIds, true)) {
                $this->fail(404, 'Group not found');
            }
        }

        $connection = $groupsTable->getConnection();

        $sql = 'SELECT u.id, u.username, u.name, u.role, u.email'
            . ' FROM ib_users u'
            . ' INNER JOIN ib_users_groups ug ON ug.user_id = u.id'
            . ' WHERE ug.group_id = :group_id'
            . ' AND u.deleted IS NULL'
            . ' ORDER BY u.id asc';

        $result = $connection->execute($sql, ['group_id' => $id])->fetchAll('assoc');

        $users = [];

        foreach ($result as $row) {
            $users[] = [
                'id' => isset($row['id']) ? (int)$row['id'] : 0,
                'username' => $row['username'] ?? '',
                'name' => $row['name'] ?? '',
                'role' => $row['role'] ?? '',
                'email' => $row['email'] ?? '',
            ];
        }

        return $this->okList($users, ['count' => count($users)]);
    }

    /**
     * グループにユーザを割り当てる
     *
     * @param int $id グループID
     * @return \Cake\Http\Response
     */
    public function assignUser(int $id): Response
    {
        $this->requireManager();

        $groupsTable = $this->fetchTable('Groups');

        // グループ存在チェック
        if (!$groupsTable->exists(['id' => $id])) {
            $this->fail(404, 'Group not found');
        }

        $input = $this->input();

        if (!isset($input['user_id']) || $input['user_id'] === '' || $input['user_id'] === null) {
            $this->fail(400, 'user_id is required');
        }

        $userId = (int)$input['user_id'];

        // ユーザ存在チェック
        $usersTable = $this->fetchTable('Users');

        if (!$usersTable->exists(['id' => $userId])) {
            $this->fail(404, 'User not found');
        }

        // 既に所属しているかチェック
        $usersGroupsTable = $this->fetchTable('UsersGroups');
        $existing = $usersGroupsTable->find()
            ->where([
                'user_id' => $userId,
                'group_id' => $id,
            ])
            ->first();

        if ($existing) {
            return $this->ok(['group_id' => $id, 'user_id' => $userId, 'assigned' => true, 'created' => false]);
        }

        $entity = $usersGroupsTable->newEntity([
            'user_id' => $userId,
            'group_id' => $id,
        ]);

        if (!$usersGroupsTable->save($entity)) {
            $this->fail(500, 'Failed to assign user');
        }

        return $this->ok(['group_id' => $id, 'user_id' => $userId, 'assigned' => true, 'created' => true], 201);
    }

    /**
     * グループからユーザを解除する
     *
     * @param int $id グループID
     * @param int $userId ユーザID
     * @return \Cake\Http\Response
     */
    public function unassignUser(int $id, int $userId): Response
    {
        $this->requireManager();

        $groupsTable = $this->fetchTable('Groups');

        // グループ存在チェック
        if (!$groupsTable->exists(['id' => $id])) {
            $this->fail(404, 'Group not found');
        }

        $deleted = false;

        $usersGroupsTable = $this->fetchTable('UsersGroups');
        $existing = $usersGroupsTable->find()
            ->where([
                'user_id' => $userId,
                'group_id' => $id,
            ])
            ->first();

        if ($existing) {
            $deleted = (bool)$usersGroupsTable->delete($existing);
        }

        return $this->ok(['group_id' => $id, 'user_id' => $userId, 'deleted' => $deleted]);
    }

    /**
     * グループに所属するコース一覧を取得する
     *
     * @param int $id グループID
     * @return \Cake\Http\Response
     */
    public function courses(int $id): Response
    {
        $groupsTable = $this->fetchTable('Groups');

        // グループ存在チェック
        if (!$groupsTable->exists(['id' => $id])) {
            $this->fail(404, 'Group not found');
        }

        $connection = $groupsTable->getConnection();

        $sql = 'SELECT c.id, c.title, c.introduction, c.opened,'
            . ' c.sort_no, c.user_id, c.created, c.modified'
            . ' FROM ib_courses c'
            . ' INNER JOIN ib_groups_courses gc ON gc.course_id = c.id'
            . ' WHERE gc.group_id = :group_id'
            . ' AND c.deleted IS NULL'
            . ' ORDER BY c.sort_no asc';

        $result = $connection->execute($sql, ['group_id' => $id])->fetchAll('assoc');

        $courses = [];

        foreach ($result as $row) {
            $courses[] = [
                'id' => isset($row['id']) ? (int)$row['id'] : 0,
                'title' => $row['title'] ?? '',
                'introduction' => $row['introduction'] ?? null,
                'opened' => $row['opened'] ?? null,
                'sort_no' => isset($row['sort_no']) ? (int)$row['sort_no'] : 0,
                'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : 0,
                'created' => $row['created'] ?? null,
                'modified' => $row['modified'] ?? null,
            ];
        }

        return $this->okList($courses, ['count' => count($courses)]);
    }

    /**
     * グループにコースを割り当てる
     *
     * @param int $id グループID
     * @return \Cake\Http\Response
     */
    public function assignCourse(int $id): Response
    {
        $this->requireManager();

        $groupsTable = $this->fetchTable('Groups');

        // グループ存在チェック
        if (!$groupsTable->exists(['id' => $id])) {
            $this->fail(404, 'Group not found');
        }

        $input = $this->input();

        if (!isset($input['course_id']) || $input['course_id'] === '' || $input['course_id'] === null) {
            $this->fail(400, 'course_id is required');
        }

        $courseId = (int)$input['course_id'];

        // コース存在チェック
        $coursesTable = $this->fetchTable('Courses');

        if (!$coursesTable->exists(['id' => $courseId])) {
            $this->fail(404, 'Course not found');
        }

        // 既に割当済みかチェック
        $groupsCoursesTable = $this->fetchTable('GroupsCourses');
        $existing = $groupsCoursesTable->find()
            ->where([
                'group_id' => $id,
                'course_id' => $courseId,
            ])
            ->first();

        if ($existing) {
            return $this->ok(['group_id' => $id, 'course_id' => $courseId, 'assigned' => true, 'created' => false]);
        }

        $entity = $groupsCoursesTable->newEntity([
            'group_id' => $id,
            'course_id' => $courseId,
        ]);

        if (!$groupsCoursesTable->save($entity)) {
            $this->fail(500, 'Failed to assign course');
        }

        return $this->ok(['group_id' => $id, 'course_id' => $courseId, 'assigned' => true, 'created' => true], 201);
    }

    /**
     * グループのコース割当を解除する
     *
     * @param int $id グループID
     * @param int $courseId コースID
     * @return \Cake\Http\Response
     */
    public function unassignCourse(int $id, int $courseId): Response
    {
        $this->requireManager();

        $groupsTable = $this->fetchTable('Groups');

        // グループ存在チェック
        if (!$groupsTable->exists(['id' => $id])) {
            $this->fail(404, 'Group not found');
        }

        $deleted = false;

        $groupsCoursesTable = $this->fetchTable('GroupsCourses');
        $existing = $groupsCoursesTable->find()
            ->where([
                'group_id' => $id,
                'course_id' => $courseId,
            ])
            ->first();

        if ($existing) {
            $deleted = (bool)$groupsCoursesTable->delete($existing);
        }

        return $this->ok(['group_id' => $id, 'course_id' => $courseId, 'deleted' => $deleted]);
    }
}
