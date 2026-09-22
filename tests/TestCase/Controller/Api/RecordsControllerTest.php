<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * RecordsController API のテスト
 *
 * 学習記録一覧・詳細の統合テスト
 */
class RecordsControllerTest extends TestCase
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
     * テスト用コースを作成して返す
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

    /**
     * テスト用コンテンツを作成して返す
     */
    private function createContent(int $courseId, int $userId, array $overrides = []): \Cake\Datasource\EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $data = array_merge([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テストコンテンツ',
            'kind' => 'document',
            'status' => 1,
            'sort_no' => 1,
        ], $overrides);

        $entity = $contentsTable->newEntity($data);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, "コンテンツの作成に失敗");

        return $result;
    }

    /**
     * テスト用学習記録を作成して返す
     */
    private function createRecord(array $overrides = []): \Cake\Datasource\EntityInterface
    {
        $recordsTable = $this->getTableLocator()->get('Records');
        $data = array_merge([
            'course_id' => 1,
            'user_id' => 1,
            'content_id' => 1,
            'full_score' => 100,
            'score' => 80,
            'is_passed' => 1,
            'progress' => 1,
            'study_sec' => 300,
            'created' => '2024-06-01 10:00:00',
        ], $overrides);

        $entity = $recordsTable->newEntity($data);
        $result = $recordsTable->save($entity);
        $this->assertNotFalse($result, "学習記録の作成に失敗");

        return $result;
    }

    // ----------------------------------------------------------------
    // GET /api/v1/records (index)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin で学習記録一覧取得 → 200
     */
    public function testIndexAsAdmin(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('記録コース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'content_id' => $content->id,
        ]);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/records');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertCount(1, $body['data']);
    }

    /**
     * 正常系: user_id フィルタ
     */
    public function testIndexFilterByUserId(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user1 = $this->createUser('user01', ['role' => 'user']);
        $user2 = $this->createUser('user02', ['role' => 'user']);
        $course = $this->createCourse('フィルタコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user1->id,
            'content_id' => $content->id,
        ]);
        $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user2->id,
            'content_id' => $content->id,
            'created' => '2024-06-02 10:00:00',
        ]);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/records?user_id=' . $user1->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame((int)$user1->id, $body['data'][0]['user_id']);
    }

    /**
     * 正常系: course_id フィルタ
     */
    public function testIndexFilterByCourseId(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course1 = $this->createCourse('コースA', (int)$admin->id);
        $course2 = $this->createCourse('コースB', (int)$admin->id);
        $content1 = $this->createContent((int)$course1->id, (int)$admin->id);
        $content2 = $this->createContent((int)$course2->id, (int)$admin->id, ['sort_no' => 2]);

        $this->createRecord([
            'course_id' => $course1->id,
            'user_id' => $user->id,
            'content_id' => $content1->id,
        ]);
        $this->createRecord([
            'course_id' => $course2->id,
            'user_id' => $user->id,
            'content_id' => $content2->id,
            'created' => '2024-06-02 10:00:00',
        ]);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/records?course_id=' . $course1->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame((int)$course1->id, $body['data'][0]['course_id']);
    }

    /**
     * 正常系: content_id フィルタ
     */
    public function testIndexFilterByContentId(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('コンテンツフィルタ', (int)$admin->id);
        $content1 = $this->createContent((int)$course->id, (int)$admin->id);
        $content2 = $this->createContent((int)$course->id, (int)$admin->id, ['sort_no' => 2]);

        $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'content_id' => $content1->id,
        ]);
        $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'content_id' => $content2->id,
            'created' => '2024-06-02 10:00:00',
        ]);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/records?content_id=' . $content1->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame((int)$content1->id, $body['data'][0]['content_id']);
    }

    /**
     * 正常系: from/to 日付範囲フィルタ
     */
    public function testIndexFilterByDateRange(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('日付フィルタ', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'content_id' => $content->id,
            'created' => '2024-06-01 10:00:00',
        ]);
        $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'content_id' => $content->id,
            'created' => '2024-06-15 10:00:00',
        ]);
        $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'content_id' => $content->id,
            'created' => '2024-07-01 10:00:00',
        ]);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/records?from=2024-06-01&to=2024-06-30');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(2, $body['data']);
    }

    /**
     * 正常系: 一般ユーザは自分のレコードのみ取得
     */
    public function testIndexAsUserOnlyOwnRecords(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user1 = $this->createUser('user01', ['role' => 'user']);
        $user2 = $this->createUser('user02', ['role' => 'user']);
        $course = $this->createCourse('自分の記録', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user1->id,
            'content_id' => $content->id,
        ]);
        $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user2->id,
            'content_id' => $content->id,
            'created' => '2024-06-02 10:00:00',
        ]);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/records');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame((int)$user1->id, $body['data'][0]['user_id']);
    }

    /**
     * 正常系: ページング
     */
    public function testIndexPagination(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('ページング', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        for ($i = 1; $i <= 3; $i++) {
            $this->createRecord([
                'course_id' => $course->id,
                'user_id' => $user->id,
                'content_id' => $content->id,
                'created' => "2024-06-0{$i} 10:00:00",
            ]);
        }

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/records?page=1&limit=2');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(2, $body['data']);
        $this->assertSame(3, $body['meta']['total']);
    }

    /**
     * 異常系: トークンなし → 401
     */
    public function testIndexUnauthenticated(): void
    {
        $this->get('/api/v1/records');
        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(401, $body['error']['code']);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/records/{id} (view)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin で学習記録詳細取得 → 200
     */
    public function testViewAsAdmin(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('ビューコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $record = $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'content_id' => $content->id,
        ]);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/records/' . $record->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame((int)$record->id, $body['data']['id']);
    }

    /**
     * 正常系: 一般ユーザが自分の記録を参照 → 200
     */
    public function testViewSelfAsUser(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('自分の記録', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $record = $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'content_id' => $content->id,
        ]);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/records/' . $record->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame((int)$record->id, $body['data']['id']);
    }

    /**
     * 異常系: 一般ユーザが他人の記録を参照 → 403
     */
    public function testViewOtherUserRecordForbidden(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user1 = $this->createUser('user01', ['role' => 'user']);
        $user2 = $this->createUser('user02', ['role' => 'user']);
        $course = $this->createCourse('他人の記録', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $record = $this->createRecord([
            'course_id' => $course->id,
            'user_id' => $user2->id,
            'content_id' => $content->id,
        ]);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/records/' . $record->id);
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
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/records/99999');
        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Record not found', $body['error']['message']);
    }

    /**
     * 異常系: トークンなし → 401
     */
    public function testViewUnauthenticated(): void
    {
        $this->get('/api/v1/records/1');
        $this->assertResponseCode(401);
    }
}
