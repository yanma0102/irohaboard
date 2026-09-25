<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\GetContentHtmlTool;
use App\Mcp\Tool\GetContentTool;
use App\Service\AccessControlService;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * get_content / get_content_html ツールのテスト
 *
 * - markdown: MarkdownRenderer でサニタイズされた HTML を返す（G-9）
 * - html: 生 HTML をそのまま返す（Phase 1/2 仕様・G-9）
 * - 権限: 非受講者は Access denied、未公開は not found
 */
class GetContentHtmlToolTest extends TestCase
{
    private GetContentTool $getTool;
    private GetContentHtmlTool $htmlTool;
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
        $this->getTool = new GetContentTool($accessControl);
        $this->htmlTool = new GetContentHtmlTool($accessControl);
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

    private function createUser(string $username = 'htmluser', string $role = 'user'): EntityInterface
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
    // get_content（生ボディ）
    // ----------------------------------------------------------------

    /**
     * get_content は生の body を返す
     */
    public function testGetContentReturnsRawBody(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('rawuser');
        $this->enrollUser((int)$user->id, (int)$course->id);
        $this->createContent((int)$course->id, [
            'kind' => 'markdown',
            'body' => "# 見出し\n\n**太字**",
        ]);

        $result = $this->getTool->__invoke($this->makeContext((int)$user->id), $this->lastContentId);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame("# 見出し\n\n**太字**", $result['data']['body']);
        $this->assertSame('markdown', $result['data']['kind']);
    }

    // ----------------------------------------------------------------
    // get_content_html（レンダリング）
    // ----------------------------------------------------------------

