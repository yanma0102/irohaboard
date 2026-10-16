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
 * get_test_question ツール: テスト問題詳細（スタッフのみ）
 */
class GetTestQuestionTool
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
     * テスト問題の詳細を返す（正解・解説を含む）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $question_id テスト問題ID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_test_question',
        description: 'Get a test question by question_id (staff only, course membership required). '
            . 'Includes options and correct answers.',
    )]
    public function __invoke(RequestContext $context, int $question_id): array
    {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can view test questions.');
        }

        $question = TableRegistry::getTableLocator()->get('ContentsQuestions')
            ->find()
            ->where(['id' => $question_id])
            ->first();

        if ($question === null) {
            throw new ToolCallException('Test question not found.');
        }

        $content = TableRegistry::getTableLocator()->get('Contents')
            ->find()
            ->where(['id' => (int)$question->content_id, 'deleted IS NULL'])
            ->first();

        if ($content === null) {
            throw new ToolCallException('Content not found.');
        }

        if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id, $role)) {
            throw new ToolCallException('Access denied to this course.');
        }

        return ['data' => $question];
    }
}
