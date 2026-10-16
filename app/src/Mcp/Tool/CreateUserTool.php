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
 * create_user ツール: ユーザ作成（管理者・マネージャのみ）
 */
class CreateUserTool
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
     * ユーザを新規作成する。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param string $username ログインID
     * @param string $password パスワード
     * @param string $name 氏名
     * @param string|null $role ロール（admin/manager/editor/teacher/user/guest）
     * @param string|null $email メールアドレス
     * @param string|null $comment コメント
     * @param string|null $started 受講開始日
     * @param string|null $ended 受講終了日
     * @param int|null $is_active 有効フラグ（1=有効, 0=無効）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_user',
        description: 'Create a user (admin or manager only). '
            . 'role defaults to the schema default; password is hashed by the model.',
    )]
    public function __invoke(
        RequestContext $context,
        string $username,
        string $password,
        string $name,
        #[Schema(enum: ['admin', 'manager', 'editor', 'teacher', 'user', 'guest'])]
        ?string $role = null,
        ?string $email = null,
        ?string $comment = null,
        ?string $started = null,
        ?string $ended = null,
        ?int $is_active = null,
    ): array {
        $actorRole = $this->getRole($context);

        if (!$this->accessControl->isManager($actorRole)) {
            throw new ToolCallException('Only administrators or managers can create users.');
        }

        $fields = [
            'username' => $username,
            'password' => $password,
            'name' => $name,
        ];
        if ($role !== null) {
            $fields['role'] = $role;
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

        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $entity = $usersTable->newEntity($fields);

        if (!$usersTable->save($entity)) {
            throw new ToolCallException(json_encode(
                ['error' => 'Validation failed.', 'details' => $entity->getErrors()],
                JSON_UNESCAPED_UNICODE,
            ) ?: 'Validation failed.');
        }

        return ['data' => $this->userPayload($entity)];
    }
}
