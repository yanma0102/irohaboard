<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Utility\RequestPathHelper;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * API Rate Limiting Middleware
 *
 * Applies a per-minute sliding-window rate limit to requests under /api/
 * (excluding /mcp which has its own limiter).
 *
 * Key strategy:
 *  - Authenticated requests (Authorization header present): key = hash of header
 *  - Unauthenticated requests: key = client IP address
 *
 * Configuration (config/app.php or config/ib_config.php):
 *  'api_rate_limit_per_minute' => 120   (default when not configured)
 *  Set to 0 to disable rate limiting entirely.
 *
 * Returns HTTP 429 with Retry-After header and a JSON error body
 * consistent with the API error contract: {"error":{"code":429,"message":"..."}}.
 */
class ApiRateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // ベースパスを除いたパスで判定する（サブディレクトリ配置でも /api/ に一致させる）
        $path = RequestPathHelper::baseRelative($request);

        // Only apply to /api/ paths, skip /mcp (has its own limiter)
        if (!str_starts_with($path, '/api/') || str_starts_with($path, '/mcp')) {
            return $handler->handle($request);
        }

        $limit = (int)(Configure::read('api_rate_limit_per_minute') ?? 120);
        if ($limit <= 0) {
            return $handler->handle($request);
        }

        // Derive rate-limit key: auth header hash when present, IP otherwise
        $key = $this->buildKey($request);

        // Per-minute sliding window — minute-granularity key ensures natural expiry
        $windowKey = $key . '_' . date('YmHi');

        // Use a simpler approach: read → increment → write
        $current = (int)Cache::read($windowKey, 'api_rate_limit');
        $count = $current + 1;
        Cache::write($windowKey, $count, 'api_rate_limit');

        if ($count > $limit) {
            // Retry-After: seconds until the current minute window rolls over
            $secondsRemaining = 60 - (int)date('s');
            $retryAfter = max(1, $secondsRemaining);

            $body = json_encode(
                ['error' => ['code' => 429, 'message' => 'Rate limit exceeded. Please try again later.']],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );

            return (new Response())
                ->withStatus(429)
                ->withHeader('Retry-After', (string)$retryAfter)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-RateLimit-Limit', (string)$limit)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withStringBody($body);
        }

        /** @var \Psr\Http\Message\ResponseInterface $response */
        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string)$limit)
            ->withHeader('X-RateLimit-Remaining', (string)max(0, $limit - $count));
    }

    /**
     * Build the rate-limit key from the Authorization header or client IP.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return string
     */
    private function buildKey(ServerRequestInterface $request): string
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader !== '') {
            return 'api_rl_' . hash('sha256', $authHeader);
        }

        return 'api_rl_ip_' . md5($this->getClientIp($request));
    }

    /**
     * Extract the best-guess client IP address.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return string
     */
    private function getClientIp(ServerRequestInterface $request): string
    {
        // Check common proxy headers first
        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        if ($forwardedFor !== '') {
            $ips = array_map('trim', explode(',', $forwardedFor));

            return $ips[0] ?: '127.0.0.1';
        }

        $realIp = $request->getHeaderLine('X-Real-Ip');
        if ($realIp !== '') {
            return $realIp;
        }

        // Fall back to server params
        $serverParams = $request->getServerParams();

        return $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
