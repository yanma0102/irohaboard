<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\GetRecordTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * get_record ツールのテスト
 */
class GetRecordToolTest extends TestCase
{
    private GetRecordTool $tool;

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

        $this->tool = new GetRecordTool(
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
                'name' => 'get_record',
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

    private function createRecord(int $userId): EntityInterface
    {
        $recordsTable = $this->getTableLocator()->get('Records');
        $result = $recordsTable->save($recordsTable->newEntity([
            'user_id' => $userId,
            'course_id' => 1,
            'content_id' => 1,
            'study_sec' => 60,
            'understanding' => 3,
            'is_passed' => -1,
            'is_complete' => 1,
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
     * スタッフは任意の記録を参照できる
     */
    public function testStaffCanViewAnyRecord(): void
    {
        $admin = $this->createUser('admin01', 'admin');
        $target = $this->createUser('user01');
        $record = $this->createRecord((int)$target->id);

        $result = $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), (int)$record->id);

        $this->assertSame((int)$target->id, (int)$result['data']['user_id']);
        $this->assertSame(60, (int)$result['data']['study_sec']);
    }

    /**
     * 一般ユーザは他人の記録を参照できない
     */
    public function testGeneralUserCannotViewOthers(): void
    {
        $user = $this->createUser('user01');
        $other = $this->createUser('user02');
        $record = $this->createRecord((int)$other->id);

        $this->assertToolCallError(
            'You can only view your own records.',
            fn() => $this->tool->__invoke($this->makeContext((int)$user->id, 'user'), (int)$record->id),
        );
    }

    /**
     * 存在しない記録は not found
     */
    public function testNotFound(): void
    {
        $admin = $this->createUser('admin01', 'admin');

        $this->assertToolCallError(
            'Record not found.',
            fn() => $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), 99999),
        );
    }
}
