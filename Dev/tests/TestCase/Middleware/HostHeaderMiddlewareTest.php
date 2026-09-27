<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\HostHeaderMiddleware;
use Cake\Core\Configure;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HostHeaderMiddleware Test
 */
class HostHeaderMiddlewareTest extends TestCase
{
    /**
     * @var \App\Middleware\HostHeaderMiddleware
     */
    private HostHeaderMiddleware $middleware;

    /**
     * Saved values to restore after each test.
     */
    private bool $originalDebug;
    private mixed $originalFullBaseUrl;

    public function setUp(): void
    {
        parent::setUp();
        $this->middleware = new HostHeaderMiddleware();
        $this->originalDebug = (bool)Configure::read('debug');
        $this->originalFullBaseUrl = Configure::read('App.fullBaseUrl');
    }

    public function tearDown(): void
    {
        Configure::write('debug', $this->originalDebug);
        if ($this->originalFullBaseUrl !== null) {
            Configure::write('App.fullBaseUrl', $this->originalFullBaseUrl);
        } else {
            Configure::delete('App.fullBaseUrl');
        }
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
                return (new Response())->withStatus(200)
                    ->withHeader('Content-Type', 'text/html')
                    ->withStringBody('<html></html>');
            }

            public function canHandle(ServerRequestInterface $request): bool
            {
                return true;
            }
        };
    }

    /**
     * T1: debug=false, App.fullBaseUrl configured, matching Host → passes through (200).
     */
    public function testMatchingHostPassesThrough(): void
    {
        Configure::write('debug', false);
        Configure::write('App.fullBaseUrl', 'http://example.com');

        $request = new ServerRequest([
            'url' => '/',
            'environment' => ['HTTP_HOST' => 'example.com'],
        ]);
        $response = $this->middleware->process($request, $this->createOkHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * T2: debug=false, App.fullBaseUrl configured, mismatched Host → 400 Bad Request.
     */
    public function testMismatchedHostReturns400(): void
    {
        Configure::write('debug', false);
        Configure::write('App.fullBaseUrl', 'http://example.com');

        $request = new ServerRequest([
            'url' => '/',
            'environment' => ['HTTP_HOST' => 'evil.com'],
        ]);

        $this->expectException(BadRequestException::class);
        $this->middleware->process($request, $this->createOkHandler());
    }

    /**
     * T3: debug=false, App.fullBaseUrl NOT configured → passes through (no exception, no 500).
     * This is the core of D-25.
     */
    public function testUnconfiguredFullBaseUrlPassesThrough(): void
    {
        Configure::write('debug', false);
        Configure::delete('App.fullBaseUrl');

        $request = new ServerRequest([
            'url' => '/',
            'environment' => ['HTTP_HOST' => 'example.com'],
        ]);

        // Should NOT throw any exception (especially not InternalErrorException)
        $response = $this->middleware->process($request, $this->createOkHandler());
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * T4: debug=true, App.fullBaseUrl NOT configured, Host=evil.com → passes through (debug early return).
     */
    public function testDebugModeBypassesValidation(): void
    {
        Configure::write('debug', true);
        Configure::delete('App.fullBaseUrl');

        $request = new ServerRequest([
            'url' => '/',
            'environment' => ['HTTP_HOST' => 'evil.com'],
        ]);
        $response = $this->middleware->process($request, $this->createOkHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * T5: debug=false, App.fullBaseUrl configured, Host in different case → passes through (strtolower comparison).
     */
    public function testCaseInsensitiveHostMatchPasses(): void
    {
        Configure::write('debug', false);
        Configure::write('App.fullBaseUrl', 'http://example.com');

        $request = new ServerRequest([
            'url' => '/',
            'environment' => ['HTTP_HOST' => 'EXAMPLE.COM'],
        ]);
        $response = $this->middleware->process($request, $this->createOkHandler());

        $this->assertSame(200, $response->getStatusCode());
    }
}
