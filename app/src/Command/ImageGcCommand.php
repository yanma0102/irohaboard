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
use Cake\Core\Configure;

/**
 * どのコンテンツからも参照されていないアップロード画像を検出・削除するコマンド
 *
 * 既定は dry-run（検出のみ）。削除するには --delete を付ける。
 * 保存前の画像（アップロード直後）を消さないよう、既定で 1 日以内のファイルは対象外。
 *
 * 使い方:
 *   bin/cake image_gc            # 検出のみ
 *   bin/cake image_gc --delete   # 削除
 */
class ImageGcCommand extends Command
{
    /**
     * 既定の保護期間（秒）。これより新しいファイルは削除対象にしない。
     */
    private const DEFAULT_MIN_AGE = 86400;

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'image_gc';
    }

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'List (or delete with --delete) uploaded images that no content references.';
    }

    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);
        $parser->setDescription(static::getDescription());
        $parser->addOption('delete', [
            'help' => 'Actually delete orphan files. Without this option the command only lists them.',
            'boolean' => true,
            'default' => false,
        ]);
        $parser->addOption('min-age', [
            'help' => 'Keep files younger than this many seconds (default: 86400).',
            'default' => (string)self::DEFAULT_MIN_AGE,
        ]);
        $parser->addOption('upload-dir', [
            'help' => 'Directory to scan (default: webroot/uploads).',
            'default' => '',
        ]);

        return $parser;
    }

    /**
     * 孤児画像を検出し、--delete 指定時に削除する
     *
     * @param \Cake\Console\Arguments $args 引数
     * @param \Cake\Console\ConsoleIo $io 入出力
     * @return int 終了コード
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $uploadDir = (string)$args->getOption('upload-dir');
        $uploadDir = $uploadDir !== '' ? $uploadDir : WWW_ROOT . 'uploads';

        if (!is_dir($uploadDir)) {
            $io->error(sprintf('Upload directory not found: %s', $uploadDir));

            return static::CODE_ERROR;
        }

        $minAge = max(0, (int)$args->getOption('min-age'));
        $delete = (bool)$args->getOption('delete');

        $referenced = array_flip(ContentImageService::create()->scanReferencedFileNames());

        $allowed = array_map(
            static fn($value): string => strtolower((string)$value),
            (array)Configure::read('upload_image_extensions'),
        );

        $now = time();
        $orphans = [];
        $skipped = 0;

        foreach (glob($uploadDir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $name = basename($path);
            $extension = '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, $allowed, true) || isset($referenced[$name])) {
                continue;
            }

            if ($minAge > 0 && ($now - (int)filemtime($path)) < $minAge) {
                $skipped++;

                continue;
            }

            $orphans[] = $path;
        }

        sort($orphans);

        $totalBytes = 0;
        foreach ($orphans as $path) {
            $size = (int)filesize($path);
            $totalBytes += $size;
            $io->out(sprintf('%s %s (%d bytes)', $delete ? 'DELETE' : 'ORPHAN', basename($path), $size));
        }

        $io->out(sprintf(
            'referenced: %d, orphan: %d, skipped (min-age): %d, orphan bytes: %d',
            count($referenced),
            count($orphans),
            $skipped,
            $totalBytes,
        ));

        if (!$delete) {
            if ($orphans !== []) {
                $io->warning('Dry-run. Re-run with --delete to remove these files.');
            }

            return static::CODE_SUCCESS;
        }

        $deleted = 0;
        foreach ($orphans as $path) {
            if (@unlink($path)) {
                $deleted++;
            } else {
                $io->error(sprintf('Failed to delete: %s', $path));
            }
        }

        $io->success(sprintf('Deleted %d file(s).', $deleted));

        return static::CODE_SUCCESS;
    }
}
