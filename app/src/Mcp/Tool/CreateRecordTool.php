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
 * create_record ツール: 学習履歴の記録（受講者本人のみ）
 */
class CreateRecordTool
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
     * 非テストコンテンツの学習履歴を記録する。
     * Web の RecordsController::add() と同じく is_passed は -1（未採点）で保存する。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $content_id コンテンツID
     * @param int $study_sec 学習時間（秒）
     * @param int $understanding 理解度（0〜5）
     * @param int $is_complete 完了フラグ（0/1）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_record',
        description: 'Record learning progress for the authenticated user (non-test content only). '
            . 'Access to the course is required; unpublished content can only be recorded by admins.',
    )]
    public function __invoke(
        RequestContext $context,
        int $content_id,
        int $study_sec = 0,
        int $understanding = 0,
        int $is_complete = 0,
    ): array {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if ($userId <= 0) {
            throw new ToolCallException('Authentication is required.');
        }

        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $content = $contentsTable->find()
            ->where(['id' => $content_id, 'deleted IS NULL'])
            ->first();

        if ($content === null) {
            throw new ToolCallException('Content not found.');
        }

        if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id, $role)) {
            throw new ToolCallException('Access denied to this content.');
        }

        if ($role !== 'admin' && (int)$content->status !== 1) {
            throw new ToolCallException('This content is not published.');
        }

        // テストの採点はテスト受験フロー（is_passed/score）で行うため、ここでは扱わない
        if ((string)$content->kind === 'test') {
            throw new ToolCallException('create_record can only be used for non-test content.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('Records');
        $record = $recordsTable->newEmptyEntity();
        $record = $recordsTable->patchEntity($record, [
            'user_id' => $userId,
            'course_id' => (int)$content->course_id,
            'content_id' => $content_id,
            'study_sec' => $study_sec,
            'understanding' => $understanding,
            'is_passed' => -1,
            'is_complete' => $is_complete,
        ]);

        if (!$recordsTable->save($record)) {
            throw new ToolCallException(json_encode(
                ['error' => 'Validation failed.', 'details' => $record->getErrors()],
                JSON_UNESCAPED_UNICODE,
            ) ?: 'Validation failed.');
        }

        return [
            'data' => [
                'id' => $record->id,
                'course_id' => $record->course_id,
                'content_id' => $record->content_id,
                'study_sec' => $record->study_sec,
                'understanding' => $record->understanding,
                'is_complete' => $record->is_complete,
            ],
        ];
    }
}
