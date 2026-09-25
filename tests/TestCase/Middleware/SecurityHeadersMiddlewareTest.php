<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\SecurityHeadersMiddleware;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * SecurityHeadersMiddleware Test
 */
class SecurityHeadersMiddlewareTest extends TestCase
{
    /**
     * @var \App\Middleware\SecurityHeadersMiddleware
     */
    private SecurityHeadersMiddleware $middleware;

    public function setUp(): void
    {
        parent::setUp();
        $this->middleware = new SecurityHeadersMiddleware();
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
     * Referrer-Policy is set on every response.
     */
    public function testReferrerPolicy(): void
    {
        $request = new ServerRequest(['url' => '/']);
        $response = $this->middleware->process($request, $this->createOkHandler());

        $this->assertTrue($response->hasHeader('Referrer-Policy'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
    }

    /**
     * Permissions-Policy is set on every response.
     */
    public function testPermissionsPolicy(): void
    {
        $request = new ServerRequest(['url' => '/']);
        $response = $this->middleware->process($request, $this->createOkHandler());

        $this->assertTrue($response->hasHeader('Permissions-Policy'));
        $this->assertSame(
            'geolocation=(), microphone=(), camera=()',
            $response->getHeaderLine('Permissions-Policy'),
        );
    }

    /**
     * Content-Security-Policy is set with expected directives.
     */
    public function testContentSecurityPolicy(): void
    {
        $request = new ServerRequest(['url' => '/']);
        $response = $this->middleware->process($request, $this->createOkHandler());

        $this->assertTrue($response->hasHeader('Content-Security-Policy'));
        $csp = $response->getHeaderLine('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("style-src 'self'", $csp);
        $this->assertStringContainsString("img-src 'self' data:", $csp);
        $this->assertStringContainsString("font-src 'self' data:", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    /**
     * X-Frame-Options is set to SAMEORIGIN.
     */
    public function testXFrameOptions(): void
    {
        $request = new ServerRequest(['url' => '/']);
        $response = $this->middleware->process($request, $this->createOkHandler());

        $this->assertTrue($response->hasHeader('X-Frame-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
    }

    /**
     * X-Content-Type-Options is set to nosniff.
     */
    public function testXContentTypeOptions(): void
    {
        $request = new ServerRequest(['url' => '/']);
        $response = $this->middleware->process($request, $this->createOkHandler());

        $this->assertTrue($response->hasHeader('X-Content-Type-Options'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    /**
     * HSTS is NOT sent on plain HTTP requests.
     */
    public function testHstsNotSentOnHttp(): void
    {
        $request = new ServerRequest([
            'url' => '/',
            'environment' => ['HTTPS' => 'off'],
        ]);
        $response = $this->middleware->process($request, $this->createOkHandler());

        $this->assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    /**
     * HSTS IS sent on HTTPS requests.
     */
    public function testHstsSentOnHttps(): void
    {
        $request = new ServerRequest([
            'url' => '/',
            'environment' => ['HTTPS' => 'on'],
        ]);
        $response = $this->middleware->process($request, $this->createOkHandler());

        $this->assertTrue($response->hasHeader('Strict-Transport-Security'));
        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $response->getHeaderLine('Strict-Transport-Security'),
        );
    }

    /**
     * HSTS IS sent when X-Forwarded-Proto is https (proxy scenario).
     */
    public function testHstsSentViaForwardedProto(): void
    {
        $request = new ServerRequest([
            'url' => '/',
            'environment' => ['HTTP_X_FORWARDED_PROTO' => 'https'],
        ]);
        $response = $this->middleware->process($request, $this->createOkHandler());

        $this->assertTrue($response->hasHeader('Strict-Transport-Security'));
    }

    /**
     * All security headers are present simultaneously on an HTTP request.
     */
    public function testAllHeadersOnHttp(): void
    {
        $request = new ServerRequest([
            'url' => '/api/v1/courses',
            'environment' => ['HTTPS' => 'off'],
        ]);
        $response = $this->middleware->process($request, $this->createOkHandler());

        $this->assertTrue($response->hasHeader('Referrer-Policy'));
        $this->assertTrue($response->hasHeader('Permissions-Policy'));
        $this->assertTrue($response->hasHeader('Content-Security-Policy'));
        $this->assertTrue($response->hasHeader('X-Frame-Options'));
        $this->assertTrue($response->hasHeader('X-Content-Type-Options'));
        $this->assertFalse($response->hasHeader('Strict-Transport-Security'));
    }
}
