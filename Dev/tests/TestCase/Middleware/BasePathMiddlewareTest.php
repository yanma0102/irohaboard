<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\BasePathMiddleware;
use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * BasePathMiddleware Test
 *
 * リバースプロキシが接頭辞を剥がす構成を主対象とする。
 * 接頭辞の決定は X-Forwarded-Prefix を優先し、無ければ base_path を使う。
 */
class BasePathMiddlewareTest extends TestCase
{
    /**
     * @var \App\Middleware\BasePathMiddleware
     */
    private BasePathMiddleware $middleware;

    /**
     * 元の base_path 設定（テスト後に復元する）
     */
    private mixed $originalBasePath;

    public function setUp(): void
    {
        parent::setUp();
        $this->middleware = new BasePathMiddleware();
        $this->originalBasePath = Configure::read('base_path');
    }

    public function tearDown(): void
    {
        if ($this->originalBasePath !== null) {
            Configure::write('base_path', $this->originalBasePath);
        } else {
            Configure::delete('base_path');
        }
        parent::tearDown();
    }

    /**
     * 受け取った request を保持するハンドラを生成する。
     */
    private function createCapturingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $received = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->received = $request;

                return (new Response())->withStatus(200);
            }

            public function canHandle(ServerRequestInterface $request): bool
            {
                return true;
            }
        };
    }

    /**
     * T1: base_path 未設定・ヘッダ無し → 何も変更せず通過する。
     */
    public function testNoBasePathAndNoHeaderPassesThrough(): void
    {
        Configure::write('base_path', '');

        $request = new ServerRequest(['url' => '/users/login']);
        $beforeBase = $request->getAttribute('base');
        $beforeWebroot = $request->getAttribute('webroot');

        $handler = $this->createCapturingHandler();
        $this->middleware->process($request, $handler);

        $this->assertSame($beforeBase, $handler->received->getAttribute('base'));
        $this->assertSame($beforeWebroot, $handler->received->getAttribute('webroot'));
        $this->assertSame('/users/login', $handler->received->getUri()->getPath());
    }

    /**
     * T2: X-Forwarded-Prefix がある → その接頭辞を base/webroot に適用する。
     * （プロキシが剥がした後のパス＝接頭辞無しでも、外部接頭辞を復元する）
     */
    public function testForwardedPrefixHeaderIsApplied(): void
    {
        Configure::write('base_path', '');

        $request = (new ServerRequest(['url' => '/users/login']))
            ->withHeader('X-Forwarded-Prefix', '/iroha1');

        $handler = $this->createCapturingHandler();
        $this->middleware->process($request, $handler);

        $received = $handler->received;
        $this->assertSame('/iroha1', $received->getAttribute('base'));
        $this->assertSame('/iroha1/', $received->getAttribute('webroot'));
        // プロキシが剥が済みのためパスは剥離されない（そのまま）
        $this->assertSame('/users/login', $received->getUri()->getPath());
    }

    /**
     * T3: base_path 設定あり・ヘッダ無し → 設定値を使用する（従来動作）。
     */
    public function testConfiguredBasePathIsUsedWithoutHeader(): void
    {
        Configure::write('base_path', '/iroha1');

        $request = new ServerRequest(['url' => '/users/login']);
        $handler = $this->createCapturingHandler();
        $this->middleware->process($request, $handler);

        $received = $handler->received;
        $this->assertSame('/iroha1', $received->getAttribute('base'));
        $this->assertSame('/iroha1/', $received->getAttribute('webroot'));
    }

    /**
     * T4: リクエストパスに接頭辞がある場合 → 剥離してアプリへ渡す。
     */
    public function testRequestPathWithPrefixIsStripped(): void
    {
        Configure::write('base_path', '/iroha1');

        $request = new ServerRequest(['url' => '/iroha1/users/login']);
        $handler = $this->createCapturingHandler();
        $this->middleware->process($request, $handler);

        $received = $handler->received;
        $this->assertSame('/users/login', $received->getUri()->getPath());
        $this->assertSame('/iroha1', $received->getAttribute('base'));
        $this->assertSame('/iroha1/', $received->getAttribute('webroot'));
    }

    /**
     * T5: ヘッダは base_path より優先される。
     */
    public function testHeaderTakesPrecedenceOverConfiguredBasePath(): void
    {
        Configure::write('base_path', '/old');

        $request = (new ServerRequest(['url' => '/users/login']))
            ->withHeader('X-Forwarded-Prefix', '/new');

        $handler = $this->createCapturingHandler();
        $this->middleware->process($request, $handler);

        $this->assertSame('/new', $handler->received->getAttribute('base'));
        $this->assertSame('/new/', $handler->received->getAttribute('webroot'));
    }

    /**
     * T6: 不正な形式のヘッダは無視し、自動判定にフォールバックする。
     */
    public function testInvalidForwardedPrefixIsIgnored(): void
    {
        Configure::write('base_path', '');

        // 空白を含む不正値
        $request = (new ServerRequest(['url' => '/users/login']))
            ->withHeader('X-Forwarded-Prefix', '/bad path');

        $beforeBase = $request->getAttribute('base');
        $beforeWebroot = $request->getAttribute('webroot');

        $handler = $this->createCapturingHandler();
        $this->middleware->process($request, $handler);

        $received = $handler->received;
        $this->assertSame($beforeBase, $received->getAttribute('base'));
        $this->assertSame($beforeWebroot, $received->getAttribute('webroot'));
        $this->assertSame('/users/login', $received->getUri()->getPath());
    }
}
