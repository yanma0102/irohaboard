<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ErrorsController API のテスト
 *
 * 未定義ルートに対する 404 JSON 応答の検証
 * D-7 修正の回帰テスト
 */
class ErrorsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * 未定義の API ルートにアクセス → 404 + Endpoint not found
     */
    public function testUndefinedRouteReturns404(): void
    {
        $this->get('/api/v1/nonexistent');

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(404, $body['error']['code']);
        $this->assertSame('Endpoint not found', $body['error']['message']);
    }

    /**
     * 不正 Bearer トークン付きで未定義ルートにアクセス → 仍然 404（notFound は認証免除）
     */
    public function testUndefinedRouteWithInvalidTokenStillReturns404(): void
    {
        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer invalid:token'],
        ]);
        $this->get('/api/v1/nonexistent');

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(404, $body['error']['code']);
        $this->assertSame('Endpoint not found', $body['error']['message']);
    }
}
