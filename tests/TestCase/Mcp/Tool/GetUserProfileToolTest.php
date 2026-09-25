<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\GetUserProfileTool;
use App\Service\AccessControlService;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * get_user_profile ツールのテスト
 *
 * 本人参照・staff による他ユーザ参照・非 staff の他ユーザ拒否・
 * 不存在/削除済みユーザを検証する。
 */
class GetUserProfileToolTest extends TestCase
{
    private GetUserProfileTool $tool;

    public function setUp(): void
    {
        parent::setUp();

        foreach (
            [
            'UserTokens', 'Users', 'UsersCourses', 'GroupsCourses',
            'UsersGroups', 'Groups', 'Courses', 'Contents', 'Records', 'Logs',
            ] as $table
        ) {
            $this->getTableLocator()->get($table)->deleteAll('1 = 1');
        }

        $this->tool = new GetUserProfileTool(
            new AccessControlService($this->getTableLocator()->get('Users')->getConnection()),
        );
    }

    /**
     * oauth.user_id を _meta に持つ実 RequestContext を構築する
     */
    private function makeContext(int $userId, string $role = 'user'): RequestContext
    {
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_user_profile',
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

        return new RequestContext(
            $this->createStub(SessionInterface::class),
            $request,
        );
    }

    private function createUser(string $username = 'profuser', string $role = 'user'): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $entity = $usersTable->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テストユーザ',
            'role' => $role,
            'email' => $username . '@example.com',
        ]);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    // ----------------------------------------------------------------
    // 正常系
    // ----------------------------------------------------------------

    /**
     * 本人参照（user_id 省略）→ password は含まれない
     */
    public function testSelfProfileWithoutPassword(): void
    {
        $user = $this->createUser('profme');

        $result = $this->tool->__invoke($this->makeContext((int)$user->id));

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('profme', $result['data']['username']);
        $this->assertArrayNotHasKey('password', $result['data']);
    }

    /**
     * staff は他ユーザのプロフィールを参照できる
     */
    public function testStaffViewsOtherUserProfile(): void
    {
        $target = $this->createUser('proftarget');
        $admin = $this->createUser('profadmin', 'admin');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$target->id,
        );

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('proftarget', $result['data']['username']);
        $this->assertArrayNotHasKey('password', $result['data']);
    }

    // ----------------------------------------------------------------
    // 権限制御・異常系
    // ----------------------------------------------------------------

    /**
     * 非 staff が他ユーザを指定 → Access denied
     */
    public function testNonStaffOtherUserDenied(): void
    {
        $target = $this->createUser('proftarget2');
        $me = $this->createUser('profme2');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$me->id),
            (int)$target->id,
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('own profile', $result['error']);
    }

    /**
     * 存在しないユーザ（staff が参照）→ User not found
     */
    public function testUserNotFound(): void
    {
        $admin = $this->createUser('profadmin2', 'admin');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            999999,
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('User not found.', $result['error']);
    }

    /**
     * 削除済みユーザ（staff が参照）→ User not found
     */
    public function testDeletedUserHidden(): void
    {
        $target = $this->createUser('profdeleted');
        $admin = $this->createUser('profadmin3', 'admin');

        $usersTable = $this->getTableLocator()->get('Users');
        $target->deleted = date('Y-m-d H:i:s');
        $this->assertNotFalse($usersTable->save($target));

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$target->id,
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('User not found.', $result['error']);
    }
}
