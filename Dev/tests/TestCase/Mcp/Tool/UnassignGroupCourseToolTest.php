<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\UnassignGroupCourseTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * unassign_group_course ツールのテスト
 */
class UnassignGroupCourseToolTest extends TestCase
{
    private UnassignGroupCourseTool $tool;

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

        $this->tool = new UnassignGroupCourseTool(
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
     * グループのコース割当を解除できる
     */
    public function testUnassignsCourse(): void
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
            $this->makeContext('unassign_group_course', (int)$manager->id, 'manager'),
            (int)$group->id,
            (int)$course->id,
        );

        $this->assertTrue($result['data']['deleted']);
        $this->assertFalse($groupsCoursesTable->exists([
            'group_id' => (int)$group->id,
            'course_id' => (int)$course->id,
        ]));
    }

    /**
     * 未割当なら deleted=false
     */
    public function testNotAssigned(): void
    {
        $manager = $this->createUser('mgr01', 'manager');
        $group = $this->createGroup();
        $course = $this->createCourse();

        $result = $this->tool->__invoke(
            $this->makeContext('unassign_group_course', (int)$manager->id, 'manager'),
            (int)$group->id,
            (int)$course->id,
        );

        $this->assertFalse($result['data']['deleted']);
    }

    /**
     * 存在しないグループは not found
     */
    public function testGroupNotFound(): void
    {
        $manager = $this->createUser('mgr01', 'manager');
        $course = $this->createCourse();

        $this->assertToolCallError(
            'Group not found.',
            fn() => $this->tool->__invoke(
                $this->makeContext('unassign_group_course', (int)$manager->id, 'manager'),
                99999,
                (int)$course->id,
            ),
        );
    }
}
