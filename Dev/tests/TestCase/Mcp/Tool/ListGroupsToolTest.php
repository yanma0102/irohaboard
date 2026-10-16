<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\ListGroupsTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * list_groups ツールのテスト
 */
class ListGroupsToolTest extends TestCase
{
    private ListGroupsTool $tool;

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

        $this->tool = new ListGroupsTool(
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
                'name' => 'list_groups',
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

    private function assignUser(int $userId, int $groupId): void
    {
        $table = $this->getTableLocator()->get('UsersGroups');
        $this->assertNotFalse($table->save($table->newEntity([
            'user_id' => $userId,
            'group_id' => $groupId,
        ])));
    }

    /**
     * スタッフは全グループを参照できる
     */
    public function testStaffSeesAllGroups(): void
    {
        $admin = $this->createUser('admin01', 'admin');
        $this->createGroup('グループA');
        $this->createGroup('グループB');

        $result = $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'));

        $this->assertSame(2, $result['meta']['total']);
    }

    /**
     * 一般ユーザは所属グループのみ参照できる
     */
    public function testGeneralUserSeesOwnGroupsOnly(): void
    {
        $user = $this->createUser('user01');
        $joined = $this->createGroup('所属グループ');
        $this->createGroup('未所属グループ');
        $this->assignUser((int)$user->id, (int)$joined->id);

        $result = $this->tool->__invoke($this->makeContext((int)$user->id, 'user'));

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('所属グループ', $result['data'][0]['title']);
    }

    /**
     * 所属グループが無い一般ユーザは空になる
     */
    public function testGeneralUserWithoutGroups(): void
    {
        $user = $this->createUser('user01');
        $this->createGroup('グループA');

        $result = $this->tool->__invoke($this->makeContext((int)$user->id, 'user'));

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['meta']['total']);
    }
}
