<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\GetTestQuestionTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * get_test_question ツールのテスト
 */
class GetTestQuestionToolTest extends TestCase
{
    private GetTestQuestionTool $tool;

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

        $this->tool = new GetTestQuestionTool(
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
                'name' => 'get_test_question',
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

    private function createQuestion(): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $course = $coursesTable->save($coursesTable->newEntity([
            'title' => 'テストコース',
            'sort_no' => 1,
            'user_id' => 1,
        ]));
        $this->assertNotFalse($course);

        $contentsTable = $this->getTableLocator()->get('Contents');
        $content = $contentsTable->save($contentsTable->newEntity([
            'course_id' => (int)$course->id,
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
            'question_type' => 'single',
            'options' => 'A|B',
            'correct' => '1',
            'score' => 1,
            'sort_no' => 1,
        ]));
        $this->assertNotFalse($question);

        return $question;
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
     * スタッフは問題詳細（正解含む）を取得できる
     */
    public function testStaffGetsQuestion(): void
    {
        $admin = $this->createUser('admin01', 'admin');
        $question = $this->createQuestion();

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$question->id,
        );

        $this->assertSame('問題1', $result['data']['title']);
        $this->assertSame('1', $result['data']['correct']);
    }

    /**
     * 非スタッフは取得できない
     */
    public function testNonStaffDenied(): void
    {
        $user = $this->createUser('user01', 'user');
        $question = $this->createQuestion();

        $this->assertToolCallError(
            'Only staff members can view test questions.',
            fn() => $this->tool->__invoke($this->makeContext((int)$user->id, 'user'), (int)$question->id),
        );
    }

    /**
     * 存在しない問題は not found
     */
    public function testNotFound(): void
    {
        $admin = $this->createUser('admin01', 'admin');

        $this->assertToolCallError(
            'Test question not found.',
            fn() => $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), 99999),
        );
    }
}
