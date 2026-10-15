<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Service;

use Cake\Core\Configure;
use Cake\Routing\Router;
use Laminas\Diactoros\UploadedFile;
use Throwable;

/**
 * 画像アップロードの検証・保存を一元化する。
 *
 * admin（EasyMDE / Summernote）・REST API・MCP の 3 経路から共用し、
 * 検証条件（拡張子・サイズ・実体）と保存先を 1 箇所に集約する。
 */
class ImageStorageService
{
    /**
     * @param string $uploadDir 保存先ディレクトリ（webroot/uploads）
     */
    public function __construct(private string $uploadDir)
    {
    }

    /**
     * 既定の保存先（webroot/uploads）を使うインスタンスを生成する
     *
     * @param string|null $uploadDir 保存先（未指定時は webroot/uploads）
     * @return self インスタンス
     */
    public static function create(?string $uploadDir = null): self
    {
        return new self($uploadDir ?? WWW_ROOT . 'uploads');
    }

    /**
     * アップロードファイルを検証して保存する
     *
     * @param \Laminas\Diactoros\UploadedFile $file アップロードファイル
     * @return array{file_name: string, size: int, mime: string} 保存結果
     * @throws \App\Service\ImageUploadException 検証失敗・保存失敗
     */
    public function storeFromUploadedFile(UploadedFile $file): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ImageUploadException(
                ImageUploadException::REASON_NO_FILE,
                'Upload error code: ' . $file->getError(),
            );
        }

        $ext = $this->extensionOf((string)$file->getClientFilename());
        $this->assertAllowedExtension($ext);
        $this->assertSize((int)$file->getSize());

        $tmpPath = (string)$file->getStream()->getMetadata('uri');
        $mime = $this->assertIsImage($tmpPath);

        $dest = $this->destinationPath($ext);

        try {
            $file->moveTo($dest);
        } catch (Throwable $e) {
            throw new ImageUploadException(ImageUploadException::REASON_SAVE_FAILED, $e->getMessage());
        }

        if (!is_file($dest)) {
            throw new ImageUploadException(
                ImageUploadException::REASON_SAVE_FAILED,
                'moveTo() did not create ' . $dest,
            );
        }

        return [
            'file_name' => basename($dest),
            'size' => (int)filesize($dest),
            'mime' => $mime,
        ];
    }

    /**
     * base64 文字列 / data URL から保存する（MCP 用）
     *
     * @param string $data base64 文字列または data URL
     * @param string $originalFileName 元ファイル名（拡張子の判定に使用）
     * @return array{file_name: string, size: int, mime: string} 保存結果
     * @throws \App\Service\ImageUploadException 検証失敗・保存失敗
     */
    public function storeFromBase64(string $data, string $originalFileName): array
    {
        if (str_starts_with($data, 'data:')) {
            $separator = strpos($data, ',');
            if ($separator === false) {
                throw new ImageUploadException(
                    ImageUploadException::REASON_NOT_IMAGE,
                    'Malformed data URL',
                );
            }
            $data = substr($data, $separator + 1);
        }

        $binary = base64_decode($data, true);
        if ($binary === false) {
            throw new ImageUploadException(
                ImageUploadException::REASON_NOT_IMAGE,
                'Base64 decode failed',
            );
        }

        $ext = $this->extensionOf($originalFileName);
        $this->assertAllowedExtension($ext);
        $this->assertSize(strlen($binary));

        $tmpPath = tempnam(sys_get_temp_dir(), 'iroha_image_');
        if ($tmpPath === false || file_put_contents($tmpPath, $binary) === false) {
            throw new ImageUploadException(
                ImageUploadException::REASON_SAVE_FAILED,
                'Failed to write temporary file',
            );
        }

        try {
            $mime = $this->assertIsImage($tmpPath);
            $dest = $this->destinationPath($ext);

            if (!@rename($tmpPath, $dest)) {
                throw new ImageUploadException(
                    ImageUploadException::REASON_SAVE_FAILED,
                    'Failed to move to ' . $dest,
                );
            }
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        return [
            'file_name' => basename($dest),
            'size' => (int)filesize($dest),
            'mime' => $mime,
        ];
    }

    /**
     * 保存済みファイルの配信用絶対 URL を返す
     *
     * admin prefix 配下のリクエストから呼ばれても front の
     * /contents/file-image/{fileName} を生成する（prefix の明示的な解除）。
     *
     * @param string $fileName ファイル名
     * @return string 絶対 URL
     */
    public function url(string $fileName): string
    {
        return Router::url([
            'controller' => 'Contents',
            'action' => 'fileImage',
            0 => $fileName,
            'prefix' => false,
        ], true);
    }

    /**
     * ファイル名から拡張子（ドット付き・小文字）を取り出す
     *
     * @param string $fileName ファイル名
     * @return string 拡張子（例: .png）
     */
    private function extensionOf(string $fileName): string
    {
        return '.' . strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    }

    /**
     * 許可された拡張子か検証する
     *
     * @param string $ext 拡張子
     * @return void
     * @throws \App\Service\ImageUploadException 許可されていない場合
     */
    private function assertAllowedExtension(string $ext): void
    {
        $allowed = array_map(
            static fn($value): string => strtolower((string)$value),
            (array)Configure::read('upload_image_extensions'),
        );

        if (!in_array($ext, $allowed, true)) {
            throw new ImageUploadException(
                ImageUploadException::REASON_EXTENSION,
                'Extension not allowed: ' . $ext,
            );
        }
    }

    /**
     * サイズ上限以下か検証する
     *
     * @param int $size サイズ（バイト）
     * @return void
     * @throws \App\Service\ImageUploadException 上限超過の場合
     */
    private function assertSize(int $size): void
    {
        $maxSize = (int)Configure::read('upload_image_maxsize');

        if ($maxSize > 0 && $size > $maxSize) {
            throw new ImageUploadException(
                ImageUploadException::REASON_TOO_LARGE,
                'Size ' . $size . ' exceeds ' . $maxSize,
            );
        }
    }

    /**
     * 実体が画像か検証し、MIME タイプを返す
     *
     * 拡張子を偽装したファイル（例: .png を名乗る PHP）を拒否する。
     *
     * @param string $path 検証対象のパス
     * @return string MIME タイプ
     * @throws \App\Service\ImageUploadException 画像でない場合
     */
    private function assertIsImage(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            throw new ImageUploadException(
                ImageUploadException::REASON_NOT_IMAGE,
                'Temporary file not found',
            );
        }

        $info = @getimagesize($path);
        if ($info === false || empty($info['mime'])) {
            throw new ImageUploadException(
                ImageUploadException::REASON_NOT_IMAGE,
                'getimagesize() failed',
            );
        }

        return (string)$info['mime'];
    }

    /**
     * 保存先のフルパスを生成する（既存の命名規則を踏襲）
     *
     * @param string $ext 拡張子（ドット付き）
     * @return string 保存先パス
     * @throws \App\Service\ImageUploadException ディレクトリを作成できない場合
     */
    private function destinationPath(string $ext): string
    {
        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
            throw new ImageUploadException(
                ImageUploadException::REASON_SAVE_FAILED,
                'Failed to create upload directory: ' . $this->uploadDir,
            );
        }

        $suffix = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 4);

        return $this->uploadDir . DIRECTORY_SEPARATOR . date('YmdHis') . $suffix . $ext;
    }
}
