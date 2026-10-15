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
 * unassign_user_course ツール: ユーザのコース割当解除（管理者・マネージャのみ）
 */
class UnassignUserCourseTool
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
     * ユーザのコース割当を解除する（未割当の場合は deleted=false）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $userId ユーザID
     * @param int $courseId コースID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'unassign_user_course',
        description: 'Remove a course assignment from a user (admin or manager only).',
    )]
    public function __invoke(RequestContext $context, int $user_id, int $course_id): array
    {
        $role = $this->getRole($context);

        if (!$this->accessControl->isManager($role)) {
            throw new ToolCallException('Only administrators or managers can unassign courses.');
        }

        $usersTable = TableRegistry::getTableLocator()->get('Users');

        if (!$usersTable->exists(['id' => $user_id])) {
            throw new ToolCallException('User not found.');
        }

        $deleted = false;

        $usersCoursesTable = TableRegistry::getTableLocator()->get('UsersCourses');
        $existing = $usersCoursesTable->find()
            ->where(['user_id' => $user_id, 'course_id' => $course_id])
            ->first();

        if ($existing !== null) {
            $deleted = (bool)$usersCoursesTable->delete($existing);
        }

        return [
            'data' => [
                'user_id' => $user_id,
                'course_id' => $course_id,
                'deleted' => $deleted,
            ],
        ];
    }
}
