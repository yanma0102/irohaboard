<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\DeleteCourseTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * delete_course ツールのテスト
 */
class DeleteCourseToolTest extends TestCase
{
    private DeleteCourseTool $tool;

    public function setUp(): void
    {
        parent::setUp();
        Configure::load('ib_config');

        foreach (
            [
            'UserTokens', 'Users', 'UsersCourses', 'GroupsCourses',
            'UsersGroups', 'Groups', 'Courses', 'Contents', 'ContentsQuestions',
            'Records', 'Logs',
            ] as $table
        ) {
            $this->getTableLocator()->get($table)->deleteAll('1 = 1');
        }

        $this->tool = new DeleteCourseTool(
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

    private function createContent(int $courseId): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $result = $contentsTable->save($contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => 1,
            'title' => 'テストコンテンツ',
            'kind' => 'html',
            'body' => '<p>x</p>',
            'status' => 1,
            'sort_no' => 1,
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
     * コースと配下のコンテンツが削除される
     */
    public function testDeletesCourseAndContents(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('admin01', 'admin');
        $this->createContent((int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext('delete_course', (int)$admin->id, 'admin'),
            (int)$course->id,
        );

        $this->assertTrue($result['data']['deleted']);
        $this->assertFalse($this->getTableLocator()->get('Courses')->exists(['id' => (int)$course->id]));
        $this->assertSame(
            0,
            $this->getTableLocator()->get('Contents')->find()->where(['course_id' => (int)$course->id])->count(),
        );
    }

    /**
     * 存在しないコースは not found
     */
    public function testNotFound(): void
    {
        $admin = $this->createUser('admin01', 'admin');

        $this->assertToolCallError(
            'Course not found.',
            fn() => $this->tool->__invoke($this->makeContext('delete_course', (int)$admin->id, 'admin'), 99999),
        );
    }

    /**
     * editor は削除できない（manager 以上のみ）
     */
    public function testNonManagerDenied(): void
    {
        $course = $this->createCourse();
        $editor = $this->createUser('editor01', 'editor');

        $this->assertToolCallError(
            'Only administrators or managers can delete courses.',
            fn() => $this->tool->__invoke(
                $this->makeContext('delete_course', (int)$editor->id, 'editor'),
                (int)$course->id,
            ),
        );
    }
}
