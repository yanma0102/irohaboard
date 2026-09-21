<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Controller\Api\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * API ルート用エラーハンドリングミドルウェア
 *
 * ApiException をキャッチして JSON レスポンスを返す
 */
class ApiErrorMiddleware implements MiddlewareInterface
{
    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ApiException $e) {
            $payload = $e->getErrorPayload();
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                $json = json_encode(['error' => ['code' => 500, 'message' => 'Failed to encode response']]);
            }

            $response = new \Cake\Http\Response();
            return $response
                ->withStatus($e->getCode())
                ->withType('application/json')
                ->withStringBody($json);
        }
    }
}
