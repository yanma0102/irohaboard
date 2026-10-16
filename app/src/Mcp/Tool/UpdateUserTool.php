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
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * update_user ツール: ユーザ更新（管理者・マネージャのみ）
 */
class UpdateUserTool
{
    use HasOAuthContextTrait;
    use HasUserPayloadTrait;

    /**
     * @param \App\Service\AccessControlService $accessControl 権限チェックサービス
     */
    public function __construct(
        private AccessControlService $accessControl,
    ) {
    }

    /**
     * ユーザを部分更新する。Api\UsersController::edit() と同じ保護ルールを適用する。
     * - 管理者以外は admin アカウントを変更不可
     * - 自分のロールは変更不可 / admin 付与は admin のみ
     * - 自分自身の無効化・最後の有効な管理者の無効化は不可（無効化時はトークン失効）
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $user_id ユーザID
     * @param string|null $role ロール
     * @param string|null $name 氏名
     * @param string|null $email メールアドレス
     * @param string|null $comment コメント
     * @param string|null $started 受講開始日
     * @param string|null $ended 受講終了日
     * @param int|null $is_active 有効フラグ（1=有効, 0=無効）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'update_user',
        description: 'Update fields of an existing user (admin or manager only). '
            . 'Administrator protection rules are enforced (no self role change, '
            . 'no self deactivation, no deactivation of the last active administrator).',
    )]
    public function __invoke(
        RequestContext $context,
        int $user_id,
        #[Schema(enum: ['admin', 'manager', 'editor', 'teacher', 'user', 'guest'])]
        ?string $role = null,
        ?string $name = null,
        ?string $email = null,
        ?string $comment = null,
        ?string $started = null,
        ?string $ended = null,
        ?int $is_active = null,
    ): array {
        $actorId = $this->getUserId($context);
        $actorRole = $this->getRole($context);

        if (!$this->accessControl->isManager($actorRole)) {
            throw new ToolCallException('Only administrators or managers can update users.');
        }

        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $target = $usersTable->find()
            ->where(['id' => $user_id, 'deleted IS NULL'])
            ->first();

        if ($target === null) {
            throw new ToolCallException('User not found.');
        }

        $isAdmin = $actorRole === 'admin';

        // 管理者以外は管理者アカウントを変更不可
        if ($target->role === 'admin' && !$isAdmin) {
            throw new ToolCallException('Only administrators can modify an administrator account.');
        }

        $fields = [];
        if ($role !== null) {
            $fields['role'] = $role;
        }
        if ($name !== null) {
            $fields['name'] = $name;
        }
        if ($email !== null) {
            $fields['email'] = $email;
        }
        if ($comment !== null) {
            $fields['comment'] = $comment;
        }
        if ($started !== null) {
            $fields['started'] = $started;
        }
        if ($ended !== null) {
            $fields['ended'] = $ended;
        }
        if ($is_active !== null) {
            $fields['is_active'] = $is_active;
        }

        if ($fields === []) {
            throw new ToolCallException('No fields to update.');
        }

        if (array_key_exists('role', $fields)) {
            if ($user_id === $actorId) {
                throw new ToolCallException('Cannot change your own role.');
            }
            if ($fields['role'] === 'admin' && !$isAdmin) {
                throw new ToolCallException('Only administrators can grant the admin role.');
            }
        }

        if (array_key_exists('is_active', $fields)) {
            if ($user_id === $actorId && !$fields['is_active']) {
                throw new ToolCallException('Cannot deactivate your own account.');
            }

            if (!$fields['is_active'] && $target->role === 'admin') {
                $adminCount = $usersTable->find()
                    ->where(['role' => 'admin', 'deleted IS NULL', 'is_active' => true])
                    ->count();

                if ($adminCount <= 1) {
                    throw new ToolCallException('Cannot deactivate the last active administrator.');
                }
            }
        }

        $wasActive = (bool)($target->is_active ?? true);

        $entity = $usersTable->patchEntity($target, $fields);

        if (!$usersTable->save($entity)) {
            throw new ToolCallException(json_encode(
                ['error' => 'Validation failed.', 'details' => $entity->getErrors()],
                JSON_UNESCAPED_UNICODE,
            ) ?: 'Validation failed.');
        }

        // 無効化時はトークンを全失効する
        $isActiveNow = (bool)($entity->is_active ?? true);
        if ($wasActive && !$isActiveNow) {
            $userTokensTable = TableRegistry::getTableLocator()->get('UserTokens');
            $userTokensTable->revokeAllForUser($user_id);
            $userTokensTable->revokeAllApiForUser($user_id);
        }

        return ['data' => $this->userPayload($entity)];
    }
}
