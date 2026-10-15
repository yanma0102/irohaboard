<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\DeleteTestQuestionTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * delete_test_question ツールのテスト
 */
class DeleteTestQuestionToolTest extends TestCase
{
    private DeleteTestQuestionTool $tool;

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

        $this->tool = new DeleteTestQuestionTool(
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
                'name' => 'delete_test_question',
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

    private function createUser(string $username = 'dquser', string $role = 'admin'): EntityInterface
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

    private function createQuestion(int $courseId): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $content = $contentsTable->save($contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => 1,
            'title' => 'テスト',
            'kind' => 'test',
            'body' => '',
            'status' => 1,
            'sort_no' => 1,
        ]));
        $this->assertNotFalse($content);

        $questionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $question = $questionsTable->save($questionsTable->newEntity([
            'content_id' => (int)$content->id,
            'title' => '問題1',
            'body' => '問題文',
            'question_type' => 'text',
            'score' => 1,
            'sort_no' => 1,
        ]));
        $this->assertNotFalse($question);

        return $question;
    }

    /**
     * スタッフはテスト問題を削除できる
     */
    public function testStaffDeletesQuestion(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('dqadmin01');
        $question = $this->createQuestion((int)$course->id);

        $result = $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), (int)$question->id);

        $this->assertTrue($result['data']['deleted']);
        $this->assertFalse($this->getTableLocator()->get('ContentsQuestions')->exists(['id' => (int)$question->id]));
    }

    /**
     * 存在しない問題は not found
     */
    public function testNotFound(): void
    {
        $admin = $this->createUser('dqadmin02');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Test question not found.');

        $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), 99999);
    }

    /**
     * 非スタッフは拒否される
     */
    public function testNonStaffDenied(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('dquser01', 'user');
        $question = $this->createQuestion((int)$course->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Only staff members can delete test questions.');

        $this->tool->__invoke($this->makeContext((int)$user->id, 'user'), (int)$question->id);
    }
}
