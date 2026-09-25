<?php
declare(strict_types=1);

use Migrations\BaseSeed;

/**
 * DS-1 Users Seed
 *
 * Creates 2 groups (public / private), and users for every role:
 * admin, manager1, editor1, teacher1, user1, user2.
 * Assigns user1 → public group, user2 → private group.
 *
 * Expected IDs (after Ds0 resets AUTO_INCREMENT):
 *   groups: 1=公開, 2=非公開
 *   users:  1=admin, 2=manager1, 3=editor1, 4=teacher1, 5=user1, 6=user2
 */
class Ds1UsersSeed extends BaseSeed
{
    public function isIdempotent(): bool
    {
        return true;
    }

    public function run(): void
    {
        $now  = date('Y-m-d H:i:s');
        $hash = password_hash('password', PASSWORD_BCRYPT);
        $adminHash = password_hash('adminpass', PASSWORD_BCRYPT);

        // ── Clean ──
        $this->execute('DELETE FROM `ib_users_groups`');
        $this->execute('DELETE FROM `ib_users`');
        $this->execute('DELETE FROM `ib_groups`');

        // Reset AUTO_INCREMENT for stable IDs
        $this->execute('ALTER TABLE `ib_users` AUTO_INCREMENT = 1');
        $this->execute('ALTER TABLE `ib_groups` AUTO_INCREMENT = 1');

        // ── Groups ──
        // id 1 = public (status 1), id 2 = private (status 0)
        $this->table('ib_groups')->insert([
            [
                'title'     => '公開グループ',
                'comment'   => null,
                'created'   => $now,
                'modified'  => $now,
                'deleted'   => null,
                'status'    => 1,
                'logo'      => null,
                'copyright' => null,
                'module'    => '00000000',
            ],
            [
                'title'     => '非公開グループ',
                'comment'   => null,
                'created'   => $now,
                'modified'  => $now,
                'deleted'   => null,
                'status'    => 0,
                'logo'      => null,
                'copyright' => null,
                'module'    => '00000000',
            ],
        ])->save();

        // ── Users ──
        $users = [
            // id 1 = admin (re-inserted since we deleted all)
            [
                'username'     => 'admin',
                'password'     => $adminHash,
                'name'         => '管理者',
                'role'         => 'admin',
                'email'        => 'admin@example.com',
                'comment'      => null,
                'last_logined' => null,
                'started'      => null,
                'ended'        => null,
                'created'      => $now,
                'modified'     => $now,
                'deleted'      => null,
            ],
            // id 2
            [
                'username'     => 'manager1',
                'password'     => $hash,
                'name'         => '管理者1',
                'role'         => 'manager',
                'email'        => 'manager1@example.com',
                'comment'      => null,
                'last_logined' => null,
                'started'      => null,
                'ended'        => null,
                'created'      => $now,
                'modified'     => $now,
                'deleted'      => null,
            ],
            // id 3
            [
                'username'     => 'editor1',
                'password'     => $hash,
                'name'         => '編集者1',
                'role'         => 'editor',
                'email'        => 'editor1@example.com',
                'comment'      => null,
                'last_logined' => null,
                'started'      => null,
                'ended'        => null,
                'created'      => $now,
                'modified'     => $now,
                'deleted'      => null,
            ],
            // id 4
            [
                'username'     => 'teacher1',
                'password'     => $hash,
                'name'         => '講師1',
                'role'         => 'teacher',
                'email'        => 'teacher1@example.com',
                'comment'      => null,
                'last_logined' => null,
                'started'      => null,
                'ended'        => null,
                'created'      => $now,
                'modified'     => $now,
                'deleted'      => null,
            ],
            // id 5
            [
                'username'     => 'user1',
                'password'     => $hash,
                'name'         => 'ユーザー1',
                'role'         => 'user',
                'email'        => 'user1@example.com',
                'comment'      => null,
                'last_logined' => null,
                'started'      => null,
                'ended'        => null,
                'created'      => $now,
                'modified'     => $now,
                'deleted'      => null,
            ],
            // id 6
            [
                'username'     => 'user2',
                'password'     => $hash,
                'name'         => 'ユーザー2',
                'role'         => 'user',
                'email'        => 'user2@example.com',
                'comment'      => null,
                'last_logined' => null,
                'started'      => null,
                'ended'        => null,
                'created'      => $now,
                'modified'     => $now,
                'deleted'      => null,
            ],
        ];

        $this->table('ib_users')->insert($users)->save();

        // ── User ↔ Group assignments ──
        // user1 (id 5) → public group (id 1)
        // user2 (id 6) → private group (id 2)
        $this->table('ib_users_groups')->insert([
            [
                'user_id'  => 5,
                'group_id' => 1,
                'created'  => $now,
                'modified' => $now,
                'comment'  => null,
            ],
            [
                'user_id'  => 6,
                'group_id' => 2,
                'created'  => $now,
                'modified' => $now,
                'comment'  => null,
            ],
        ])->save();
    }
}
