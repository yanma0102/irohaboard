<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * ジャンクションテーブル（ユーザー⇔グループ / ユーザー⇔コース）に一意インデックスを追加する。
 *
 * 既存の idx_* は非一意のため、API の find-or-create（存在チェック→INSERT）が
 * 同時リクエストで競合すると同一組の行が重複して挿入されうる。
 * 一意制約を課すことで、競合側の一意キー違反を検出可能にする。
 */
class AddUniqueIndexesToJunctionTables extends BaseMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        // 一意制約の追加前に、既存の重複行（id が大きい側）を削除する
        $this->execute(
            'DELETE d1 FROM ib_users_courses d1
             INNER JOIN ib_users_courses d2
             ON d1.user_id = d2.user_id AND d1.course_id = d2.course_id AND d1.id > d2.id'
        );
        $this->execute(
            'DELETE d1 FROM ib_users_groups d1
             INNER JOIN ib_users_groups d2
             ON d1.user_id = d2.user_id AND d1.group_id = d2.group_id AND d1.id > d2.id'
        );
        $this->execute(
            'DELETE d1 FROM ib_settings d1
             INNER JOIN ib_settings d2
             ON d1.setting_key = d2.setting_key AND d1.id > d2.id'
        );

        $this->table('ib_users_courses')
            ->addIndex(
                $this->index(['user_id', 'course_id'])
                    ->setName('uk_user_course')
                    ->setType('unique')
            )
            ->update();

        $this->table('ib_users_groups')
            ->addIndex(
                $this->index(['user_id', 'group_id'])
                    ->setName('uk_user_group')
                    ->setType('unique')
            )
            ->update();

        // 旧 idx_* は unique インデックスに包含されるため削除
        $this->table('ib_users_courses')->removeIndexByName('idx_user_course_id')->update();
        $this->table('ib_users_groups')->removeIndexByName('idx_user_group_id')->update();

        // setSettings() の upsert とキー重複防止のため setting_key を一意化
        $this->table('ib_settings')
            ->addIndex(
                $this->index(['setting_key'])
                    ->setName('uk_setting_key')
                    ->setType('unique')
            )
            ->update();
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        $this->table('ib_users_courses')
            ->addIndex(
                $this->index(['user_id', 'course_id'])
                    ->setName('idx_user_course_id')
            )
            ->update();

        $this->table('ib_users_groups')
            ->addIndex(
                $this->index(['user_id', 'group_id'])
                    ->setName('idx_user_group_id')
            )
            ->update();

        $this->table('ib_users_courses')->removeIndexByName('uk_user_course')->update();
        $this->table('ib_users_groups')->removeIndexByName('uk_user_group')->update();
        $this->table('ib_settings')->removeIndexByName('uk_setting_key')->update();
    }
}
