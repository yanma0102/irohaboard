<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\ListEnqueteQuestionsTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * list_enquete_questions ツールのテスト
 */
class ListEnqueteQuestionsToolTest extends TestCase
{
    private ListEnqueteQuestionsTool $tool;

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

        $this->tool = new ListEnqueteQuestionsTool(
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
                'name' => 'list_enquete_questions',
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

    private function createEnqueteContent(): EntityInterface
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
            'title' => 'アンケート',
            'kind' => 'enquete',
            'body' => '',
            'status' => 1,
            'sort_no' => 1,
        ]));
        $this->assertNotFalse($content);

        $questionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $saved = $questionsTable->save($questionsTable->newEntity([
            'content_id' => (int)$content->id,
            'title' => '質問1',
            'body' => '質問文',
            'question_type' => 'text',
            'correct' => '',
            'score' => 0,
            'sort_no' => 1,
        ]));
        $this->assertNotFalse($saved);

        return $content;
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
     * スタッフはアンケート質問一覧を取得できる
     */
    public function testStaffListsQuestions(): void
    {
        $admin = $this->createUser('admin01', 'admin');
        $content = $this->createEnqueteContent();

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
        );

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('質問1', $result['data'][0]['title']);
    }

    /**
     * 非スタッフは取得できない
     */
    public function testNonStaffDenied(): void
    {
        $user = $this->createUser('user01', 'user');
        $content = $this->createEnqueteContent();

        $this->assertToolCallError(
            'Only staff members can list enquete questions.',
            fn() => $this->tool->__invoke($this->makeContext((int)$user->id, 'user'), (int)$content->id),
        );
    }

    /**
     * 存在しないコンテンツは not found
     */
    public function testContentNotFound(): void
    {
        $admin = $this->createUser('admin01', 'admin');

        $this->assertToolCallError(
            'Content not found.',
            fn() => $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), 99999),
        );
    }
}
