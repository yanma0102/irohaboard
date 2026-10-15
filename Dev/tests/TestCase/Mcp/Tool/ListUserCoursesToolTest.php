<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\ListUserCoursesTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * list_user_courses ツールのテスト
 */
class ListUserCoursesToolTest extends TestCase
{
    private ListUserCoursesTool $tool;

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

        $this->tool = new ListUserCoursesTool(
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
                'name' => 'list_user_courses',
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

    private function createUser(string $username = 'tooluser', string $role = 'user'): EntityInterface
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

    private function enroll(int $userId, int $courseId): void
    {
        $table = $this->getTableLocator()->get('UsersCourses');
        $this->assertNotFalse($table->save($table->newEntity([
            'user_id' => $userId,
            'course_id' => $courseId,
        ])));
    }

    private function assertToolCallError(string $expectedMessage, callable $callback): void
    {
        try {
            $callback();
            $this->fail('ToolCallException がスローされていない');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
        }
    }

    /**
     * 割当済みコースを返す
     */
    public function testListsAssignedCourses(): void
    {
        $admin = $this->createUser('admin01', 'admin');
        $target = $this->createUser('user01');
        $course = $this->createCourse('割当コース');
        $this->createCourse('未割当コース');
        $this->enroll((int)$target->id, (int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$target->id,
        );

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('割当コース', $result['data'][0]['title']);
    }

    /**
     * 一般ユーザは他人のコース一覧を取得できない
     */
    public function testGeneralUserCannotViewOthers(): void
    {
        $user = $this->createUser('user01');
        $other = $this->createUser('user02');

        $this->assertToolCallError(
            'You can only list your own courses.',
            fn() => $this->tool->__invoke($this->makeContext((int)$user->id, 'user'), (int)$other->id),
        );
    }

    /**
     * 存在しないユーザは not found
     */
    public function testNotFound(): void
    {
        $admin = $this->createUser('admin01', 'admin');

        $this->assertToolCallError(
            'User not found.',
            fn() => $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), 99999),
        );
    }
}
