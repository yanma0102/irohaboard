<?php
declare(strict_types=1);

/**
 * iroha Board REST API ユーザコントローラ
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
 * ApiUsers Controller
 * ユーザの一覧・詳細・追加・更新・削除・コース割当を提供する
 */
class UsersController extends BaseController
{
    /**
     * ユーザ一覧を取得する
     *
     * @return \Cake\Http\Response
     */
    public function index(): Response
    {
        $conditions = ['deleted IS NULL'];
        $currentUser = $this->currentUserId();
        $usersTable = $this->fetchTable('Users');

        // スタッフは全件参照可能、一般ユーザは自分のみ
        if ($this->isStaff()) {
            $username = $this->queryParam('username');
            if ($username !== null) {
                if ($this->wantsExact()) {
                    $conditions['username'] = $username;
                } else {
                    $conditions['username LIKE'] = '%' . $username . '%';
                }
            }

            $name = $this->queryParam('name');
            if ($name !== null) {
                if ($this->wantsExact()) {
                    $conditions['name'] = $name;
                } else {
                    $conditions['name LIKE'] = '%' . $name . '%';
                }
            }

            $role = $this->queryParam('role');
            if ($role !== null) {
                $conditions['role'] = $role;
            }
        } else {
            $conditions['id'] = $currentUser;
        }

        $fields = [
            'id', 'username', 'name', 'role',
            'email', 'comment', 'last_logined',
            'started', 'ended', 'created', 'modified',
        ];

        $order = ['id' => 'asc'];

        [$rows, $meta] = $this->paginatedList($usersTable, [
            'conditions' => $conditions,
            'fields' => $fields,
            'order' => $order,
        ]);

        // password を念のため除去
        foreach ($rows as &$row) {
            unset($row['password']);
        }

        return $this->okList($rows, $meta);
    }

    /**
     * ユーザ詳細を取得する
     *
     * @param int $id ユーザID
     * @return \Cake\Http\Response
     */
    public function view(int $id): Response
    {
        // スタッフは誰でも参照可能、一般ユーザは自分のみ
        if (!$this->isStaff() && $id !== $this->currentUserId()) {
            $this->fail(403, 'Forbidden');
        }

        $usersTable = $this->fetchTable('Users');

        // 存在チェック
        if (!$usersTable->exists(['id' => $id])) {
            $this->fail(404, 'User not found');
        }

        $user = $usersTable->get($id);

        $data = $user->toArray();
        unset($data['password']);

        return $this->ok($data);
    }

