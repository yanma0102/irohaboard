<?php
declare(strict_types=1);

use Migrations\BaseSeed;

/**
 * DS-5 Infos & Settings Seed
 *
 * Creates 3 infos (全体公開 / グループ限定 / 非公開) and representative
 * ib_settings values.
 */
class Ds5InfosSettingsSeed extends BaseSeed
{
    public function isIdempotent(): bool
    {
        return true;
    }

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->execute('DELETE FROM `ib_infos_groups`');
        $this->execute('DELETE FROM `ib_infos`');
        $this->execute('DELETE FROM `ib_settings`');

        // Reset AUTO_INCREMENT for stable IDs
        $this->execute('ALTER TABLE `ib_infos` AUTO_INCREMENT = 1');
        $this->execute('ALTER TABLE `ib_settings` AUTO_INCREMENT = 1');

        // ── Infos ──
        // info 1: 全体公開 (no group restriction)
        // info 2: グループ限定 (linked to group 1 via ib_infos_groups)
        // info 3: 非公開 (opened = null, closed = null)

        $this->table('ib_infos')->insert([
            [
                'title'    => '全体公開のお知らせ',
                'body'     => '全ユーザーに表示されるお知らせです。',
                'opened'   => $now,
                'closed'   => null,
                'created'  => $now,
                'modified' => $now,
                'user_id'  => 1,
            ],
            [
                'title'    => 'グループ限定のお知らせ',
                'body'     => '特定グループのみに表示されるお知らせです。',
                'opened'   => $now,
                'closed'   => null,
                'created'  => $now,
                'modified' => $now,
                'user_id'  => 1,
            ],
            [
                'title'    => '非公開のお知らせ',
                'body'     => '公開されていないお知らせです。',
                'opened'   => null,
                'closed'   => null,
                'created'  => $now,
                'modified' => $now,
                'user_id'  => 1,
            ],
        ])->save();

        // ── Info ↔ Group (info 2 → public group id 1) ──
        $this->table('ib_infos_groups')->insert([
            [
                'info_id'   => 2,
                'group_id'  => 1,
                'created'   => $now,
                'modified'  => $now,
                'comment'   => null,
            ],
        ])->save();

        // ── Settings ──
        $this->table('ib_settings')->insert([
            [
                'setting_key'   => 'title',
                'setting_name'  => 'システム名',
                'setting_value' => 'irohaboard',
            ],
            [
                'setting_key'   => 'copyright',
                'setting_name'  => 'コピーライト',
                'setting_value' => 'irohaboard',
            ],
            [
                'setting_key'   => 'color',
                'setting_name'  => 'テーマカラー',
                'setting_value' => '#337ab7',
            ],
            [
                'setting_key'   => 'information',
                'setting_name'  => '全体のお知らせ',
                'setting_value' => 'システムメンテナンスのお知らせです。',
            ],
        ])->save();
    }
}
