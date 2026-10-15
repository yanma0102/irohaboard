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
 * create_course ツール: コース作成（管理者・マネージャのみ）
 */
class CreateCourseTool
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
     * コースを新規作成する。sort_no を省略した場合は最大値 + 1 を採番する。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param string $title タイトル
     * @param string|null $introduction 紹介文
     * @param string|null $opened 公開日
     * @param string|null $comment コメント
     * @param int|null $sortNo 並び順
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_course',
        description: 'Create a course (admin or manager only). '
            . 'sort_no is auto-assigned when omitted.',
    )]
    public function __invoke(
        RequestContext $context,
        string $title,
        ?string $introduction = null,
        ?string $opened = null,
        ?string $comment = null,
        ?int $sort_no = null,
    ): array {
        $role = $this->getRole($context);

        if (!$this->accessControl->isManager($role)) {
            throw new ToolCallException('Only administrators or managers can create courses.');
        }

        $coursesTable = TableRegistry::getTableLocator()->get('Courses');

        $fields = ['title' => $title];
        if ($introduction !== null) {
            $fields['introduction'] = $introduction;
        }
        if ($opened !== null) {
            $fields['opened'] = $opened;
        }
        if ($comment !== null) {
            $fields['comment'] = $comment;
        }
        if ($sort_no === null) {
            $maxSortNo = (int)$coursesTable->find()
                ->where(['deleted IS NULL'])
                ->select(['max_sort_no' => $coursesTable->find()->func()->max('sort_no')])
                ->first()
                ->max_sort_no;
            $sort_no = $maxSortNo + 1;
        }
        $fields['sort_no'] = $sort_no;
        $fields['user_id'] = $this->getUserId($context);

        $entity = $coursesTable->newEntity($fields);

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
