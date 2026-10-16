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
 * delete_test_question ツール: テスト問題削除（スタッフのみ）
 */
class DeleteTestQuestionTool
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
     * テスト問題を削除する。
     * Admin/ContentsQuestionsController::delete() と同じ物理削除。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $question_id テスト問題ID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'delete_test_question',
        description: 'Delete a test question (staff only, course membership required).',
    )]
    public function __invoke(RequestContext $context, int $question_id): array
    {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can delete test questions.');
        }

        $questionsTable = TableRegistry::getTableLocator()->get('ContentsQuestions');
        $question = $questionsTable->find()
            ->where(['id' => $question_id])
            ->first();

        if ($question === null) {
            throw new ToolCallException('Test question not found.');
        }

        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $content = $contentsTable->find()
            ->where(['id' => (int)$question->content_id, 'deleted IS NULL'])
            ->first();

        if ($content === null) {
            throw new ToolCallException('Content not found.');
        }

        if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id, $role)) {
            throw new ToolCallException('Access denied to this course.');
        }

        if (!$questionsTable->delete($question)) {
            throw new ToolCallException('Failed to delete test question.');
        }

        return [
            'data' => [
                'id' => $question_id,
                'content_id' => (int)$content->id,
                'deleted' => true,
            ],
        ];
    }
}
