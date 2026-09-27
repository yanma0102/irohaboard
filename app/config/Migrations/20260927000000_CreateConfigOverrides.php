<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateConfigOverrides extends BaseMigration
{
    /**
     * Up Method.
     *
     * More information on this method is available here:
     * https://book.cakephp.org/migrations/5/guides/writing-migrations/migration-methods.html#the-up-method
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('ib_config_overrides', ['id' => false, 'primary_key' => ['id']]);
        $table
            ->addColumn('id', 'integer', [
                'identity' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('config_key', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => 'ib_config.phpのキー名',
            ])
            ->addColumn('config_value', 'text', [
                'null' => false,
                'comment' => '正規化済み値（型はスキーマ定義に従う）',
            ])
            ->addColumn('created', 'datetime', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('modified', 'datetime', [
                'default' => null,
                'null' => true,
            ])
            ->addIndex(
                $this->index('config_key')
                    ->setName('uk_config_key')
                    ->setType('unique')
            )
            ->create();
    }

    /**
     * Down Method.
     *
     * More information on this method is available here:
     * https://book.cakephp.org/migrations/5/guides/writing-migrations/migration-methods.html#the-down-method
     *
     * @return void
     */
    public function down(): void
    {
        $this->table('ib_config_overrides')->drop()->save();
    }
}
