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
 * assign_group_user ツール: グループにユーザを割当（管理者・マネージャのみ）
 */
class AssignGroupUserTool
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
     * グループにユーザを割り当てる（既に所属している場合は何もしない）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $groupId グループID
     * @param int $userId ユーザID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'assign_group_user',
        description: 'Assign a user to a group (admin or manager only). Idempotent.',
    )]
    public function __invoke(RequestContext $context, int $group_id, int $user_id): array
    {
        $role = $this->getRole($context);

        if (!$this->accessControl->isManager($role)) {
            throw new ToolCallException('Only administrators or managers can assign users.');
        }

        $groupsTable = TableRegistry::getTableLocator()->get('Groups');

        if (!$groupsTable->exists(['id' => $group_id])) {
            throw new ToolCallException('Group not found.');
        }

        $usersTable = TableRegistry::getTableLocator()->get('Users');

        if (!$usersTable->exists(['id' => $user_id])) {
            throw new ToolCallException('User not found.');
        }

        $usersGroupsTable = TableRegistry::getTableLocator()->get('UsersGroups');
        $existing = $usersGroupsTable->find()
            ->where(['user_id' => $user_id, 'group_id' => $group_id])
            ->first();

        if ($existing !== null) {
            return [
                'data' => [
                    'group_id' => $group_id,
                    'user_id' => $user_id,
                    'assigned' => true,
                    'created' => false,
                ],
            ];
        }

        $entity = $usersGroupsTable->newEntity([
            'user_id' => $user_id,
            'group_id' => $group_id,
        ]);

        if (!$usersGroupsTable->save($entity)) {
            throw new ToolCallException('Failed to assign user.');
        }

        return [
            'data' => [
                'group_id' => $group_id,
                'user_id' => $user_id,
                'assigned' => true,
                'created' => true,
            ],
        ];
    }
}
