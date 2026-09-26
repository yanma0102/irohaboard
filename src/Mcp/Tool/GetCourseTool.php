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
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * get_course ツール: コース詳細を返す
 */
class GetCourseTool
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
     * コース詳細を返す。スタッフは全件、一般ユーザは受講可能なコースのみ。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $courseId コースID
     * @return array<string, mixed>
     */
    public function __invoke(RequestContext $context, int $course_id): array
    {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        $coursesTable = TableRegistry::getTableLocator()->get('Courses');
        $course = $coursesTable->find()
            ->where(['id' => $course_id, 'deleted IS NULL'])
            ->first();

        if ($course === null) {
            throw new ToolCallException('Course not found.');
        }

        if (!$this->accessControl->isStaff($role) && !$this->accessControl->canAccessCourse($userId, $course_id, $role)) {
            throw new ToolCallException('Access denied to this course.');
        }

        return ['data' => $course->toArray()];
    }
}
