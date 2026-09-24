<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\UserTokensTable;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;

/**
 * UserTokensTable のテスト
 */
class UserTokensTableTest extends TestCase
{
    protected UserTokensTable $UserTokens;

    public function setUp(): void
    {
        parent::setUp();
        $this->UserTokens = $this->getTableLocator()->get('UserTokens');
        $this->UserTokens->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        Configure::write('remember_token_expired_days', 14);
        Configure::write('api_token_expired_days', 30);
    }

    public function tearDown(): void
    {
        unset($this->UserTokens);
        parent::tearDown();
    }

    private function saveUser(string $username = 'tokenuser'): int
    {
        $Users = $this->getTableLocator()->get('Users');
        $user = $Users->save($Users->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'トークンユーザ',
            'role' => 'user',
        ]));
        $this->assertNotFalse($user);

        return (int)$user->id;
    }

    public function testIsAvailable(): void
    {
        $this->assertTrue($this->UserTokens->isAvailable());
    }

    public function testParseCookie(): void
    {
        $valid = str_repeat('a', 32) . ':' . str_repeat('b', 64);
        $parsed = $this->UserTokens->parseCookie($valid);
        $this->assertSame(str_repeat('a', 32), $parsed['selector']);
        $this->assertSame(str_repeat('b', 64), $parsed['validator']);

        $this->assertNull($this->UserTokens->parseCookie(''));
        $this->assertNull($this->UserTokens->parseCookie('short:value'));
        $this->assertNull($this->UserTokens->parseCookie('no-colon-here'));
        $this->assertNull($this->UserTokens->parseCookie(str_repeat('z', 32) . ':' . str_repeat('x', 64)), '非 hex は拒否');
    }

    public function testIssueAndAuthenticateRememberToken(): void
    {
        $userId = $this->saveUser('remember1');

        $cookie = $this->UserTokens->issueRememberToken($userId);
        $this->assertNotNull($cookie);
        $this->assertSame(97, strlen($cookie), 'selector(32) + ":" + validator(64) の97文字');

        $auth = $this->UserTokens->authenticateRememberCookie($cookie);
        $this->assertNotNull($auth, '正しい Cookie でユーザを認証');
        $this->assertSame($userId, (int)$auth['id']);
    }

    public function testAuthenticateRememberCookieWithInvalidFormat(): void
    {
        $this->assertNull($this->UserTokens->authenticateRememberCookie('invalid-format'));
        $this->assertNull($this->UserTokens->authenticateRememberCookie(''));
    }

    public function testAuthenticateRememberCookieUnknownToken(): void
    {
        $this->assertNull(
            $this->UserTokens->authenticateRememberCookie(str_repeat('a', 32) . ':' . str_repeat('b', 64))
        );
    }

    public function testRevokeByCookie(): void
    {
        $userId = $this->saveUser('remember2');
        $cookie = $this->UserTokens->issueRememberToken($userId);
        $this->assertNotNull($cookie);

        $this->UserTokens->revokeByCookie($cookie);
        $this->assertNull($this->UserTokens->authenticateRememberCookie($cookie), '失効後は認証不可');
    }

    public function testRevokeAllForUser(): void
    {
        $userId = $this->saveUser('remember3');
        $cookie1 = $this->UserTokens->issueRememberToken($userId);
        $cookie2 = $this->UserTokens->issueRememberToken($userId);
        $this->assertNotNull($cookie1);
        $this->assertNotNull($cookie2);

        $this->UserTokens->revokeAllForUser($userId);

        $this->assertNull($this->UserTokens->authenticateRememberCookie($cookie1));
        $this->assertNull($this->UserTokens->authenticateRememberCookie($cookie2));
    }

    public function testIssueAndAuthenticateApiToken(): void
    {
        $userId = $this->saveUser('apiuser1');

        $token = $this->UserTokens->issueApiToken($userId);
        $this->assertNotNull($token);
        $this->assertSame(97, strlen($token));

        $result = $this->UserTokens->authenticateApiToken($token);
        $this->assertNotNull($result, 'API トークンで認証できる');
        $this->assertSame($userId, (int)$result['user']['id']);
        $this->assertArrayNotHasKey('password', $result['user'], 'password は返されない');
        $this->assertNotEmpty($result['token_id']);
    }

    public function testIssuePermanentApiToken(): void
    {
        $userId = $this->saveUser('apiuser2');

        $token = $this->UserTokens->issueApiToken($userId, permanent: true);
        $this->assertNotNull($token);

        $row = $this->UserTokens->find()
            ->where(['user_id' => $userId, 'token_type' => 'api'])
            ->first();
        $this->assertSame('9999-12-31 23:59:59', $row->expired->format('Y-m-d H:i:s'));
    }

    public function testRevokeApiToken(): void
    {
        $userId = $this->saveUser('apiuser3');
        $token = $this->UserTokens->issueApiToken($userId);
        $this->assertNotNull($token);

        $this->assertTrue($this->UserTokens->revokeApiToken($token));
        $this->assertNull($this->UserTokens->authenticateApiToken($token), '失効後は認証不可');
        $this->assertFalse($this->UserTokens->revokeApiToken($token), '再失効は false');
    }

    public function testRevokeAllApiForUser(): void
    {
        $userId = $this->saveUser('apiuser4');
        $token1 = $this->UserTokens->issueApiToken($userId);
        $token2 = $this->UserTokens->issueApiToken($userId);
        $this->assertNotNull($token1);
        $this->assertNotNull($token2);

        $this->UserTokens->revokeAllApiForUser($userId);

        $this->assertNull($this->UserTokens->authenticateApiToken($token1));
        $this->assertNull($this->UserTokens->authenticateApiToken($token2));
    }

    public function testIssueTokenForInvalidUser(): void
    {
        $this->assertNull($this->UserTokens->issueRememberToken(0));
        $this->assertNull($this->UserTokens->issueApiToken(0));
    }
}