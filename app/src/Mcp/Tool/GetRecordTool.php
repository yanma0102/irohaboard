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
 * get_record ツール: 学習記録の詳細
 */
class GetRecordTool
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
     * 学習記録の詳細を返す。一般ユーザは自分の記録のみ参照できる。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $record_id 学習記録ID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_record',
        description: 'Get a learning record by record_id. '
            . 'Non-staff users can only view their own records.',
    )]
    public function __invoke(RequestContext $context, int $record_id): array
    {
        $actorId = $this->getUserId($context);
        $actorRole = $this->getRole($context);

        $recordsTable = TableRegistry::getTableLocator()->get('Records');
        $record = $recordsTable->find()
            ->select([
                'id', 'course_id', 'user_id', 'content_id',
                'full_score', 'pass_score', 'score', 'is_passed', 'is_complete',
                'progress', 'understanding', 'study_sec', 'created',
            ])
            ->where(['id' => $record_id])
            ->first();

        if ($record === null) {
            throw new ToolCallException('Record not found.');
        }

        if (!$this->accessControl->isStaff($actorRole) && (int)$record->user_id !== $actorId) {
            throw new ToolCallException('You can only view your own records.');
        }

        return ['data' => $record];
    }
}
