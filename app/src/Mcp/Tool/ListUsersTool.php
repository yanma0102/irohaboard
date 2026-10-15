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
 * list_users ツール: ユーザ一覧（スタッフは全件、一般ユーザは自分のみ）
 */
class ListUsersTool
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
     * ユーザ一覧を返す。スタッフのみ username / name / role で絞り込める。
     * password は選択しない。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param string|null $username ログインID（部分一致）
     * @param string|null $name 氏名（部分一致）
     * @param string|null $role ロール
     * @param int $page ページ番号（1 以上）
     * @param int $limit 取得件数（1〜200）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_users',
        description: 'List users with pagination. Staff can filter by username/name/role; '
            . 'non-staff users only see their own record. Password is never returned.',
    )]
    public function __invoke(
        RequestContext $context,
        ?string $username = null,
        ?string $name = null,
        ?string $role = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        $page = max(1, $page);
        $limit = max(1, min(200, $limit));

        $userId = $this->getUserId($context);
        $actorRole = $this->getRole($context);

        $conditions = ['deleted IS NULL'];

        if ($this->accessControl->isStaff($actorRole)) {
            if ($username !== null && $username !== '') {
                $conditions['username LIKE'] = '%' . $username . '%';
            }
            if ($name !== null && $name !== '') {
                $conditions['name LIKE'] = '%' . $name . '%';
            }
            if ($role !== null && $role !== '') {
                $conditions['role'] = $role;
            }
        } else {
            $conditions['id'] = $userId;
        }

        $usersTable = TableRegistry::getTableLocator()->get('Users');

        $fields = [
            'id', 'username', 'name', 'role', 'email', 'comment',
            'last_logined', 'started', 'ended', 'created', 'modified', 'is_active',
        ];

        $total = (int)$usersTable->find()->where($conditions)->count();

        $rows = $usersTable->find()
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
