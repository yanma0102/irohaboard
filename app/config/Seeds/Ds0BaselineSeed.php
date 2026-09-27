<?php
declare(strict_types=1);

use Migrations\BaseSeed;

/**
 * DS-0 Baseline Seed
 *
 * Wipes all dependent tables in FK-safe order and inserts
 * a single admin user so every test set starts from a known state.
 *
 * IDs are stable because we reset AUTO_INCREMENT after each truncate.
 */
class Ds0BaselineSeed extends BaseSeed
{
    public function isIdempotent(): bool
    {
        return true;
    }

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        // ── Delete all rows in FK-safe order ──
        $tables = [
            'ib_records_questions',
            'ib_records',
            'ib_contents_questions',
            'ib_contents',
            'ib_infos_groups',
            'ib_infos',
            'ib_groups_courses',
            'ib_users_groups',
            'ib_users_courses',
            'ib_groups',
            'ib_courses',
            'ib_user_tokens',
            'ib_logs',
            'ib_settings',
            'ib_users',
        ];

        foreach ($tables as $table) {
            $this->execute("DELETE FROM `{$table}`");
        }

        // Reset AUTO_INCREMENT so IDs start from 1
        foreach ($tables as $table) {
            $this->execute("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
        }

        // ── Seed admin user (will be id = 1) ──
        $adminPassword = password_hash('adminpass', PASSWORD_BCRYPT);

        $this->table('ib_users')->insert([
            [
                'username'     => 'admin',
                'password'     => $adminPassword,
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
        ])->save();
    }
}
