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
 * delete_course ツール: コース削除（管理者・マネージャのみ）
 */
class DeleteCourseTool
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
     * コースを削除する（コンテンツ・テスト問題も削除）。
     * Api\CoursesController::delete() と同じ CoursesTable::deleteCourse() を使う。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $course_id コースID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'delete_course',
        description: 'Delete a course and its contents (admin or manager only).',
    )]
    public function __invoke(RequestContext $context, int $course_id): array
    {
        $role = $this->getRole($context);

        if (!$this->accessControl->isManager($role)) {
            throw new ToolCallException('Only administrators or managers can delete courses.');
        }

        $coursesTable = TableRegistry::getTableLocator()->get('Courses');

        if (!$coursesTable->exists(['id' => $course_id])) {
            throw new ToolCallException('Course not found.');
        }

        $coursesTable->deleteCourse($course_id);

        return [
            'data' => [
                'id' => $course_id,
                'deleted' => true,
            ],
        ];
    }
}
