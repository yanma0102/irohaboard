<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\CopyContentTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * copy_content ツールのテスト
 */
class CopyContentToolTest extends TestCase
{
    private CopyContentTool $tool;

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

        $this->tool = new CopyContentTool(
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
                'name' => 'copy_content',
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

    private function createUser(string $username = 'ccuser', string $role = 'admin'): EntityInterface
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

    private function createContent(int $courseId, string $kind = 'test', int $sortNo = 1): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $result = $contentsTable->save($contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => 1,
            'title' => 'テストコンテンツ',
            'kind' => $kind,
            'body' => $kind === 'test' ? '' : '<p>x</p>',
            'status' => 1,
            'sort_no' => $sortNo,
        ]));
        $this->assertNotFalse($result);

        return $result;
    }

    /**
     * 同じコースへ複製し、テスト問題も複製される
     */
    public function testCopiesContentAndQuestions(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('ccadmin01');
        $content = $this->createContent((int)$course->id);

        $questionsTable = $this->getTableLocator()->get('ContentsQuestions');
        foreach ([1, 2] as $sortNo) {
            $saved = $questionsTable->save($questionsTable->newEntity([
                'content_id' => (int)$content->id,
                'title' => '問題' . $sortNo,
                'body' => '問題文' . $sortNo,
                'question_type' => 'text',
                'score' => 1,
                'sort_no' => $sortNo,
            ]));
            $this->assertNotFalse($saved);
        }

        $result = $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), (int)$content->id);

        $newId = (int)$result['data']['id'];
        $this->assertNotSame((int)$content->id, $newId);
        $this->assertSame('テストコンテンツの複製', $result['data']['title']);
        $this->assertSame(0, $result['data']['status']);
        $this->assertSame(2, $result['data']['questions']);

        $copied = $this->getTableLocator()->get('Contents')->get($newId);
        $this->assertSame((int)$course->id, (int)$copied->course_id);
        $this->assertSame(0, (int)$copied->status);
        $this->assertSame(2, $questionsTable->find()->where(['content_id' => $newId])->count());
    }

    /**
     * target_course_id を指定すると別コースへ複製される
     */
    public function testCopiesToAnotherCourse(): void
    {
        $course = $this->createCourse();
        $target = $this->createCourse('コピー先');
        $admin = $this->createUser('ccadmin02');
        $content = $this->createContent((int)$course->id, 'markdown');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            (int)$target->id,
        );

        $this->assertSame((int)$target->id, $result['data']['course_id']);
        $this->assertSame((int)$target->id, (int)$this->getTableLocator()->get('Contents')->get((int)$result['data']['id'])->course_id);
    }

    /**
     * 存在しないコンテンツは not found
     */
    public function testNotFound(): void
    {
        $admin = $this->createUser('ccadmin03');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Content not found.');

        $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), 99999);
    }

    /**
     * 非スタッフは拒否される
     */
    public function testNonStaffDenied(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('ccuser01', 'user');
        $content = $this->createContent((int)$course->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Only staff members can copy content.');

        $this->tool->__invoke($this->makeContext((int)$user->id, 'user'), (int)$content->id);
    }
}
