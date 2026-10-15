<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\ListUsersTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * list_users ツールのテスト
 */
class ListUsersToolTest extends TestCase
{
    private ListUsersTool $tool;

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

        $this->tool = new ListUsersTool(
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
                'name' => 'list_users',
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
            'name' => $username . 'の名前',
            'role' => $role,
            'email' => $username . '@example.com',
        ]));
        $this->assertNotFalse($result);

        return $result;
    }

    /**
     * スタッフは全ユーザを参照でき、password は含まれない
     */
    public function testStaffSeesAllUsers(): void
    {
        $admin = $this->createUser('admin01', 'admin');
        $this->createUser('user01');
        $this->createUser('user02');

        $result = $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'));

        $this->assertSame(3, $result['meta']['total']);
        $this->assertCount(3, $result['data']);
        $this->assertArrayNotHasKey('password', $result['data'][0]);
    }

    /**
     * スタッフは role / username で絞り込める
     */
    public function testStaffFiltersByRoleAndUsername(): void
    {
        $admin = $this->createUser('admin01', 'admin');
        $this->createUser('teacher01', 'teacher');
        $this->createUser('user01');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            'user0',
            null,
            'user',
        );

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('user01', $result['data'][0]['username']);
    }

    /**
     * 一般ユーザは自分のレコードのみ
     */
    public function testGeneralUserSeesOnlySelf(): void
    {
        $user = $this->createUser('user01');
        $this->createUser('user02', 'admin');

        $result = $this->tool->__invoke($this->makeContext((int)$user->id, 'user'));

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame((int)$user->id, (int)$result['data'][0]['id']);
    }

    /**
     * limit は 1〜200 にクランプされる
     */
    public function testLimitIsClamped(): void
    {
        $admin = $this->createUser('admin01', 'admin');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            null,
            null,
            null,
            1,
            500,
        );

        $this->assertSame(200, $result['meta']['limit']);
    }
}
