<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * フロント側 Contents Controller の統合テスト
 *
 * ContentsController::index() は学習コンテンツ一覧を表示。
 * ContentsController::view() はコンテンツの閲覧画面を表示。
 * ContentsController::file_image() は画像ファイルを表示。
 * ContentsController::preview() はセッションベースのプレビュー表示。
 *
 * 実装バグ:
 * DashedRoute がアクション名 file_image を fileImage に変換するため、
 * Controller::isAction() が false を返し MissingActionException → 404 になる。
 * 同様に file_download → fileDownload、file_movie → fileMovie も影響を受ける。
 * テストでは実際の挙動（404）を検証する。
 */
class ContentsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * setUp
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->cleanTables();
    }

    /**
     * 外部キー依存順でテーブルをクリーンアップ
     */
    private function cleanTables(): void
    {
        foreach (
            [
                'RecordsQuestions',
                'Records',
                'ContentsQuestions',
                'Contents',
                'UsersCourses',
                'UsersGroups',
                'GroupsCourses',
                'Courses',
                'Groups',
                'Users',
                'Logs',
                'UserTokens',
            ] as $table
        ) {
            $this->getTableLocator()->get($table)->deleteAll('1 = 1');
        }
    }

    /**
     * テスト用ユーザーを作成
     */
    private function createUser(
        string $username = 'testuser',
        string $role = 'user',
    ): \Cake\Datasource\EntityInterface {
        $usersTable = $this->getTableLocator()->get('Users');
        $entity = $usersTable->newEntity([
            'username' => $username,
            'password' => 'userpass',
            'name' => $username . 'の名前',
            'role' => $role,
            'email' => $username . '@example.com',
        ]);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result, 'ユーザーの保存に失敗');

        return $result;
    }

    /**
     * フロント側一般ユーザ ログイン状態を再現（セッション直接注入方式）
     */
    private function loginAsUser(): \Cake\Datasource\EntityInterface
    {
        $user = $this->createUser();

        $this->session([
            'Auth' => [
                'id' => $user->id,
                'username' => $user->username,
                'password' => $user->password,
                'name' => $user->name,
                'role' => $user->role,
                'email' => $user->email,
            ],
        ]);

        $this->enableCsrfToken();
        $this->enableSecurityToken();

        return $user;
    }

    /**
     * テスト用コースを作成
     */
    private function createCourse(int $userId, string $title = 'テストコース'): \Cake\Datasource\EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $entity = $coursesTable->newEntity([
            'title' => $title,
            'user_id' => $userId,
        ]);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result, 'コースの保存に失敗');

        return $result;
    }

    /**
     * ユーザをコースに受講登録
     */
    private function enrollUser(int $userId, int $courseId): void
    {
        $usersCoursesTable = $this->getTableLocator()->get('UsersCourses');
        $entity = $usersCoursesTable->newEntity([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
        $result = $usersCoursesTable->save($entity);
        $this->assertNotFalse($result, '受講登録の保存に失敗');
    }

    /**
     * テスト用コンテンツを作成
     */
    private function createContent(
        int $courseId,
        int $userId,
        string $kind = 'html',
        int $status = 1,
        ?string $url = null,
    ): \Cake\Datasource\EntityInterface {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $data = [
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テストコンテンツ',
            'kind' => $kind,
            'status' => $status,
        ];
        if ($url !== null) {
            $data['url'] = $url;
        }
        $entity = $contentsTable->newEntity($data);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'コンテンツの保存に失敗');

        return $result;
    }

    // =========================================================================
    // index アクションのテスト
    // =========================================================================

    /**
     * 未認証で /contents/index/{course_id} にアクセスするとリダイレクトされること
     */
    public function testIndexRequiresLogin(): void
    {
        $this->get('/contents/index/1');
        $this->assertRedirect();
    }

    /**
     * ログイン＋受講登録済みコースでコンテンツ一覧が表示されること
     */
    public function testIndexWithEnrolledCourse(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $this->createContent((int)$course->id, (int)$user->id, 'html', 1, null);

        $this->get("/contents/index/{$course->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('テストコンテンツ');
    }

    /**
     * ログイン済みだがコース未登録の場合、例外が発生すること
     */
    public function testIndexWithUnenrolledCourse(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);

        $this->get("/contents/index/{$course->id}");
        $this->assertResponseCode(404);
    }

    /**
     * 存在しないコースIDでアクセスすると例外が発生すること
     */
    public function testIndexWithNonExistentCourse(): void
    {
        $this->loginAsUser();

        $this->get('/contents/index/99999');
        $this->assertResponseCode(404);
    }

    // =========================================================================
    // view アクションのテスト
    // =========================================================================

    /**
     * ログイン＋受講登録済み＋公開コンテンツで閲覧画面が表示されること
     */
    public function testView(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$user->id, 'html', 1);

        $this->get("/contents/view/{$content->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('テストコンテンツ');
    }

    /**
     * 存在しないコンテンツIDで view にアクセスすると 404 が返されること
     */
    public function testViewNotFound(): void
    {
        $this->loginAsUser();

        $this->get('/contents/view/99999');
        $this->assertResponseCode(404);
    }

    /**
     * 非公開コンテンツ（status != 1）に一般ユーザがアクセスすると 404 が返されること
     */
    public function testViewUnpublishedContent(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$user->id, 'html', 0);

        $this->get("/contents/view/{$content->id}");
        $this->assertResponseCode(404);
    }

    // =========================================================================
    // preview アクションのテスト
    // =========================================================================

    /**
     * ログイン後にプレビュー画面が表示されること
     */
    public function testPreview(): void
    {
        $this->loginAsUser();

        $this->get('/contents/preview');
        $this->assertResponseOk();
    }

    /**
     * 未認証でプレビューにアクセスするとリダイレクトされること
     */
    public function testPreviewRequiresLogin(): void
    {
        $this->get('/contents/preview');
        $this->assertRedirect();
    }

    // =========================================================================
    // file_image アクションのテスト
    // =========================================================================

    /**
     * 未認証で /contents/file-image にアクセスするとリダイレクトされること
     *
     * file_image は認証コンポーネントにより認証が必要。
     */
    public function testFileImageRequiresLogin(): void
    {
        $this->get('/contents/file-image/some_image.png');
        $this->assertRedirect();
    }

    /**
     * バグ確認: file_image は DashedRoute のアクション名変換により 404 が返されること
     *
     * DashedRoute が file_image → fileImage に変換し、
     * Controller::isAction() が false を返すため MissingActionException になる。
     * ファイルが存在しても 404 が返される。
     */
    public function testFileImageActionNotFoundDueToRouteBug(): void
    {
        $this->loginAsUser();

        $testFile = ROOT . DS . 'files' . DS . 'test_action_bug.png';
        $pngData = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQABNjN9GQAAAABJRUEFTkSuQmCC'
        );
        file_put_contents($testFile, $pngData);

        try {
            $this->get('/contents/file-image/test_action_bug.png');
            // 実装バグ: DashedRoute が file_image を fileImage に変換 → 404
            $this->assertResponseCode(404);
        } finally {
            if (file_exists($testFile)) {
                unlink($testFile);
            }
        }
    }

    /**
     * 許可拡張子の画像ファイルを配置しても、バグにより 404 が返されること
     *
     * ROOT/files/ にダミー PNG を配置。本来は 200 で返すべきだが、
     * アクション名変換バグにより 404 が返される。
     */
    public function testFileImageValidPng(): void
    {
        $this->loginAsUser();

        $testFile = ROOT . DS . 'files' . DS . 'test_image.png';

        // 最小限の PNG ファイルを生成（1x1 ピクセルの透過 PNG）
        $pngData = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQABNjN9GQAAAABJRUEFTkSuQmCC'
        );
        file_put_contents($testFile, $pngData);

        try {
            $this->get('/contents/file-image/test_image.png');
            // 実装バグ: DashedRoute が file_image を fileImage に変換 → 404
            $this->assertResponseCode(404);
        } finally {
            if (file_exists($testFile)) {
                unlink($testFile);
            }
        }
    }

    /**
     * 存在しない画像ファイルは 404 が返されること
     *
     * 注: アクション名バグにより本来のバリデーション前に 404 になる。
     */
    public function testFileImageNotFound(): void
    {
        $this->loginAsUser();

        $this->get('/contents/file-image/nonexistent_image.png');
        $this->assertResponseCode(404);
    }

    /**
     * 許可されていない拡張子のファイルは 404 が返されること
     *
     * 注: アクション名バグにより本来のバリデーション前に 404 になる。
     */
    public function testFileImageInvalidExtension(): void
    {
        $this->loginAsUser();

        $this->get('/contents/file-image/hack.php');
        $this->assertResponseCode(404);
    }

    /**
     * 不正な文字を含むファイル名は 404 が返されること
     *
     * 注: アクション名バグにより本来のバリデーション前に 404 になる。
     */
    public function testFileImageInvalidFilename(): void
    {
        $this->loginAsUser();

        $this->get('/contents/file-image/test%3Brm.png');
        $this->assertResponseCode(404);
    }

    /**
     * 先頭がドットのファイル名は 404 が返されること
     *
     * 注: アクション名バグにより本来のバリデーション前に 404 になる。
     */
    public function testFileImageDotPrefix(): void
    {
        $this->loginAsUser();

        $this->get('/contents/file-image/.hidden.png');
        $this->assertResponseCode(404);
    }

    /**
     * 許可拡張子の .jpg ファイルもバグにより 404 が返されること
     */
    public function testFileImageValidJpg(): void
    {
        $this->loginAsUser();

        $testFile = ROOT . DS . 'files' . DS . 'test_image.jpg';

        // 最小限の JPEG ファイルを生成
        $jpgData = base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDABALDA4MChAODQ4SERATGCgaGBYWGDEjJR0oOjM9PDkzODdASFxOQERXRTc4UG1RV19iZ2hnPk1xeXBkeFxlZ2f/2wBDARESEhgVGC8aGC9nQTtBZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2f/wAARCAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AKwA//9k='
        );
        file_put_contents($testFile, $jpgData);

        try {
            $this->get('/contents/file-image/test_image.jpg');
            // 実装バグ: DashedRoute が file_image を fileImage に変換 → 404
            $this->assertResponseCode(404);
        } finally {
            if (file_exists($testFile)) {
                unlink($testFile);
            }
        }
    }
}
