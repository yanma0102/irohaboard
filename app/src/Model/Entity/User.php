<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * User Entity
 */
class User extends Entity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    protected array $_hidden = [
        'password',
    ];

    protected function _getRoleName(): string
    {
        $roles = [
            'admin' => '管理者',
            'manager' => '担当者',
            'user' => '受講者',
        ];

        return $roles[$this->role] ?? $this->role;
    }
}
