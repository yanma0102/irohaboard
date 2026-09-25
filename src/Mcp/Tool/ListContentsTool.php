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
 * list_contents ツール: 指定コースのコンテンツ一覧を返す
 */
class ListContentsTool
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
     * 指定コースのコンテンツ一覧を返す（ページネーション付き）。
     * 一般ユーザは受講可能なコースの公開コンテンツのみ。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $courseId コースID
     * @param string|null $kind コース種別フィルタ（省略可）
     * @param int $page ページ番号（1 以上）
     * @param int $limit 取得件数（1〜200）
     * @return array<string, mixed>
     */
    public function __invoke(
        RequestContext $context,
        int $course_id,
        ?string $kind = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        $page = max(1, $page);
        $limit = max(1, min(200, $limit));

        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        $isStaff = $this->accessControl->isStaff($role);
        if (!$isStaff && !$this->accessControl->canAccessCourse($userId, $course_id)) {
            return ['error' => 'Access denied to this course.'];
        }

        $conditions = [
            'course_id' => $course_id,
            'deleted IS NULL',
        ];

        if ($kind !== null && $kind !== '') {
            $conditions['kind'] = $kind;
        }

        if (!$isStaff) {
            // 一般ユーザは公開コンテンツのみ（API と同一）
            $conditions['status'] = 1;
        }

        $contentsTable = TableRegistry::getTableLocator()->get('Contents');

        $total = (int)$contentsTable->find()->where($conditions)->count();

        $rows = $contentsTable->find()
            ->select(['id', 'course_id', 'title', 'kind', 'status', 'sort_no', 'created'])
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
