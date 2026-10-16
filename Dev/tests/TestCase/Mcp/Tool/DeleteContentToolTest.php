<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\DeleteContentTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * delete_content ツールのテスト
 */
class DeleteContentToolTest extends TestCase
{
    private DeleteContentTool $tool;

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

        $this->tool = new DeleteContentTool(
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
                'name' => 'delete_content',
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

    private function createUser(string $username = 'dcuser', string $role = 'admin'): EntityInterface
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

    private function createCourse(): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $result = $coursesTable->save($coursesTable->newEntity([
            'title' => 'テストコース',
            'sort_no' => 1,
            'user_id' => 1,
        ]));
        $this->assertNotFalse($result);

        return $result;
    }

    private function createContent(int $courseId, string $kind = 'html'): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $result = $contentsTable->save($contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => 1,
            'title' => 'テストコンテンツ',
            'kind' => $kind,
            'body' => '<p>x</p>',
            'status' => 1,
            'sort_no' => 1,
        ]));
        $this->assertNotFalse($result);

        return $result;
    }

    /**
     * スタッフはコンテンツと紐づくテスト問題を削除できる
     */
    public function testStaffDeletesContentWithQuestions(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('dcadmin01');
        $content = $this->createContent((int)$course->id, 'test');

        $questionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $questionsTable->save($questionsTable->newEntity([
            'content_id' => (int)$content->id,
            'title' => '問題1',
            'body' => '問題文',
            'question_type' => 'text',
            'score' => 1,
            'sort_no' => 1,
        ]));

        $result = $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), (int)$content->id);

        $this->assertTrue($result['data']['deleted']);
        $this->assertFalse($this->getTableLocator()->get('Contents')->exists(['id' => (int)$content->id]));
        $this->assertSame(0, $questionsTable->find()->where(['content_id' => (int)$content->id])->count());
    }

    /**
     * 非スタッフは削除できない
     */
    public function testNonStaffDenied(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('dcuser01', 'user');
        $content = $this->createContent((int)$course->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Only staff members can delete content.');

        $this->tool->__invoke($this->makeContext((int)$user->id, 'user'), (int)$content->id);
    }

    /**
     * 存在しないコンテンツは not found
     */
    public function testNotFound(): void
    {
        $admin = $this->createUser('dcadmin02');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Content not found.');

        $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), 99999);
    }
}
