<?php
declare(strict_types=1);

namespace App\Middleware;

use Cake\Http\Exception\InvalidCsrfTokenException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSRF クッキーの path を、ブラウザから見た実際の URL 接頭辞に合わせる middleware。
 *
 * CakePHP の CsrfProtectionMiddleware は csrfToken クッキーの path に
 * request の webroot 属性をそのまま使う。BasePathMiddleware はアセット URL 用に
 * webroot へ接頭辞を付与するため、次のような不一致が起きる。
 *
 *   - ブラウザの URL: /iroha1/admin/groups
 *   - アプリ内部の path: /admin/groups（接頭辞をルーティング前に剥がすため）
 *   - csrfToken の path: /iroha1/（webroot 由来）
 *
 * この状態でもブラウザは /iroha1/ 配下にはクッキーを送るが、デプロイ形態に
 * よっては送られず、削除などの POST で
 * 「Missing or invalid CSRF cookie.」となる。
 *
 * 本 middleware は CSRF middleware の直後に置き、Set-Cookie ヘッダーの
 * csrfToken の path を「ブラウザから見た接頭辞」にそろえる。
 * 接頭辞が無い（ルート配置）場合は path=/ にそろえる。
 */
class CsrfCookiePathMiddleware implements MiddlewareInterface
{
    /**
     * 対象クッキー名（CsrfProtectionMiddleware の既定値）
     */
    private const COOKIE_NAME = 'csrfToken';

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (InvalidCsrfTokenException $e) {
            // 検証失敗時に積まれた失効クッキーの path も補正してから再送出する
            $path = $this->browserBase($request);
            $headers = $e->getHeaders()['Set-Cookie'] ?? [];
            if ($headers !== []) {
                $e->setHeader('Set-Cookie', $this->replaceCookiePath($headers[0], $path));
            }

            throw $e;
        }

        $setCookies = $response->getHeader('Set-Cookie');
        if ($setCookies === []) {
            return $response;
        }

        $path = $this->browserBase($request);
        $rewritten = array_map(
            fn(string $value): string => $this->replaceCookiePath($value, $path),
            $setCookies,
        );

        return $response->withHeader('Set-Cookie', $rewritten);
    }

    /**
     * ブラウザから見たパスの接頭辞を返す。
     *
     * BasePathMiddleware が接頭辞を剥がした場合は base 属性に接頭辞が入る。
     * プロキシ側で既に剥がされている場合、もしくはルート配置の場合は '' を返す。
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return string 先頭 '/' 付きの接頭辞、または ''（接頭辞なし）
     */
    private function browserBase(ServerRequestInterface $request): string
    {
        $base = (string)$request->getAttribute('base', '');

        if ($base === '' || $base === '/') {
            return '';
        }

        return '/' . trim($base, '/');
    }

    /**
     * Set-Cookie 文字列の path 属性を置換する（対象クッキーのみ）。
     *
     * @param string $value Set-Cookie ヘッダー値
     * @param string $path 設定したい path（'' の場合は '/'）
     * @return string
     */
    private function replaceCookiePath(string $value, string $path): string
    {
        if (!str_starts_with($value, self::COOKIE_NAME . '=')) {
            return $value;
        }

        $newPath = $path === '' ? '/' : $path;

        if (preg_match('/;\s*path=[^;]*/i', $value)) {
            return (string)preg_replace('/;\s*path=[^;]*/i', '; path=' . $newPath, $value, 1);
        }

        return $value . '; path=' . $newPath;
    }
}
