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
 * delete_group ツール: グループ削除（管理者・マネージャのみ）
 */
class DeleteGroupTool
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
     * グループを削除する（中間テーブルも削除）。
     * Api\GroupsController::delete() と同じ GroupsTable::deleteGroup() を使う。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $groupId グループID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'delete_group',
        description: 'Delete a group and its user/course assignments (admin or manager only).',
    )]
    public function __invoke(RequestContext $context, int $group_id): array
    {
        $role = $this->getRole($context);

        if (!$this->accessControl->isManager($role)) {
            throw new ToolCallException('Only administrators or managers can delete groups.');
        }

        $groupsTable = TableRegistry::getTableLocator()->get('Groups');

        if (!$groupsTable->exists(['id' => $group_id])) {
            throw new ToolCallException('Group not found.');
        }

        $groupsTable->deleteGroup($group_id);

        return [
            'data' => [
                'id' => $group_id,
                'deleted' => true,
            ],
        ];
    }
}
