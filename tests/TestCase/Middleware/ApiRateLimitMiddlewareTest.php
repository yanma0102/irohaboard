<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\ApiRateLimitMiddleware;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * ApiRateLimitMiddleware Test
 */
class ApiRateLimitMiddlewareTest extends TestCase
{
    /**
     * @var \App\Middleware\ApiRateLimitMiddleware
     */
    private ApiRateLimitMiddleware $middleware;

    /**
     * @var int
     */
    private int $originalLimit;

    public function setUp(): void
    {
        parent::setUp();
        $this->middleware = new ApiRateLimitMiddleware();
        $this->originalLimit = (int)(Configure::read('api_rate_limit_per_minute') ?? 120);

        // Clear any rate limit cache entries
        Cache::clearAll('api_rate_limit');
    }

    public function tearDown(): void
    {
        Configure::write('api_rate_limit_per_minute', $this->originalLimit);
        Cache::clearAll('api_rate_limit');
        parent::tearDown();
    }

    /**
     * Helper: create a simple request handler that returns 200
     */
    private function createOkHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                return $response->withStatus(200)
                    ->withHeader('Content-Type', 'application/json')
                    ->withStringBody('{"ok":true}');
            }

            public function canHandle(ServerRequestInterface $request): bool
            {
                return true;
            }
        };
    }

    /**
     * Non-API paths should pass through without rate limiting.
     */
    public function testNonApiPathPassesThrough(): void
    {
        Configure::write('api_rate_limit_per_minute', 1);
        $request = new ServerRequest(['url' => '/users/login']);
        $handler = $this->createOkHandler();

        $response = $this->middleware->process($request, $handler);

        $this->assertNotSame(429, $response->getStatusCode());
    }

    /**
     * /mcp paths should be excluded from rate limiting.
     */
    public function testMcpPathExcluded(): void
    {
        Configure::write('api_rate_limit_per_minute', 1);
        $request = new ServerRequest(['url' => '/mcp/sessions']);
        $handler = $this->createOkHandler();

        $response = $this->middleware->process($request, $handler);

        $this->assertNotSame(429, $response->getStatusCode());
    }

    /**
     * When limit is 0, rate limiting is disabled.
     */
    public function testDisabledWhenLimitZero(): void
    {
        Configure::write('api_rate_limit_per_minute', 0);
        $request = new ServerRequest(['url' => '/api/v1/courses']);
        $handler = $this->createOkHandler();

        // Make many requests — none should be 429
        for ($i = 0; $i < 10; $i++) {
            $response = $this->middleware->process($request, $handler);
            $this->assertNotSame(429, $response->getStatusCode(), "Request {$i} should not be rate limited");
        }
    }

    /**
     * Exceeding the configured limit returns 429 with Retry-After.
     */
    public function testExceedingLimitReturns429(): void
    {
        Configure::write('api_rate_limit_per_minute', 3);
        $request = new ServerRequest([
            'url' => '/api/v1/courses',
            'environment' => ['REMOTE_ADDR' => '10.0.0.1'],
        ]);
        $handler = $this->createOkHandler();

        // First 3 requests should pass
        for ($i = 0; $i < 3; $i++) {
            $response = $this->middleware->process($request, $handler);
            $this->assertNotSame(429, $response->getStatusCode(), "Request {$i} should pass");
        }

        // 4th request should be rate limited
        $response = $this->middleware->process($request, $handler);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Retry-After'));
        $this->assertNotEmpty($response->getHeaderLine('Retry-After'));
    }

    /**
     * 429 response body matches API error contract.
     */
    public function testRateLimitResponseBodyMatchesContract(): void
    {
        Configure::write('api_rate_limit_per_minute', 1);
        $request = new ServerRequest([
            'url' => '/api/v1/courses',
            'environment' => ['REMOTE_ADDR' => '10.0.0.2'],
        ]);
        $handler = $this->createOkHandler();

        // Exhaust the limit
        $this->middleware->process($request, $handler);

        // This one should be 429
        $response = $this->middleware->process($request, $handler);
        $this->assertSame(429, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertArrayHasKey('code', $body['error']);
        $this->assertSame(429, $body['error']['code']);
        $this->assertArrayHasKey('message', $body['error']);
        $this->assertIsString($body['error']['message']);
    }

    /**
     * Rate limit headers are added to successful responses.
     */
    public function testRateLimitHeadersOnSuccess(): void
    {
        Configure::write('api_rate_limit_per_minute', 100);
        $request = new ServerRequest([
            'url' => '/api/v1/courses',
            'environment' => ['REMOTE_ADDR' => '10.0.0.3'],
        ]);
        $handler = $this->createOkHandler();

        $response = $this->middleware->process($request, $handler);

        $this->assertTrue($response->hasHeader('X-RateLimit-Limit'));
        $this->assertSame('100', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertTrue($response->hasHeader('X-RateLimit-Remaining'));
    }

    /**
     * Authenticated requests use a different key than unauthenticated (IP-based).
     */
    public function testAuthenticatedAndUnauthenticatedUseDifferentKeys(): void
    {
        Configure::write('api_rate_limit_per_minute', 2);

        $handler = $this->createOkHandler();

        // Unauthenticated request from IP
        $unauthRequest = new ServerRequest([
            'url' => '/api/v1/courses',
            'environment' => ['REMOTE_ADDR' => '10.0.0.4'],
        ]);

        // Exhaust unauthenticated limit
        $this->middleware->process($unauthRequest, $handler);
        $this->middleware->process($unauthRequest, $handler);

        // This should be rate limited
        $response = $this->middleware->process($unauthRequest, $handler);
        $this->assertSame(429, $response->getStatusCode());

        // But an authenticated request should still work (different key)
        $authRequest = new ServerRequest([
            'url' => '/api/v1/courses',
            'environment' => [
                'REMOTE_ADDR' => '10.0.0.4',
                'HTTP_AUTHORIZATION' => 'Bearer testtoken123',
            ],
        ]);

        $response = $this->middleware->process($authRequest, $handler);
        $this->assertNotSame(429, $response->getStatusCode());
    }
}
