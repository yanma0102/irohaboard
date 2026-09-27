<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * CoursesController API のテスト
 *
 * コース更新（edit）および CRUD 流れの統合テスト
 */
class CoursesControllerTest extends TestCase
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
     * テスト用コースを作成して返す
     */
    private function createCourse(string $title = 'テストコース', int $userId = 0, array $overrides = []): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $data = array_merge([
            'title' => $title,
            'introduction' => 'テスト用コースです',
            'opened' => '2024-01-01',
            'comment' => 'コメント',
            'sort_no' => 1,
            'user_id' => $userId,
        ], $overrides);

        $entity = $coursesTable->newEntity($data);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result, "コース {$title} の作成に失敗");

        return $result;
    }

    // ----------------------------------------------------------------
    // PUT/PATCH /api/v1/courses/{id} (edit)
    // ----------------------------------------------------------------

    /**
     * 正常系: PUT でコース更新 → 200
     */
    public function testEditSuccess(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $course = $this->createCourse('更新前コース', (int)$this->getTableLocator()->get('Users')->find()->firstOrFail()->id);

        $this->put('/api/v1/courses/' . $course->id, [
            'title' => '更新後コース',
            'comment' => '更新済み',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame('更新後コース', $body['data']['title']);
        $this->assertSame('更新済み', $body['data']['comment']);
    }

    /**
     * 正常系: PATCH でもコース更新 → 200
     */
    public function testEditPatchSuccess(): void
    {
        $this->createUser('admin02', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin02', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $course = $this->createCourse('PATCH前コース', (int)$this->getTableLocator()->get('Users')->find()->firstOrFail()->id);

        $this->patch('/api/v1/courses/' . $course->id, [
            'title' => 'PATCH後コース',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('PATCH後コース', $body['data']['title']);
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testEditNotFound(): void
    {
        $this->createUser('admin03', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin03', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/courses/99999', [
            'title' => 'Not Found',
        ]);

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('Course not found', $body['error']['message']);
    }

    /**
     * 異常系: title 未指定 → 400 バリデーションエラー
     */
    public function testEditValidationError(): void
    {
        $this->createUser('admin04', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin04', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $course = $this->createCourse('バリデーションテスト', (int)$this->getTableLocator()->get('Users')->find()->firstOrFail()->id);

        $this->put('/api/v1/courses/' . $course->id, [
            'title' => '',
        ]);

        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('Validation failed', $body['error']['message']);
    }

    /**
     * 異常系: 一般ユーザ → 403 Forbidden
     */
    public function testEditForbiddenForUser(): void
    {
        $this->createUser('user01', ['role' => 'user']);
        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $course = $this->createCourse('Forbidden', (int)$this->getTableLocator()->get('Users')->find()->firstOrFail()->id);

        $this->put('/api/v1/courses/' . $course->id, [
            'title' => 'Forbidden Edit',
        ]);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(403, $body['error']['code']);
    }

    // ----------------------------------------------------------------
    // POST → PUT → DELETE の一連フロー
    // ----------------------------------------------------------------

    /**
     * コースの作成→更新→削除の一連が通ること
     */
    public function testAddEditDeleteFlow(): void
    {
        $this->createUser('admin05', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin05', 'testpass');

        // POST: 作成
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->post('/api/v1/courses', [
            'title' => 'フローテストコース',
            'comment' => '作成時',
        ]);
        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $courseId = $body['data']['id'];
        $this->assertSame('フローテストコース', $body['data']['title']);

        // PUT: 更新
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->put('/api/v1/courses/' . $courseId, [
            'title' => '更新済みフロー',
            'comment' => '更新時',
        ]);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('更新済みフロー', $body['data']['title']);

        // DELETE: 削除
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->delete('/api/v1/courses/' . $courseId);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($body['data']['deleted']);

        // 削除後に GET → 404
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->get('/api/v1/courses/' . $courseId);
        $this->assertResponseCode(404);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/courses (index)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でコース一覧取得 → 200 + JSON が配列
     */
    public function testIndexAsAdmin(): void
    {
        $this->createUser('admin10', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin10', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $adminUser = $this->getTableLocator()->get('Users')->find()->firstOrFail();
        $this->createCourse('一覧テストコース', (int)$adminUser->id);

        $this->get('/api/v1/courses');

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);
        $this->assertNotEmpty($body['data']);
    }

    /**
     * 正常系: 一般ユーザでコース一覧取得 → 200 + JSON が配列
     */
    public function testIndexAsUser(): void
    {
        $this->createUser('user10', ['role' => 'user']);
        $tokenData = $this->issueToken('user10', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/courses');

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/courses/{id} (view)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でコース詳細取得 → 200 + JSON に id, title が含まれる
     */
    public function testView(): void
    {
        $this->createUser('admin11', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin11', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $adminUser = $this->getTableLocator()->get('Users')->find()->firstOrFail();
        $course = $this->createCourse('詳細テストコース', (int)$adminUser->id);

        $this->get('/api/v1/courses/' . $course->id);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('id', $body['data']);
        $this->assertArrayHasKey('title', $body['data']);
        $this->assertSame($course->id, $body['data']['id']);
        $this->assertSame('詳細テストコース', $body['data']['title']);
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testViewNotFound(): void
    {
        $this->createUser('admin12', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin12', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/courses/99999');

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('Course not found', $body['error']['message']);
    }

    // ----------------------------------------------------------------
    // POST /api/v1/courses (add)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でコース作成 → 201 + DB にレコード作成
     */
    public function testAdd(): void
    {
        $this->createUser('admin13', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin13', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/courses', [
            'title' => '新規テストコース',
            'comment' => '作成テスト',
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame('新規テストコース', $body['data']['title']);

        // DB にレコードが作成されたことを確認
        $coursesTable = $this->getTableLocator()->get('Courses');
        $this->assertTrue(
            $coursesTable->exists(['title' => '新規テストコース']),
            'ib_courses にレコードが作成されている',
        );
    }

    // ----------------------------------------------------------------
    // DELETE /api/v1/courses/{id} (delete)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でコース削除 → 200 + DB からレコード削除
     */
    public function testDelete(): void
    {
        $this->createUser('admin14', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin14', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $adminUser = $this->getTableLocator()->get('Users')->find()->firstOrFail();
        $course = $this->createCourse('削除テストコース', (int)$adminUser->id);

        $this->delete('/api/v1/courses/' . $course->id);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame($course->id, $body['data']['id']);
        $this->assertTrue($body['data']['deleted']);

        // DB からレコードが削除されたことを確認
        $coursesTable = $this->getTableLocator()->get('Courses');
        $this->assertFalse(
            $coursesTable->exists(['id' => $course->id]),
            'ib_courses の該当行が削除されている',
        );
    }
}
