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
 * delete_content ツール: コンテンツ削除（スタッフのみ）
 */
class DeleteContentTool
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
     * コンテンツを削除する（紐づくテスト問題も削除）。
     * Api\ContentsController::delete() と同じ物理削除。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $contentId コンテンツID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'delete_content',
        description: 'Delete a content (staff only, course membership required). '
            . 'Related test questions are also deleted.',
    )]
    public function __invoke(RequestContext $context, int $content_id): array
    {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can delete content.');
        }

        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $content = $contentsTable->find()
            ->where(['id' => $content_id, 'deleted IS NULL'])
            ->first();

        if ($content === null) {
            throw new ToolCallException('Content not found.');
        }

        if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id, $role)) {
            throw new ToolCallException('Access denied to this course.');
        }

        if (!$contentsTable->delete($content)) {
            throw new ToolCallException('Failed to delete content.');
        }

        TableRegistry::getTableLocator()->get('ContentsQuestions')->deleteAll(['content_id' => $content_id]);

        return [
            'data' => [
                'id' => $content_id,
                'deleted' => true,
            ],
        ];
    }
}
