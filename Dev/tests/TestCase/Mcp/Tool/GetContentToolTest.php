<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\GetContentTool;
use App\Service\AccessControlService;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * get_content ツールのサニタイズ動作テスト（U-5 適用済み）
 *
 * - kind='html': MarkdownRenderer::purifyHtml() でサニタイズ、sanitized=true
 * - kind='markdown': Markdown 原文のまま返す、sanitized=false
 * - kind='text': そのまま返す、sanitized=false
 */
class GetContentToolTest extends TestCase
{
    private GetContentTool $tool;
    private int $lastContentId = 0;

    public function setUp(): void
    {
        parent::setUp();

        foreach (
            [
            'UserTokens', 'Users', 'UsersCourses', 'GroupsCourses',
            'UsersGroups', 'Groups', 'Courses', 'Contents', 'Records', 'Logs',
            ] as $table
        ) {
            $this->getTableLocator()->get($table)->deleteAll('1 = 1');
        }

        $accessControl = new AccessControlService(
            $this->getTableLocator()->get('Users')->getConnection(),
        );
        $this->tool = new GetContentTool($accessControl);
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    private function makeContext(int $userId, string $role = 'user'): RequestContext
    {
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_content',
                'arguments' => [],
                '_meta' => [
                    'oauth' => [
                        'oauth.user_id' => $userId,
                        'oauth.role' => $role,
                        'oauth.name' => 'テストユーザ',
                        'oauth.token_id' => 1,
                    ],
                ],
            ],
        ]);

        return new RequestContext(
            $this->createStub(SessionInterface::class),
            $request,
        );
    }

    private function createUser(string $username = 'contentuser', string $role = 'user'): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $entity = $usersTable->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テストユーザ',
            'role' => $role,
            'email' => $username . '@example.com',
        ]);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    private function createCourse(): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $entity = $coursesTable->newEntity([
            'title' => 'テストコース',
            'introduction' => 'テスト用コースです',
            'opened' => '2024-01-01',
            'comment' => 'コメント',
            'sort_no' => 1,
            'user_id' => 1,
        ]);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    private function enrollUser(int $userId, int $courseId): void
    {
        $table = $this->getTableLocator()->get('UsersCourses');
        $this->assertNotFalse($table->save($table->newEntity([
            'user_id' => $userId,
            'course_id' => $courseId,
        ])));
    }

    private function createContent(int $courseId, array $overrides = []): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity(array_merge([
            'course_id' => $courseId,
            'user_id' => 1,
            'title' => 'テストコンテンツ',
            'kind' => 'html',
            'body' => '<p>本文</p>',
            'status' => 1,
            'sort_no' => 1,
        ], $overrides));
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result);
        $this->lastContentId = (int)$result->id;

        return $result;
    }

    // ----------------------------------------------------------------
    // kind='html' サニタイズ
    // ----------------------------------------------------------------

    /**
     * kind='html' で <script> を含む場合、サニタイズされて除去され sanitized=true
     */
    public function testHtmlKindStripsScriptTag(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('htmlscript');
        $this->enrollUser((int)$user->id, (int)$course->id);
        $this->createContent((int)$course->id, [
            'kind' => 'html',
            'body' => '<p>ok</p><script>alert(1)</script>',
        ]);

        $result = $this->tool->__invoke($this->makeContext((int)$user->id), $this->lastContentId);

        $this->assertArrayHasKey('data', $result);
        $this->assertStringContainsString('<p>ok</p>', $result['data']['body']);
        $this->assertStringNotContainsString('<script>', $result['data']['body']);
        $this->assertTrue($result['data']['sanitized']);
    }

    /**
     * kind='html' で正当な HTML を含む場合、サニタイズ後もそのまま保持され sanitized=true
     */
    public function testHtmlKindPreservesBenignHtml(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('htmlbenign');
        $this->enrollUser((int)$user->id, (int)$course->id);
        $body = '<p>段落</p><h2>見出し</h2>';
        $this->createContent((int)$course->id, [
            'kind' => 'html',
            'body' => $body,
        ]);

        $result = $this->tool->__invoke($this->makeContext((int)$user->id), $this->lastContentId);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame($body, $result['data']['body']);
        $this->assertTrue($result['data']['sanitized']);
    }

    // ----------------------------------------------------------------
    // kind='markdown' — サニタイズなし
    // ----------------------------------------------------------------

    /**
     * kind='markdown' は Markdown 原文をそのまま返し sanitized=false
     * （script タグを含む文字列でもサニタイズしない）
     */
    public function testMarkdownKindReturnsRawMarkdown(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('mdraw');
        $this->enrollUser((int)$user->id, (int)$course->id);
        $body = "# 見出し\n\n<script>alert('xss')</script>\n\n**太字**";
        $this->createContent((int)$course->id, [
            'kind' => 'markdown',
            'body' => $body,
        ]);

        $result = $this->tool->__invoke($this->makeContext((int)$user->id), $this->lastContentId);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame($body, $result['data']['body']);
        $this->assertFalse($result['data']['sanitized']);
    }

    // ----------------------------------------------------------------
    // kind='text' — サニタイズなし
    // ----------------------------------------------------------------

    /**
     * kind='text' は本文をそのまま返し sanitized=false
     */
    public function testTextKindReturnsRawBody(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('textraw');
        $this->enrollUser((int)$user->id, (int)$course->id);
        $this->createContent((int)$course->id, [
            'kind' => 'text',
            'body' => 'プレーンテキスト',
        ]);

        $result = $this->tool->__invoke($this->makeContext((int)$user->id), $this->lastContentId);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('プレーンテキスト', $result['data']['body']);
        $this->assertFalse($result['data']['sanitized']);
    }
}
