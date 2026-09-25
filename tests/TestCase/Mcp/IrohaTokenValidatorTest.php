<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp;

use App\Mcp\IrohaTokenValidator;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;

/**
 * IrohaTokenValidator のテスト
 *
 * 既存 Bearer トークンの検証（lookupApiToken 使用・失効副作用なし）と
 * oauth.* 属性の付与を検証する。
 */
class IrohaTokenValidatorTest extends TestCase
{
    private IrohaTokenValidator $validator;

    public function setUp(): void
    {
        parent::setUp();

        $this->getTableLocator()->get('UserTokens')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');

        $this->validator = new IrohaTokenValidator(
            $this->getTableLocator()->get('UserTokens'),
        );
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    private function createUser(string $username = 'mcpuser'): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $entity = $usersTable->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'MCP太郎',
            'role' => 'user',
            'email' => $username . '@example.com',
        ]);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    private function issueToken(int $userId): string
    {
        $token = $this->getTableLocator()->get('UserTokens')->issueApiToken($userId);
        $this->assertNotNull($token, 'トークン発行に失敗');

        return $token;
    }

    // ----------------------------------------------------------------
    // 正常系
    // ----------------------------------------------------------------

    /**
     * 有効なトークン → allow + oauth.* 属性
     */
    public function testValidTokenAllowsWithOAuthAttributes(): void
    {
        $user = $this->createUser();
        $token = $this->issueToken((int)$user->id);

        $result = $this->validator->validate($token);

        $this->assertTrue($result->isAllowed());

        $attrs = $result->getAttributes();
        $this->assertSame((int)$user->id, $attrs['oauth.user_id']);
        $this->assertSame('user', $attrs['oauth.role']);
        $this->assertSame('MCP太郎', $attrs['oauth.name']);
        $this->assertArrayHasKey('oauth.token_id', $attrs);
        $this->assertIsInt($attrs['oauth.token_id']);
    }

    /**
     * スタッフロール属性がそのまま付与される
     */
    public function testStaffRolePassedThrough(): void
    {
        $user = $this->createUser('adminuser');
        $user->role = 'admin';
        $this->getTableLocator()->get('Users')->save($user);
        $token = $this->issueToken((int)$user->id);

        $result = $this->validator->validate($token);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('admin', $result->getAttributes()['oauth.role']);
    }

    // ----------------------------------------------------------------
    // 異常系
    // ----------------------------------------------------------------

    /**
     * 不正なトークン → unauthorized（invalid_token）
     */
    public function testInvalidTokenUnauthorized(): void
    {
        $result = $this->validator->validate('invalid-token-string');

        $this->assertFalse($result->isAllowed());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertNotEmpty($result->getErrorDescription());
    }

    /**
     * 存在しない selector → unauthorized
     */
    public function testUnknownSelectorUnauthorized(): void
    {
        $result = $this->validator->validate(
            str_repeat('ab', 16) . ':' . str_repeat('cd', 32),
        );

        $this->assertFalse($result->isAllowed());
        $this->assertSame('invalid_token', $result->getError());
    }

    /**
     * 失効済みトークン → unauthorized
     */
    public function testRevokedTokenUnauthorized(): void
    {
        $user = $this->createUser();
        $token = $this->issueToken((int)$user->id);

        $tokensTable = $this->getTableLocator()->get('UserTokens');
        $entity = $tokensTable->find()->firstOrFail();
        $entity->revoked = date('Y-m-d H:i:s');
        $this->assertNotFalse($tokensTable->save($entity));

        $result = $this->validator->validate($token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame('invalid_token', $result->getError());
    }

    /**
     * 期限切れトークン → unauthorized
     */
    public function testExpiredTokenUnauthorized(): void
    {
        $user = $this->createUser();
        $token = $this->issueToken((int)$user->id);

        $tokensTable = $this->getTableLocator()->get('UserTokens');
        $entity = $tokensTable->find()->firstOrFail();
        $entity->expired = '2000-01-01 00:00:00';
        $this->assertNotFalse($tokensTable->save($entity));

        $result = $this->validator->validate($token);

        $this->assertFalse($result->isAllowed());
    }

    /**
     * validator 不一致 → lookupApiToken は null を返し、
     * トークンを失効させない（G-3・副作用なし）
     */
    public function testValidatorMismatchDoesNotRevoke(): void
    {
        $user = $this->createUser();
        $issued = $this->issueToken((int)$user->id);
        [$selector] = explode(':', $issued);

        $lookup = $this->getTableLocator()->get('UserTokens')->lookupApiToken(
            $selector . ':' . str_repeat('00', 32),
        );
        $this->assertNull($lookup);

        $tokensTable = $this->getTableLocator()->get('UserTokens');
        $entity = $tokensTable->find()->firstOrFail();
        $this->assertNull($entity->revoked, 'validator 不一致で失効してはならない');

        // 元のトークンは引き続き有効
        $result = $this->validator->validate($issued);
        $this->assertTrue($result->isAllowed());
    }

    /**
     * 削除済みユーザのトークン → unauthorized
     */
    public function testDeletedUserUnauthorized(): void
    {
        $user = $this->createUser();
        $token = $this->issueToken((int)$user->id);

        $usersTable = $this->getTableLocator()->get('Users');
        $user->deleted = date('Y-m-d H:i:s');
        $this->assertNotFalse($usersTable->save($user));

        $result = $this->validator->validate($token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame('invalid_token', $result->getError());
    }
}
