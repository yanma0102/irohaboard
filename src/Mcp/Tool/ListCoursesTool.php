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
use Mcp\Server\RequestContext;

/**
 * list_courses ツール: アクセス可能なコース一覧を返す
 */
class ListCoursesTool
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
     * アクセス可能なコース一覧を返す（ページネーション付き）。
     * スタッフは全件、一般ユーザは受講可能なコースのみ。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $page ページ番号（1 以上）
     * @param int $limit 取得件数（1〜200）
     * @return array<string, mixed>
     */
    public function __invoke(RequestContext $context, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = max(1, min(200, $limit));

        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        $conditions = ['deleted IS NULL'];

        if (!$this->accessControl->isStaff($role)) {
            $courseIds = $this->accessControl->accessibleCourseIds($userId);

            if (empty($courseIds)) {
                return [
                    'data' => [],
                    'meta' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'count' => 0],
                ];
            }

            $conditions['id IN'] = $courseIds;
        }

        $coursesTable = TableRegistry::getTableLocator()->get('Courses');

        $total = (int)$coursesTable->find()->where($conditions)->count();

        $rows = $coursesTable->find()
            ->select([
                'id', 'title', 'introduction',
                'opened', 'sort_no', 'comment',
                'user_id', 'created', 'modified',
            ])
            ->where($conditions)
            ->orderBy(['sort_no' => 'ASC', 'id' => 'ASC'])
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
