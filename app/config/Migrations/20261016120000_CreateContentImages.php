<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * 画像とコンテンツ（コース）の参照関係を保持する ib_content_images を作成する。
 *
 * 画像配信（Contents::fileImage）は「その画像を参照している公開コンテンツの
 * コースを受講しているか」で許可を判定する。判定を高速化するため、コンテンツ本文に
 * 含まれる画像ファイル名を保存時に展開して保持する。
 *
 * 既存コンテンツは `bin/cake image_sync` で遡及展開できる。
 */
class CreateContentImages extends BaseMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $this->table('ib_content_images')
            ->addColumn('content_id', 'integer', [
                'default' => 0,
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('course_id', 'integer', [
                'default' => 0,
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('file_name', 'string', [
                'default' => '',
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(
                $this->index('file_name')
                    ->setName('idx_content_images_file_name')
            )
            ->addIndex(
                $this->index('content_id')
                    ->setName('idx_content_images_content_id')
            )
            ->addIndex(
                $this->index('course_id')
                    ->setName('idx_content_images_course_id')
            )
            ->create();
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        $this->table('ib_content_images')->drop()->save();
    }
}
