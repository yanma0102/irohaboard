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
 * assign_group_course ツール: グループにコースを割当（管理者・マネージャのみ）
 */
class AssignGroupCourseTool
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
     * グループにコースを割り当てる（既に割当済みの場合は何もしない）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $group_id グループID
     * @param int $course_id コースID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'assign_group_course',
        description: 'Assign a course to a group (admin or manager only). Idempotent.',
    )]
    public function __invoke(RequestContext $context, int $group_id, int $course_id): array
    {
        $role = $this->getRole($context);

        if (!$this->accessControl->isManager($role)) {
            throw new ToolCallException('Only administrators or managers can assign courses.');
        }

        $groupsTable = TableRegistry::getTableLocator()->get('Groups');

        if (!$groupsTable->exists(['id' => $group_id])) {
            throw new ToolCallException('Group not found.');
        }

        $coursesTable = TableRegistry::getTableLocator()->get('Courses');

        if (!$coursesTable->exists(['id' => $course_id])) {
            throw new ToolCallException('Course not found.');
        }

        $groupsCoursesTable = TableRegistry::getTableLocator()->get('GroupsCourses');
        $existing = $groupsCoursesTable->find()
            ->where(['group_id' => $group_id, 'course_id' => $course_id])
            ->first();

        if ($existing !== null) {
            return [
                'data' => [
                    'group_id' => $group_id,
                    'course_id' => $course_id,
                    'assigned' => true,
                    'created' => false,
                ],
            ];
        }

        $entity = $groupsCoursesTable->newEntity([
            'group_id' => $group_id,
            'course_id' => $course_id,
        ]);

        if (!$groupsCoursesTable->save($entity)) {
            throw new ToolCallException('Failed to assign course.');
        }

        return [
            'data' => [
                'group_id' => $group_id,
                'course_id' => $course_id,
                'assigned' => true,
                'created' => true,
            ],
        ];
    }
}
