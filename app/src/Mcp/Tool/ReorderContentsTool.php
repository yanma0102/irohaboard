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
 * reorder_contents ツール: コース内コンテンツの並び替え（スタッフのみ）
 */
class ReorderContentsTool
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
     * 指定した順序で content_ids を並び替える（sort_no を 1 から振り直す）。
     * 管理画面 Admin/ContentsController::order() と同じ ContentsTable::setOrder() を使う。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $courseId コースID
     * @param array<int, mixed> $contentIds 並び順に並べたコンテンツID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'reorder_contents',
        description: 'Reorder contents in a course (staff only, course membership required). '
            . 'content_ids must list every content of the course in the desired order.',
    )]
    public function __invoke(RequestContext $context, int $course_id, array $content_ids = []): array
    {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can reorder contents.');
        }

        if (!$this->accessControl->canAccessCourse($userId, $course_id, $role)) {
            throw new ToolCallException('Access denied to this course.');
        }

        if ($content_ids === []) {
            throw new ToolCallException('content_ids must not be empty.');
        }

        // 文字列 ID を許容しつつ int へ正規化する
        $ids = array_map(static fn($value): int => (int)$value, array_values($content_ids));

        $contentsTable = TableRegistry::getTableLocator()->get('Contents');

        $found = $contentsTable->find()
            ->select(['id'])
            ->where(['id IN' => $ids, 'course_id' => $course_id, 'deleted IS NULL'])
            ->all()
            ->extract('id')
            ->toList();
        $found = array_map(static fn($value): int => (int)$value, $found);

        foreach ($ids as $id) {
            if (!in_array($id, $found, true)) {
                throw new ToolCallException('Content ' . $id . ' does not belong to this course.');
            }
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new ToolCallException('content_ids contains duplicates.');
        }

        $contentsTable->setOrder($ids);

        return [
            'data' => [
                'course_id' => $course_id,
                'content_ids' => $ids,
            ],
        ];
    }
}
