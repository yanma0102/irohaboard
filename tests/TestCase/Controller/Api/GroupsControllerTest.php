<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * GroupsController API のテスト
 *
 * グループ CRUD（add/edit/delete）の統合テスト
 */
class GroupsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * setUp: 各テスト前にテーブルをクリーンアップ
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->getTableLocator()->get('UserTokens')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('GroupsCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersGroups')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Groups')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Courses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Logs')->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    /**
     * テスト用ユーザを作成して返す
     */
    private function createUser(string $username = 'testuser', array $overrides = []): \Cake\Datasource\EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $data = array_merge([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テスト太郎',
            'role' => 'user',
            'email' => $username . '@example.com',
        ], $overrides);

        $entity = $usersTable->newEntity($data);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result, "ユーザ {$username} の作成に失敗");

        return $result;
    }

    /**
     * issueToken でトークンを発行し、レスポンスボディを返す
     */
    private function issueToken(string $username, string $password): array
    {
        $this->post('/api/v1/auth/token', [
            'username' => $username,
            'password' => $password,
        ]);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body, 'レスポンスに data キーが存在する');

        return $body['data'];
    }

    /**
     * テスト用グループを作成して返す
     */
    private function createGroup(string $title = 'テストグループ', array $overrides = []): \Cake\Datasource\EntityInterface
    {
        $groupsTable = $this->getTableLocator()->get('Groups');
        $data = array_merge([
            'title' => $title,
            'comment' => 'テスト用グループです',
            'status' => 1,
        ], $overrides);

        $entity = $groupsTable->newEntity($data);
        $result = $groupsTable->save($entity);
        $this->assertNotFalse($result, "グループ {$title} の作成に失敗");

        return $result;
    }

    // ----------------------------------------------------------------
    // POST /api/v1/groups (add)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でグループ作成 → 201
     */
    public function testAddSuccess(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/groups', [
            'title' => '新規グループ',
            'comment' => 'テスト',
            'status' => 1,
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame('新規グループ', $body['data']['title']);
        $this->assertArrayHasKey('id', $body['data']);
        $this->assertIsInt($body['data']['id']);
    }

    /**
     * 異常系: title 未指定 → 400 バリデーションエラー
     */
    public function testAddValidationError(): void
    {
        $this->createUser('admin02', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin02', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/groups', [
            'comment' => 'title なし',
        ]);

        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(400, $body['error']['code']);
        $this->assertSame('Validation failed', $body['error']['message']);
    }

    /**
     * 異常系: 一般ユーザ → 403 Forbidden
     */
    public function testAddForbiddenForUser(): void
    {
        $this->createUser('user01', ['role' => 'user']);
        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/groups', [
            'title' => 'Forbidden',
        ]);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(403, $body['error']['code']);
    }

    /**
     * 異常系: トークンなし → 401
     */
    public function testAddUnauthenticated(): void
    {
        $this->post('/api/v1/groups', [
            'title' => 'NoAuth',
        ]);

        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(401, $body['error']['code']);
    }

    // ----------------------------------------------------------------
    // PUT/PATCH /api/v1/groups/{id} (edit)
    // ----------------------------------------------------------------

    /**
     * 正常系: PUT でグループ更新 → 200
     */
    public function testEditSuccess(): void
    {
        $this->createUser('admin03', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin03', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $group = $this->createGroup('更新前グループ');

        $this->put('/api/v1/groups/' . $group->id, [
            'title' => '更新後グループ',
            'comment' => '更新済み',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame('更新後グループ', $body['data']['title']);
        $this->assertSame('更新済み', $body['data']['comment']);
    }

    /**
     * 正常系: PATCH でもグループ更新 → 200
     */
    public function testEditPatchSuccess(): void
    {
        $this->createUser('admin04', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin04', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $group = $this->createGroup('PATCH前グループ');

        $this->patch('/api/v1/groups/' . $group->id, [
            'title' => 'PATCH後グループ',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('PATCH後グループ', $body['data']['title']);
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testEditNotFound(): void
    {
        $this->createUser('admin05', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin05', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/groups/99999', [
            'title' => 'Not Found',
        ]);

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('Group not found', $body['error']['message']);
    }

    // ----------------------------------------------------------------
    // DELETE /api/v1/groups/{id} (delete)
    // ----------------------------------------------------------------

    /**
     * 正常系: グループ削除 → 200 + 中間テーブルも削除確認
     */
    public function testDeleteSuccess(): void
    {
        $this->createUser('admin06', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin06', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $group = $this->createGroup('削除対象グループ');

        // ユーザを作成（コースの user_id に使用）
        $testUser = $this->createUser('member01');

        // コースと中間テーブルにデータを作成（user_id は NOT NULL）
        $coursesTable = $this->getTableLocator()->get('Courses');
        $courseEntity = $coursesTable->newEntity([
            'title' => 'テストコース',
            'sort_no' => 1,
            'user_id' => (int)$testUser->id,
        ]);
        $course = $coursesTable->save($courseEntity);
        $this->assertNotFalse($course);

        $groupsCoursesTable = $this->getTableLocator()->get('GroupsCourses');
        $gcEntity = $groupsCoursesTable->newEntity([
            'group_id' => $group->id,
            'course_id' => $course->id,
        ]);
        $groupsCoursesTable->save($gcEntity);

        // ユーザと中間テーブルにデータを作成
        $usersGroupsTable = $this->getTableLocator()->get('UsersGroups');
        $ugEntity = $usersGroupsTable->newEntity([
            'user_id' => $testUser->id,
            'group_id' => $group->id,
        ]);
        $usersGroupsTable->save($ugEntity);

        // 削除実行
        $this->delete('/api/v1/groups/' . $group->id);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame($group->id, $body['data']['id']);
        $this->assertTrue($body['data']['deleted']);

        // 中間テーブルが削除されていることを確認
        $this->assertFalse(
            $groupsCoursesTable->exists(['group_id' => $group->id]),
            'ib_groups_courses の該当行が削除されている'
        );
        $this->assertFalse(
            $usersGroupsTable->exists(['group_id' => $group->id]),
            'ib_users_groups の該当行が削除されている'
        );
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testDeleteNotFound(): void
    {
        $this->createUser('admin07', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin07', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/groups/99999');

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Group not found', $body['error']['message']);
    }

    /**
     * 異常系: 一般ユーザ → 403 Forbidden
     */
    public function testDeleteForbiddenForUser(): void
    {
        $this->createUser('user02', ['role' => 'user']);
        $tokenData = $this->issueToken('user02', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $group = $this->createGroup('削除不可グループ');

        $this->delete('/api/v1/groups/' . $group->id);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(403, $body['error']['code']);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/groups/{id} (view)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でグループ詳細取得 → 200 + JSON に id, title が含まれる
     */
    public function testView(): void
    {
        $this->createUser('admin10', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin10', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $group = $this->createGroup('詳細テストグループ');

        $this->get('/api/v1/groups/' . $group->id);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('id', $body['data']);
        $this->assertArrayHasKey('title', $body['data']);
        $this->assertSame($group->id, $body['data']['id']);
        $this->assertSame('詳細テストグループ', $body['data']['title']);
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testViewNotFound(): void
    {
        $this->createUser('admin11', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin11', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/groups/99999');

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('Group not found', $body['error']['message']);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/groups/{id}/users (users)
    // ----------------------------------------------------------------

    /**
     * 正常系: グループ所属ユーザ一覧取得 → 200 + JSON が配列
     */
    public function testUsers(): void
    {
        $this->createUser('admin12', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin12', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $group = $this->createGroup('ユーザ一覧テストグループ');

        $this->get('/api/v1/groups/' . $group->id . '/users');

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);
    }

    // ----------------------------------------------------------------
    // POST /api/v1/groups/{id}/users (assignUser)
    // ----------------------------------------------------------------

    /**
     * 正常系: グループにユーザを割り当て → 200 + DB にレコード作成
     */
    public function testAssignUser(): void
    {
        $this->createUser('admin13', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin13', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $group = $this->createGroup('割当テストグループ');
        $user = $this->createUser('assignuser01');

        $this->post('/api/v1/groups/' . $group->id . '/users', [
            'user_id' => $user->id,
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertTrue($body['data']['assigned']);
        $this->assertTrue($body['data']['created']);

        // DB にレコードが作成されたことを確認
        $usersGroupsTable = $this->getTableLocator()->get('UsersGroups');
        $this->assertTrue(
            $usersGroupsTable->exists(['user_id' => $user->id, 'group_id' => $group->id]),
            'ib_users_groups にレコードが作成されている'
        );
    }

    // ----------------------------------------------------------------
    // DELETE /api/v1/groups/{id}/users/{user_id} (unassignUser)
    // ----------------------------------------------------------------

    /**
     * 正常系: グループからユーザを解除 → 200 + DB からレコード削除
     */
    public function testUnassignUser(): void
    {
        $this->createUser('admin14', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin14', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $group = $this->createGroup('解除テストグループ');
        $user = $this->createUser('unassignuser01');

        // 事前にユーザを割り当て
        $usersGroupsTable = $this->getTableLocator()->get('UsersGroups');
        $ugEntity = $usersGroupsTable->newEntity([
            'user_id' => $user->id,
            'group_id' => $group->id,
        ]);
        $usersGroupsTable->save($ugEntity);
        $this->assertTrue(
            $usersGroupsTable->exists(['user_id' => $user->id, 'group_id' => $group->id]),
            '事前確認: ib_users_groups にレコードが存在する'
        );

        $this->delete('/api/v1/groups/' . $group->id . '/users/' . $user->id);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertTrue($body['data']['deleted']);

        // DB からレコードが削除されたことを確認
        $this->assertFalse(
            $usersGroupsTable->exists(['user_id' => $user->id, 'group_id' => $group->id]),
            'ib_users_groups の該当行が削除されている'
        );
    }
}
