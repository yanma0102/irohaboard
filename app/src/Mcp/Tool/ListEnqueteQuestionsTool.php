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
 * list_enquete_questions ツール: アンケート質問一覧（スタッフのみ）
 */
class ListEnqueteQuestionsTool
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
     * アンケート質問の一覧を sort_no 順に返す。
     * 管理画面 Admin/EnquetesQuestionsController::index() と同じ並び
     * （保存先はテスト問題と同じ ib_contents_questions）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $content_id コンテンツID
     * @param int $page ページ番号（1 以上）
     * @param int $limit 取得件数（1〜200）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_enquete_questions',
        description: 'List enquete questions of a content (staff only, course membership required).',
    )]
    public function __invoke(RequestContext $context, int $content_id, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = max(1, min(200, $limit));

        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can list enquete questions.');
        }

        $content = TableRegistry::getTableLocator()->get('Contents')
            ->find()
            ->where(['id' => $content_id, 'deleted IS NULL'])
            ->first();

        if ($content === null) {
            throw new ToolCallException('Content not found.');
        }

        if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id, $role)) {
            throw new ToolCallException('Access denied to this course.');
        }

        $questionsTable = TableRegistry::getTableLocator()->get('ContentsQuestions');

        $total = (int)$questionsTable->find()->where(['content_id' => $content_id])->count();

        $rows = $questionsTable->find()
            ->where(['content_id' => $content_id])
            ->orderBy(['sort_no' => 'ASC'])
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
