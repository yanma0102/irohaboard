<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Mcp\Tool;

use App\Service\ImageStorageService;
use App\Service\ImageUploadException;
use Cake\Core\Configure;
use Mcp\Exception\ToolCallException;

/**
 * MCP ツール共通: base64 画像のサイズ検証・保存・Markdown への埋め込み。
 *
 * 保存・検証条件は admin / REST API と共通の ImageStorageService に委譲し、
 * ここでは JSON 引数特有の上限（mcp_image_maxbytes）とエラー変換を担う。
 */
trait HandlesImageDataTrait
{
    /**
     * base64 文字列長の上限を検証する
     *
     * @param string $data base64 文字列または data URL
     * @return void
     * @throws \Mcp\Exception\ToolCallException 上限超過の場合
     */
    protected function assertImageDataSize(string $data): void
    {
        $maxBytes = (int)Configure::read('mcp_image_maxbytes');

        if ($maxBytes > 0 && strlen($data) > $maxBytes) {
            throw new ToolCallException(
                'Image data is too large (max ' . $maxBytes . ' bytes of base64 data).',
            );
        }
    }

    /**
     * 画像を保存し、保存情報を返す
     *
     * @param string $data base64 文字列または data URL
     * @param string $fileName 元ファイル名（拡張子の判定に使用）
     * @return array{file_name: string, url: string, size: int, mime: string} 保存結果
     * @throws \Mcp\Exception\ToolCallException 検証・保存失敗
     */
    protected function storeImage(string $data, string $fileName): array
    {
        $this->assertImageDataSize($data);

        $storage = ImageStorageService::create();

        try {
            $stored = $storage->storeFromBase64($data, $fileName);
        } catch (ImageUploadException $e) {
            throw new ToolCallException('Failed to store image: ' . $e->getPublicMessage());
        }

        return [
            'file_name' => $stored['file_name'],
            'url' => $storage->url($stored['file_name']),
            'size' => $stored['size'],
            'mime' => $stored['mime'],
        ];
    }

    /**
     * images[] を保存し、本文末尾に Markdown の画像記法を追記する
     *
     * 画像の保存に失敗した時点で例外を投げるため、呼び出し側は
     * コンテンツを保存しない（画像のみ残るが孤児画像は本設計の対象外）。
     *
     * @param array<int, mixed> $images 画像定義（file_name, data, alt?）
     * @param string $body 追記対象の本文（参照渡し）
     * @return array<int, array{file_name: string, url: string, alt: string|null}> 保存結果
     * @throws \Mcp\Exception\ToolCallException 検証・保存失敗
     */
    protected function attachImages(array $images, string &$body): array
    {
        $attached = [];

        foreach (array_values($images) as $index => $image) {
            if (!is_array($image)) {
                throw new ToolCallException(sprintf('images[%d] must be an object.', $index));
            }

            $fileName = (string)($image['file_name'] ?? '');
            $data = (string)($image['data'] ?? '');
            $alt = isset($image['alt']) ? (string)$image['alt'] : null;

            if ($fileName === '' || $data === '') {
                throw new ToolCallException(sprintf('images[%d] requires file_name and data.', $index));
            }

            try {
                $stored = $this->storeImage($data, $fileName);
            } catch (ToolCallException $e) {
                throw new ToolCallException(sprintf('images[%d]: %s', $index, $e->getMessage()));
            }

            $body .= "\n\n![" . ($alt ?? '') . '](' . $stored['url'] . ')';

            $attached[] = [
                'file_name' => $stored['file_name'],
                'url' => $stored['url'],
                'alt' => $alt,
            ];
        }

        return $attached;
    }
}
