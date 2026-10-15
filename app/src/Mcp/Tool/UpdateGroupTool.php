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
 * update_group ツール: グループ更新（管理者・マネージャのみ）
 */
class UpdateGroupTool
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
     * グループを部分更新する（指定フィールドのみ変更）。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $groupId グループID
     * @param string|null $title グループ名
     * @param string|null $comment コメント
     * @param int|null $status 状態
     * @param string|null $logo ロゴ
     * @param string|null $copyright コピーライト
     * @param string|null $module モジュール
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'update_group',
        description: 'Update fields of an existing group (admin or manager only). '
            . 'Only the provided fields are changed.',
    )]
    public function __invoke(
        RequestContext $context,
        int $group_id,
        ?string $title = null,
        ?string $comment = null,
        ?int $status = null,
        ?string $logo = null,
        ?string $copyright = null,
        ?string $module = null,
    ): array {
        $role = $this->getRole($context);

        if (!$this->accessControl->isManager($role)) {
            throw new ToolCallException('Only administrators or managers can update groups.');
        }

        $groupsTable = TableRegistry::getTableLocator()->get('Groups');
        $group = $groupsTable->find()
            ->where(['id' => $group_id, 'deleted IS NULL'])
            ->first();

        if ($group === null) {
            throw new ToolCallException('Group not found.');
        }

        $fields = [];
        $optional = [
            'title' => $title,
            'comment' => $comment,
            'status' => $status,
            'logo' => $logo,
            'copyright' => $copyright,
            'module' => $module,
        ];
        foreach ($optional as $key => $value) {
            if ($value !== null) {
                $fields[$key] = $value;
            }
        }

        if ($fields === []) {
            throw new ToolCallException('No fields to update.');
        }

        $entity = $groupsTable->patchEntity($group, $fields);

        if (!$groupsTable->save($entity)) {
            throw new ToolCallException(json_encode(
                ['error' => 'Validation failed.', 'details' => $entity->getErrors()],
                JSON_UNESCAPED_UNICODE,
            ));
        }

        return [
            'data' => [
                'id' => (int)$entity->id,
                'title' => $entity->title,
                'comment' => $entity->comment,
                'status' => $entity->status,
            ],
        ];
    }
}
