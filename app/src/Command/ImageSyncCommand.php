<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Command;

use App\Service\ContentImageService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * コンテンツ本文の画像参照（ib_content_images）を作り直すコマンド
 *
 * 既存コンテンツ（本機能の導入前に保存されたもの）へ遡及適用する用途。
 *
 * 使い方: bin/cake image_sync
 */
class ImageSyncCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'image_sync';
    }

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Rebuild content image references (ib_content_images) from content bodies.';
    }

    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);
        $parser->setDescription(static::getDescription());

        return $parser;
    }

    /**
     * 全コンテンツを走査して参照関係を作り直す
     *
     * @param \Cake\Console\Arguments $args 引数
     * @param \Cake\Console\ConsoleIo $io 入出力
     * @return int 終了コード
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $processed = ContentImageService::create()->rebuildAll();
        $io->success(sprintf('%d content(s) scanned.', $processed));

        return static::CODE_SUCCESS;
    }
}
