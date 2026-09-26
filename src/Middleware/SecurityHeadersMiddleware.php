<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Security Headers Middleware
 *
 * Sets security-related HTTP headers on every response:
 *  - Referrer-Policy
 *  - Permissions-Policy
 *  - Content-Security-Policy
 *  - Strict-Transport-Security (HTTPS only)
 *  - X-Frame-Options
 *  - X-Content-Type-Options
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var \Psr\Http\Message\ResponseInterface $response */
        $response = $handler->handle($request);

        $response = $response
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; "
                . "img-src 'self' data:; "
                . "style-src 'self' 'unsafe-inline'; "
                . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
                . "font-src 'self' data:; "
                . "connect-src 'self'; "
                . "frame-ancestors 'self'; "
                . "base-uri 'self'",
            )
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        // HSTS: only send over HTTPS to avoid breaking local HTTP development
        if ($this->isHttps($request)) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }

    /**
     * Determine whether the request was made over HTTPS.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return bool
     */
    private function isHttps(ServerRequestInterface $request): bool
    {
        $serverParams = $request->getServerParams();

        // Standard HTTPS indicator
        if (!empty($serverParams['HTTPS']) && $serverParams['HTTPS'] !== 'off') {
            return true;
        }

        // Common proxy header
        if (strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https') {
            return true;
        }

        // CakePHP ssl detector (if present on request attributes)
        $sslAttr = $request->getAttribute('ssl');
        if ($sslAttr !== null && $sslAttr !== false) {
            return true;
        }

        return false;
    }
}