    /**
     * ユーザを追加する
     *
     * @return \Cake\Http\Response
     */
    public function add(): Response
    {
        $this->requireManager();

        $input = $this->input();
        $usersTable = $this->fetchTable('Users');

        $allowedFields = ['username', 'password', 'name', 'role', 'email', 'comment', 'started', 'ended'];
        $fields = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $fields[$field] = $input[$field];
            }
        }

        $entity = $usersTable->newEntity($fields);

        if ($usersTable->save($entity)) {
            $data = $entity->toArray();
            $data['id'] = (int)$data['id'];
            unset($data['password']);

            return $this->ok($data, 201);
        }

        $this->fail(400, 'Validation failed', $entity->getErrors());
    }

    /**
     * ユーザを更新する
     *
     * @param int $id ユーザID
     * @return \Cake\Http\Response
     */
    public function edit(int $id): Response
    {
        $this->requireManager();

        $usersTable = $this->fetchTable('Users');

        if (!$usersTable->exists(['id' => $id])) {
            $this->fail(404, 'User not found');
        }

        $target = $usersTable->get($id);
        $isAdmin = ($this->currentRole() === 'admin');

        // 管理者以外は管理者アカウントを変更不可
        if ($target->role === 'admin' && !$isAdmin) {
            $this->fail(403, 'Only administrators can modify an administrator account');
        }

        $input = $this->input();
        $allowed = ['role', 'name', 'email', 'comment', 'started', 'ended'];
        $fields = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $fields[$field] = $input[$field];
            }
        }

        if (empty($fields)) {
            $this->fail(400, 'No updatable fields were provided');
        }

        if (array_key_exists('role', $fields)) {
            // 自分のロール変更は禁止（ロックアウト防止）
            if ($id === $this->currentUserId()) {
                $this->fail(403, 'Cannot change your own role');
            }

            // admin への昇格は admin のみ
            if ($fields['role'] === 'admin' && !$isAdmin) {
                $this->fail(403, 'Only administrators can grant the admin role');
            }
        }

        $entity = $usersTable->patchEntity($target, $fields);

        if (!$usersTable->save($entity)) {
            $this->fail(400, 'Validation failed', $entity->getErrors());
        }

        $data = $entity->toArray();
        $data['id'] = (int)$data['id'];
        unset($data['password']);

        return $this->ok($data);
    }

    /**
     * パスワードを変更する
     *
     * @param int $id ユーザID
     * @return \Cake\Http\Response
     */
    public function changePassword(int $id): Response
    {
        $this->requireManager();

        $usersTable = $this->fetchTable('Users');

        if (!$usersTable->exists(['id' => $id])) {
            $this->fail(404, 'User not found');
        }

        $target = $usersTable->get($id);

        if ($target->role === 'admin' && $this->currentRole() !== 'admin') {
            $this->fail(403, 'Only administrators can modify an administrator account');
        }

        $input = $this->input();
        $password = null;

        // ポータル互換のため new_password も受け付ける
        if (isset($input['password']) && $input['password'] !== '') {
            $password = (string)$input['password'];
        } elseif (isset($input['new_password']) && $input['new_password'] !== '') {
            $password = (string)$input['new_password'];
        }

        if ($password === null) {
            $this->fail(400, 'password is required');
        }

        $entity = $usersTable->patchEntity($target, ['password' => $password]);

        if (!$usersTable->save($entity)) {
            $this->fail(400, 'Validation failed', $entity->getErrors());
        }

        // 変更対象ユーザのトークンを失効（Remember Me / API）
        $userTokensTable = $this->fetchTable('UserTokens');
        $userTokensTable->revokeAllForUser($id);
        $userTokensTable->revokeAllApiForUser($id);

        return $this->ok(['id' => $id, 'password_changed' => true]);
    }

    /**
     * ユーザを削除する
     *
     * @param int $id ユーザID
     * @return \Cake\Http\Response
     */
    public function delete(int $id): Response
    {
        $this->requireManager();

        $usersTable = $this->fetchTable('Users');

        if (!$usersTable->exists(['id' => $id])) {
            $this->fail(404, 'User not found');
        }

        // 自分自身は削除できない
        if ($id === $this->currentUserId()) {
            $this->fail(400, 'Cannot delete your own account');
        }

        $entity = $usersTable->get($id);
        $usersTable->delete($entity);

        return $this->ok(['id' => $id, 'deleted' => true]);
    }

    /**
     * ユーザに割当済みのコース一覧を取得する
     *
     * @param int $id ユーザID
     * @return \Cake\Http\Response
     */
    public function courses(int $id): Response
    {
        // スタッフは誰でも参照可能、一般ユーザは自分のみ
        if (!$this->isStaff() && $id !== $this->currentUserId()) {
            $this->fail(403, 'Forbidden');
        }

        $usersTable = $this->fetchTable('Users');

        // ユーザ存在チェック
        if (!$usersTable->exists(['id' => $id])) {
            $this->fail(404, 'User not found');
        }

        $connection = $usersTable->getConnection();

        $sql = 'SELECT c.id, c.title, c.introduction, c.opened,'
            . ' c.sort_no, c.user_id, c.created, c.modified'
            . ' FROM ib_courses c'
            . ' INNER JOIN ib_users_courses uc ON uc.course_id = c.id'
            . ' WHERE uc.user_id = :user_id'
            . ' AND c.deleted IS NULL'
            . ' ORDER BY c.sort_no asc';

        $result = $connection->execute($sql, ['user_id' => $id])->fetchAll('assoc');

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
     * ユーザにコースを割り当てる
     *
     * @param int $id ユーザID
     * @return \Cake\Http\Response
     */
    public function assignCourse(int $id): Response
    {
        $this->requireManager();

        $usersTable = $this->fetchTable('Users');

        // ユーザ存在チェック
        if (!$usersTable->exists(['id' => $id])) {
            $this->fail(404, 'User not found');
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
        $usersCoursesTable = $this->fetchTable('UsersCourses');
        $existing = $usersCoursesTable->find()
            ->where([
                'user_id' => $id,
                'course_id' => $courseId,
            ])
            ->first();

        if ($existing) {
            return $this->ok(['user_id' => $id, 'course_id' => $courseId, 'assigned' => true, 'created' => false]);
        }

        $entity = $usersCoursesTable->newEntity([
            'user_id' => $id,
            'course_id' => $courseId,
        ]);

        if (!$usersCoursesTable->save($entity)) {
            $this->fail(500, 'Failed to assign course');
        }

        return $this->ok(['user_id' => $id, 'course_id' => $courseId, 'assigned' => true, 'created' => true], 201);
    }

    /**
     * ユーザのコース割当を解除する
     *
     * @param int $id ユーザID
     * @param int $courseId コースID
     * @return \Cake\Http\Response
     */
    public function unassignCourse(int $id, int $courseId): Response
    {
        $this->requireManager();

        $usersTable = $this->fetchTable('Users');

        // ユーザ存在チェック
        if (!$usersTable->exists(['id' => $id])) {
            $this->fail(404, 'User not found');
        }

        $deleted = false;

        $usersCoursesTable = $this->fetchTable('UsersCourses');
        $existing = $usersCoursesTable->find()
            ->where([
                'user_id' => $id,
                'course_id' => $courseId,
            ])
            ->first();

        if ($existing) {
            $deleted = (bool)$usersCoursesTable->delete($existing);
        }

        return $this->ok(['user_id' => $id, 'course_id' => $courseId, 'deleted' => $deleted]);
    }
}
