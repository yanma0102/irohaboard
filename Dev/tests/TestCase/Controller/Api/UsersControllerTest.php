<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\Datasource\EntityInterface;
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
    private function createUser(string $username = 'testuser', array $overrides = []): EntityInterface
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
    private function createCourse(string $title = 'テストコース', int $userId = 0, array $overrides = []): EntityInterface
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
            'ib_users_courses の該当行が削除されている',
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

    // ----------------------------------------------------------------
    // ユーザ無効化 (is_active) 回帰テスト
    // ----------------------------------------------------------------

    /**
     * 正常系: admin が一般ユーザを無効化 → レスポンス & DB で is_active=false
     */
    public function testApiDeactivateUser(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->patch('/api/v1/users/' . $target->id, ['is_active' => false]);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertFalse($body['data']['is_active'], 'レスポンスの is_active が false であること');

        // DB 上でも確認
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->get($target->id);
        $this->assertFalse((bool)$user->is_active, 'DB 上の is_active が false であること');
    }

    /**
     * 無効化されたユーザのトークンが拒否される
     *
     * 1. 一般ユーザでトークン発行 → アクセス 200
     * 2. admin が当該ユーザを無効化
     * 3. 同じ（古い）トークンでアクセス → 401
     */
    public function testDeactivatedUserTokenRejected(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);

        // 1. target01 でトークン発行し、アクセスが通ることを確認
        $userTokenData = $this->issueToken('target01', 'testpass');
        $oldToken = $userTokenData['token'];

        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $oldToken]]);
        $this->get('/api/v1/users/' . $target->id);
        $this->assertResponseOk();

        // 2. admin01 で target01 を無効化
        $adminTokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $adminTokenData['token']]]);

        $this->patch('/api/v1/users/' . $target->id, ['is_active' => false]);
        $this->assertResponseOk();

        // 3. 古いトークンでアクセス → 401
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $oldToken]]);
        $this->get('/api/v1/users/' . $target->id);
        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Invalid or expired API token', $body['error']['message']);
    }

    /**
     * 無効化後、そのユーザでトークン発行ができない
     */
    public function testDeactivatedUserCannotIssueToken(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $this->createUser('target01', ['role' => 'user']);

        // admin01 で target01 を無効化
        $adminTokenData = $this->issueToken('admin01', 'testpass');
        $target = $this->getTableLocator()->get('Users')->find()->where(['username' => 'target01'])->firstOrFail();
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $adminTokenData['token']]]);

        $this->patch('/api/v1/users/' . $target->id, ['is_active' => false]);
        $this->assertResponseOk();

        // 無効化された target01 でトークン発行 → 401
        $this->post('/api/v1/auth/token', [
            'username' => 'target01',
            'password' => 'testpass',
        ]);
        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Invalid credentials', $body['error']['message']);
    }

    /**
     * 自分自身の無効化は禁止 → 403
     */
    public function testCannotDeactivateSelf(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        // admin01 自身の ID を取得
        $adminUser = $this->getTableLocator()->get('Users')->find()->where(['username' => 'admin01'])->firstOrFail();

        $this->patch('/api/v1/users/' . $adminUser->id, ['is_active' => false]);
        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Cannot deactivate your own account', $body['error']['message']);
    }

    /**
     * 最後の有効な管理者の無効化は禁止 → 403
     *
     * シナリオ:
     * 1. admin01 と admin02 の2人の有効な admin がいる状態で admin02 を無効化 → 成功
     * 2. admin01 が最後の有効な admin になった後、admin02（既に無効）を再度無効化しようとする
     *    → adminCount=1（admin01 のみ）で 403
     */
    public function testCannotDeactivateLastAdmin(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $admin02 = $this->createUser('admin02', ['role' => 'admin']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        // 1. admin01 が admin02 を無効化 → 成功（有効な admin が2人なので）
        $this->patch('/api/v1/users/' . $admin02->id, ['is_active' => false]);
        $this->assertResponseOk();

        // admin02 が無効化されたことを確認
        $usersTable = $this->getTableLocator()->get('Users');
        $admin02Refreshed = $usersTable->get($admin02->id);
        $this->assertFalse((bool)$admin02Refreshed->is_active);

        // 2. admin01（最後の有効な admin）が admin02 を再度無効化しようとする
        //    → adminCount=1（admin01 のみ有効）で 403
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->patch('/api/v1/users/' . $admin02->id, ['is_active' => false]);
        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Cannot deactivate the last active administrator', $body['error']['message']);
    }

    /**
     * 無効化されたユーザを再有効化 → 200 & DB で true、再トークン発行でアクセス可能
     */
    public function testReactivateUser(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $target = $this->createUser('target01', ['role' => 'user']);
        $targetId = $target->id;

        // admin01 で target01 を無効化
        $adminTokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $adminTokenData['token']]]);

        $this->patch('/api/v1/users/' . $targetId, ['is_active' => false]);
        $this->assertResponseOk();

        // 無効化状態を DB で確認
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->get($targetId);
        $this->assertFalse((bool)$user->is_active);

        // 再有効化
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $adminTokenData['token']]]);
        $this->patch('/api/v1/users/' . $targetId, ['is_active' => true]);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($body['data']['is_active'], 'レスポンスの is_active が true であること');

        // DB でも確認
        $user = $usersTable->get($targetId);
        $this->assertTrue((bool)$user->is_active, 'DB 上の is_active が true であること');

        // 再度トークンを発行してアクセスできることを確認
        $newTokenData = $this->issueToken('target01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $newTokenData['token']]]);

        $this->get('/api/v1/users/' . $targetId);
        $this->assertResponseOk();
    }
}
