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
 * get_group ツール: グループ詳細（スタッフは全件、一般ユーザは所属のみ）
 */
class GetGroupTool
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
     * グループ詳細を返す。一般ユーザは所属していないグループを参照できない。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $groupId グループID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_group',
        description: 'Get group details. Non-staff users can only view groups they belong to.',
    )]
    public function __invoke(RequestContext $context, int $group_id): array
    {
        $userId = $this->getUserId($context);
        $actorRole = $this->getRole($context);

        $groupsTable = TableRegistry::getTableLocator()->get('Groups');
        $group = $groupsTable->find()
            ->where(['id' => $group_id, 'deleted IS NULL'])
            ->first();

        if ($group === null) {
            throw new ToolCallException('Group not found.');
        }

        if (
            !$this->accessControl->isStaff($actorRole)
            && !in_array($group_id, $this->accessControl->currentUserGroupIds($userId), true)
        ) {
            throw new ToolCallException('Group not found.');
        }

        return ['data' => $group->toArray()];
    }
}
