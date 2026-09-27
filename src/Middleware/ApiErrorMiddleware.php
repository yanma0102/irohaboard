<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Controller\Api\ApiException;
use App\Utility\RequestPathHelper;
use Cake\Controller\Exception\InvalidParameterException;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * API ルート用エラーハンドリングミドルウェア
 *
 * ApiException / InvalidParameterException / BadRequestException をキャッチして
 * API 経路（/api/・/mcp）では JSON レスポンスを返す。
 * BadRequestException は非 API パス（フロントエンドの HTML ページ等）では
 * 従来どおり再スローし、ErrorHandlerMiddleware に委譲する。
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
        } catch (BadRequestException $e) {
            // ベースパスを除いたパスで API 経路判定（ApiRateLimitMiddleware と同一パターン）
            $path = RequestPathHelper::baseRelative($request);

            if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/mcp')) {
                // 非 API パスでは従来どおり HTML エラーページに委譲
                throw $e;
            }

            $code = $e->getCode() ?: 400;
            $message = $e->getMessage() ?: 'Bad Request';

            $payload = [
                'error' => [
                    'code' => $code,
                    'message' => $message,
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

        $response = new Response();

        return $response
            ->withStatus($statusCode)
            ->withType('application/json')
            ->withStringBody($json);
    }
}
