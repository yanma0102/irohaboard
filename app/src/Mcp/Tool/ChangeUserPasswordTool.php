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
 * change_user_password ツール: パスワード変更（管理者・マネージャのみ）
 */
class ChangeUserPasswordTool
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
     * 対象ユーザのパスワードを変更し、当該ユーザのトークンを全失効させる。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $user_id ユーザID
     * @param string $password 新しいパスワード
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'change_user_password',
        description: 'Change a user password and revoke all of that user\'s tokens '
            . '(admin or manager only). Administrators can only be modified by administrators.',
    )]
    public function __invoke(RequestContext $context, int $user_id, string $password): array
    {
        $actorRole = $this->getRole($context);

        if (!$this->accessControl->isManager($actorRole)) {
            throw new ToolCallException('Only administrators or managers can change passwords.');
        }

        if ($password === '') {
            throw new ToolCallException('password is required.');
        }

        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $target = $usersTable->find()
            ->where(['id' => $user_id, 'deleted IS NULL'])
            ->first();

        if ($target === null) {
            throw new ToolCallException('User not found.');
        }

        if ($target->role === 'admin' && $actorRole !== 'admin') {
            throw new ToolCallException('Only administrators can modify an administrator account.');
        }

        $entity = $usersTable->patchEntity($target, ['password' => $password]);

        if (!$usersTable->save($entity)) {
            throw new ToolCallException(json_encode(
                ['error' => 'Validation failed.', 'details' => $entity->getErrors()],
                JSON_UNESCAPED_UNICODE,
            ) ?: 'Validation failed.');
        }

        $userTokensTable = TableRegistry::getTableLocator()->get('UserTokens');
        $userTokensTable->revokeAllForUser($user_id);
        $userTokensTable->revokeAllApiForUser($user_id);

        return [
            'data' => [
                'id' => $user_id,
                'password_changed' => true,
            ],
        ];
    }
}
