<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\UpdateContentTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * update_content ツールのテスト
 *
 * 権限（staff / コース参加）・部分更新・バリデーションを検証する。
 */
class UpdateContentToolTest extends TestCase
{
    private UpdateContentTool $tool;

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

        $this->tool = new UpdateContentTool(
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
                'name' => 'update_content',
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

    private function createContent(int $courseId, array $overrides = []): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity(array_merge([
            'course_id' => $courseId,
            'user_id' => 1,
            'title' => 'テストコンテンツ',
            'kind' => 'markdown',
            'body' => '# 元の本文',
            'status' => 0,
            'sort_no' => 1,
        ], $overrides));
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    // ----------------------------------------------------------------
    // 正常系
    // ----------------------------------------------------------------

    /**
     * スタッフが部分更新できる（指定フィールドのみ変更）
     */
    public function testStaffPartialUpdate(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('uadmin01', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            '更新後タイトル',
        );

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('更新後タイトル', $result['data']['title']);

        $saved = $this->getTableLocator()->get('Contents')->get((int)$content->id);
        $this->assertSame('更新後タイトル', $saved->title);
        // 指定していないフィールドは変更されない
        $this->assertSame('# 元の本文', $saved->body);
    }

    /**
     * 本文の更新ができる
     */
    public function testUpdateBody(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('uadmin02', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            null,
            null,
            "# 新しい本文\n\n内容",
        );

        $this->assertArrayHasKey('data', $result);
        $saved = $this->getTableLocator()->get('Contents')->get((int)$content->id);
        $this->assertSame("# 新しい本文\n\n内容", $saved->body);
    }

    /**
     * status を変更して公開できる
     */
    public function testPublishViaStatusUpdate(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('uadmin03', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, ['status' => 0]);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            null,
            null,
            null,
            1,
        );

        $this->assertSame(1, $result['data']['status']);
        $saved = $this->getTableLocator()->get('Contents')->get((int)$content->id);
        $this->assertSame(1, (int)$saved->status);
    }

    // ----------------------------------------------------------------
    // 権限制御
    // ----------------------------------------------------------------

    /**
     * 非スタッフは更新できない
     */
    public function testNonStaffDenied(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('uuser01');
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createContent((int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$user->id),
            (int)$content->id,
            '改ざんタイトル',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Only staff members can update content.', $result['error']);
    }

    /**
     * コース未参加のスタッフは更新できない
     */
    public function testStaffWithoutEnrollmentDenied(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('uadmin04', 'admin');
        $content = $this->createContent((int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            '改ざんタイトル',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Access denied to this content.', $result['error']);
    }

    /**
     * 存在しないコンテンツ → Content not found
     */
    public function testNotFound(): void
    {
        $admin = $this->createUser('uadmin05', 'admin');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            999999,
            'タイトル',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Content not found.', $result['error']);
    }

    /**
     * 削除済みコンテンツは更新できない
     */
    public function testDeletedContentNotFound(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('uadmin06', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id);

        $content->deleted = date('Y-m-d H:i:s');
        $this->assertNotFalse($this->getTableLocator()->get('Contents')->save($content));

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            'タイトル',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Content not found.', $result['error']);
    }

    // ----------------------------------------------------------------
    // バリデーション
    // ----------------------------------------------------------------

    /**
     * 更新フィールドなし → No fields to update
     */
    public function testNoFieldsToUpdate(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('uadmin07', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('No fields to update.', $result['error']);
    }

    /**
     * content_kind 設定外の kind は拒否される
     */
    public function testInvalidKindRejected(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('uadmin08', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            null,
            'bogus_kind',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Invalid kind', $result['error']);
    }

    /**
     * kind=markdown の body を空にできない（ContentsTable ルール）
     */
    public function testBodyCannotBeEmptiedForMarkdown(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('uadmin09', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            null,
            null,
            '',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Validation failed.', $result['error']);
        $this->assertArrayHasKey('body', $result['details']);
    }
}
