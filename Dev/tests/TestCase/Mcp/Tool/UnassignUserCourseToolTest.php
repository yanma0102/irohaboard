<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\UnassignUserCourseTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * unassign_user_course ツールのテスト
 */
class UnassignUserCourseToolTest extends TestCase
{
    private UnassignUserCourseTool $tool;

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

        $this->tool = new UnassignUserCourseTool(
            new AccessControlService($this->getTableLocator()->get('Users')->getConnection()),
        );
    }

    private function makeContext(string $tool, int $userId, string $role = 'user'): RequestContext
    {
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
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
     * 割当を解除できる
     */
    public function testUnassignsCourse(): void
    {
        $manager = $this->createUser('mgr01', 'manager');
        $target = $this->createUser('user01', 'user');
        $course = $this->createCourse();
        $this->enroll((int)$target->id, (int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext('unassign_user_course', (int)$manager->id, 'manager'),
            (int)$target->id,
            (int)$course->id,
        );

        $this->assertTrue($result['data']['deleted']);
        $this->assertFalse($this->getTableLocator()->get('UsersCourses')->exists([
            'user_id' => (int)$target->id,
            'course_id' => (int)$course->id,
        ]));
    }

    /**
     * 未割当なら deleted=false
     */
    public function testNotAssigned(): void
    {
        $manager = $this->createUser('mgr01', 'manager');
        $target = $this->createUser('user01', 'user');
        $course = $this->createCourse();

        $result = $this->tool->__invoke(
            $this->makeContext('unassign_user_course', (int)$manager->id, 'manager'),
            (int)$target->id,
            (int)$course->id,
        );

        $this->assertFalse($result['data']['deleted']);
    }

    /**
     * 存在しないユーザは not found
     */
    public function testUserNotFound(): void
    {
        $manager = $this->createUser('mgr01', 'manager');
        $course = $this->createCourse();

        $this->assertToolCallError(
            'User not found.',
            fn() => $this->tool->__invoke(
                $this->makeContext('unassign_user_course', (int)$manager->id, 'manager'),
                99999,
                (int)$course->id,
            ),
        );
    }
}
