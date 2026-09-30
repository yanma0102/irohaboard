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
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * create_test_question ツール: テスト問題作成（スタッフのみ）
 */
class CreateTestQuestionTool
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
     * テスト問題を新規作成する（スタッフのみ・コース参加必須）。
     * question_type=single のとき options と correct を指定。
     * question_type=text のとき options/correct は不要。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $contentId コンテンツ（テスト）ID
     * @param string $title 問題タイトル
     * @param string $body 本文（必須）
     * @param string $questionType 問題種別（single / text）
     * @param array<int|string, string> $options 選択肢配列（single のとき必須）
     * @param array<int|string, int> $correct 正解番号配列（1始まり, single のとき必須）
     * @param int $score 配点（0〜100, -1 可）
     * @param string|null $explain 解説
     * @param string|null $comment コメント
     * @param string|null $image 画像ファイル名
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_test_question',
        description: 'Create a test question in a test content (staff only, course membership required). '
            . 'For question_type=single, provide options and correct (1-based indexes); for text, omit them.',
    )]
    public function __invoke(
        RequestContext $context,
        int $content_id,
        string $title,
        string $body,
        #[Schema(enum: ['single', 'text'])]
        string $question_type = 'single',
        array $options = [],
        array $correct = [],
        int $score = 0,
        ?string $explain = null,
        ?string $comment = null,
        ?string $image = null,
    ): array {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can create test questions.');
        }

        // コンテンツの存在確認と kind 検証
        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $content = $contentsTable->find()
            ->where(['id' => $content_id, 'deleted IS NULL'])
            ->first();

        if ($content === null) {
            throw new ToolCallException('Content not found.');
        }

        if ((string)$content->kind !== 'test') {
            throw new ToolCallException(
                'This content is not a test (kind=' . (string)$content->kind . '). '
                . 'Questions can only be created for test contents.',
            );
        }

        if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id, $role)) {
            throw new ToolCallException('Access denied to this course.');
        }

        // 入力正規化: options → pipe 区切り文字列
        $optionsStr = null;
        if ($options !== []) {
            foreach ($options as $opt) {
                if (str_contains((string)$opt, '|')) {
                    throw new ToolCallException(
                        'Options must not contain the pipe character (|) as it is used as a delimiter.',
                    );
                }
            }
            $optionsStr = implode('|', array_map('strval', $options));
        }

        // 入力正規化: correct → comma 区切り文字列
        $correctStr = '';
        if ($correct !== []) {
            $correctStr = implode(',', array_map('intval', $correct));
        }

        // single のとき correct 必須
        if ($question_type === 'single' && $correctStr === '') {
            throw new ToolCallException(
                'correct is required for question_type=single. '
                . 'Provide an array of 1-based option indexes (e.g. [1, 3]).',
            );
        }

        // sort_no 自動採番
        $questionsTable = TableRegistry::getTableLocator()->get('ContentsQuestions');
        $sortNo = $questionsTable->getNextSortNo($content_id);

        $entity = $questionsTable->newEntity([
            'content_id' => $content_id,
            'title' => $title,
            'body' => $body,
            'question_type' => $question_type,
            'options' => $optionsStr,
            'correct' => $correctStr,
            'score' => $score,
            'explain' => $explain,
            'comment' => $comment,
            'image' => $image,
            'sort_no' => $sortNo,
        ]);

        if (!$questionsTable->save($entity)) {
            throw new ToolCallException(
                json_encode(['error' => 'Validation failed.', 'details' => $entity->getErrors()], JSON_UNESCAPED_UNICODE),
            );
        }

        return [
            'data' => [
                'id' => $entity->id,
                'content_id' => $entity->content_id,
                'question_type' => $entity->question_type,
                'title' => $entity->title,
                'body' => $entity->body,
                'options' => $optionsStr !== null ? $options : null,
                'correct' => $correct,
                'score' => $entity->score,
                'explain' => $entity->explain,
                'comment' => $entity->comment,
                'image' => $entity->image,
                'sort_no' => $entity->sort_no,
            ],
        ];
    }
}
