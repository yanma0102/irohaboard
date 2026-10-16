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
use Throwable;

/**
 * copy_content ツール: コンテンツ＋テスト問題の複製（スタッフのみ）
 */
class CopyContentTool
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
     * コンテンツを複製する。target_course_id を省略した場合は同じコースへ複製する。
     * 複製後の status は 0（下書き）、タイトルには「の複製」を付ける
     * （Admin/ContentsController::copy() と同一）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $content_id 複製元のコンテンツID
     * @param int|null $target_course_id 複製先のコースID（省略時は複製元と同じ）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'copy_content',
        description: 'Copy a content and its test questions (staff only, course membership required). '
            . 'The copy is created as a draft (status=0) with "の複製" appended to the title.',
    )]
    public function __invoke(RequestContext $context, int $content_id, ?int $target_course_id = null): array
    {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can copy content.');
        }

        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $content = $contentsTable->find()
            ->where(['id' => $content_id, 'deleted IS NULL'])
            ->first();

        if ($content === null) {
            throw new ToolCallException('Content not found.');
        }

        if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id, $role)) {
            throw new ToolCallException('Access denied to the source course.');
        }

        $courseId = $target_course_id ?? (int)$content->course_id;

        if (!$this->accessControl->canAccessCourse($userId, $courseId, $role)) {
            throw new ToolCallException('Access denied to the target course.');
        }

        $contentsQuestionsTable = TableRegistry::getTableLocator()->get('ContentsQuestions');

        $newContent = $contentsTable->newEmptyEntity();
        $newContent = $contentsTable->patchEntity($newContent, [
            'title' => $content->title . 'の複製',
            'kind' => $content->kind,
            'url' => $content->url,
            'body' => $content->body,
            'file_name' => $content->file_name,
            'status' => 0,
            'course_id' => $courseId,
            'user_id' => $content->user_id,
            'sort_no' => $contentsTable->getNextSortNo($courseId),
        ]);

        if (!$contentsTable->save($newContent)) {
            throw new ToolCallException(json_encode(
                ['error' => 'Validation failed.', 'details' => $newContent->getErrors()],
                JSON_UNESCAPED_UNICODE,
            ) ?: 'Validation failed.');
        }

        $newContentId = (int)$newContent->id;

        // テスト問題の複製（sort_no を 1 から振り直す）
        $questions = $contentsQuestionsTable->find()
            ->where(['content_id' => $content_id])
            ->orderBy(['sort_no' => 'ASC'])
            ->all();

        $sortNo = 1;
        try {
            foreach ($questions as $question) {
                $newQuestion = $contentsQuestionsTable->patchEntity(
                    $contentsQuestionsTable->newEmptyEntity(),
                    [
                        'content_id' => $newContentId,
                        'sort_no' => $sortNo,
                        'question_type' => $question->question_type,
                        'title' => $question->title,
                        'body' => $question->body,
                        'image' => $question->image,
                        'options' => $question->options,
                        'correct' => $question->correct,
                        'score' => $question->score,
                        'explain' => $question->explain,
                    ],
                );

                if (!$contentsQuestionsTable->save($newQuestion)) {
                    throw new ToolCallException('Failed to copy test questions.');
                }

                $sortNo++;
            }
        } catch (Throwable $e) {
            // 問題の複製に失敗した場合は中途半端な複製を残さない
            $contentsQuestionsTable->deleteAll(['content_id' => $newContentId]);
            $contentsTable->delete($newContent);

            if ($e instanceof ToolCallException) {
                throw $e;
            }

            throw new ToolCallException('Failed to copy content: ' . $e->getMessage());
        }

        return [
            'data' => [
                'id' => $newContentId,
                'source_content_id' => $content_id,
                'course_id' => $courseId,
                'title' => $newContent->title,
                'kind' => $newContent->kind,
                'status' => $newContent->status,
                'questions' => $sortNo - 1,
            ],
        ];
    }
}
