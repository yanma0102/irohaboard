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
 * unassign_group_user ツール: グループのユーザ割当解除（管理者・マネージャのみ）
 */
class UnassignGroupUserTool
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
     * グループのユーザ割当を解除する（未所属の場合は deleted=false）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $groupId グループID
     * @param int $userId ユーザID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'unassign_group_user',
        description: 'Remove a user from a group (admin or manager only).',
    )]
    public function __invoke(RequestContext $context, int $group_id, int $user_id): array
    {
        $role = $this->getRole($context);

        if (!$this->accessControl->isManager($role)) {
            throw new ToolCallException('Only administrators or managers can unassign users.');
        }

        $groupsTable = TableRegistry::getTableLocator()->get('Groups');

        if (!$groupsTable->exists(['id' => $group_id])) {
            throw new ToolCallException('Group not found.');
        }

        $deleted = false;

        $usersGroupsTable = TableRegistry::getTableLocator()->get('UsersGroups');
        $existing = $usersGroupsTable->find()
            ->where(['user_id' => $user_id, 'group_id' => $group_id])
            ->first();

        if ($existing !== null) {
            $deleted = (bool)$usersGroupsTable->delete($existing);
        }

        return [
            'data' => [
                'group_id' => $group_id,
                'user_id' => $user_id,
                'deleted' => $deleted,
            ],
        ];
    }
}
