<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Service;

use App\Service\ImageStorageService;
use App\Service\ImageUploadException;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;

/**
 * ImageStorageService のテスト
 *
 * 検証（拡張子・サイズ・実体）・保存・配信 URL の生成を検証する。
 * admin / REST API / MCP の 3 経路が共用するロジックのため、
 * ここで境界条件を固定する。
 */
class ImageStorageServiceTest extends TestCase
{
    /**
     * 1x1 の有効な PNG
     */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $uploadDir = '';

    private ImageStorageService $service;

    public function setUp(): void
    {
        parent::setUp();

        // 実設定（拡張子許可リスト・サイズ上限）を読み込む
        Configure::load('ib_config');

        $this->uploadDir = sys_get_temp_dir() . DS . 'iroha_image_test_' . uniqid();
        mkdir($this->uploadDir, 0755, true);
        $this->service = new ImageStorageService($this->uploadDir);
    }

    public function tearDown(): void
    {
        foreach (glob($this->uploadDir . DS . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->uploadDir);

        parent::tearDown();
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    private function png(): string
    {
        return base64_decode(self::PNG_BASE64);
    }

    private function tempFile(string $contents, string $suffix = ''): string
    {
        $base = tempnam(sys_get_temp_dir(), 'iroha_upload_');
        $path = $base . $suffix;
        if ($suffix !== '') {
            rename($base, $path);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    private function uploadedFile(string $path, string $clientFileName): UploadedFile
    {
        return new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, $clientFileName);
    }

    /**
     * callback が ImageUploadException を投げ、reason が期待どおりか検証する
     */
    private function assertReason(string $expected, callable $callback): void
    {
        try {
            $callback();
            $this->fail('ImageUploadException がスローされていない');
        } catch (ImageUploadException $e) {
            $this->assertSame($expected, $e->getReason());
        }
    }

    // ----------------------------------------------------------------
    // storeFromUploadedFile
    // ----------------------------------------------------------------

    /**
     * PNG を保存し、ランダム名・サイズ・MIME を返す
     */
    public function testStoreFromUploadedFileSavesImage(): void
    {
        $tmpFile = $this->tempFile($this->png(), '.png');
        $file = $this->uploadedFile($tmpFile, 'diagram.png');

        $stored = $this->service->storeFromUploadedFile($file);

        $this->assertMatchesRegularExpression('/^\d{14}[a-z]{4}\.png$/', $stored['file_name']);
        $this->assertSame('image/png', $stored['mime']);
        $this->assertSame(filesize($this->uploadDir . DS . $stored['file_name']), $stored['size']);
        $this->assertFileExists($this->uploadDir . DS . $stored['file_name']);
        $this->assertFileDoesNotExist($tmpFile, 'アップロード元は moveTo() で移動されること');
    }

    /**
     * 許可されていない拡張子は拒否する
     */
    public function testStoreFromUploadedFileRejectsDisallowedExtension(): void
    {
        // 実体は PNG でも拡張子が .php なら拒否する
        $tmpFile = $this->tempFile($this->png(), '.php');
        $file = $this->uploadedFile($tmpFile, 'malicious.php');

        $this->assertReason(
            ImageUploadException::REASON_EXTENSION,
            fn() => $this->service->storeFromUploadedFile($file),
        );

        @unlink($tmpFile);
    }

    /**
     * 拡張子を偽装した非画像（.png を名乗る PHP）は拒否する
     */
    public function testStoreFromUploadedFileRejectsFakeImage(): void
    {
        $tmpFile = $this->tempFile('<?php echo "not an image";', '.png');
        $file = $this->uploadedFile($tmpFile, 'fake.png');

        $this->assertReason(
            ImageUploadException::REASON_NOT_IMAGE,
            fn() => $this->service->storeFromUploadedFile($file),
        );

        @unlink($tmpFile);
    }

    /**
     * サイズ上限を超えるファイルは拒否する
     */
    public function testStoreFromUploadedFileRejectsOversize(): void
    {
        Configure::write('upload_image_maxsize', 10);

        $tmpFile = $this->tempFile($this->png(), '.png');
        $file = $this->uploadedFile($tmpFile, 'large.png');

        $this->assertReason(
            ImageUploadException::REASON_TOO_LARGE,
            fn() => $this->service->storeFromUploadedFile($file),
        );

        @unlink($tmpFile);
    }

    /**
     * アップロードエラー（未選択等）は no_file として扱う
     */
    public function testStoreFromUploadedFileRejectsUploadError(): void
    {
        $tmpFile = $this->tempFile($this->png(), '.png');
        $file = new UploadedFile($tmpFile, filesize($tmpFile), UPLOAD_ERR_NO_FILE, 'none.png');

        $this->assertReason(
            ImageUploadException::REASON_NO_FILE,
            fn() => $this->service->storeFromUploadedFile($file),
        );

        @unlink($tmpFile);
    }

    // ----------------------------------------------------------------
    // storeFromBase64
    // ----------------------------------------------------------------

    /**
     * base64 文字列から保存できる
     */
    public function testStoreFromBase64SavesImage(): void
    {
        $stored = $this->service->storeFromBase64(self::PNG_BASE64, 'diagram.png');

        $this->assertMatchesRegularExpression('/^\d{14}[a-z]{4}\.png$/', $stored['file_name']);
        $this->assertSame('image/png', $stored['mime']);
        $this->assertFileExists($this->uploadDir . DS . $stored['file_name']);
    }

    /**
     * data URL（data:image/png;base64,...）から保存できる
     */
    public function testStoreFromBase64AcceptsDataUrl(): void
    {
        $stored = $this->service->storeFromBase64(
            'data:image/png;base64,' . self::PNG_BASE64,
            'diagram.png',
        );

        $this->assertSame('image/png', $stored['mime']);
        $this->assertFileExists($this->uploadDir . DS . $stored['file_name']);
    }

    /**
     * 不正な base64 は拒否する
     */
    public function testStoreFromBase64RejectsInvalidBase64(): void
    {
        $this->assertReason(
            ImageUploadException::REASON_NOT_IMAGE,
            fn() => $this->service->storeFromBase64('!!!not base64!!!', 'broken.png'),
        );
    }

    /**
     * base64 として妥当でも実体が画像でなければ拒否する
     */
    public function testStoreFromBase64RejectsNonImage(): void
    {
        $this->assertReason(
            ImageUploadException::REASON_NOT_IMAGE,
            fn() => $this->service->storeFromBase64(base64_encode('hello'), 'hello.png'),
        );
    }

    /**
     * 許可されていない拡張子は保存前に拒否する
     */
    public function testStoreFromBase64RejectsDisallowedExtension(): void
    {
        $this->assertReason(
            ImageUploadException::REASON_EXTENSION,
            fn() => $this->service->storeFromBase64(self::PNG_BASE64, 'diagram.php'),
        );
    }

    // ----------------------------------------------------------------
    // url
    // ----------------------------------------------------------------

    /**
     * 配信 URL は front の /contents/file-image/ を指す
     *
     * admin prefix 配下のリクエストから呼ばれても prefix が混入しないこと（F-2 の回帰）。
     */
    public function testUrlPointsToFrontRouteWithoutAdminPrefix(): void
    {
        $this->loadRoutes();

        $url = $this->service->url('20261015123456abcd.png');

        $this->assertStringContainsString('/contents/file-image/20261015123456abcd.png', $url);
        $this->assertStringNotContainsString('/admin/', $url);
    }
}
