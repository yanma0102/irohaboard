<?php
declare(strict_types=1);

namespace App\Test\TestCase\Mcp;

use App\Mcp\Tool\CreateContentTool;
use App\Mcp\Tool\UpdateContentTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * D-09: MCP ツールのエラー伝達テスト
 *
 * ツールレベルのエラー（権限なし、存在しない、バリデーション失敗等）
 * が ToolCallException として伝達されることを検証する。
 *
 * MCP SDK は ToolCallException を catch して CallToolResult(isError: true) に変換するため、
 * LLM クライアントはエラーを認識できる。
 */
class ToolErrorPropagationTest extends TestCase
{
    /**
     * @var \Cake\Database\Connection
     */
    private $connection;

    public function setUp(): void
    {
        parent::setUp();

        // Application::bootstrap() 相当（単体テストは Application を起動しないため）
        Configure::load('ib_config');

        $this->getTableLocator()->get('UserTokens')->deleteAll('1 = 1');
        $this->getTableLocator()->get('ContentsQuestions')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Records')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Contents')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Courses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Logs')->deleteAll('1 = 1');

        $this->connection = $this->getTableLocator()->get('Users')->getConnection();
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    private function createUser(string $username, array $overrides = []): EntityInterface
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

    private function createCourse(string $title, int $userId): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $entity = $coursesTable->newEntity([
            'title' => $title,
            'sort_no' => 1,
            'user_id' => $userId,
        ]);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result, "コース {$title} の作成に失敗");

        return $result;
    }

    private function assignCourse(int $userId, int $courseId): void
    {
        $table = $this->getTableLocator()->get('UsersCourses');
        $entity = $table->newEntity([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
        $this->assertNotFalse($table->save($entity), 'コース割当に失敗');
    }

    private function createContent(int $courseId, int $userId): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テストコンテンツ',
            'kind' => 'html',
            'body' => '<p>テスト</p>',
            'status' => 1,
            'sort_no' => 1,
        ]);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'コンテンツの作成に失敗');

        return $entity;
    }

    /**
     * モックの RequestContext を生成する
     */
    private function createMockContext(int $userId, string $role): RequestContext
    {
        $session = $this->createMock(SessionInterface::class);

        $request = $this->createMock(Request::class);
        $request->method('getMeta')->willReturn([
            'oauth' => [
                'oauth.user_id' => $userId,
                'oauth.role' => $role,
            ],
        ]);
        $request->method('getId')->willReturn('test-1');

        return new RequestContext($session, $request);
    }

    // ----------------------------------------------------------------
    // CreateContentTool: エラーパス → ToolCallException
    // ----------------------------------------------------------------

    /**
     * 非スタッフがコンテンツ作成を試みる → ToolCallException
     */
    public function testCreateContentNonStaffThrowsException(): void
    {
        $user = $this->createUser('mcpusercs', ['role' => 'user']);
        $context = $this->createMockContext((int)$user->id, 'user');

        $tool = new CreateContentTool(new AccessControlService($this->connection));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Only staff members can create content.');

        $tool($context, course_id: 1, title: 'テスト', kind: 'html', body: '<p>test</p>');
    }

    /**
     * staff が未受講コースにコンテンツ作成を試みる → 201（D-08 修正後は成功）
     */
    public function testCreateContentStaffToUnenrolledCourseSucceeds(): void
    {
        $staff = $this->createUser('mcpstaffcs', ['role' => 'admin']);
        $owner = $this->createUser('mcpownercs', ['role' => 'user']);
        $course = $this->createCourse('MCP テストコース', (int)$owner->id);
        // staff はコース未受講

        $context = $this->createMockContext((int)$staff->id, 'admin');

        $tool = new CreateContentTool(new AccessControlService($this->connection));
        $result = $tool($context, course_id: (int)$course->id, title: 'MCP テスト', kind: 'html', body: '<p>test</p>');

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('MCP テスト', $result['data']['title']);
    }

    /**
     * 無効な kind でコンテンツ作成 → ToolCallException
     */
    public function testCreateContentInvalidKindThrowsException(): void
    {
        $staff = $this->createUser('mcpstaffik', ['role' => 'admin']);
        $owner = $this->createUser('mcpownerik', ['role' => 'user']);
        $course = $this->createCourse('MCP テストコース IK', (int)$owner->id);
        $this->assignCourse((int)$staff->id, (int)$course->id);

        $context = $this->createMockContext((int)$staff->id, 'admin');

        $tool = new CreateContentTool(new AccessControlService($this->connection));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid kind.');

        $tool($context, course_id: (int)$course->id, title: 'テスト', kind: 'invalid_kind', body: '<p>test</p>');
    }

    // ----------------------------------------------------------------
    // UpdateContentTool: エラーパス → ToolCallException
    // ----------------------------------------------------------------

    /**
     * 非スタッフがコンテンツ更新を試みる → ToolCallException
     */
    public function testUpdateContentNonStaffThrowsException(): void
    {
        $user = $this->createUser('mcpuserup', ['role' => 'user']);
        $owner = $this->createUser('mcpownerup', ['role' => 'user']);
        $course = $this->createCourse('MCP 更新テストコース', (int)$owner->id);
        $content = $this->createContent((int)$course->id, (int)$owner->id);

        $context = $this->createMockContext((int)$user->id, 'user');

        $tool = new UpdateContentTool(new AccessControlService($this->connection));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Only staff members can update content.');

        $tool($context, content_id: (int)$content->id, title: '更新テスト');
    }

    /**
     * 存在しないコンテンツの更新 → ToolCallException
     */
    public function testUpdateContentNotFoundThrowsException(): void
    {
        $staff = $this->createUser('mcpstaffnf', ['role' => 'admin']);
        $context = $this->createMockContext((int)$staff->id, 'admin');

        $tool = new UpdateContentTool(new AccessControlService($this->connection));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Content not found.');

        $tool($context, content_id: 99999, title: '更新テスト');
    }

    /**
     * 更新フィールドなし → ToolCallException
     */
    public function testUpdateContentNoFieldsThrowsException(): void
    {
        $staff = $this->createUser('mcpstaffnof', ['role' => 'admin']);
        $owner = $this->createUser('mcpownernof', ['role' => 'user']);
        $course = $this->createCourse('MCP フィールドテスト', (int)$owner->id);
        $this->assignCourse((int)$staff->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$owner->id);

        $context = $this->createMockContext((int)$staff->id, 'admin');

        $tool = new UpdateContentTool(new AccessControlService($this->connection));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No fields to update.');

        // kind=null, title=null, body=null, status=null → フィールドなし
        $tool($context, content_id: (int)$content->id);
    }

    /**
     * staff が未受講コースのコンテンツを更新 → 成功（D-08 修正後）
     */
    public function testUpdateContentStaffToUnenrolledCourseSucceeds(): void
    {
        $staff = $this->createUser('mcpstaffup2', ['role' => 'editor']);
        $owner = $this->createUser('mcpownerup2', ['role' => 'user']);
        $course = $this->createCourse('MCP 更新テストコース 2', (int)$owner->id);
        $content = $this->createContent((int)$course->id, (int)$owner->id);
        // staff はコース未受講

        $context = $this->createMockContext((int)$staff->id, 'editor');

        $tool = new UpdateContentTool(new AccessControlService($this->connection));
        $result = $tool($context, content_id: (int)$content->id, title: '更新成功');

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('更新成功', $result['data']['title']);
    }

    // ----------------------------------------------------------------
    // 正常系: 成功時は isError: false の数据构造
    // ----------------------------------------------------------------

    /**
     * create_content 成功 → data キーを含む配列が返る
     */
    public function testCreateContentSuccessReturnsDataKey(): void
    {
        $staff = $this->createUser('mcpstaffok', ['role' => 'admin']);
        $owner = $this->createUser('mcpownerok', ['role' => 'user']);
        $course = $this->createCourse('MCP 成功テスト', (int)$owner->id);
        $this->assignCourse((int)$staff->id, (int)$course->id);

        $context = $this->createMockContext((int)$staff->id, 'admin');

        $tool = new CreateContentTool(new AccessControlService($this->connection));
        $result = $tool($context, course_id: (int)$course->id, title: '成功テスト', kind: 'html', body: '<p>ok</p>');

        $this->assertArrayHasKey('data', $result, '成功時は data キーが存在する');
        $this->assertArrayHasKey('id', $result['data']);
        $this->assertSame('成功テスト', $result['data']['title']);
    }
}
