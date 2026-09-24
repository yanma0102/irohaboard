<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ContentsController API のテスト
 *
 * コンテンツ一覧・詳細の統合テスト
 */
class ContentsControllerTest extends TestCase
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

    // ----------------------------------------------------------------
    // GET /api/v1/contents (index)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でコンテンツ一覧取得 → 200
     */
    public function testIndexAsAdmin(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/contents');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertIsArray($body['data']);
    }

    /**
     * 正常系: コース別フィルタ
     */
    public function testIndexFilterByCourseId(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course1 = $this->createCourse('コースA', (int)$admin->id);
        $course2 = $this->createCourse('コースB', (int)$admin->id);
        $this->createContent((int)$course1->id, (int)$admin->id, ['title' => 'コンテンツA']);
        $this->createContent((int)$course2->id, (int)$admin->id, ['title' => 'コンテンツB']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/contents?course_id=' . $course1->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('コンテンツA', $body['data'][0]['title']);
    }

    /**
     * 正常系: kind フィルタ
     */
    public function testIndexFilterByKind(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('フィルタコース', (int)$admin->id);
        $this->createContent((int)$course->id, (int)$admin->id, ['kind' => 'document', 'title' => 'ドキュメント']);
        $this->createContent((int)$course->id, (int)$admin->id, ['kind' => 'video', 'title' => '動画', 'sort_no' => 2]);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/contents?kind=document');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('document', $body['data'][0]['kind']);
    }

    /**
     * 正常系: status フィルタ
     */
    public function testIndexFilterByStatus(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('ステータスコース', (int)$admin->id);
        $this->createContent((int)$course->id, (int)$admin->id, ['status' => 1, 'title' => '公開']);
        $this->createContent((int)$course->id, (int)$admin->id, ['status' => 0, 'title' => '非公開', 'sort_no' => 2]);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/contents?status=0');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame(0, $body['data'][0]['status']);
    }

    /**
     * 正常系: 一般ユーザは受講コースの公開コンテンツのみ
     */
    public function testIndexAsUserOnlyAccessiblePublicContent(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('受講コース', (int)$admin->id);

        // ユーザにコース割当
        $usersCoursesTable = $this->getTableLocator()->get('UsersCourses');
        $ucEntity = $usersCoursesTable->newEntity([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $usersCoursesTable->save($ucEntity);

        // 公開コンテンツと非公開コンテンツを作成
        $this->createContent((int)$course->id, (int)$admin->id, ['status' => 1, 'title' => '公開']);
        $this->createContent((int)$course->id, (int)$admin->id, ['status' => 0, 'title' => '非公開', 'sort_no' => 2]);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/contents');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        // 非公開は含まれない
        $this->assertCount(1, $body['data']);
        $this->assertSame('公開', $body['data'][0]['title']);
    }

    /**
     * 正常系: 一般ユーザがコース未割当时 → 空リスト
     */
    public function testIndexAsUserNoCoursesReturnsEmpty(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('孤立コース', (int)$admin->id);
        $this->createContent((int)$course->id, (int)$admin->id);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/contents');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(0, $body['data']);
        $this->assertSame(0, $body['meta']['total']);
    }

    /**
     * 正常系: ページング
     */
    public function testIndexPagination(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('ページングコース', (int)$admin->id);
        for ($i = 1; $i <= 3; $i++) {
            $this->createContent((int)$course->id, (int)$admin->id, [
                'title' => "コンテンツ{$i}",
                'sort_no' => $i,
            ]);
        }

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/contents?page=1&limit=2');
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
        $this->get('/api/v1/contents');
        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(401, $body['error']['code']);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/contents/{id} (view)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でコンテンツ詳細取得 → 200
     */
    public function testViewAsAdmin(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('ビュー コース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, ['title' => '詳細テスト']);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/contents/' . $content->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame('詳細テスト', $body['data']['title']);
    }

    /**
     * 正常系: 一般ユーザが自分のコースの公開コンテンツを参照 → 200
     */
    public function testViewAsUserOwnCoursePublicContent(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('受講コース', (int)$admin->id);

        $usersCoursesTable = $this->getTableLocator()->get('UsersCourses');
        $ucEntity = $usersCoursesTable->newEntity([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $usersCoursesTable->save($ucEntity);

        $content = $this->createContent((int)$course->id, (int)$admin->id, ['status' => 1, 'title' => '公開コンテンツ']);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/contents/' . $content->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('公開コンテンツ', $body['data']['title']);
    }

    /**
     * 異常系: 一般ユーザが非公開コンテンツを参照 → 404
     */
    public function testViewAsUserPrivateContentReturns404(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('非公開コース', (int)$admin->id);

        $usersCoursesTable = $this->getTableLocator()->get('UsersCourses');
        $ucEntity = $usersCoursesTable->newEntity([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $usersCoursesTable->save($ucEntity);

        $content = $this->createContent((int)$course->id, (int)$admin->id, ['status' => 0, 'title' => '非公開コンテンツ']);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/contents/' . $content->id);
        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Content not found', $body['error']['message']);
    }

    /**
     * 異常系: 一般ユーザが未割当コースのコンテンツを参照 → 404
     */
    public function testViewAsUserNotAccessibleCourseReturns404(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('未割当コース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, ['status' => 1]);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/contents/' . $content->id);
        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Content not found', $body['error']['message']);
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testViewNotFound(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->get('/api/v1/contents/99999');
        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Content not found', $body['error']['message']);
    }

    /**
     * 異常系: トークンなし → 401
     */
    public function testViewUnauthenticated(): void
    {
        $this->get('/api/v1/contents/1');
        $this->assertResponseCode(401);
    }
}
