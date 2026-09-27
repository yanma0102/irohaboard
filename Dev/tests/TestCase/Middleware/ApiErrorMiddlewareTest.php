<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Controller\Api\ApiException;
use App\Middleware\ApiErrorMiddleware;
use Cake\Controller\Exception\InvalidParameterException;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * ApiErrorMiddleware Test
 */
class ApiErrorMiddlewareTest extends TestCase
{
    /**
     * @var \App\Middleware\ApiErrorMiddleware
     */
    private ApiErrorMiddleware $middleware;

    public function setUp(): void
    {
        parent::setUp();
        $this->middleware = new ApiErrorMiddleware();
    }

    /**
     * Helper: create a handler that throws the given exception
     */
    private function createThrowingHandler(Throwable $exception): RequestHandlerInterface
    {
        return new class ($exception) implements RequestHandlerInterface {
            public function __construct(private Throwable $exception)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->exception;
            }

            public function canHandle(ServerRequestInterface $request): bool
            {
                return true;
            }
        };
    }

    /**
     * Helper: create a handler that returns 200 OK
     */
    private function createOkHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200)
                    ->withHeader('Content-Type', 'application/json')
                    ->withStringBody('{"ok":true}');
            }

            public function canHandle(ServerRequestInterface $request): bool
            {
                return true;
            }
        };
    }

    // ================================================================
    // (a) ApiException — existing behaviour preserved
    // ================================================================

    /**
     * ApiException with status 400 returns JSON with correct shape
     */
    public function testApiExceptionReturnsJson400(): void
    {
        $exception = new ApiException(400, 'Validation failed', ['field' => 'required']);
        $request = new ServerRequest(['url' => '/api/v1/users']);
        $handler = $this->createThrowingHandler($exception);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(400, $body['error']['code']);
        $this->assertSame('Validation failed', $body['error']['message']);
        $this->assertArrayHasKey('errors', $body['error']);
    }

    /**
     * ApiException with status 403 returns JSON with correct shape
     */
    public function testApiExceptionReturnsJson403(): void
    {
        $exception = new ApiException(403, 'Forbidden');
        $request = new ServerRequest(['url' => '/api/v1/users']);
        $handler = $this->createThrowingHandler($exception);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(403, $body['error']['code']);
        $this->assertSame('Forbidden', $body['error']['message']);
    }

    /**
     * ApiException with status 429 returns JSON matching rate-limit contract
     */
    public function testApiExceptionReturnsJson429(): void
    {
        $exception = new ApiException(429, 'Rate limit exceeded');
        $request = new ServerRequest(['url' => '/api/v1/courses']);
        $handler = $this->createThrowingHandler($exception);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(429, $body['error']['code']);
    }

    // ================================================================
    // (b) InvalidParameterException — must return JSON, NOT HTML
    // ================================================================

    /**
     * InvalidParameterException (default code 404) returns JSON 404, not HTML
     */
    public function testInvalidParameterExceptionReturnsJson404(): void
    {
        $exception = new InvalidParameterException([
            'template' => 'failed_coercion',
            'passed' => 'abc',
            'type' => 'int',
            'parameter' => 'id',
            'controller' => 'Contents',
            'action' => 'view',
            'prefix' => 'Api',
            'plugin' => null,
        ]);
        $request = new ServerRequest(['url' => '/api/v1/contents/abc']);
        $handler = $this->createThrowingHandler($exception);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = (string)$response->getBody();
        $this->assertJson($body);

        $decoded = json_decode($body, true);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame(404, $decoded['error']['code']);
        $this->assertIsString($decoded['error']['message']);
    }

    /**
     * InvalidParameterException response must NOT contain HTML tags
     */
    public function testInvalidParameterExceptionResponseIsNotHtml(): void
    {
        $exception = new InvalidParameterException([
            'template' => 'failed_coercion',
            'passed' => 'abc',
            'type' => 'int',
            'parameter' => 'id',
            'controller' => 'Courses',
            'action' => 'view',
            'prefix' => 'Api',
            'plugin' => null,
        ]);
        $request = new ServerRequest(['url' => '/api/v1/courses/abc']);
        $handler = $this->createThrowingHandler($exception);

        $response = $this->middleware->process($request, $handler);

        $body = (string)$response->getBody();
        $this->assertJson($body);
        // Must not contain any HTML elements
        $this->assertStringNotContainsString('<html', strtolower($body));
        $this->assertStringNotContainsString('<body', strtolower($body));
        $this->assertStringNotContainsString('<h2', $body);
    }

    // ================================================================
    // (c) 500-series exceptions must NOT be converted to JSON
    // ================================================================

    /**
     * InternalErrorException (500) is NOT caught — it propagates to ErrorHandlerMiddleware
     */
    public function testInternalErrorExceptionIsNotCaught(): void
    {
        $exception = new InternalErrorException('Something broke');
        $request = new ServerRequest(['url' => '/api/v1/users']);
        $handler = $this->createThrowingHandler($exception);

        $this->expectException(InternalErrorException::class);
        $this->middleware->process($request, $handler);
    }

    // ================================================================
    // (d) BadRequestException — API paths return JSON, non-API re-throws
    // ================================================================

    /**
     * BadRequestException on /api/ path returns JSON 400, not HTML
     */
    public function testBadRequestExceptionOnApiPathReturnsJson400(): void
    {
        $exception = new BadRequestException('Invalid JSON body');
        $request = new ServerRequest(['url' => '/api/v1/courses']);
        $handler = $this->createThrowingHandler($exception);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = (string)$response->getBody();
        $this->assertJson($body);
        $this->assertStringNotContainsString('<', $body);

        $decoded = json_decode($body, true);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame(400, $decoded['error']['code']);
        $this->assertSame('Invalid JSON body', $decoded['error']['message']);
    }

    /**
     * BadRequestException on /mcp path returns JSON 400
     */
    public function testBadRequestExceptionOnMcpPathReturnsJson400(): void
    {
        $exception = new BadRequestException('Malformed request');
        $request = new ServerRequest(['url' => '/mcp']);
        $handler = $this->createThrowingHandler($exception);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = (string)$response->getBody();
        $this->assertJson($body);
        $this->assertStringNotContainsString('<', $body);

        $decoded = json_decode($body, true);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame(400, $decoded['error']['code']);
        $this->assertSame('Malformed request', $decoded['error']['message']);
    }

    /**
     * BadRequestException on non-API path propagates to ErrorHandlerMiddleware
     */
    public function testBadRequestExceptionOnNonApiPathPropagates(): void
    {
        $exception = new BadRequestException('Invalid JSON body');
        $request = new ServerRequest(['url' => '/users/login']);
        $handler = $this->createThrowingHandler($exception);

        $this->expectException(BadRequestException::class);
        $this->middleware->process($request, $handler);
    }

    /**
     * BadRequestException with base path prefix — API path detection still works
     */
    public function testBadRequestExceptionWithBasePathDetectsApi(): void
    {
        $exception = new BadRequestException('Invalid JSON body');
        $request = new ServerRequest([
            'url' => '/irohaboard/api/v1/courses',
            'server' => ['REQUEST_URI' => '/irohaboard/api/v1/courses'],
        ]);
        // サブディレクトリ配置を模倣: base 属性を設定
        $request = $request->withAttribute('base', '/irohaboard');
        $handler = $this->createThrowingHandler($exception);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $decoded = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame(400, $decoded['error']['code']);
    }

    // ================================================================
    // Pass-through: non-exception requests pass through unchanged
    // ================================================================

    /**
     * Successful request passes through the middleware unchanged
     */
    public function testSuccessfulRequestPassesThrough(): void
    {
        $request = new ServerRequest(['url' => '/api/v1/courses']);
        $handler = $this->createOkHandler();

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame(true, $body['ok']);
    }
}
