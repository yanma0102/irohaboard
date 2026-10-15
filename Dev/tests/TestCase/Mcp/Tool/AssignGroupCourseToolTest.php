<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\AssignGroupCourseTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * assign_group_course ツールのテスト
 */
class AssignGroupCourseToolTest extends TestCase
{
    private AssignGroupCourseTool $tool;

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

        $this->tool = new AssignGroupCourseTool(
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

    private function createGroup(string $title = 'テストグループ'): EntityInterface
    {
        $groupsTable = $this->getTableLocator()->get('Groups');
        $result = $groupsTable->save($groupsTable->newEntity(['title' => $title]));
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
     * グループにコースを割当できる（created=true）
     */
    public function testAssignsCourse(): void
    {
        $manager = $this->createUser('mgr01', 'manager');
        $group = $this->createGroup();
        $course = $this->createCourse();

        $result = $this->tool->__invoke(
            $this->makeContext('assign_group_course', (int)$manager->id, 'manager'),
            (int)$group->id,
            (int)$course->id,
        );

        $this->assertTrue($result['data']['created']);
        $this->assertTrue($this->getTableLocator()->get('GroupsCourses')->exists([
            'group_id' => (int)$group->id,
            'course_id' => (int)$course->id,
        ]));
    }

    /**
     * 既に割当済みなら created=false
     */
    public function testIdempotent(): void
    {
        $manager = $this->createUser('mgr01', 'manager');
        $group = $this->createGroup();
        $course = $this->createCourse();

        $groupsCoursesTable = $this->getTableLocator()->get('GroupsCourses');
        $groupsCoursesTable->save($groupsCoursesTable->newEntity([
            'group_id' => (int)$group->id,
            'course_id' => (int)$course->id,
        ]));

        $result = $this->tool->__invoke(
            $this->makeContext('assign_group_course', (int)$manager->id, 'manager'),
            (int)$group->id,
            (int)$course->id,
        );

        $this->assertFalse($result['data']['created']);
    }

    /**
     * 存在しないコースは not found
     */
    public function testCourseNotFound(): void
    {
        $manager = $this->createUser('mgr01', 'manager');
        $group = $this->createGroup();

        $this->assertToolCallError(
            'Course not found.',
            fn() => $this->tool->__invoke(
                $this->makeContext('assign_group_course', (int)$manager->id, 'manager'),
                (int)$group->id,
                99999,
            ),
        );
    }
}
