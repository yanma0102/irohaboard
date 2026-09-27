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
}
