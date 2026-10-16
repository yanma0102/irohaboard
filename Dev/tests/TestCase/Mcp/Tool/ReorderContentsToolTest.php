<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\ReorderContentsTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * reorder_contents ツールのテスト
 */
class ReorderContentsToolTest extends TestCase
{
    private ReorderContentsTool $tool;

    public function setUp(): void
    {
        parent::setUp();

        Configure::load('ib_config');

        foreach (
            [
            'UserTokens', 'Users', 'UsersCourses', 'GroupsCourses',
            'UsersGroups', 'Groups', 'Courses', 'Contents', 'Records', 'Logs',
            ] as $table
        ) {
            $this->getTableLocator()->get($table)->deleteAll('1 = 1');
        }

        $this->tool = new ReorderContentsTool(
            new AccessControlService($this->getTableLocator()->get('Users')->getConnection()),
        );
    }

    private function makeContext(int $userId, string $role = 'user'): RequestContext
    {
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'reorder_contents',
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

        return new RequestContext($this->createStub(SessionInterface::class), $request);
    }

    private function createUser(string $username = 'rcuser', string $role = 'admin'): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $result = $usersTable->save($usersTable->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テストユーザ',
            'role' => $role,
            'email' => $username . '@example.com',
        ]));
        $this->assertNotFalse($result);

        return $result;
    }

    private function createCourse(string $title = 'テストコース'): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $result = $coursesTable->save($coursesTable->newEntity([
            'title' => $title,
            'sort_no' => 1,
            'user_id' => 1,
        ]));
        $this->assertNotFalse($result);

        return $result;
    }

    private function createContent(int $courseId, string $title, int $sortNo): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $result = $contentsTable->save($contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => 1,
            'title' => $title,
            'kind' => 'html',
            'body' => '<p>x</p>',
            'status' => 1,
            'sort_no' => $sortNo,
        ]));
        $this->assertNotFalse($result);

        return $result;
    }

    /**
     * 指定順に sort_no が振り直される
     */
    public function testReorders(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('rcadmin01');
        $first = $this->createContent((int)$course->id, 'A', 1);
        $second = $this->createContent((int)$course->id, 'B', 2);
        $third = $this->createContent((int)$course->id, 'C', 3);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            [(int)$third->id, (int)$first->id, (int)$second->id],
        );

        $this->assertSame(
            [(int)$third->id, (int)$first->id, (int)$second->id],
            $result['data']['content_ids'],
        );

        $contentsTable = $this->getTableLocator()->get('Contents');
        $this->assertSame(1, (int)$contentsTable->get((int)$third->id)->sort_no);
        $this->assertSame(2, (int)$contentsTable->get((int)$first->id)->sort_no);
        $this->assertSame(3, (int)$contentsTable->get((int)$second->id)->sort_no);
    }

    /**
     * 別コースのコンテンツIDを混ぜると拒否される
     */
    public function testRejectsContentOfAnotherCourse(): void
    {
        $course = $this->createCourse();
        $otherCourse = $this->createCourse('別コース');
        $admin = $this->createUser('rcadmin02');
        $content = $this->createContent((int)$course->id, 'A', 1);
        $other = $this->createContent((int)$otherCourse->id, '他', 1);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('does not belong to this course');

        $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            [(int)$content->id, (int)$other->id],
        );
    }

    /**
     * content_ids が空の場合は拒否される
     */
    public function testRejectsEmptyList(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('rcadmin03');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('content_ids must not be empty.');

        $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), (int)$course->id, []);
    }

    /**
     * 非スタッフは拒否される
     */
    public function testNonStaffDenied(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('rcuser01', 'user');
        $content = $this->createContent((int)$course->id, 'A', 1);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Only staff members can reorder contents.');

        $this->tool->__invoke(
            $this->makeContext((int)$user->id, 'user'),
            (int)$course->id,
            [(int)$content->id],
        );
    }
}
