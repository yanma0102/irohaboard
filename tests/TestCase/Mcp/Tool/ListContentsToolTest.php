<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\ListContentsTool;
use App\Service\AccessControlService;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * list_contents ツールのテスト
 *
 * 権限（staff / 受講生）・公開状態・kind フィルタ・ページングを検証する。
 * RequestContext は final クラスのためモックではなく、
 * CallToolRequest::fromArray() で params._meta.oauth を持つ実体を構築する。
 */
class ListContentsToolTest extends TestCase
{
    private ListContentsTool $tool;

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

        $this->tool = new ListContentsTool(
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
                'name' => 'list_contents',
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
            'kind' => 'html',
            'body' => '<p>本文</p>',
            'status' => 1,
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
     * スタッフは未公開コンテンツも含めて一覧できる
     */
    public function testStaffSeesAllContentsIncludingUnpublished(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('admin01', 'admin');
        $this->createContent((int)$course->id, ['title' => '公開済み', 'status' => 1]);
        $this->createContent((int)$course->id, ['title' => '下書き', 'status' => 0, 'sort_no' => 2]);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
        );

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);
        $this->assertSame(2, $result['meta']['total']);
    }

    /**
     * 受講生は公開済みコンテンツのみ取得できる
     */
    public function testStudentSeesPublishedOnly(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('student01');
        $this->enrollUser((int)$user->id, (int)$course->id);
        $this->createContent((int)$course->id, ['title' => '公開済み', 'status' => 1]);
        $this->createContent((int)$course->id, ['title' => '下書き', 'status' => 0, 'sort_no' => 2]);

        $result = $this->tool->__invoke($this->makeContext((int)$user->id), (int)$course->id);

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertSame('公開済み', $result['data'][0]['title']);
        $this->assertSame(1, $result['meta']['total']);
    }

    /**
     * kind フィルタが機能する
     */
    public function testKindFilter(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('admin02', 'admin');
        $this->createContent((int)$course->id, ['title' => 'HTML', 'kind' => 'html']);
        $this->createContent((int)$course->id, ['title' => 'MD', 'kind' => 'markdown', 'sort_no' => 2]);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            'markdown',
        );

        $this->assertCount(1, $result['data']);
        $this->assertSame('MD', $result['data'][0]['title']);
    }

    /**
     * ページング meta が正しい
     */
    public function testPaginationMeta(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('admin03', 'admin');
        for ($i = 1; $i <= 3; $i++) {
            $this->createContent((int)$course->id, ['title' => 'C' . $i, 'sort_no' => $i]);
        }

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            null,
            2,
            2,
        );

        $this->assertCount(1, $result['data']);
        $this->assertSame(2, $result['meta']['page']);
        $this->assertSame(2, $result['meta']['limit']);
        $this->assertSame(3, $result['meta']['total']);
        $this->assertSame(1, $result['meta']['count']);
        $this->assertSame('C3', $result['data'][0]['title']);
    }

    // ----------------------------------------------------------------
    // 権限制御
    // ----------------------------------------------------------------

    /**
     * 未受講のコース → Access denied
     */
    public function testAccessDeniedForNonEnrolledStudent(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('stranger01');
        $this->createContent((int)$course->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Access denied to this course.');

        $this->tool->__invoke($this->makeContext((int)$user->id), (int)$course->id);
    }

    /**
     * 削除済みコンテンツは一覧に含まれない
     */
    public function testDeletedContentsExcluded(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('admin04', 'admin');
        $content = $this->createContent((int)$course->id, ['title' => '削除対象']);

        $contentsTable = $this->getTableLocator()->get('Contents');
        $content->deleted = date('Y-m-d H:i:s');
        $this->assertNotFalse($contentsTable->save($content));

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
        );

        $this->assertCount(0, $result['data']);
        $this->assertSame(0, $result['meta']['total']);
    }
}
