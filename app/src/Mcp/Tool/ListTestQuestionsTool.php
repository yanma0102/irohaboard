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
use Cake\Datasource\EntityInterface;
use Cake\ORM\TableRegistry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * list_test_questions ツール: テスト問題一覧（正解含む・スタッフのみ）
 */
class ListTestQuestionsTool
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
     * テスト問題の一覧を sort_no 順に返す（正解・解説を含む）。
     * 管理画面 Admin/ContentsQuestionsController::index() と同じ並び。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $contentId コンテンツID
     * @param int $page ページ番号（1 以上）
     * @param int $limit 取得件数（1〜200）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_test_questions',
        description: 'List test questions of a content (staff only, course membership required). '
            . 'Includes options and correct answers.',
    )]
    public function __invoke(RequestContext $context, int $content_id, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = max(1, min(200, $limit));

        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can list test questions.');
        }

        $content = $this->fetchContent($content_id);

        if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id, $role)) {
            throw new ToolCallException('Access denied to this course.');
        }

        return $this->questionList($content_id, $page, $limit);
    }

    /**
     * コンテンツを取得する（存在しなければ例外）
     *
     * @param int $contentId コンテンツID
     * @return \Cake\Datasource\EntityInterface
     * @throws \Mcp\Exception\ToolCallException 存在しない場合
     */
    protected function fetchContent(int $contentId): EntityInterface
    {
        $content = TableRegistry::getTableLocator()->get('Contents')
            ->find()
            ->where(['id' => $contentId, 'deleted IS NULL'])
            ->first();

        if ($content === null) {
            throw new ToolCallException('Content not found.');
        }

        return $content;
    }

    /**
     * 問題一覧を返す（共通処理）
     *
     * @param int $contentId コンテンツID
     * @param int $page ページ番号
     * @param int $limit 取得件数
     * @return array<string, mixed>
     */
    protected function questionList(int $contentId, int $page, int $limit): array
    {
        $questionsTable = TableRegistry::getTableLocator()->get('ContentsQuestions');

        $total = (int)$questionsTable->find()->where(['content_id' => $contentId])->count();

        $rows = $questionsTable->find()
            ->where(['content_id' => $contentId])
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
