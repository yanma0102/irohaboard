<?php
declare(strict_types=1);

namespace App\Controller;

use App\Mcp\IrohaAuthMiddleware;
use App\Mcp\IrohaTokenValidator;
use App\Mcp\McpRateLimitMiddleware;
use App\Mcp\McpServerFactory;
use App\Service\AccessControlService;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ResponseFactory;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface as PsrResponse;

/**
 * MCP (Model Context Protocol) endpoint controller.
 *
 * Streamable HTTP transport の単一エンドポイント /mcp を扱う。
 * 認証は IrohaAuthMiddleware（Bearer トークン）により、
 * JSON-RPC メッセージ処理より前に実行される。
 */
class McpController extends Controller
{
    /**
     * POST /mcp — JSON-RPC メッセージを処理する（initialize / tools 等）。
     *
     * @return \Cake\Http\Response
     */
    public function endpoint(): Response
    {
        return $this->handle();
    }

    /**
     * DELETE /mcp — セッションを破棄する。
     *
     * @return \Cake\Http\Response
     */
    public function deleteSession(): Response
    {
        return $this->handle();
    }

    /**
     * OPTIONS /mcp — プリフライトリクエストを処理する。
     *
     * CORS preflight（Access-Control-Request-Method ヘッダ付き OPTIONS）は
     * 認証なしで 204 + CORS ヘッダを返す。通常の OPTIONS は従来どおり認証を通す。
     *
     * @return \Cake\Http\Response
     */
    public function options(): Response
    {
        // CORS preflight のみ認証なしで応答する
        if ($this->request->getHeaderLine('Access-Control-Request-Method') !== '') {
            return $this->handleCorsPreflight();
        }

        // 通常の OPTIONS（preflight ではない）は認証を通す
        return $this->handle();
    }

