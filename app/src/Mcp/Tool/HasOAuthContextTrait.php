<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Mcp\Tool;

use Mcp\Server\RequestContext;

/**
 * MCP ツール共通: OAuthRequestMetaMiddleware が転記した
 * params._meta.oauth から認証ユーザ情報を取り出す trait。
 */
trait HasOAuthContextTrait
{
    /**
     * 認証済みユーザIDを取得する
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト
     * @return int ユーザID（未設定時は 0）
     */
    protected function getUserId(RequestContext $context): int
    {
        $meta = $context->getRequest()->getMeta();

        return (int)($meta['oauth']['oauth.user_id'] ?? 0);
    }

    /**
     * 認証済みユーザのロールを取得する
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト
     * @return string ロール（未設定時は空文字）
     */
    protected function getRole(RequestContext $context): string
    {
        $meta = $context->getRequest()->getMeta();

        return (string)($meta['oauth']['oauth.role'] ?? '');
    }
}
