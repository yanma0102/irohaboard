<?php
declare(strict_types=1);

namespace App\Utility;

use Psr\Http\Message\ServerRequestInterface;

/**
 * リクエストパスの判定ヘルパー。
 *
 * PSR-7 の `getUri()->getPath()` はリクエストの**生**のパスを返すため、
 * サブディレクトリ配置（例: `http://example.com/irohaboard/`）では
 * `/irohaboard/api/v1/contents` のようにベースパスが含まれる。
 * `/api/` や `/mcp` のような前方一致判定をサブディレクトリ配置的でも
 * 正しく行うには、ベースパスを除いた値との比較が必要になる。
 *
 * ベースパスは CakePHP が `PHP_SELF` から検出して
 * `$request->getAttribute('base')` に設定した値
 * （`Cake\Http\ServerRequestFactory` → `Cake\Http\UriFactory`）を使用する。
 */
final class RequestPathHelper
{
    /**
     * ベースパスを除いたパス（ルーティング基準のパス）を返す。
     *
     * サブディレクトリ配置でない場合は `getUri()->getPath()` と同一になる。
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     * @return string 例: `/`, `/api/v1/contents`, `/users/login`
     */
    public static function baseRelative(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        $base = (string)$request->getAttribute('base', '');
        if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return $path === '' ? '/' : $path;
    }
}