    /**
     * markdown → サンプルな HTML（script タグは除去される）
     */
    public function testGetContentHtmlMarkdownSanitized(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('mduser');
        $this->enrollUser((int)$user->id, (int)$course->id);
        $this->createContent((int)$course->id, [
            'kind' => 'markdown',
            'body' => "# 見出し\n\n<script>alert('xss')</script>\n\n**太字**",
        ]);

        $result = $this->htmlTool->__invoke($this->makeContext((int)$user->id), $this->lastContentId);

        $this->assertArrayHasKey('data', $result);
        $html = $result['data']['html'];
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('<strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html, 'script タグはエスケープされる');
    }

    /**
     * html → 生 HTML がそのまま返る（G-9・Phase 1/2 仕様）
     */
    public function testGetContentHtmlKindReturnsRawHtml(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('htmlkinduser');
        $this->enrollUser((int)$user->id, (int)$course->id);
        $this->createContent((int)$course->id, [
            'kind' => 'html',
            'body' => '<div class="raw">生HTML</div><script>keep()</script>',
        ]);

        $result = $this->htmlTool->__invoke($this->makeContext((int)$user->id), $this->lastContentId);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame(
            '<div class="raw">生HTML</div><script>keep()</script>',
            $result['data']['html'],
        );
    }

    // ----------------------------------------------------------------
    // 権限制御
    // ----------------------------------------------------------------

    /**
     * 未受講ユーザ → Access denied
     */
    public function testAccessDeniedForNonEnrolled(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('stranger02');
        $this->createContent((int)$course->id);

        $result = $this->getTool->__invoke($this->makeContext((int)$user->id), $this->lastContentId);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Access denied', $result['error']);

        $resultHtml = $this->htmlTool->__invoke($this->makeContext((int)$user->id), $this->lastContentId);

        $this->assertArrayHasKey('error', $resultHtml);
        $this->assertStringContainsString('Access denied', $resultHtml['error']);
    }

    /**
     * 受講生は未公開コンテンツを取得できない（not found 扱い）
     */
    public function testUnpublishedContentHiddenFromStudent(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('draftuser');
        $this->enrollUser((int)$user->id, (int)$course->id);
        $this->createContent((int)$course->id, ['status' => 0]);

        $result = $this->getTool->__invoke($this->makeContext((int)$user->id), $this->lastContentId);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not found', $result['error']);
    }

    /**
     * スタッフは未公開コンテンツも取得できる
     */
    public function testStaffSeesUnpublishedContent(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('adminhtml', 'admin');
        $this->createContent((int)$course->id, ['status' => 0, 'body' => '<p>下書き</p>']);

        $result = $this->getTool->__invoke($this->makeContext((int)$admin->id, 'admin'), $this->lastContentId);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('<p>下書き</p>', $result['data']['body']);
    }

    /**
     * 存在しないコンテンツ → not found
     */
    public function testContentNotFound(): void
    {
        $user = $this->createUser('nofound');

        $result = $this->getTool->__invoke($this->makeContext((int)$user->id), 9999);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not found', $result['error']);
    }

    // ----------------------------------------------------------------
    // html kind サニタイズ導入前のログ評価（U-5 / Phase 3 3-5）
    // ----------------------------------------------------------------

    /**
     * html kind でサニタイズ差分がある場合、応答は生 HTML のまま返しつつ
     * ib_logs に評価ログを 1 件だけ記録する（2 回目の呼び出しでは重複しない）
     */
    public function testSanitizeCandidateLoggedOnceForDirtyHtml(): void
    {
        $admin = $this->createUser('evaladmin', 'admin');
        $course = $this->createCourse();
        $content = $this->createContent((int)$course->id, [
            'body' => '<p>ok</p><script>alert(1)</script>',
        ]);

        $result = $this->htmlTool->__invoke($this->makeContext((int)$admin->id, 'admin'), (int)$content->id);

        // 応答は変更されない（ログ評価フェーズのため生 HTML のまま）
        $this->assertStringContainsString('<script>', $result['data']['html']);

        $logsTable = $this->getTableLocator()->get('Logs');
        $rows = $logsTable->find()->where([
            'log_type' => GetContentHtmlTool::SANITIZE_EVAL_LOG_TYPE,
            'log_content' => (string)$this->lastContentId,
        ])->all()->toList();
        $this->assertCount(1, $rows);
        $this->assertSame((int)$admin->id, (int)$rows[0]->user_id);
        $this->assertNotNull($rows[0]->created);

        // 2 回目は重複記録しない
        $this->htmlTool->__invoke($this->makeContext((int)$admin->id, 'admin'), (int)$content->id);
        $count = $logsTable->find()->where([
            'log_type' => GetContentHtmlTool::SANITIZE_EVAL_LOG_TYPE,
            'log_content' => (string)$this->lastContentId,
        ])->count();
        $this->assertSame(1, $count);
    }

    /**
     * サニタイズで変化しない HTML は記録しない
     */
    public function testNoLogForCleanHtml(): void
    {
        $admin = $this->createUser('evaladmin2', 'admin');
        $course = $this->createCourse();
        $content = $this->createContent((int)$course->id, [
            'body' => '<p>きれいな本文</p>',
        ]);

        $this->htmlTool->__invoke($this->makeContext((int)$admin->id, 'admin'), (int)$content->id);

        $count = $this->getTableLocator()->get('Logs')->find()
            ->where(['log_type' => GetContentHtmlTool::SANITIZE_EVAL_LOG_TYPE])
            ->count();
        $this->assertSame(0, $count);
    }

    /**
     * markdown kind はもともとサニタイズ済みのため記録しない
     */
    public function testNoLogForMarkdownKind(): void
    {
        $admin = $this->createUser('evaladmin3', 'admin');
        $course = $this->createCourse();
        $content = $this->createContent((int)$course->id, [
            'kind' => 'markdown',
            'body' => "# 見出し\n\n<script>alert(1)</script>",
        ]);

        $result = $this->htmlTool->__invoke($this->makeContext((int)$admin->id, 'admin'), (int)$content->id);
        $this->assertStringNotContainsString('<script>', $result['data']['html']);

        $count = $this->getTableLocator()->get('Logs')->find()
            ->where(['log_type' => GetContentHtmlTool::SANITIZE_EVAL_LOG_TYPE])
            ->count();
        $this->assertSame(0, $count);
    }
}
