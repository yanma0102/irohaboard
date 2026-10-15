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
 * get_user ツール: ユーザ詳細（get_user_profile の明示用別名）
 */
class GetUserTool
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
     * ユーザ詳細を返す。スタッフは誰でも、一般ユーザは自分のみ参照できる。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $userId ユーザID
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_user',
        description: 'Get a user profile by user_id. Staff can query any user; '
            . 'non-staff users can only query themselves. Password is never returned.',
    )]
    public function __invoke(RequestContext $context, int $user_id): array
    {
        $actorId = $this->getUserId($context);
        $actorRole = $this->getRole($context);

        if (!$this->accessControl->isStaff($actorRole) && $user_id !== $actorId) {
            throw new ToolCallException('You can only view your own profile.');
        }

        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $user = $usersTable->find()
            ->where(['id' => $user_id, 'deleted IS NULL'])
            ->first();

        if ($user === null) {
            throw new ToolCallException('User not found.');
        }

        return ['data' => $this->userPayload($user)];
    }
}
