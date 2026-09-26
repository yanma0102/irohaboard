<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * AuthController API のテスト
 *
 * issueToken / revokeToken と認証フローの統合テスト
 */
class AuthControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * setUp: 各テスト前にテーブルをクリーンアップ
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->getTableLocator()->get('UserTokens')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Logs')->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    /**
     * テスト用ユーザを作成して返す（パスワードは bcrypt で自動ハッシュ化される）
     */
    private function createUser(string $username = 'testuser', array $overrides = []): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $data = array_merge([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テスト太郎',
            'role' => 'user',
            'email' => $username . '@example.com',
        ], $overrides);

        $entity = $usersTable->newEntity($data);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result, "ユーザ {$username} の作成に失敗");

        return $result;
    }

    /**
     * issueToken でトークンを発行し、レスポンスボディを返す
     *
     * @return array{token: string, token_type: string, permanent: bool, expires: string, user: array}
     */
    private function issueToken(string $username, string $password, bool $permanent = false): array
    {
        $this->post('/api/v1/auth/token', [
            'username' => $username,
            'password' => $password,
            'permanent' => $permanent ? '1' : '0',
        ]);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body, 'レスポンスに data キーが存在する');

        return $body['data'];
    }

    // ----------------------------------------------------------------
    // T1-a: issueToken のテスト
    // ----------------------------------------------------------------

    /**
     * 正常系: ユーザ名とパスワードでトークンを発行できる
     */
    public function testIssueTokenSuccess(): void
    {
        $this->createUser('apiuser01');

        $this->post('/api/v1/auth/token', [
            'username' => 'apiuser01',
            'password' => 'testpass',
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);

        $data = $body['data'];
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEmpty($data['token'], 'トークンが空でない');
        $this->assertStringContainsString(':', $data['token'], 'トークンは selector:validator 形式');
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertFalse($data['permanent']);
        $this->assertArrayHasKey('expires', $data);
        $this->assertArrayHasKey('user', $data);
        $this->assertSame('apiuser01', $data['user']['username']);
        $this->assertSame('テスト太郎', $data['user']['name']);
        $this->assertSame('user', $data['user']['role']);
    }

    /**
     * 異常系: 誤ったパスワード → 401 Invalid credentials
     */
    public function testIssueTokenInvalidCredentials(): void
    {
        $this->createUser('apiuser02');

        $this->post('/api/v1/auth/token', [
            'username' => 'apiuser02',
            'password' => 'wrongpass',
        ]);

        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(401, $body['error']['code']);
        $this->assertSame('Invalid credentials', $body['error']['message']);
    }

    /**
     * 異常系: password が未指定 → 400
     */
    public function testIssueTokenMissingPassword(): void
    {
        $this->createUser('apiuser03');

        $this->post('/api/v1/auth/token', [
            'username' => 'apiuser03',
        ]);

        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(400, $body['error']['code']);
        $this->assertSame('username and password are required', $body['error']['message']);
    }

    /**
     * 異常系: 永続トークンを一般ユーザが要求 → 403
     */
    public function testIssuePermanentTokenDeniedForNonAdmin(): void
    {
        $this->createUser('apiuser04', ['role' => 'user']);

        $this->post('/api/v1/auth/token', [
            'username' => 'apiuser04',
            'password' => 'testpass',
            'permanent' => '1',
        ]);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(403, $body['error']['code']);
        $this->assertSame('Only administrators can issue a permanent token', $body['error']['message']);
    }

    // ----------------------------------------------------------------
    // T1-a: 認証フローのテスト
    // ----------------------------------------------------------------

    /**
     * 未認証で保護された API にアクセス → 401 Authorization header is missing
     */
    public function testUnauthenticatedAccessReturns401(): void
    {
        $this->get('/api/v1/users');

        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('Authorization header is missing', $body['error']['message']);
    }

    /**
     * 不正トークン → 401 Invalid or expired API token
     */
    public function testInvalidTokenReturns401(): void
    {
        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer invalid:token'],
        ]);
        $this->get('/api/v1/users');

        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('Invalid or expired API token', $body['error']['message']);
    }

    /**
     * 有効なトークンで保護された API にアクセス → 200
     */
    public function testValidTokenAccessReturns200(): void
    {
        $this->createUser('apiuser05', ['role' => 'admin']);

        $tokenData = $this->issueToken('apiuser05', 'testpass');
        $token = $tokenData['token'];

        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->get('/api/v1/users');

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);
    }

    // ----------------------------------------------------------------
    // T1-a: revokeToken のテスト
    // ----------------------------------------------------------------

    /**
     * トークンを無効化できる
     */
    public function testRevokeTokenSuccess(): void
    {
        $this->createUser('apiuser06');

        $tokenData = $this->issueToken('apiuser06', 'testpass');
        $token = $tokenData['token'];

        // トークンで revokeToken を呼ぶ
        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->delete('/api/v1/auth/token');

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertTrue($body['data']['revoked']);

        // 無効化後に同じトークンでアクセス → 401
        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->get('/api/v1/users');

        $this->assertResponseCode(401);

        $errorBody = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Invalid or expired API token', $errorBody['error']['message']);
    }
}
