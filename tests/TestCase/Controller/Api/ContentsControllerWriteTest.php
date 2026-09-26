<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ContentsController API Write のテスト
 *
 * POST / PUT / PATCH / DELETE の統合テスト
 */
class ContentsControllerWriteTest extends TestCase
{
    use IntegrationTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->getTableLocator()->get('UserTokens')->deleteAll('1 = 1');
        $this->getTableLocator()->get('ContentsQuestions')->deleteAll('1 = 1');
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

    private function assignCourse(int $userId, int $courseId): void
    {
        $usersCoursesTable = $this->getTableLocator()->get('UsersCourses');
        $entity = $usersCoursesTable->newEntity([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
        $result = $usersCoursesTable->save($entity);
        $this->assertNotFalse($result, 'コース割当に失敗');
    }

    private function createContent(int $courseId, int $userId, array $overrides = []): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $data = array_merge([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テストコンテンツ',
            'kind' => 'html',
            'body' => '<p>テスト</p>',
            'status' => 1,
            'sort_no' => 1,
        ], $overrides);

        $entity = $contentsTable->newEntity($data);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'コンテンツの作成に失敗');

        return $result;
    }

    // ----------------------------------------------------------------
    // POST /api/v1/contents (add)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin で HTML コンテンツ作成 → 201
     */
    public function testAddHtmlContent(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'HTML テスト',
            'kind' => 'html',
            'body' => '<p>テスト</p>',
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('html', $body['data']['kind']);
    }

