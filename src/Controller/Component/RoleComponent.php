<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Controller\Component;

use Cake\Controller\Component;
use Cake\Http\Exception\ForbiddenException;

/**
 * ロール判定コンポーネント
 *
 * 04-authentication.md §5.2 に基づく手動ロール判定の集約。
 *
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 */
class RoleComponent extends Component
{
    /**
     * staff ロール（管理画面へのアクセスが許可されるロール）
     *
     * @var array<string>
     */
    protected array $staffRoles = ['admin', 'manager', 'editor', 'teacher'];

    /**
     * staff ロールかを確認
     *
     * @return bool
     */
    public function isStaff(): bool
    {
        return in_array($this->getRole(), $this->staffRoles, true);
    }

    /**
     * admin ロールかを確認
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->getRole() === 'admin';
    }

    /**
     * 現在のログインユーザのロールを取得
     *
     * @return string|null
     */
    public function getRole(): ?string
    {
        $controller = $this->getController();
        $identity = $controller->Authentication->getIdentity();

        if (!$identity instanceof \Authentication\IdentityInterface) {
            return null;
        }

        $user = $identity->getOriginalData();

        return $user['role'] ?? null;
    }

    /**
     * staff ロールでない場合に ForbiddenException を送出
     *
     * @return void
     * @throws \Cake\Http\Exception\ForbiddenException
     */
    public function requireStaff(): void
    {
        if (!$this->isStaff()) {
            throw new ForbiddenException(__('管理画面へのアクセス権限がありません'));
        }
    }
}