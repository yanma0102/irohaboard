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
 * list_group_users ツール: グループ所属ユーザ一覧
 */
class ListGroupUsersTool
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
     * グループに所属するユーザ一覧を返す（Api\GroupsController::users() と同一）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $group_id グループID
     * @param int $page ページ番号（1 以上）
     * @param int $limit 取得件数（1〜200）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_group_users',
        description: 'List users of a group with pagination. '
            . 'Non-staff users can only list groups they belong to.',
    )]
    public function __invoke(RequestContext $context, int $group_id, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = max(1, min(200, $limit));

        $userId = $this->getUserId($context);
        $actorRole = $this->getRole($context);

        if (
            !$this->accessControl->isStaff($actorRole)
            && !in_array($group_id, $this->accessControl->currentUserGroupIds($userId), true)
        ) {
            throw new ToolCallException('Group not found.');
        }

        $groupsTable = TableRegistry::getTableLocator()->get('Groups');

        if (!$groupsTable->exists(['id' => $group_id])) {
            throw new ToolCallException('Group not found.');
        }

        $connection = $groupsTable->getConnection();

        $sql = 'SELECT u.id, u.username, u.name, u.role, u.email'
            . ' FROM ib_users u'
            . ' INNER JOIN ib_users_groups ug ON ug.user_id = u.id'
            . ' WHERE ug.group_id = :group_id'
            . ' AND u.deleted IS NULL'
            . ' ORDER BY u.id asc';

        $rows = $connection->execute($sql, ['group_id' => $group_id])->fetchAll('assoc');

        $users = [];
        foreach ($rows as $row) {
            $users[] = [
                'id' => (int)($row['id'] ?? 0),
                'username' => (string)($row['username'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'role' => (string)($row['role'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
            ];
        }

        $total = count($users);
        $paged = array_slice($users, ($page - 1) * $limit, $limit);

        return [
            'data' => $paged,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'count' => count($paged),
            ],
        ];
    }
}
