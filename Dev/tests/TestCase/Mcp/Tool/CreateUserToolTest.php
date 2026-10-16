<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\CreateUserTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * create_user ツールのテスト
 */
class CreateUserToolTest extends TestCase
{
    private CreateUserTool $tool;

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

        $this->tool = new CreateUserTool(
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
     * manager はユーザを作成でき、応答に password を含めない
     */
    public function testManagerCreatesUser(): void
    {
        $manager = $this->createUser('mgr01', 'manager');

        $result = $this->tool->__invoke(
            $this->makeContext('create_user', (int)$manager->id, 'manager'),
            'newuser01',
            'secretpass',
            '新規太郎',
            'user',
            'newuser01@example.com',
        );

        $this->assertArrayNotHasKey('password', $result['data']);
        $this->assertSame('newuser01', $result['data']['username']);
        $this->assertSame('user', $result['data']['role']);

        $saved = $this->getTableLocator()->get('Users')->get((int)$result['data']['id']);
        $this->assertNotSame('secretpass', $saved->password, 'パスワードがハッシュ化されていない');
    }

    /**
     * 一般ユーザは作成できない
     */
    public function testNonManagerDenied(): void
    {
        $user = $this->createUser('user01', 'user');

        $this->assertToolCallError(
            'Only administrators or managers can create users.',
            fn() => $this->tool->__invoke(
                $this->makeContext('create_user', (int)$user->id, 'user'),
                'x01',
                'pass1234',
                'テスト',
            ),
        );
    }
}
