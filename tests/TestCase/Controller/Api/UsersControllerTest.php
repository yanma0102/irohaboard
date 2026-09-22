<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * UsersController API のテスト
 *
 * ユーザ CRUD + コース割当 + パスワード変更の統合テスト
 */
class UsersControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * setUp: 各テスト前にテーブルをクリーンアップ
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->getTableLocator()->get('UserTokens')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Records')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Contents')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Courses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
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
     * テスト用コースを作成して返す（user_id は NOT NULL）
     */
    private function createCourse(string $title = 'テストコース', int $userId = 0, array $overrides = []): \Cake\Datasource\EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $data = array_merge([
            'title' => $title,
            'sort_no' => 1,
            'user_id' => $userId,
        ], $overrides);

        $entity = $coursesTable->newEntity($data);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result, "コース {$title} の作成に失敗");

        return $result;
    }

    // ----------------------------------------------------------------
    // GET /api/v1/users (index)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でユーザ一覧取得 → 200
     */
    public function testIndexAsAdmin(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $this->createUser('user01', ['role' => 'user']);
        $this->createUser('user02', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertCount(3, $body['data']);
        $this->assertSame(3, $body['meta']['total']);
    }

    /**
     * 正常系: 一般ユーザは自分のみ取得 → 200
     */
    public function testIndexAsUserReturnsOnlySelf(): void
    {
        $this->createUser('user01', ['role' => 'user']);
        $this->createUser('user02', ['role' => 'user']);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('user01', $body['data'][0]['username']);
    }

    /**
     * 正常系: username でフィルタ → 該当ユーザのみ
     */
    public function testIndexFilterByUsername(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $this->createUser('target01', ['role' => 'user']);
        $this->createUser('target02', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users?username=target01');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('target01', $body['data'][0]['username']);
    }

    /**
     * 正常系: name でフィルタ（部分一致）
     */
    public function testIndexFilterByName(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $this->createUser('tanaka', ['name' => '田中太郎']);
        $this->createUser('suzuki', ['name' => '鈴木花子']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users?name=田中');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('tanaka', $body['data'][0]['username']);
    }

    /**
     * 正常系: role でフィルタ
     */
    public function testIndexFilterByRole(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $this->createUser('user01', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users?role=user');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('user', $body['data'][0]['role']);
    }

    /**
     * 正常系: password フィールドがレスポンスに含まれない
     */
    public function testIndexDoesNotExposePassword(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        foreach ($body['data'] as $user) {
            $this->assertArrayNotHasKey('password', $user);
        }
    }

    /**
     * 正常系: ページング
     */
    public function testIndexPagination(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        for ($i = 1; $i <= 3; $i++) {
            $this->createUser("pageuser{$i}", ['role' => 'user']);
        }

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users?page=1&limit=2');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(2, $body['data']);
        $this->assertSame(4, $body['meta']['total']);
        $this->assertSame(1, $body['meta']['page']);
    }

    /**
     * 異常系: トークンなし → 401
     */
    public function testIndexUnauthenticated(): void
    {
        $this->get('/api/v1/users');
        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(401, $body['error']['code']);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/users/{id} (view)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin がユーザ詳細を取得 → 200
     */
    public function testViewAsAdmin(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users/' . $target->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame('target01', $body['data']['username']);
        $this->assertArrayNotHasKey('password', $body['data']);
    }

    /**
     * 正常系: 一般ユーザが自分自身を取得 → 200
     */
    public function testViewSelfAsUser(): void
    {
        $user = $this->createUser('user01', ['role' => 'user']);
        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users/' . $user->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('user01', $body['data']['username']);
    }

    /**
     * 異常系: 一般ユーザが他人の詳細を取得 → 403
     */
    public function testViewOtherUserForbiddenForUser(): void
    {
        $this->createUser('user01', ['role' => 'user']);
        $other = $this->createUser('user02', ['role' => 'user']);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users/' . $other->id);
        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(403, $body['error']['code']);
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testViewNotFound(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users/99999');
        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('User not found', $body['error']['message']);
    }

    /**
     * 異常系: トークンなし → 401
     */
    public function testViewUnauthenticated(): void
    {
        $this->get('/api/v1/users/1');
        $this->assertResponseCode(401);
    }

    // ----------------------------------------------------------------
    // POST /api/v1/users (add)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でユーザ作成 → 201
     */
    public function testAddSuccess(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/users', [
            'username' => 'newuser01',
            'password' => 'newpass01',
            'name' => '新規太郎',
            'role' => 'user',
            'email' => 'newuser01@example.com',
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame('newuser01', $body['data']['username']);
        $this->assertSame('新規太郎', $body['data']['name']);
        $this->assertArrayNotHasKey('password', $body['data']);
    }

    /**
     * 異常系: manager でもユーザ作成可能 → 201
     */
    public function testAddSuccessAsManager(): void
    {
        $this->createUser('mgr01', ['role' => 'manager']);
        $tokenData = $this->issueToken('mgr01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/users', [
            'username' => 'newuser02',
            'password' => 'newpass02',
            'name' => 'マネージャ作成',
            'role' => 'user',
        ]);

        $this->assertResponseCode(201);
    }

    /**
     * 異常系: バリデーションエラー（username 未指定）→ 400
     */
    public function testAddValidationError(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/users', [
            'name' => 'ユーザ名のみ',
        ]);

        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
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

        $this->post('/api/v1/users', [
            'username' => 'forbidden_user',
            'password' => 'pass',
            'name' => 'Forbidden',
        ]);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(403, $body['error']['code']);
    }

    /**
     * 異常系: editor → 403 Forbidden
     */
    public function testAddForbiddenForEditor(): void
    {
        $this->createUser('editor01', ['role' => 'editor']);
        $tokenData = $this->issueToken('editor01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/users', [
            'username' => 'forbidden_editor',
            'password' => 'pass',
            'name' => 'Forbidden',
        ]);

        $this->assertResponseCode(403);
    }

    /**
     * 異常系: トークンなし → 401
     */
    public function testAddUnauthenticated(): void
    {
        $this->post('/api/v1/users', [
            'username' => 'noauth',
        ]);

        $this->assertResponseCode(401);
    }

    // ----------------------------------------------------------------
    // PUT/PATCH /api/v1/users/{id} (edit)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でユーザ更新 → 200
     */
    public function testEditSuccess(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/' . $target->id, [
            'name' => '更新後太郎',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('更新後太郎', $body['data']['name']);
    }

    /**
     * 正常系: PATCH でも更新可能 → 200
     */
    public function testEditPatchSuccess(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->patch('/api/v1/users/' . $target->id, [
            'name' => 'PATCH後太郎',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('PATCH後太郎', $body['data']['name']);
    }

    /**
     * 正常系: manager でユーザ更新 → 200
     */
    public function testEditSuccessAsManager(): void
    {
        $this->createUser('mgr01', ['role' => 'manager']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('mgr01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/' . $target->id, [
            'name' => 'マネージャ更新',
        ]);

        $this->assertResponseOk();
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testEditNotFound(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/99999', [
            'name' => 'Not Found',
        ]);

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('User not found', $body['error']['message']);
    }

    /**
     * 異常系: 一般ユーザ → 403 Forbidden
     */
    public function testEditForbiddenForUser(): void
    {
        $this->createUser('user01', ['role' => 'user']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/' . $target->id, [
            'name' => 'Forbidden',
        ]);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(403, $body['error']['code']);
    }

    /**
     * 異常系: 自分自身のロール変更は禁止 → 403
     */
    public function testEditSelfRoleChangeForbidden(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        // admin01 自身の ID を取得
        $adminUser = $this->getTableLocator()->get('Users')->find()->where(['username' => 'admin01'])->firstOrFail();

        $this->put('/api/v1/users/' . $adminUser->id, [
            'role' => 'user',
        ]);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Cannot change your own role', $body['error']['message']);
    }

    /**
     * 異常系: manager が管理者アカウントを変更 → 403
     */
    public function testEditAdminAccountByManagerForbidden(): void
    {
        $this->createUser('mgr01', ['role' => 'manager']);
        $adminTarget = $this->createUser('admintg', ['role' => 'admin']);

        $tokenData = $this->issueToken('mgr01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/' . $adminTarget->id, [
            'name' => '変更不可',
        ]);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Only administrators can modify an administrator account', $body['error']['message']);
    }

    /**
     * 異常系: manager が admin に昇格させようとする → 403
     */
    public function testEditGrantAdminRoleByManagerForbidden(): void
    {
        $this->createUser('mgr01', ['role' => 'manager']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('mgr01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/' . $target->id, [
            'role' => 'admin',
        ]);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Only administrators can grant the admin role', $body['error']['message']);
    }

    /**
     * 異常系: admin が他の admin のロール変更は可 → 200
     */
    public function testEditAdminCanChangeOtherAdminRole(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $adminTarget = $this->createUser('admintg2', ['role' => 'admin']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/' . $adminTarget->id, [
            'role' => 'manager',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('manager', $body['data']['role']);
    }

    /**
     * 異常系: 更新フィールドなし → 400
     */
    public function testEditNoFieldsProvided(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/' . $target->id, []);

        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('No updatable fields were provided', $body['error']['message']);
    }

    // ----------------------------------------------------------------
    // DELETE /api/v1/users/{id} (delete)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でユーザ削除 → 200
     */
    public function testDeleteSuccess(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/users/' . $target->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame($target->id, $body['data']['id']);
        $this->assertTrue($body['data']['deleted']);
    }

    /**
     * 正常系: manager でユーザ削除 → 200
     */
    public function testDeleteSuccessAsManager(): void
    {
        $this->createUser('mgr01', ['role' => 'manager']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('mgr01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/users/' . $target->id);
        $this->assertResponseOk();
    }

    /**
     * 異常系: 自分自身を削除 → 400
     */
    public function testDeleteSelfForbidden(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $adminUser = $this->getTableLocator()->get('Users')->find()->where(['username' => 'admin01'])->firstOrFail();

        $this->delete('/api/v1/users/' . $adminUser->id);
        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Cannot delete your own account', $body['error']['message']);
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testDeleteNotFound(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/users/99999');
        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('User not found', $body['error']['message']);
    }

    /**
     * 異常系: 一般ユーザ → 403 Forbidden
     */
    public function testDeleteForbiddenForUser(): void
    {
        $this->createUser('user01', ['role' => 'user']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/users/' . $target->id);
        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(403, $body['error']['code']);
    }

    // ----------------------------------------------------------------
    // PUT/PATCH /api/v1/users/{id}/password (changePassword)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でパスワード変更 → 200
     */
    public function testChangePasswordSuccess(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/' . $target->id . '/password', [
            'password' => 'newpass123',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertTrue($body['data']['password_changed']);
    }

    /**
     * 正常系: new_password パラメータでも変更可能 → 200
     */
    public function testChangePasswordWithNewPasswordParam(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/' . $target->id . '/password', [
            'new_password' => 'newpass456',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($body['data']['password_changed']);
    }

    /**
     * 異常系: パスワード未指定 → 400
     */
    public function testChangePasswordMissing(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/' . $target->id . '/password', []);

        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('password is required', $body['error']['message']);
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testChangePasswordNotFound(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/99999/password', [
            'password' => 'newpass',
        ]);

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('User not found', $body['error']['message']);
    }

    /**
     * 異常系: 一般ユーザ → 403 Forbidden
     */
    public function testChangePasswordForbiddenForUser(): void
    {
        $this->createUser('user01', ['role' => 'user']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/' . $target->id . '/password', [
            'password' => 'newpass',
        ]);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(403, $body['error']['code']);
    }

    /**
     * 異常系: manager が管理者のパスワード変更 → 403
     */
    public function testChangePasswordAdminAccountByManagerForbidden(): void
    {
        $this->createUser('mgr01', ['role' => 'manager']);
        $adminTarget = $this->createUser('admintg3', ['role' => 'admin']);

        $tokenData = $this->issueToken('mgr01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/users/' . $adminTarget->id . '/password', [
            'password' => 'newpass',
        ]);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Only administrators can modify an administrator account', $body['error']['message']);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/users/{id}/courses (courses)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin がユーザのコース一覧を取得 → 200
     */
    public function testCoursesAsAdmin(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);
        $course = $this->createCourse('テストコース', (int)$target->id);

        // コース割当
        $usersCoursesTable = $this->getTableLocator()->get('UsersCourses');
        $ucEntity = $usersCoursesTable->newEntity([
            'user_id' => $target->id,
            'course_id' => $course->id,
        ]);
        $usersCoursesTable->save($ucEntity);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users/' . $target->id . '/courses');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertCount(1, $body['data']);
        $this->assertSame('テストコース', $body['data'][0]['title']);
    }

    /**
     * 正常系: 一般ユーザが自分のコース一覧を取得 → 200
     */
    public function testCoursesSelfAsUser(): void
    {
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('自分のコース', (int)$user->id);

        $usersCoursesTable = $this->getTableLocator()->get('UsersCourses');
        $ucEntity = $usersCoursesTable->newEntity([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $usersCoursesTable->save($ucEntity);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users/' . $user->id . '/courses');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $body['data']);
    }

    /**
     * 異常系: 一般ユーザが他人のコース一覧を取得 → 403
     */
    public function testCoursesOtherUserForbidden(): void
    {
        $this->createUser('user01', ['role' => 'user']);
        $other = $this->createUser('user02', ['role' => 'user']);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users/' . $other->id . '/courses');
        $this->assertResponseCode(403);
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testCoursesNotFound(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/users/99999/courses');
        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('User not found', $body['error']['message']);
    }

    // ----------------------------------------------------------------
    // POST /api/v1/users/{id}/courses (assignCourse)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でコース割当 → 201
     */
    public function testAssignCourseSuccess(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);
        $course = $this->createCourse('割当コース', (int)$target->id);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/users/' . $target->id . '/courses', [
            'course_id' => $course->id,
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($body['data']['assigned']);
        $this->assertTrue($body['data']['created']);
    }

    /**
     * 正常系: 既に割当済みの場合 → 200 + created=false
     */
    public function testAssignCourseAlreadyAssigned(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);
        $course = $this->createCourse('既存コース', (int)$target->id);

        // 事前に割当
        $usersCoursesTable = $this->getTableLocator()->get('UsersCourses');
        $ucEntity = $usersCoursesTable->newEntity([
            'user_id' => $target->id,
            'course_id' => $course->id,
        ]);
        $usersCoursesTable->save($ucEntity);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/users/' . $target->id . '/courses', [
            'course_id' => $course->id,
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($body['data']['assigned']);
        $this->assertFalse($body['data']['created']);
    }

    /**
     * 異常系: course_id 未指定 → 400
     */
    public function testAssignCourseMissingCourseId(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/users/' . $target->id . '/courses', []);

        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('course_id is required', $body['error']['message']);
    }

    /**
     * 異常系: 存在しないユーザ → 404
     */
    public function testAssignCourseUserNotFound(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('孤児コース', 1);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/users/99999/courses', [
            'course_id' => $course->id,
        ]);

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('User not found', $body['error']['message']);
    }

    /**
     * 異常系: 存在しないコース → 404
     */
    public function testAssignCourseCourseNotFound(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/users/' . $target->id . '/courses', [
            'course_id' => 99999,
        ]);

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Course not found', $body['error']['message']);
    }

    /**
     * 異常系: 一般ユーザ → 403 Forbidden
     */
    public function testAssignCourseForbiddenForUser(): void
    {
        $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('Forbidden', 1);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/users/1/courses', [
            'course_id' => $course->id,
        ]);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(403, $body['error']['code']);
    }

    // ----------------------------------------------------------------
    // DELETE /api/v1/users/{id}/courses/{course_id} (unassignCourse)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でコース割当解除 → 200
     */
    public function testUnassignCourseSuccess(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);
        $course = $this->createCourse('解除コース', (int)$target->id);

        // 事前に割当
        $usersCoursesTable = $this->getTableLocator()->get('UsersCourses');
        $ucEntity = $usersCoursesTable->newEntity([
            'user_id' => $target->id,
            'course_id' => $course->id,
        ]);
        $usersCoursesTable->save($ucEntity);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/users/' . $target->id . '/courses/' . $course->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($body['data']['deleted']);

        // 削除されたことを DB で確認
        $this->assertFalse(
            $usersCoursesTable->exists([
                'user_id' => $target->id,
                'course_id' => $course->id,
            ]),
            'ib_users_courses の該当行が削除されている'
        );
    }

    /**
     * 正常系: 割当がない場合でも deleted=false で 200 を返す
     */
    public function testUnassignCourseNotAssigned(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);
        $course = $this->createCourse('未割当コース', (int)$target->id);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/users/' . $target->id . '/courses/' . $course->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertFalse($body['data']['deleted']);
    }

    /**
     * 異常系: 存在しないユーザ → 404
     */
    public function testUnassignCourseUserNotFound(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/users/99999/courses/1');
        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('User not found', $body['error']['message']);
    }

    /**
     * 異常系: 一般ユーザ → 403 Forbidden
     */
    public function testUnassignCourseForbiddenForUser(): void
    {
        $this->createUser('user01', ['role' => 'user']);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/users/1/courses/1');
        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(403, $body['error']['code']);
    }
}
