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
use Mcp\Server\RequestContext;

/**
 * get_user_profile ツール: プロフィールを返す
 */
class GetUserProfileTool
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
     * プロフィールを返す。user_id 省略時は本人。
     * 他のユーザを参照する場合はスタッフのみ。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int|null $userId 参照先ユーザID（省略時は本人）
     * @return array<string, mixed>
     */
    public function __invoke(RequestContext $context, ?int $user_id = null): array
    {
        $currentUserId = $this->getUserId($context);
        $role = $this->getRole($context);

        $targetUserId = $user_id ?? $currentUserId;

        if ($targetUserId !== $currentUserId && !$this->accessControl->isStaff($role)) {
            return ['error' => 'Access denied. You can only view your own profile.'];
        }

        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $user = $usersTable->find()
            ->where(['id' => $targetUserId, 'deleted IS NULL'])
            ->first();

        if ($user === null) {
            return ['error' => 'User not found.'];
        }

        $data = $user->toArray();
        unset($data['password']);

        return ['data' => $data];
    }
}