    /**
     * GET /mcp — サーバー主導メッセージ用の SSE ストリームを開く（認証不要）。
     *
     * MCP Streamable HTTP 仕様では、GET はサーバー→クライアントの SSE ストリームを
     * 開くために使う。仕様上は 405 を返すことも認められているが、クライアントが
     * `Accept: text/event-stream` を送って SSE を期待するケースがあるため、
     * その場合は Content-Type: text/event-stream で応答する必要がある。
     *
     * PHP はリクエスト毎に処理を完結させるため接続維持型の SSE は提供できない。
     * そこで仕様が許容する「有限の SSE ストリーム」（keep-alive コメントと retry
     * ヒントを送って即座に閉じる）を返す。クライアントは text/event-stream を
     * 受け取り、イベントが無いままストリーム終了 → retry 間隔で再接続する。
     *
     * `Accept` に text/event-stream を含まない（監視ツール等）場合は、
     * 従来どおり 200 + JSON のヘルスチェック応答を返す。
     *
     * @return \Cake\Http\Response
     */
    public function health(): Response
    {
        $accept = $this->request->getHeaderLine('Accept');

        // SSE を期待するクライアントには text/event-stream で応答する
        if (str_contains(strtolower($accept), 'text/event-stream')) {
            // SSE コメント行（: 始まり）と retry ヒント。有限ストリームとして閉じる。
            $body = ": irohaboard mcp\n"
                . ": server-initiated SSE stream is not available (stateless PHP)\n"
                . "retry: 3000\n\n";

            return (new Response())
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/event-stream; charset=UTF-8')
                ->withHeader('Cache-Control', 'no-cache')
                ->withHeader('X-Accel-Buffering', 'no')
                ->withHeader('Allow', 'POST, DELETE, OPTIONS, GET')
                ->withStringBody($body);
        }

        // 疎通確認（ヘルスチェック）用途: 200 + JSON
        $payload = [
            'status' => 'ok',
            'service' => 'irohaboard',
            'transport' => 'streamable-http',
            'methods' => 'POST, DELETE, OPTIONS',
        ];

        return (new Response())
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Allow', 'POST, DELETE, OPTIONS, GET')
            ->withStringBody((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * MCP サーバーを構築し、トランスポートでリクエストを処理して
     * PSR-7 応答を CakePHP の Response に変換して返す。
     *
     * Content-Type（application/json / text/event-stream 等）を
     * 必ず保持すること（SSE と JSON の判別に必須）。
     *
     * @return \Cake\Http\Response
     */
    private function handle(): Response
    {
        $userTokens = $this->fetchTable('UserTokens');
        $validator = new IrohaTokenValidator($userTokens);
        $accessControl = new AccessControlService($this->fetchTable('Users')->getConnection());
        $server = (new McpServerFactory())->create($accessControl);
        $responseFactory = new ResponseFactory();

        $allowedOrigins = Configure::read('mcp_cors_allowed_origins') ?? [];

        // DNS リバインディング保護で許可するホスト名。
        // 未設定時は SDK 既定（localhost 系のみ）。
        $allowedHosts = Configure::read('mcp_allowed_hosts') ?? [];

        $transport = new StreamableHttpTransport(
            request: $this->request,
            middleware: [
                new CorsMiddleware($allowedOrigins),
                new DnsRebindingProtectionMiddleware($allowedHosts),
                new IrohaAuthMiddleware($validator, $responseFactory),
                new McpRateLimitMiddleware(
                    $this->fetchTable('Logs'),
                    McpRateLimitMiddleware::DEFAULT_MAX_REQUESTS,
                    $responseFactory,
                ),
                new OAuthRequestMetaMiddleware(),
            ],
        );

        $psrResponse = $server->run($transport);

        return $this->convertToCakeResponse($psrResponse);
    }

    /**
     * CORS preflight リクエストに認証なしで応答する。
     *
     * ブラウザの CORS preflight は Authorization ヘッダを送らないため、
     * 通常の handle() 経由だと IrohaAuthMiddleware で 401 になる。
     * 本番環境では mcp_cors_allowed_origins で適切に制限すること。
     *
     * @return \Cake\Http\Response
     */
    private function handleCorsPreflight(): Response
    {
        $allowedOrigins = Configure::read('mcp_cors_allowed_origins') ?? [];
        $origin = $this->request->getHeaderLine('Origin');

        $response = (new Response())
            ->withStatus(204);

        // Access-Control-Allow-Origin
        if (!empty($allowedOrigins)) {
            if (in_array('*', $allowedOrigins, true)) {
                $response = $response->withHeader('Access-Control-Allow-Origin', '*');
            } elseif ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
                $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
                $response = $response->withHeader('Vary', 'Origin');
            }
        }

        // Access-Control-Allow-Methods
        $response = $response->withHeader(
            'Access-Control-Allow-Methods',
            'GET, POST, DELETE, OPTIONS',
        );

        // Access-Control-Allow-Headers（MCP Streamable HTTP で使用されるヘッダ）
        $response = $response->withHeader(
            'Access-Control-Allow-Headers',
            'Accept, Authorization, Content-Type, Last-Event-ID, Mcp-Protocol-Version, Mcp-Session-Id',
        );

        // Access-Control-Max-Age（preflight キャッシュ 24 時間）
        $response = $response->withHeader('Access-Control-Max-Age', '86400');

        return $response;
    }

    /**
     * PSR-7 応答を CakePHP の Response へ変換する。
     *
     * ステータス・全ヘッダ・ボディをコピーする。
     * Content-Type ヘッダを保持することが重要（SSE / JSON の判別）。
     *
     * @param \Psr\Http\Message\ResponseInterface $psrResponse PSR-7 応答
     * @return \Cake\Http\Response
     */
    private function convertToCakeResponse(PsrResponse $psrResponse): Response
    {
        $response = (new Response())
            ->withStatus($psrResponse->getStatusCode());

        foreach ($psrResponse->getHeaders() as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                $response = $first
                    ? $response->withHeader((string)$name, $value)
                    : $response->withAddedHeader((string)$name, $value);
                $first = false;
            }
        }

        return $response->withStringBody((string)$psrResponse->getBody());
    }
}
