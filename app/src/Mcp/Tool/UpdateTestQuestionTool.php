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
 * update_test_question ツール: テスト問題更新（スタッフのみ・部分更新）
 */
class UpdateTestQuestionTool
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
     * 既存のテスト問題を部分更新する（スタッフのみ・コース参加必須）。
     * 指定されたフィールドのみ更新される。
     * options/correct は配列で渡す。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $questionId 問題ID
     * @param string|null $title タイトル（省略時は変更なし）
     * @param string|null $body 本文（省略時は変更なし）
     * @param string|null $questionType 問題種別（single / text）
     * @param array|null $options 選択肢配列（省略時は変更なし）
     * @param array|null $correct 正解番号配列（省略時は変更なし）
     * @param int|null $score 配点
     * @param string|null $explain 解説
     * @param string|null $comment コメント
     * @param string|null $image 画像ファイル名
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'update_test_question',
        description: 'Update fields of an existing test question (staff only, course membership required). '
            . 'Only provided fields are changed. options/correct are arrays.',
    )]
    public function __invoke(
        RequestContext $context,
        int $question_id,
        ?string $title = null,
        ?string $body = null,
        #[Schema(enum: ['single', 'text'])]
        ?string $question_type = null,
        ?array $options = null,
        ?array $correct = null,
        ?int $score = null,
        ?string $explain = null,
        ?string $comment = null,
        ?string $image = null,
    ): array {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can update test questions.');
        }

        // 問題の存在確認
        $questionsTable = TableRegistry::getTableLocator()->get('ContentsQuestions');
        $question = $questionsTable->find()
            ->where(['id' => $question_id])
            ->first();

        if ($question === null) {
            throw new ToolCallException('Question not found.');
        }

        // コンテンツ経由でコースアクセス権を検証
        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $content = $contentsTable->find()
            ->where(['id' => $question->content_id, 'deleted IS NULL'])
            ->first();

        if ($content === null) {
            throw new ToolCallException('Parent content not found or has been deleted.');
        }

        if ((string)$content->kind !== 'test') {
            throw new ToolCallException(
                'This question belongs to a non-test content (kind=' . (string)$content->kind . ').',
            );
        }

        if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id, $role)) {
            throw new ToolCallException('Access denied to this course.');
        }

        $patchData = [];

        if ($title !== null) {
            $patchData['title'] = $title;
        }
        if ($body !== null) {
            $patchData['body'] = $body;
        }
        if ($question_type !== null) {
            $patchData['question_type'] = $question_type;
        }
        if ($score !== null) {
            $patchData['score'] = $score;
        }
        if ($explain !== null) {
            $patchData['explain'] = $explain;
        }
        if ($comment !== null) {
            $patchData['comment'] = $comment;
        }
        if ($image !== null) {
            $patchData['image'] = $image;
        }

        // options の正規化（配列が渡された場合のみ）
        if ($options !== null) {
            foreach ($options as $opt) {
                if (str_contains((string)$opt, '|')) {
                    throw new ToolCallException(
                        'Options must not contain the pipe character (|) as it is used as a delimiter.',
                    );
                }
            }
            $patchData['options'] = $options !== [] ? implode('|', array_map('strval', $options)) : null;
        }

        // correct の正規化（配列が渡された場合のみ）
        if ($correct !== null) {
            $patchData['correct'] = $correct !== [] ? implode(',', array_map('intval', $correct)) : '';
        }

        if ($patchData === []) {
            throw new ToolCallException('No fields to update.');
        }

        // single に変更する場合、correct が必要か確認
        $effectiveType = $question_type ?? (string)$question->question_type;
        $effectiveCorrect = array_key_exists('correct', $patchData) ? $patchData['correct'] : (string)$question->correct;
        if ($effectiveType === 'single' && $effectiveCorrect === '') {
            throw new ToolCallException(
                'correct is required when question_type is single. '
                . 'Provide an array of 1-based option indexes (e.g. [1, 3]).',
            );
        }

        $entity = $questionsTable->patchEntity($question, $patchData);

        if (!$questionsTable->save($entity)) {
            throw new ToolCallException(
                json_encode(['error' => 'Validation failed.', 'details' => $entity->getErrors()], JSON_UNESCAPED_UNICODE),
            );
        }

        // 戻り値の options/correct を配列に変換
        $resultOptions = null;
        if ($entity->options !== null && $entity->options !== '') {
            $resultOptions = explode('|', (string)$entity->options);
        }

        $resultCorrect = [];
        if ($entity->correct !== null && $entity->correct !== '') {
            $resultCorrect = array_map('intval', explode(',', (string)$entity->correct));
        }

        return [
            'data' => [
                'id' => $entity->id,
                'content_id' => $entity->content_id,
                'question_type' => $entity->question_type,
                'title' => $entity->title,
                'body' => $entity->body,
                'options' => $resultOptions,
                'correct' => $resultCorrect,
                'score' => $entity->score,
                'explain' => $entity->explain,
                'comment' => $entity->comment,
                'image' => $entity->image,
                'sort_no' => $entity->sort_no,
            ],
        ];
    }
}
