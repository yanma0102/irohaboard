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
 * list_group_courses ツール: グループ割当コース一覧
 */
class ListGroupCoursesTool
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
     * グループに割り当てられたコース一覧を返す（Api\GroupsController::courses() と同一）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $groupId グループID
     * @param int $page ページ番号（1 以上）
     * @param int $limit 取得件数（1〜200）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_group_courses',
        description: 'List courses assigned to a group with pagination. '
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

        $sql = 'SELECT c.id, c.title, c.introduction, c.opened,'
            . ' c.sort_no, c.user_id, c.created, c.modified'
            . ' FROM ib_courses c'
            . ' INNER JOIN ib_groups_courses gc ON gc.course_id = c.id'
            . ' WHERE gc.group_id = :group_id'
            . ' AND c.deleted IS NULL'
            . ' ORDER BY c.sort_no asc';

        $rows = $connection->execute($sql, ['group_id' => $group_id])->fetchAll('assoc');

        $courses = [];
        foreach ($rows as $row) {
            $courses[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => (string)($row['title'] ?? ''),
                'introduction' => $row['introduction'] ?? null,
                'opened' => $row['opened'] ?? null,
                'sort_no' => (int)($row['sort_no'] ?? 0),
                'user_id' => (int)($row['user_id'] ?? 0),
            ];
        }

        $total = count($courses);
        $paged = array_slice($courses, ($page - 1) * $limit, $limit);

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
