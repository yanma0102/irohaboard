<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\CreateContentTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * create_content ツールのテスト
 *
 * 権限（staff / コース参加）・バリデーション・sort_no 自動採番を検証する。
 */
class CreateContentToolTest extends TestCase
{
    private CreateContentTool $tool;

    public function setUp(): void
    {
        parent::setUp();

        // Application::bootstrap() 相当（単体テストは Application を起動しないため）
        Configure::load('ib_config');

        foreach (
            [
            'UserTokens', 'Users', 'UsersCourses', 'GroupsCourses',
            'UsersGroups', 'Groups', 'Courses', 'Contents', 'Records', 'Logs',
            ] as $table
        ) {
            $this->getTableLocator()->get($table)->deleteAll('1 = 1');
        }

        $this->tool = new CreateContentTool(
            new AccessControlService($this->getTableLocator()->get('Users')->getConnection()),
        );
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    /**
     * oauth.user_id を _meta に持つ実 RequestContext を構築する
     */
    private function makeContext(int $userId, string $role = 'user'): RequestContext
    {
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_content',
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

    private function createUser(string $username = 'tooluser', string $role = 'user'): EntityInterface
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

    // ----------------------------------------------------------------
    // 正常系
    // ----------------------------------------------------------------

    /**
     * スタッフが Markdown コンテンツを新規作成できる
     */
    public function testStaffCreatesMarkdownContent(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('wadmin01', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            '新規レッスン',
            'markdown',
            "# 見出し\n\n**太字**",
        );

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('新規レッスン', $result['data']['title']);
        $this->assertSame('markdown', $result['data']['kind']);
        $this->assertSame(0, $result['data']['status']);
        $this->assertIsInt($result['data']['id']);

        $saved = $this->getTableLocator()->get('Contents')->get((int)$result['data']['id']);
        $this->assertSame("# 見出し\n\n**太字**", $saved->body);
        $this->assertSame((int)$admin->id, (int)$saved->user_id);
        $this->assertSame((int)$course->id, (int)$saved->course_id);
    }

    /**
     * 指定した status が保存される
     */
    public function testStatusIsSaved(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('wadmin02', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            '公開コンテンツ',
            'html',
            '<p>本文</p>',
            1,
        );

        $this->assertSame(1, $result['data']['status']);
    }

    /**
     * sort_no は自動採番される（既存の末尾 + 1）
     */
    public function testSortNoAutoIncrement(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('wadmin03', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);

        $contentsTable = $this->getTableLocator()->get('Contents');
        $existing = $contentsTable->newEntity([
            'course_id' => (int)$course->id,
            'user_id' => (int)$admin->id,
            'title' => '既存コンテンツ',
            'kind' => 'html',
            'body' => '<p>既存</p>',
            'status' => 1,
            'sort_no' => 3,
        ]);
        $this->assertNotFalse($contentsTable->save($existing));

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            '追加コンテンツ',
            'html',
            '<p>追加</p>',
        );

        $this->assertSame(4, $result['data']['sort_no']);
    }

    // ----------------------------------------------------------------
    // 権限制御
    // ----------------------------------------------------------------

    /**
     * 非スタッフは作成できない
     */
    public function testNonStaffDenied(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('wuser01');
        $this->enrollUser((int)$user->id, (int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$user->id),
            (int)$course->id,
            'タイトル',
            'html',
            '<p>x</p>',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Only staff members can create content.', $result['error']);
    }

    /**
     * コース未参加のスタッフは作成できない
     */
    public function testStaffWithoutEnrollmentDenied(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('wadmin04', 'admin');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            'タイトル',
            'html',
            '<p>x</p>',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Access denied to this course.', $result['error']);
    }

    // ----------------------------------------------------------------
    // バリデーション
    // ----------------------------------------------------------------

    /**
     * content_kind 設定外の kind は拒否される
     */
    public function testInvalidKindRejected(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('wadmin05', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            'タイトル',
            'bogus_kind',
            '本文',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Invalid kind', $result['error']);
    }

    /**
     * kind=markdown は body 必須（ContentsTable ルール）
     */
    public function testBodyRequiredForMarkdown(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('wadmin06', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            'タイトル',
            'markdown',
            '',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Validation failed.', $result['error']);
        $this->assertArrayHasKey('body', $result['details']);
    }
}
