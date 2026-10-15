<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;

/**
 * POST /api/v1/contents/images のテスト
 *
 * 検証・保存は ImageStorageService（管理画面と共通）に委譲しているため、
 * API 層ではステータスコード・レスポンス封筒・認可を検証する。
 */
class ContentsImageUploadTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * 1x1 の有効な PNG
     */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function setUp(): void
    {
        parent::setUp();
        $this->getTableLocator()->get('UserTokens')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Courses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Logs')->deleteAll('1 = 1');
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    private function createUser(string $username = 'testuser', array $overrides = []): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $data = array_merge([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テスト太郎',
            'role' => 'user',
            'email' => $username . '@example.com',
        ], $overrides);

        $entity = $usersTable->newEntity($data);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result, "ユーザ {$username} の作成に失敗");

        return $result;
    }

    private function issueToken(string $username, string $password): array
    {
        $this->post('/api/v1/auth/token', [
            'username' => $username,
            'password' => $password,
        ]);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body, 'レスポンスに data キーが存在する');

        return $body['data'];
    }

    private function createCourse(string $title, int $userId): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $entity = $coursesTable->newEntity([
            'title' => $title,
            'sort_no' => 1,
            'user_id' => $userId,
        ]);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result, 'コースの作成に失敗');

        return $result;
    }

    /**
     * テスト用の一時ファイルを作成する
     *
     * @param string $contents 内容
     * @param string $ext 拡張子（ドットなし）
     * @return string パス
     */
    private function tempFile(string $contents, string $ext): string
    {
        $base = tempnam(sys_get_temp_dir(), 'api_upload_');
        $path = $base . '.' . $ext;
        rename($base, $path);
        file_put_contents($path, $contents);

        return $path;
    }

    private function uploadedFile(string $path, string $clientFileName): UploadedFile
    {
        return new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, $clientFileName);
    }

    /**
     * 保存済みファイルを削除する
     *
     * @param string|null $url uploadImage() が返した URL
     */
    private function cleanup(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $name = basename(parse_url($url, PHP_URL_PATH) ?? '');
        if ($name !== '') {
            @unlink(ROOT . DS . 'webroot' . DS . 'uploads' . DS . $name);
        }
    }

    // ----------------------------------------------------------------
    // テスト
    // ----------------------------------------------------------------

    /**
     * 正常系: staff が PNG をアップロード → 201 と配信 URL
     */
    public function testUploadImageReturnsCreated(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $tokenData = $this->issueToken('admin01', 'testpass');

        $tmpFile = $this->tempFile(base64_decode(self::PNG_BASE64), 'png');

        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $tokenData['token']],
            'files' => ['file' => $this->uploadedFile($tmpFile, 'diagram.png')],
        ]);

        $this->post('/api/v1/contents/images', ['course_id' => $course->id]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);

        $data = $body['data'];
        $this->assertMatchesRegularExpression('/^\d{14}[a-z]{4}\.png$/', $data['file_name']);
        $this->assertSame('image/png', $data['mime']);
        $this->assertGreaterThan(0, $data['size']);
        $this->assertStringContainsString('/contents/file-image/' . $data['file_name'], $data['url']);
        $this->assertStringNotContainsString('/admin/', $data['url'], '配信 URL に admin prefix が混入しないこと');

        // 保存されていること
        $this->assertFileExists(ROOT . DS . 'webroot' . DS . 'uploads' . DS . $data['file_name']);

        $this->cleanup($data['url']);
        @unlink($tmpFile);
    }

    /**
     * `image` フィールドでも受け付ける
     */
    public function testUploadImageAcceptsImageFieldName(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');

        $tmpFile = $this->tempFile(base64_decode(self::PNG_BASE64), 'png');

        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $tokenData['token']],
            'files' => ['image' => $this->uploadedFile($tmpFile, 'diagram.png')],
        ]);

        $this->post('/api/v1/contents/images');

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->cleanup($body['data']['url']);
        @unlink($tmpFile);
    }

    /**
     * ファイル無し → 400
     */
    public function testUploadImageWithoutFileReturnsBadRequest(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents/images');

        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('image file is required', $body['error']['message']);
    }

    /**
     * 実体が画像でない（.png を名乗る非画像）→ 415
     */
    public function testUploadImageRejectsNonImage(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');

        $tmpFile = $this->tempFile('<?php echo "not an image";', 'png');

        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $tokenData['token']],
            'files' => ['file' => $this->uploadedFile($tmpFile, 'fake.png')],
        ]);

        $this->post('/api/v1/contents/images');

        $this->assertResponseCode(415);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Unsupported image type', $body['error']['message']);

        @unlink($tmpFile);
    }

    /**
     * 許可されていない拡張子 → 415
     */
    public function testUploadImageRejectsDisallowedExtension(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');

        // 実体は PNG でも .php は拒否する
        $tmpFile = $this->tempFile(base64_decode(self::PNG_BASE64), 'php');

        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $tokenData['token']],
            'files' => ['file' => $this->uploadedFile($tmpFile, 'malicious.php')],
        ]);

        $this->post('/api/v1/contents/images');

        $this->assertResponseCode(415);

        @unlink($tmpFile);
    }

    /**
     * サイズ上限超過 → 413
     */
    public function testUploadImageRejectsOversize(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');

        $tmpFile = $this->tempFile(str_repeat('x', 1024 * 1024 * 2 + 1), 'png');

        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $tokenData['token']],
            'files' => ['file' => $this->uploadedFile($tmpFile, 'large.png')],
        ]);

        $this->post('/api/v1/contents/images');

        $this->assertResponseCode(413);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Image is too large', $body['error']['message']);

        @unlink($tmpFile);
    }

    /**
     * 非 staff（一般ユーザ）は 403
     */
    public function testUploadImageRequiresStaff(): void
    {
        $this->createUser('user01', ['role' => 'user']);
        $tokenData = $this->issueToken('user01', 'testpass');

        $tmpFile = $this->tempFile(base64_decode(self::PNG_BASE64), 'png');

        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $tokenData['token']],
            'files' => ['file' => $this->uploadedFile($tmpFile, 'diagram.png')],
        ]);

        $this->post('/api/v1/contents/images');

        $this->assertResponseCode(403);

        @unlink($tmpFile);
    }

    /**
     * course_id 未指定でもアップロードできる
     */
    public function testUploadImageWithoutCourseId(): void
    {
        $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');

        $tmpFile = $this->tempFile(base64_decode(self::PNG_BASE64), 'png');

        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $tokenData['token']],
            'files' => ['file' => $this->uploadedFile($tmpFile, 'diagram.png')],
        ]);

        $this->post('/api/v1/contents/images');

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->cleanup($body['data']['url']);
        @unlink($tmpFile);
    }
}
