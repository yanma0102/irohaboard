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
 * assign_user_course ツール: ユーザにコースを割当（管理者・マネージャのみ）
 */
class AssignUserCourseTool
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
     * ユーザにコースを割り当てる（既に割当済みの場合は何もしない）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $userId ユーザID
     * @param int $courseId コースID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'assign_user_course',
        description: 'Assign a course to a user (admin or manager only). Idempotent.',
    )]
    public function __invoke(RequestContext $context, int $user_id, int $course_id): array
    {
        $role = $this->getRole($context);

        if (!$this->accessControl->isManager($role)) {
            throw new ToolCallException('Only administrators or managers can assign courses.');
        }

        $usersTable = TableRegistry::getTableLocator()->get('Users');

        if (!$usersTable->exists(['id' => $user_id])) {
            throw new ToolCallException('User not found.');
        }

        $coursesTable = TableRegistry::getTableLocator()->get('Courses');

        if (!$coursesTable->exists(['id' => $course_id])) {
            throw new ToolCallException('Course not found.');
        }

        $usersCoursesTable = TableRegistry::getTableLocator()->get('UsersCourses');
        $existing = $usersCoursesTable->find()
            ->where(['user_id' => $user_id, 'course_id' => $course_id])
            ->first();

        if ($existing !== null) {
            return [
                'data' => [
                    'user_id' => $user_id,
                    'course_id' => $course_id,
                    'assigned' => true,
                    'created' => false,
                ],
            ];
        }

        $entity = $usersCoursesTable->newEntity([
            'user_id' => $user_id,
            'course_id' => $course_id,
        ]);

        if (!$usersCoursesTable->save($entity)) {
            throw new ToolCallException('Failed to assign course.');
        }

        return [
            'data' => [
                'user_id' => $user_id,
                'course_id' => $course_id,
                'assigned' => true,
                'created' => true,
            ],
        ];
    }
}
