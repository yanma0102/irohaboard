<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddIsActiveToUsers extends BaseMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('ib_users');
        $table->addColumn('is_active', 'boolean', [
            'default' => true,
            'null' => false,
            'comment' => 'ユーザ有効フラグ (true=有効, false=無効)',
            'after' => 'deleted',
        ])->update();
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        $table = $this->table('ib_users');
        $table->removeColumn('is_active')->update();
    }
}
