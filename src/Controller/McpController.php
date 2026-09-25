<?php
declare(strict_types=1);

namespace App\Controller;

use App\Mcp\IrohaAuthMiddleware;
use App\Mcp\IrohaTokenValidator;
use App\Mcp\McpRateLimitMiddleware;
use App\Mcp\McpServerFactory;
use App\Service\AccessControlService;
use Cake\Controller\Controller;
use Cake\Http\Response;
use Cake\Http\ResponseFactory;
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
     * @return \Cake\Http\Response
     */
    public function options(): Response
    {
        return $this->handle();
    }

    /**
     * GET /mcp — サーバー主導メッセージ用 SSE ストリームは非対応（405）。
     *
     * MCP Streamable HTTP 仕様では、GET 未対応のサーバーは 405 Method Not Allowed
     * を返す。PHP はリクエスト毎に処理を完結させるため、接続を維持する
     * SSE ストリームは提供できない（提供するとワーカーを占有する）。
     * ルート未定義の 404 (HTML) はクライアント（Inspector / Claude Desktop 等）の
     * 接続フローを壊すため、405 + Allow ヘッダで明示する。
     *
     * @return \Cake\Http\Response
     */
    public function getNotAllowed(): Response
    {
        return (new Response())
            ->withStatus(405)
            ->withHeader('Allow', 'POST, DELETE, OPTIONS');
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

        $transport = new StreamableHttpTransport(
            request: $this->request,
            middleware: [
                ...StreamableHttpTransport::defaultMiddleware(),
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
