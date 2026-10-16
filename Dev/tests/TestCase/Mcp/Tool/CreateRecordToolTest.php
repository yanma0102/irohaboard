<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\CreateRecordTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * create_record ツールのテスト
 */
class CreateRecordToolTest extends TestCase
{
    private CreateRecordTool $tool;

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

        $this->tool = new CreateRecordTool(
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
                'name' => 'create_record',
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

    private function createUser(string $username = 'cruser', string $role = 'user'): EntityInterface
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

    private function enroll(int $userId, int $courseId): void
    {
        $table = $this->getTableLocator()->get('UsersCourses');
        $this->assertNotFalse($table->save($table->newEntity([
            'user_id' => $userId,
            'course_id' => $courseId,
        ])));
    }

    private function createContent(int $courseId, string $kind = 'html', int $status = 1): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $result = $contentsTable->save($contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => 1,
            'title' => 'テストコンテンツ',
            'kind' => $kind,
            'body' => $kind === 'test' ? '' : '<p>x</p>',
            'status' => $status,
            'sort_no' => 1,
        ]));
        $this->assertNotFalse($result);

        return $result;
    }

    /**
     * 受講者が公開コンテンツの学習履歴を記録できる（is_passed は -1）
     */
    public function testUserRecordsProgress(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('cruser01');
        $this->enroll((int)$user->id, (int)$course->id);
        $content = $this->createContent((int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$user->id, 'user'),
            (int)$content->id,
            120,
            3,
            1,
        );

        $this->assertSame(120, $result['data']['study_sec']);
        $this->assertSame(3, $result['data']['understanding']);
        $this->assertSame(1, $result['data']['is_complete']);

        $record = $this->getTableLocator()->get('Records')->get((int)$result['data']['id']);
        $this->assertSame(-1, (int)$record->is_passed);
        $this->assertSame((int)$user->id, (int)$record->user_id);
    }

    /**
     * 未割当コースのコンテンツは記録できない
     */
    public function testAccessDeniedForUnenrolledCourse(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('cruser02');
        $content = $this->createContent((int)$course->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Access denied to this content.');

        $this->tool->__invoke($this->makeContext((int)$user->id, 'user'), (int)$content->id);
    }

    /**
     * 非公開コンテンツは管理者以外は記録できない
     */
    public function testUnpublishedContentRejectedForNonAdmin(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('cruser03');
        $this->enroll((int)$user->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, 'html', 0);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('This content is not published.');

        $this->tool->__invoke($this->makeContext((int)$user->id, 'user'), (int)$content->id);
    }

    /**
     * テストコンテンツには使えない
     */
    public function testTestContentRejected(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('cruser04');
        $this->enroll((int)$user->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, 'test');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('create_record can only be used for non-test content.');

        $this->tool->__invoke($this->makeContext((int)$user->id, 'user'), (int)$content->id);
    }
}
