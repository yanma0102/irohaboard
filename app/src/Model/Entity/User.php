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

    /**
     * アカウント状態の表示値を取得する。
     *
     * is_active が NULL の場合（マイグレーションでカラム追加後に値が
     * 投入されていない環境など）は、有効として扱う。これにより管理画面の
     * ラジオボタンで「有効」「無効」のどちらも選択されない状態になるのを防ぎ、
     * 利用者が明示的に選択できる状態にする。
     *
     * @return string '1'（有効）または '0'（無効）
     */
    protected function _getIsActiveValue(): string
    {
        return ($this->is_active === null || $this->is_active) ? '1' : '0';
    }
}
