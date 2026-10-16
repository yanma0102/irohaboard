<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\ChangeUserPasswordTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * change_user_password ツールのテスト
 */
class ChangeUserPasswordToolTest extends TestCase
{
    private ChangeUserPasswordTool $tool;

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

        $this->tool = new ChangeUserPasswordTool(
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
     * パスワードが変更され、対象ユーザのトークンが失効する
     */
    public function testChangesPasswordAndRevokesTokens(): void
    {
        $admin = $this->createUser('admin01', 'admin');
        $target = $this->createUser('user01', 'user');

        $tokensTable = $this->getTableLocator()->get('UserTokens');
        $this->assertNotEmpty(
            $tokensTable->issueApiToken((int)$target->id),
            'API トークンの発行に失敗',
        );

        $result = $this->tool->__invoke(
            $this->makeContext('change_user_password', (int)$admin->id, 'admin'),
            (int)$target->id,
            'newpassword123',
        );

        $this->assertTrue($result['data']['password_changed']);

        $saved = $this->getTableLocator()->get('Users')->get((int)$target->id);
        $this->assertNotSame('newpassword123', $saved->password);
        $this->assertSame(
            0,
            $tokensTable->find()
                ->where(['user_id' => (int)$target->id, 'revoked IS' => null])
                ->count(),
            'トークンが失効していない',
        );
    }

    /**
     * manager は admin のパスワードを変更できない
     */
    public function testManagerCannotChangeAdminPassword(): void
    {
        $manager = $this->createUser('mgr01', 'manager');
        $admin = $this->createUser('admin01', 'admin');

        $this->assertToolCallError(
            'Only administrators can modify an administrator account.',
            fn() => $this->tool->__invoke(
                $this->makeContext('change_user_password', (int)$manager->id, 'manager'),
                (int)$admin->id,
                'newpassword123',
            ),
        );
    }

    /**
     * 空のパスワードは拒否する
     */
    public function testEmptyPasswordRejected(): void
    {
        $admin = $this->createUser('admin01', 'admin');
        $target = $this->createUser('user01', 'user');

        $this->assertToolCallError(
            'password is required.',
            fn() => $this->tool->__invoke(
                $this->makeContext('change_user_password', (int)$admin->id, 'admin'),
                (int)$target->id,
                '',
            ),
        );
    }
}
