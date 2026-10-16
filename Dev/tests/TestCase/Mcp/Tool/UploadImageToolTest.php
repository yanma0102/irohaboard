<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\UploadImageTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * upload_image ツールのテスト
 *
 * 権限（staff）・base64 上限（mcp_image_maxbytes）・ImageStorageService の
 * 検証エラーが ToolCallException に変換されることを検証する。
 */
class UploadImageToolTest extends TestCase
{
    /**
     * 1x1 の有効な PNG
     */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private UploadImageTool $tool;

    public function setUp(): void
    {
        parent::setUp();

        // Application::bootstrap() 相当（単体テストは Application を起動しないため）
        Configure::load('ib_config');

        // ImageStorageService::url() はルート定義を必要とする
        $this->loadRoutes();

        foreach (
            [
            'UserTokens', 'Users', 'UsersCourses', 'GroupsCourses',
            'UsersGroups', 'Groups', 'Courses', 'Contents', 'Records', 'Logs',
            ] as $table
        ) {
            $this->getTableLocator()->get($table)->deleteAll('1 = 1');
        }

        $this->tool = new UploadImageTool(
            new AccessControlService($this->getTableLocator()->get('Users')->getConnection()),
        );
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    private function makeContext(int $userId, string $role = 'user'): RequestContext
    {
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upload_image',
                'arguments' => [],
                '_meta' => [
                    'oauth' => [
                        'oauth.user_id' => $userId,
                        'oauth.role' => $role,
                        'oauth.name' => 'テストユーザ',
                        'oauth.token_id' => 1,
                    ],
                ],
            ],
        ]);

        return new RequestContext(
            $this->createStub(SessionInterface::class),
            $request,
        );
    }

    private function createUser(string $username = 'imguser', string $role = 'user'): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $entity = $usersTable->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テストユーザ',
            'role' => $role,
            'email' => $username . '@example.com',
        ]);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    private function createCourse(): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $entity = $coursesTable->newEntity([
            'title' => 'テストコース',
            'introduction' => 'テスト用コースです',
            'opened' => '2024-01-01',
            'comment' => 'コメント',
            'sort_no' => 1,
            'user_id' => 1,
        ]);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    /**
     * 保存された画像ファイルを削除する
     *
     * @param string $fileName 保存ファイル名
     */
    private function cleanup(string $fileName): void
    {
        @unlink(ROOT . DS . 'webroot' . DS . 'uploads' . DS . $fileName);
    }

    // ----------------------------------------------------------------
    // テスト
    // ----------------------------------------------------------------

    /**
     * staff が PNG をアップロードすると URL と保存情報を返す
     */
    public function testStaffUploadsPng(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('wadmin01', 'admin');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            'diagram.png',
            self::PNG_BASE64,
            '図1',
        );

        $this->assertArrayHasKey('data', $result);
        $this->assertMatchesRegularExpression('/^\d{14}[a-z]{4}\.png$/', $result['data']['file_name']);
        $this->assertSame('image/png', $result['data']['mime']);
        $this->assertGreaterThan(0, $result['data']['size']);
        $this->assertSame('図1', $result['data']['alt']);
        $this->assertStringContainsString(
            '/contents/file-image/' . $result['data']['file_name'],
            $result['data']['url'],
        );
        $this->assertStringNotContainsString('/admin/', $result['data']['url']);
        $this->assertFileExists(ROOT . DS . 'webroot' . DS . 'uploads' . DS . $result['data']['file_name']);

        $this->cleanup($result['data']['file_name']);
    }

    /**
     * data URL 形式でも受け付ける
     */
    public function testAcceptsDataUrl(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('wadmin01', 'admin');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            'diagram.png',
            'data:image/png;base64,' . self::PNG_BASE64,
        );

        $this->assertSame('image/png', $result['data']['mime']);
        $this->assertNull($result['data']['alt']);

        $this->cleanup($result['data']['file_name']);
    }

    /**
     * 非 staff は拒否する
     */
    public function testRejectsNonStaff(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('imguser01', 'user');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Only staff members can upload images.');

        $this->tool->__invoke(
            $this->makeContext((int)$user->id, 'user'),
            (int)$course->id,
            'diagram.png',
            self::PNG_BASE64,
        );
    }

    /**
     * 許可されていない拡張子は拒否する
     */
    public function testRejectsDisallowedExtension(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('wadmin01', 'admin');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Failed to store image');

        $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            'malicious.php',
            self::PNG_BASE64,
        );
    }

    /**
     * 実体が画像でない場合は拒否する
     */
    public function testRejectsNonImage(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('wadmin01', 'admin');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Failed to store image');

        $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
            'fake.png',
            base64_encode('not an image'),
        );
    }

    /**
     * base64 文字列長の上限（mcp_image_maxbytes）を超える場合は拒否する
     */
    public function testRejectsTooLargeData(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('wadmin01', 'admin');

        Configure::write('mcp_image_maxbytes', 10);

        try {
            $this->expectException(ToolCallException::class);
            $this->expectExceptionMessage('Image data is too large');

            $this->tool->__invoke(
                $this->makeContext((int)$admin->id, 'admin'),
                (int)$course->id,
                'diagram.png',
                self::PNG_BASE64,
            );
        } finally {
            Configure::load('ib_config');
        }
    }
}
