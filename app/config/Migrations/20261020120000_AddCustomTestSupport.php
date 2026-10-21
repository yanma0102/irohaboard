<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * カスタム受験（練習）用の設定と問題カテゴリを追加する。
 *
 * - ib_contents.allow_custom_test: 練習の許可（既定 0）
 * - ib_contents.practice_timelimit_max: 練習で設定できる制限時間の上限（NULL = 上限なし）
 * - ib_contents_questions.category: 出題範囲に使うカテゴリ（NULL / 空文字 = 未分類）
 */
class AddCustomTestSupport extends BaseMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $this->table('ib_contents')
            ->addColumn('allow_custom_test', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'question_count',
            ])
            ->addColumn('practice_timelimit_max', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'allow_custom_test',
            ])
            ->update();

        $this->table('ib_contents_questions')
            ->addColumn('category', 'string', [
                'limit' => 100,
                'null' => true,
                'default' => null,
                'after' => 'title',
            ])
            ->addIndex(['content_id', 'category'], ['name' => 'idx_content_category'])
            ->update();
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        $this->table('ib_contents_questions')
            ->removeIndexByName('idx_content_category')
            ->removeColumn('category')
            ->update();

        $this->table('ib_contents')
            ->removeColumn('practice_timelimit_max')
            ->removeColumn('allow_custom_test')
            ->update();
    }
}
