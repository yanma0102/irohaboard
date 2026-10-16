<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\DeleteGroupTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * delete_group ツールのテスト
 */
class DeleteGroupToolTest extends TestCase
{
    private DeleteGroupTool $tool;

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

        $this->tool = new DeleteGroupTool(
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
     * グループと中間テーブルが削除される
     */
    public function testDeletesGroup(): void
    {
        $group = $this->createGroup();
        $admin = $this->createUser('admin01', 'admin');
        $user = $this->createUser('user01', 'user');

        $usersGroupsTable = $this->getTableLocator()->get('UsersGroups');
        $usersGroupsTable->save($usersGroupsTable->newEntity([
            'user_id' => (int)$user->id,
            'group_id' => (int)$group->id,
        ]));

        $result = $this->tool->__invoke(
            $this->makeContext('delete_group', (int)$admin->id, 'admin'),
            (int)$group->id,
        );

        $this->assertTrue($result['data']['deleted']);
        $this->assertFalse($this->getTableLocator()->get('Groups')->exists(['id' => (int)$group->id]));
        $this->assertSame(0, $usersGroupsTable->find()->where(['group_id' => (int)$group->id])->count());
    }

    /**
     * 存在しないグループは not found
     */
    public function testNotFound(): void
    {
        $admin = $this->createUser('admin01', 'admin');

        $this->assertToolCallError(
            'Group not found.',
            fn() => $this->tool->__invoke($this->makeContext('delete_group', (int)$admin->id, 'admin'), 99999),
        );
    }

    /**
     * editor は削除できない（manager 以上のみ）
     */
    public function testNonManagerDenied(): void
    {
        $group = $this->createGroup();
        $editor = $this->createUser('editor01', 'editor');

        $this->assertToolCallError(
            'Only administrators or managers can delete groups.',
            fn() => $this->tool->__invoke(
                $this->makeContext('delete_group', (int)$editor->id, 'editor'),
                (int)$group->id,
            ),
        );
    }
}
