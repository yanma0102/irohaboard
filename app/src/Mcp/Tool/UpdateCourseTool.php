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
 * update_course ツール: コース更新（管理者・マネージャのみ）
 */
class UpdateCourseTool
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
     * コースを部分更新する（指定フィールドのみ変更）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $courseId コースID
     * @param string|null $title タイトル
     * @param string|null $introduction 紹介文
     * @param string|null $opened 公開日
     * @param string|null $comment コメント
     * @param int|null $sortNo 並び順
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'update_course',
        description: 'Update fields of an existing course (admin or manager only). '
            . 'Only the provided fields are changed.',
    )]
    public function __invoke(
        RequestContext $context,
        int $course_id,
        ?string $title = null,
        ?string $introduction = null,
        ?string $opened = null,
        ?string $comment = null,
        ?int $sort_no = null,
    ): array {
        $role = $this->getRole($context);

        if (!$this->accessControl->isManager($role)) {
            throw new ToolCallException('Only administrators or managers can update courses.');
        }

        $coursesTable = TableRegistry::getTableLocator()->get('Courses');
        $course = $coursesTable->find()
            ->where(['id' => $course_id, 'deleted IS NULL'])
            ->first();

        if ($course === null) {
            throw new ToolCallException('Course not found.');
        }

        $fields = [];
        if ($title !== null) {
            $fields['title'] = $title;
        }
        if ($introduction !== null) {
            $fields['introduction'] = $introduction;
        }
        if ($opened !== null) {
            $fields['opened'] = $opened;
        }
        if ($comment !== null) {
            $fields['comment'] = $comment;
        }
        if ($sort_no !== null) {
            $fields['sort_no'] = $sort_no;
        }

        if ($fields === []) {
            throw new ToolCallException('No fields to update.');
        }

        $entity = $coursesTable->patchEntity($course, $fields);

        if (!$coursesTable->save($entity)) {
            throw new ToolCallException(json_encode(
                ['error' => 'Validation failed.', 'details' => $entity->getErrors()],
                JSON_UNESCAPED_UNICODE,
            ));
        }

        return [
            'data' => [
                'id' => (int)$entity->id,
                'title' => $entity->title,
                'introduction' => $entity->introduction,
                'opened' => $entity->opened,
                'comment' => $entity->comment,
                'sort_no' => (int)$entity->sort_no,
                'user_id' => (int)$entity->user_id,
            ],
        ];
    }
}
