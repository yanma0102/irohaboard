<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Mcp;

use Cake\Http\ResponseFactory;
use Cake\Http\StreamFactory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Bearer トークン検証ミドルウェア（mcp/sdk v0.8.1 検証に基づく自作）。
 *
 * SDK の AuthorizationMiddleware は使わない — ProtectedResourceMetadata に
 * authorizationServers が必須（AS 無しの自己発行トークンでは不適）。
 * 成功時は oauth.* 属性を PSR-7 request attributes に設定し、
 * OAuthRequestMetaMiddleware が params._meta.oauth へ転記する。
 */
class IrohaAuthMiddleware implements MiddlewareInterface
{
    /**
     * @param \App\Mcp\IrohaTokenValidator $validator トークンバリデータ
     * @param \Psr\Http\Message\ResponseFactoryInterface|null $responseFactory 401 応答生成用
     */
    public function __construct(
        private IrohaTokenValidator $validator,
        private ?ResponseFactoryInterface $responseFactory = null,
    ) {
    }

    /**
     * Authorization ヘッダを検証し、成功時は oauth.* 属性を付与する
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request リクエスト
     * @param \Psr\Http\Server\RequestHandlerInterface $handler 次ハンドラ
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $this->unauthorized('invalid_token', 'Missing bearer token');
        }

        $result = $this->validator->validate($m[1]);
        if (!$result->isAllowed()) {
            return $this->unauthorized(
                $result->getError() ?? 'invalid_token',
                $result->getErrorDescription() ?? 'Token is invalid or expired',
            );
        }

        foreach ($result->getAttributes() as $key => $value) {
            // キーは必ず 'oauth.' プレフィクス（OAuthRequestMetaMiddleware の抽出条件）
            $request = $request->withAttribute((string)$key, $value);
        }

        return $handler->handle($request);
    }

    /**
     * 401 応答を生成する（MCP 仕様の WWW-Authenticate 形式）
     *
     * @param string $error エラーコード
     * @param string $description 説明
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function unauthorized(string $error, string $description): ResponseInterface
    {
        $header = sprintf('Bearer error="%s", error_description="%s"', $error, $description);
        $factory = $this->responseFactory ?? new ResponseFactory();
        $body = (new StreamFactory())->createStream(
            json_encode(['error' => $error, 'error_description' => $description], JSON_UNESCAPED_UNICODE) ?: '',
        );

        return $factory->createResponse(401)
            ->withHeader('WWW-Authenticate', $header)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
    }
}
