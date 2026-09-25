<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Mcp;

use App\Model\Table\UserTokensTable;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

/**
 * 既存 Bearer トークンで MCP 認証するバリデータ。
 *
 * authenticateApiToken() ではなく lookupApiToken() を使い、
 * 毎リクエストの照合でトークンを失効しない（G-3）。
 */
class IrohaTokenValidator implements AuthorizationTokenValidatorInterface
{
    /**
     * @param \App\Model\Table\UserTokensTable $userTokensTable トークンテーブル
     */
    public function __construct(
        private UserTokensTable $userTokensTable,
    ) {
    }

    /**
     * Bearer トークンの検証。IrohaAuthMiddleware から呼ぶ。
     *
     * @param string $token Bearer トークン文字列（selector:validator）
     * @return \Mcp\Server\Transport\Http\OAuth\AuthorizationResult
     */
    public function validate(string $token): AuthorizationResult
    {
        $result = $this->userTokensTable->lookupApiToken($token);

        if ($result === null) {
            return AuthorizationResult::unauthorized(
                'invalid_token',
                'Token is invalid or expired',
            );
        }

        $user = $result['user'];

        // 属性キーは必ず 'oauth.' プレフィクスを付ける。
        // OAuthRequestMetaMiddleware が JSON-RPC の params._meta.oauth へ転記する。
        return AuthorizationResult::allow([
            'oauth.user_id' => (int)$user['id'],
            'oauth.role' => (string)$user['role'],
            'oauth.name' => (string)$user['name'],
            'oauth.token_id' => (int)$result['token_id'],
        ]);
    }
}