    /**
     * 正常系: kind=movie で body 省略 → 201
     */
    public function testAddMovieContentWithoutBody(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => '動画テスト',
            'kind' => 'movie',
            'url' => 'https://example.com/video.mp4',
        ]);

        $this->assertResponseCode(201);
    }

    /**
     * 正常系: sort_no 未指定時は自動採番
     */
    public function testAddAutoSortNo(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $this->createContent((int)$course->id, (int)$admin->id, ['sort_no' => 5]);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => '自動採番',
            'kind' => 'html',
            'body' => '<p>test</p>',
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(6, $body['data']['sort_no']);
    }

    /**
     * 正常系: status 未指定時は 0（非公開）
     */
    public function testAddDefaultStatusZero(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'ステータス既定',
            'kind' => 'html',
            'body' => '<p>test</p>',
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(0, $body['data']['status']);
    }

    /**
     * 正常系: user_id はクライアント指定を無視されること
     */
    public function testAddUserIdOverridden(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $other = $this->createUser('other01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'user_id 無視テスト',
            'kind' => 'html',
            'body' => '<p>test</p>',
            'user_id' => $other->id,
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame((int)$admin->id, $body['data']['user_id']);
    }

    /**
     * 正常系: admin で Markdown コンテンツ作成 → 201
     */
    public function testAddMarkdownContent(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'Markdown テスト',
            'kind' => 'markdown',
            'body' => "# Hello\n\n**bold**",
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame('markdown', $body['data']['kind']);
        $this->assertSame('# Hello', substr($body['data']['body'], 0, 7));

        // DB 確認: body は Markdown ソースのまま保存されること
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->get($body['data']['id']);
        $this->assertSame('markdown', $entity->kind);
        $this->assertSame("# Hello\n\n**bold**", $entity->body);
    }

    /**
     * 異常系: kind=markdown で body 未指定 → 400 バリデーションエラー
     */
    public function testAddMarkdownMissingBody(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'テスト',
            'kind' => 'markdown',
        ]);
        $this->assertResponseCode(400);
    }

    /**
     * 正常系: 既存 HTML コンテンツを kind=markdown に更新 → 200
     */
    public function testEditToMarkdown(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, [
            'kind' => 'html', 'body' => '<p>old</p>',
        ]);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/' . $content->id, [
            'kind' => 'markdown',
            'body' => '# Markdown に変更',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('markdown', $body['data']['kind']);
        $this->assertSame('# Markdown に変更', $body['data']['body']);
    }

    // ----------------------------------------------------------------
    // POST /api/v1/contents — 異常系
    // ----------------------------------------------------------------

    /**
     * 異常系: 未認証 → 401
     */
    public function testAddUnauthenticated(): void
    {
        $this->post('/api/v1/contents', [
            'course_id' => 1,
            'title' => 'テスト',
            'kind' => 'html',
            'body' => '<p>test</p>',
        ]);
        $this->assertResponseCode(401);
    }

    /**
     * 異常系: 一般ユーザ → 403
     */
    public function testAddForbiddenForUser(): void
    {
        $this->createUser('user01', ['role' => 'user']);
        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => 1,
            'title' => 'テスト',
            'kind' => 'html',
            'body' => '<p>test</p>',
        ]);
        $this->assertResponseCode(403);
    }

    /**
     * 異常系: course_id 未指定 → 400
     */
    public function testAddMissingCourseId(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'title' => 'テスト',
            'kind' => 'html',
            'body' => '<p>test</p>',
        ]);
        $this->assertResponseCode(400);
    }

    /**
     * 正常系: staff ロールは未受講コースでもコンテンツ作成可能 → 201
     * （Web 管理画面と同一の挙動）
     */
    public function testStaffCanAddToUnenrolledCourse(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $otherAdmin = $this->createUser('admin02', ['role' => 'admin']);
        $course = $this->createCourse('他人のコース', (int)$otherAdmin->id);
        $this->assignCourse((int)$otherAdmin->id, (int)$course->id);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'テスト',
            'kind' => 'html',
            'body' => '<p>test</p>',
        ]);
        $this->assertResponseCode(201);
    }

    /**
     * 異常系: kind=html で body 未指定 → 400 バリデーションエラー
     */
    public function testAddHtmlMissingBody(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'テスト',
            'kind' => 'html',
        ]);
        $this->assertResponseCode(400);
    }

    /**
     * 異常系: 無効な kind → 400
     */
    public function testAddInvalidKind(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'テスト',
            'kind' => 'invalid_kind',
            'body' => '<p>test</p>',
        ]);
        $this->assertResponseCode(400);
    }

    /**
     * 異常系: title 未指定 → 400
     */
    public function testAddMissingTitle(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'kind' => 'html',
            'body' => '<p>test</p>',
        ]);
        $this->assertResponseCode(400);
    }

    // ----------------------------------------------------------------
    // PUT/PATCH /api/v1/contents/{id} (edit)
    // ----------------------------------------------------------------

    /**
     * 正常系: PUT でコンテンツ更新 → 200
     */
    public function testEditSuccess(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, [
            'kind' => 'html', 'body' => '<p>old</p>',
        ]);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/' . $content->id, [
            'title' => '更新後タイトル',
            'body' => '<p>new</p>',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('更新後タイトル', $body['data']['title']);
        $this->assertSame('<p>new</p>', $body['data']['body']);
    }

    /**
     * 正常系: PATCH で部分更新 → 200
     */
    public function testEditPatchSuccess(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->patch('/api/v1/contents/' . $content->id, [
            'body' => '<p>patched</p>',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('<p>patched</p>', $body['data']['body']);
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testEditNotFound(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/99999', ['title' => 'Not Found']);
        $this->assertResponseCode(404);
    }

    /**
     * 正常系: staff ロールは未受講コースでもコンテンツ更新可能 → 200
     * （Web 管理画面と同一の挙動）
     */
    public function testStaffCanEditToUnenrolledCourse(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $otherAdmin = $this->createUser('admin02', ['role' => 'admin']);
        $course = $this->createCourse('他人のコース', (int)$otherAdmin->id);
        $this->assignCourse((int)$otherAdmin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$otherAdmin->id);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/' . $content->id, ['title' => 'No Access']);
        $this->assertResponseOk();
    }

    /**
     * 異常系: 一般ユーザ → 403
     */
    public function testEditForbiddenForUser(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/' . $content->id, ['title' => 'Forbidden']);
        $this->assertResponseCode(403);
    }

    /**
     * 異常系: 更新フィールドなし → 400
     */
    public function testEditNoFields(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/' . $content->id, []);
        $this->assertResponseCode(400);
    }

    // ----------------------------------------------------------------
    // DELETE /api/v1/contents/{id} (delete)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でコンテンツ削除 → 200 + DB 削除
     */
    public function testDeleteSuccess(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/contents/' . $content->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame((int)$content->id, $body['data']['id']);
        $this->assertTrue($body['data']['deleted']);

        $contentsTable = $this->getTableLocator()->get('Contents');
        $this->assertFalse(
            $contentsTable->exists(['id' => $content->id]),
            'ib_contents の該当行が削除されている',
        );
    }

    /**
     * 正常系: ContentsQuestions のカスケード削除
     */
    public function testDeleteCascadesContentsQuestions(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, ['kind' => 'test']);

        $cqTable = $this->getTableLocator()->get('ContentsQuestions');
        $cqEntity = $cqTable->newEntity([
            'content_id' => $content->id,
            'question_type' => 'single',
            'body' => 'テスト問題',
            'score' => 10,
            'sort_no' => 1,
            'correct' => '1',
        ]);
        $cqTable->save($cqEntity);
        $this->assertTrue($cqTable->exists(['content_id' => $content->id]), '問題が作成されている');

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/contents/' . $content->id);
        $this->assertResponseOk();

        $this->assertFalse(
            $cqTable->exists(['content_id' => $content->id]),
            'ContentsQuestions がカスケード削除されている',
        );
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testDeleteNotFound(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/contents/99999');
        $this->assertResponseCode(404);
    }

    /**
     * 異常系: 一般ユーザ → 403
     */
    public function testDeleteForbiddenForUser(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/contents/' . $content->id);
        $this->assertResponseCode(403);
    }

    // ----------------------------------------------------------------
    // 回帰: 既存 kind='' レコードが壊れないこと
    // ----------------------------------------------------------------

    /**
     * 既存 kind='' レコードの更新がバリデーションで失敗しないこと
     */
    public function testExistingEmptyKindNotBroken(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);

        $conn = $this->getTableLocator()->get('Contents')->getConnection();
        $conn->execute(
            "INSERT INTO ib_contents (course_id, user_id, title, kind, status, sort_no, created) VALUES (:cid, :uid, :title, :kind, 1, 1, NOW())",
            ['cid' => $course->id, 'uid' => $admin->id, 'title' => '旧データ', 'kind' => ''],
        );
        $insertedId = (int)$conn->execute('SELECT LAST_INSERT_ID() AS id')->fetch('assoc')['id'];

        $contentsTable = $this->getTableLocator()->get('Contents');
        $this->assertTrue($contentsTable->exists(['id' => $insertedId]), '旧レコードが存在する');

        $entity = $contentsTable->get($insertedId);
        $entity->body = 'updated body';
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'kind="" レコードの body 更新が成功する');
    }

    // ----------------------------------------------------------------
    // POST → PUT → DELETE の一連フロー
    // ----------------------------------------------------------------

    /**
     * 作成→更新→削除の一連が通ること
     */
    public function testAddEditDeleteFlow(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $tokenData = $this->issueToken('admin01', 'testpass');

        // POST: 作成
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'フローテスト',
            'kind' => 'html',
            'body' => '<p>original</p>',
        ]);
        $this->assertResponseCode(201);
        $body = json_decode((string)$this->_response->getBody(), true);
        $contentId = $body['data']['id'];
        $this->assertSame('フローテスト', $body['data']['title']);

        // PUT: 更新
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->put('/api/v1/contents/' . $contentId, [
            'title' => '更新済み',
            'body' => '<p>updated</p>',
        ]);
        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('更新済み', $body['data']['title']);
        $this->assertSame('<p>updated</p>', $body['data']['body']);

        // DELETE: 削除
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->delete('/api/v1/contents/' . $contentId);
        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($body['data']['deleted']);

        // 削除後に GET → 404
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->get('/api/v1/contents/' . $contentId);
        $this->assertResponseCode(404);
    }
}
