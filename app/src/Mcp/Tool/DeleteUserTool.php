<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Mcp\Tool;

use App\Service\AccessControlService;
use Cake\ORM\TableRegistry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * delete_user ツール: ユーザ削除（管理者・マネージャのみ）
 */
class DeleteUserTool
{
    use HasOAuthContextTrait;

    /**
     * @param \App\Service\AccessControlService $accessControl 権限チェックサービス
     */
    public function __construct(
        private AccessControlService $accessControl,
    ) {
    }

    /**
     * ユーザを削除する（自分自身は削除不可）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $user_id ユーザID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'delete_user',
        description: 'Delete a user (admin or manager only). You cannot delete your own account.',
    )]
    public function __invoke(RequestContext $context, int $user_id): array
    {
        $actorId = $this->getUserId($context);
        $actorRole = $this->getRole($context);

        if (!$this->accessControl->isManager($actorRole)) {
            throw new ToolCallException('Only administrators or managers can delete users.');
        }

        if ($user_id === $actorId) {
            throw new ToolCallException('Cannot delete your own account.');
        }

        $usersTable = TableRegistry::getTableLocator()->get('Users');

        if (!$usersTable->exists(['id' => $user_id])) {
            throw new ToolCallException('User not found.');
        }

        if (!$usersTable->delete($usersTable->get($user_id))) {
            throw new ToolCallException('Failed to delete user.');
        }

        return [
            'data' => [
                'id' => $user_id,
                'deleted' => true,
            ],
        ];
    }
}
