<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\GetGroupTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * get_group ツールのテスト
 */
class GetGroupToolTest extends TestCase
{
    private GetGroupTool $tool;

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

        $this->tool = new GetGroupTool(
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
                'name' => 'get_group',
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
     * スタッフは任意のグループを参照できる
     */
    public function testStaffCanViewAnyGroup(): void
    {
        $admin = $this->createUser('admin01', 'admin');
        $group = $this->createGroup('グループA');

        $result = $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), (int)$group->id);

        $this->assertSame('グループA', $result['data']['title']);
    }

    /**
     * 一般ユーザは未所属グループを参照できない
     */
    public function testNonMemberDenied(): void
    {
        $user = $this->createUser('user01');
        $group = $this->createGroup();

        $this->assertToolCallError(
            'Group not found.',
            fn() => $this->tool->__invoke($this->makeContext((int)$user->id, 'user'), (int)$group->id),
        );
    }

    /**
     * 所属していれば一般ユーザも参照できる
     */
    public function testMemberCanView(): void
    {
        $user = $this->createUser('user01');
        $group = $this->createGroup('グループA');

        $usersGroupsTable = $this->getTableLocator()->get('UsersGroups');
        $usersGroupsTable->save($usersGroupsTable->newEntity([
            'user_id' => (int)$user->id,
            'group_id' => (int)$group->id,
        ]));

        $result = $this->tool->__invoke($this->makeContext((int)$user->id, 'user'), (int)$group->id);

        $this->assertSame('グループA', $result['data']['title']);
    }

    /**
     * 存在しないグループは not found
     */
    public function testNotFound(): void
    {
        $admin = $this->createUser('admin01', 'admin');

        $this->assertToolCallError(
            'Group not found.',
            fn() => $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'), 99999),
        );
    }
}
