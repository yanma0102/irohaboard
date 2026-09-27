<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Brute-force lockout の IP ベース分離テスト（D-06 リグレッション）
 *
 * 同一ユーザ名でも IP が異なればロックアウトされないことを確認する。
 * 旧バグ: ユーザ名のみでロックしていたため、攻撃者が他人のアカウントをロックアウトできた。
 * 修正後: ユーザ名 + IP の組み合わせでロック判定する。
 */
class BruteForceIpLockoutTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var array<string, mixed> 前回の $_SERVER 状態を退避
     */
    private array $savedServerKeys = [];

    public function setUp(): void
    {
        parent::setUp();

        // 退避対象の $_SERVER キー
        foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR'] as $key) {
            $this->savedServerKeys[$key] = $_SERVER[$key] ?? null;
        }

        // 全テーブルをクリーンアップ
        $this->getTableLocator()->get('UserTokens')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Logs')->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        // $_SERVER を復元
        foreach ($this->savedServerKeys as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        parent::tearDown();
    }

    // ----------------------------------------------------------------
    // ヘルパー
    // ----------------------------------------------------------------

    /**
     * テスト用ユーザを作成
     */
    private function createUser(string $username = 'testuser'): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $entity = $usersTable->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テスト太郎',
            'role' => 'user',
            'email' => $username . '@example.com',
        ]);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result, "ユーザ {$username} の作成に失敗");

        return $result;
    }

    /**
     * 指定件数分の login_error ログを投入する（Web ログイン用）
     */
    private function insertLoginErrorLogs(string $username, string $ip, int $count): void
    {
        $logsTable = $this->getTableLocator()->get('Logs');
        for ($i = 0; $i < $count; $i++) {
            $log = $logsTable->newEntity([
                'log_type' => 'login_error',
                'log_content' => $username,
                'user_ip' => $ip,
                'user_agent' => 'test-agent',
                'created' => new DateTime('now'),
            ]);
            $logsTable->save($log);
        }
    }

    /**
     * 指定件数分の api_login_error ログを投入する（API 用）
     */
    private function insertApiLoginErrorLogs(string $username, string $ip, int $count): void
    {
        $logsTable = $this->getTableLocator()->get('Logs');
        for ($i = 0; $i < $count; $i++) {
            $log = $logsTable->newEntity([
                'log_type' => 'api_login_error',
                'log_content' => $username,
                'user_ip' => $ip,
                'user_agent' => 'test-agent',
                'created' => new DateTime('now'),
            ]);
            $logsTable->save($log);
        }
    }

    /**
     * 指定の IP アドレスをリクエストのクライアント IP として設定する。
     *
     * CakePHP の IntegrationTestTrait はリクエスト生成時に $_SERVER をコピーするため、
     * リクエスト直前に $_SERVER を設定する必要がある。
     */
    private function setClientIp(string $ip): void
    {
        // CakePHP の ServerRequestFactory::fromGlobals() が読む $_SERVER を直接設定
        $_SERVER['REMOTE_ADDR'] = $ip;
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    // ----------------------------------------------------------------
    // Web ログイン（UserLoginTrait）のテスト
    // ----------------------------------------------------------------

    /**
     * 同一 IP からの10回以上の失敗でロックされること
     */
    public function testWebLoginBlockedFromSameIp(): void
    {
        $this->createUser('webuser1');
        $this->insertLoginErrorLogs('webuser1', '10.0.0.1', 10);

        $this->enableCsrfToken();
        $this->enableSecurityToken();

        // リクエスト直前に $_SERVER を設定
        $this->setClientIp('10.0.0.1');

        $this->post('/users/login', [
            'username' => 'webuser1',
            'password' => 'wrongpass',
        ]);

        // ログインページが再描画される（フォーム再表示）
        $this->assertResponseOk();

        // Flash エラーに「試行回数が上限」メッセージが含まれること
        $this->assertResponseContains('ログイン試行回数が上限に達しました');
    }

    /**
     * 別 IP からのログインはブロックされないこと（D-06 コア回帰テスト）
     */
    public function testWebLoginNotBlockedFromDifferentIp(): void
    {
        $this->createUser('webuser2');
        $this->insertLoginErrorLogs('webuser2', '10.0.0.1', 10);

        $this->enableCsrfToken();
        $this->enableSecurityToken();

        // 異なる IP からログイン試行
        $this->setClientIp('10.0.0.2');

        $this->post('/users/login', [
            'username' => 'webuser2',
            'password' => 'wrongpass',
        ]);

        // ログインページが再描画される（ロックアウトではない）
        $this->assertResponseOk();

        // 「試行回数が上限」のエラーが含まれないこと
        $this->assertResponseNotContains('ログイン試行回数が上限に達しました');

        // 通常の「ログインID、もしくはパスワードが正しくありません」エラーが表示されること
        $this->assertResponseContains('ログインID、もしくはパスワードが正しくありません');
    }

    /**
     * 別 IP からの成功ログインがブロックされないこと
     */
    public function testWebLoginSuccessFromDifferentIp(): void
    {
        $this->createUser('webuser3');
        $this->insertLoginErrorLogs('webuser3', '10.0.0.1', 10);

        $this->enableCsrfToken();
        $this->enableSecurityToken();

        // 異なる IP から正しいパスワードでログイン
        $this->setClientIp('10.0.0.2');

        $this->post('/users/login', [
            'username' => 'webuser3',
            'password' => 'testpass',
        ]);

        // ログイン成功でリダイレクト
        $this->assertRedirect();
    }

    // ----------------------------------------------------------------
    // API（AuthController）のテスト
    // ----------------------------------------------------------------

    /**
     * 同一 IP からの10回以上の失敗で API トークン発行がブロックされること
     */
    public function testApiTokenBlockedFromSameIp(): void
    {
        $this->createUser('apiuser1');
        $this->insertApiLoginErrorLogs('apiuser1', '10.0.0.1', 10);

        $this->setClientIp('10.0.0.1');

        $this->post('/api/v1/auth/token', [
            'username' => 'apiuser1',
            'password' => 'wrongpass',
        ]);

        $this->assertResponseCode(429);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(429, $body['error']['code']);
        $this->assertStringContainsString('Too many failed attempts', $body['error']['message']);
    }

    /**
     * 別 IP からの API ログインはブロックされないこと（D-06 コア回帰テスト）
     */
    public function testApiTokenNotBlockedFromDifferentIp(): void
    {
        $this->createUser('apiuser2');
        $this->insertApiLoginErrorLogs('apiuser2', '10.0.0.1', 10);

        // 異なる IP からログイン試行（誤ったパスワード）
        $this->setClientIp('10.0.0.2');

        $this->post('/api/v1/auth/token', [
            'username' => 'apiuser2',
            'password' => 'wrongpass',
        ]);

        // ブロックではなく 401 が返されること
        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('Invalid credentials', $body['error']['message']);
    }

    /**
     * 別 IP からの成功 API ログインがブロックされないこと
     */
    public function testApiTokenSuccessFromDifferentIp(): void
    {
        $this->createUser('apiuser3');
        $this->insertApiLoginErrorLogs('apiuser3', '10.0.0.1', 10);

        // 異なる IP から正しいパスワードでログイン
        $this->setClientIp('10.0.0.2');

        $this->post('/api/v1/auth/token', [
            'username' => 'apiuser3',
            'password' => 'testpass',
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('token', $body['data']);
        $this->assertNotEmpty($body['data']['token']);
    }

    /**
     * 9回失敗（しきい値未満）ではブロックされないこと
     */
    public function testApiTokenNotBlockedBelowThreshold(): void
    {
        $this->createUser('apiuser4');

        // 9回だけ失敗を記録（しきい値 10 の未満）
        $this->insertApiLoginErrorLogs('apiuser4', '10.0.0.1', 9);

        $this->setClientIp('10.0.0.1');

        $this->post('/api/v1/auth/token', [
            'username' => 'apiuser4',
            'password' => 'wrongpass',
        ]);

        // まだブロックされていない → 401
        $this->assertResponseCode(401);
    }
}
