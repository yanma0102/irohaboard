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
 * list_records ツール: 学習履歴を返す
 */
class ListRecordsTool
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
     * 学習履歴を返す（ページネーション付き）。
     * スタッフは user_id / course_id でフィルタ可能。
     * 一般ユーザは自分の履歴のみ（API と同一）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int|null $courseId コースID フィルタ（スタッフのみ有効）
     * @param int|null $userId ユーザID フィルタ（スタッフのみ有効）
     * @param int $page ページ番号（1 以上）
     * @param int $limit 取得件数（1〜200）
     * @return array<string, mixed>
     */
    public function __invoke(
        RequestContext $context,
        ?int $course_id = null,
        ?int $user_id = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        $page = max(1, $page);
        $limit = max(1, min(200, $limit));

        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        $conditions = [];

        if ($this->accessControl->isStaff($role)) {
            // スタッフは指定条件で参照可能
            if ($user_id !== null) {
                $conditions['user_id'] = $user_id;
            }
            if ($course_id !== null) {
                $conditions['course_id'] = $course_id;
            }
        } else {
            // 一般ユーザは自分のレコードのみ
            $conditions['user_id'] = $userId;
        }

        $recordsTable = TableRegistry::getTableLocator()->get('Records');

        $total = (int)$recordsTable->find()->where($conditions)->count();

        $rows = $recordsTable->find()
            ->select([
                'id', 'course_id', 'user_id',
                'content_id', 'full_score', 'pass_score',
                'score', 'is_passed', 'is_complete',
                'progress', 'understanding', 'study_sec',
                'created',
            ])
            ->where($conditions)
            ->orderBy(['id' => 'DESC'])
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
