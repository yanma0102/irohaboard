<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Controller\Api\ApiException;
use Cake\Controller\Exception\InvalidParameterException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * API ルート用エラーハンドリングミドルウェア
 *
 * ApiException / InvalidParameterException をキャッチして JSON レスポンスを返す。
 * 500 系例外（InternalErrorException 等）は JSON 化せず、
 * 従来どおり ErrorHandlerMiddleware に委譲する。
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
            return $this->buildJsonResponse($e->getErrorPayload(), $e->getCode());
        } catch (InvalidParameterException $e) {
            $code = $e->getCode() ?: 404;
            $payload = [
                'error' => [
                    'code' => $code,
                    'message' => $e->getMessage(),
                ],
            ];

            return $this->buildJsonResponse($payload, $code);
        }
    }

    /**
     * JSON レスポンスを組み立てる
     *
     * @param array $payload API エラー契約に準拠したペイロード
     * @param int $statusCode HTTP ステータスコード
     */
    private function buildJsonResponse(array $payload, int $statusCode): ResponseInterface
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $json = json_encode(['error' => ['code' => 500, 'message' => 'Failed to encode response']]);
        }

        $response = new \Cake\Http\Response();
        return $response
            ->withStatus($statusCode)
            ->withType('application/json')
            ->withStringBody($json);
    }
}
