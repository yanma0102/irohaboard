<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Mcp\Tool;

use Cake\Datasource\EntityInterface;

/**
 * MCP ユーザ系ツール共通: レスポンス用のユーザ情報を組み立てる。
 *
 * password（ハッシュ）を応答に含めないため、Api\UsersController と同じく
 * toArray() から password を取り除く。
 */
trait HasUserPayloadTrait
{
    /**
     * ユーザの公開フィールドを返す（password は含めない）
     *
     * @param \Cake\Datasource\EntityInterface $user ユーザエンティティ
     * @return array<string, mixed>
     */
    protected function userPayload(EntityInterface $user): array
    {
        $data = $user->toArray();
        unset($data['password']);

        if (isset($data['id'])) {
            $data['id'] = (int)$data['id'];
        }

        return $data;
    }
}
