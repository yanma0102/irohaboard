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
use Mcp\Server\RequestContext;

/**
 * list_groups ツール: グループ一覧（スタッフは全件、一般ユーザは所属のみ）
 */
class ListGroupsTool
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
     * グループ一覧を返す。スタッフのみ title / status で絞り込める。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param string|null $title グループ名（部分一致）
     * @param int|null $status 状態
     * @param int $page ページ番号（1 以上）
     * @param int $limit 取得件数（1〜200）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_groups',
        description: 'List groups with pagination. Staff see all groups; '
            . 'non-staff users only see the groups they belong to.',
    )]
    public function __invoke(
        RequestContext $context,
        ?string $title = null,
        ?int $status = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        $page = max(1, $page);
        $limit = max(1, min(200, $limit));

        $userId = $this->getUserId($context);
        $actorRole = $this->getRole($context);

        $conditions = ['deleted IS NULL'];

        if (!$this->accessControl->isStaff($actorRole)) {
            $groupIds = $this->accessControl->currentUserGroupIds($userId);

            if ($groupIds === []) {
                return [
                    'data' => [],
                    'meta' => ['page' => 1, 'limit' => 0, 'total' => 0, 'count' => 0],
                ];
            }

            $conditions['id IN'] = $groupIds;
        }

        if ($title !== null && $title !== '') {
            $conditions['title LIKE'] = '%' . $title . '%';
        }
        if ($status !== null) {
            $conditions['status'] = $status;
        }

        $groupsTable = TableRegistry::getTableLocator()->get('Groups');

        $fields = ['id', 'title', 'comment', 'status', 'logo', 'copyright', 'module', 'created', 'modified'];

        $total = (int)$groupsTable->find()->where($conditions)->count();

        $rows = $groupsTable->find()
            ->select($fields)
            ->where($conditions)
            ->orderBy(['id' => 'ASC'])
            ->limit($limit)
            ->offset(($page - 1) * $limit)
            ->all()
            ->toArray();

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'count' => count($rows),
            ],
        ];
    }
}
