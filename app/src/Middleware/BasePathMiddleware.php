<?php
declare(strict_types=1);

namespace App\Middleware;

use Cake\Core\Configure;
use Cake\Log\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 設定されたベースパス（URLのサブディレクトリ接頭辞）を request に適用する。
 *
 * CakePHP は既定で PHP_SELF からベースパスを自動判定する
 * （UriFactory::marshalUriAndBaseFromSapi → request 属性 'base'）。
 * 一部の構成ではリバースプロキシが接頭辞を剥がすため、PHP_SELF からは
 * 接頭辞を検出できない。その場合に ib_config.php の `base_path` で
 * 明示し、生成URL（RoutedURL / AssetMiddleware / 認証リダイレクト）に付与する。
 *
 * AssetMiddleware・RoutingMiddleware より前に挿入すること。
 * ここ以降のパイプラインは全てこの値を前提に URL を組み立てる。
 */
class BasePathMiddleware implements MiddlewareInterface
{
    /**
     * ベースパスとして許可する形式（先頭 /、末尾 / なし、クエリ・スキーム・空白を含むものは不可）
     */
    private const VALID_PATTERN = '#^/(?:[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*)?$#';

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $configured = trim((string)Configure::read('base_path'));

        if ($configured === '') {
            // 未設定（推奨）: CakePHP の自動判定をそのまま使う
            return $handler->handle($request);
        }

        $base = '/' . trim($configured, '/');

        if (!preg_match(self::VALID_PATTERN, $base)) {
            // 不正値は取り込まない: 自動判定にフォールバックしログに記録する
            Log::warning(sprintf(
                'base_path "%s" は不正な形式のため無視しました（自動判定を使用します）。'
                . '英数字・記号（. _ ~ -）と / のみ使用し、先頭と末尾の / は不要です。',
                $base
            ), ['scope' => ['base_path']]);

            return $handler->handle($request);
        }

        // webroot 属性は通常 '/' 末尾。接頭辞を付与する前に '/' へ戻す。
        $webroot = '/' . ltrim((string)$request->getAttribute('webroot', '/'), '/');

        // サーバ側に rewrite 設定が無くても Setting だけで動くよう、
        // リクエストパス先頭の接頭辞を剥がしてアプリに渡す。
        // （サーバー側で既に接頭辞が剥が済みの場合は何もせずそのまま通す）
        $path = $request->getUri()->getPath();
        if ($path === $base || str_starts_with($path, $base . '/')) {
            $stripped = substr($path, strlen($base));
            $request = $request
                ->withUri($request->getUri()->withPath($stripped === '' ? '/' : $stripped))
                ->withAttribute('base', $base)
                // アセットURL（/css/ /js/ /img/）は base ではなく webroot 属性から
                // 構築される（Routing\Asset::requestWebroot）ため、接頭辞を付ける。
                ->withAttribute('webroot', $base . $webroot);

            return $handler->handle($request);
        }

        // パスに接頭辞が含まれていない場合は、webroot に接頭辞を付与しない。
        // （ベースパス設定ありながらルート等でアクセスした際に、
        // 誤って資産URLに接頭辞が付与されてファイルが見つからないのを防止）
        return $handler->handle(
            $request
                ->withAttribute('base', $base)
                // そのままの webroot を維持する
        );
    }
}
