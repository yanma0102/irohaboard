<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Admin ContentsController の統合テスト
 *
 * コンテンツ CRUD、ファイルアップロード（S-1 回帰テスト）を検証する。
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
     * admin ロールのユーザーを作成
     */
    private function createAdminUser(): \Cake\Datasource\EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $entity = $usersTable->newEntity([
            'username' => 'testadmin',
            'password' => 'adminpass',
            'name' => 'テスト管理者',
            'role' => 'admin',
            'email' => 'admin@example.com',
        ]);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result, 'admin ユーザーの保存に失敗');

        return $result;
    }

    /**
     * admin ログイン状態を再現（セッション直接注入方式）
     */
    private function loginAsAdmin(): \Cake\Datasource\EntityInterface
    {
        $admin = $this->createAdminUser();

        $this->session([
            'Auth' => [
                'id' => $admin->id,
                'username' => $admin->username,
                'password' => $admin->password,
                'name' => $admin->name,
                'role' => $admin->role,
                'email' => $admin->email,
            ],
        ]);

        $this->enableCsrfToken();
        $this->enableSecurityToken();

        return $admin;
    }

    /**
     * テスト用コースを作成
     */
    private function createCourse(string $title, int $userId): \Cake\Datasource\EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $entity = $coursesTable->newEntity([
            'title' => $title,
            'introduction' => $title . 'の紹介文',
            'comment' => $title . 'のコメント',
            'user_id' => $userId,
        ]);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result, 'コースの保存に失敗');

        return $result;
    }

    /**
     * テスト用コンテンツを作成
     */
    private function createContent(int $courseId, int $userId, string $title = 'テストコンテンツ'): \Cake\Datasource\EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'title' => $title,
            'course_id' => $courseId,
            'user_id' => $userId,
            'kind' => 'html',
            'body' => $title . 'の本文',
            'sort_no' => 1,
        ]);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'コンテンツの保存に失敗');

        return $result;
    }

    /**
     * テスト用のアップロードファイルを作成
     */
    private function createTempFile(string $ext, int $size): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'test_upload_');
        file_put_contents($tmpPath, str_repeat('x', $size));

        // 拡張子を変更（tempnam は .tmp を作る）
        $newPath = $tmpPath . '.' . $ext;
        rename($tmpPath, $newPath);

        return $newPath;
    }

    /**
     * アップロードで保存されたファイルと一時ファイルを削除
     *
     * @param string|false $fileUrl uploadImage() が返した URL
     * @param string $tmpFile テスト用に作成した一時ファイルのパス
     */
    private function cleanupUploadedFile($fileUrl, string $tmpFile): void
    {
        if (is_string($fileUrl) && $fileUrl !== '') {
            $savedName = basename(parse_url($fileUrl, PHP_URL_PATH) ?? '');
            if ($savedName !== '') {
                @unlink(ROOT . DS . 'files' . DS . $savedName);
            }
        }
        @unlink($tmpFile);
    }

    /**
     * 未認証アクセステスト
     *
     * 管理画面未認証でアクセスするとログインページへリダイレクトされること
     */
    public function testIndexRequiresLogin(): void
    {
        $this->get('/admin/contents/index/1');
        $this->assertRedirect();
    }

    /**
     * 一覧表示テスト
     *
     * admin ログイン状態で GET /admin/contents/index/{course_id} → 200。
     * 保存済みコンテンツのタイトルがレスポンス本文に含まれること。
     */
    public function testIndex(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->createContent((int)$course->id, (int)$admin->id, 'CakePHP講座第一回');

        $this->get("/admin/contents/index/{$course->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('CakePHP講座第一回');
    }

    /**
     * 存在しないコースで一覧アクセステスト
     *
     * 存在しない course_id → 404（Courses->get() が例外）
     */
    public function testIndexWithInvalidCourse(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/contents/index/99999');
        $this->assertResponseCode(404);
    }

    /**
     * 追加画面表示テスト
     *
     * GET /admin/contents/add/{course_id} → 200
     */
    public function testAddGet(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('追加テストコース', (int)$admin->id);

        $this->get("/admin/contents/add/{$course->id}");
        $this->assertResponseOk();
    }

    /**
     * 追加(add) POST テスト
     *
     * POST で新規コンテンツ作成 → 302（一覧へリダイレクト）＋
     * DB に保存され course_id / user_id / sort_no が設定されていること。
     *
     * ContentsController::add() は edit() に course_id を渡すため、
     * Courses/Groups の add バグ（get(0)）と異なり正常に動作する。
     */
    public function testAddPost(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('新規コンテンツコース', (int)$admin->id);

        $this->post("/admin/contents/add/{$course->id}", [
            'title' => '新規コンテンツ',
            'kind' => 'html',
            'body' => '新規コンテンツの本文',
        ]);
        $this->assertRedirect();

        // DB 確認
        $contentsTable = $this->getTableLocator()->get('Contents');
        $content = $contentsTable->find()->where(['title' => '新規コンテンツ'])->first();
        $this->assertNotNull($content, 'コンテンツが DB に保存されていない');
        $this->assertSame((int)$course->id, (int)$content->course_id, 'course_id が正しく設定されていない');
        $this->assertSame((int)$admin->id, (int)$content->user_id, 'user_id が正しく設定されていない');
        $this->assertSame(1, (int)$content->sort_no, 'sort_no が正しく設定されていない');
    }

    /**
     * 編集(edit) POST テスト
     *
     * タイトル変更が DB に反映されること。
     */
    public function testEditPost(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('編集テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, '元のコンテンツ名');

        $this->post("/admin/contents/edit/{$course->id}/{$content->id}", [
            'title' => '更新後のコンテンツ名',
            'kind' => 'html',
            'body' => '更新後の本文',
        ]);
        $this->assertRedirect();

        // DB 確認
        $updated = $this->getTableLocator()->get('Contents')->get((int)$content->id);
        $this->assertSame('更新後のコンテンツ名', $updated->title);
    }

    /**
     * 編集(edit) GET テスト（存在しないコンテンツ）
     *
     * 存在しない content_id → 404
     */
    public function testEditNotFound(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('存在チェックコース', (int)$admin->id);

        $this->get("/admin/contents/edit/{$course->id}/99999");
        $this->assertResponseCode(404);
    }

    /**
     * 削除(delete) POST テスト
     *
     * コンテンツ削除 → 302 ＋ DB から消え、
     * 紐づく ib_contents_questions も削除されること。
     */
    public function testDelete(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('削除テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, '削除対象コンテンツ');
        $contentId = (int)$content->id;

        // テスト問題も作成
        $contentsQuestionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $question = $contentsQuestionsTable->newEntity([
            'content_id' => $contentId,
            'title' => 'テスト問題',
            'sort_no' => 1,
        ]);
        $contentsQuestionsTable->save($question);

        $this->post("/admin/contents/delete/{$contentId}");
        $this->assertRedirect();

        // DB 確認: コンテンツが削除されている
        $exists = $this->getTableLocator()->get('Contents')->exists(['id' => $contentId]);
        $this->assertFalse($exists, 'コンテンツが DB から削除されていない');

        // DB 確認: 紐づくテスト問題も削除されている
        $questionExists = $contentsQuestionsTable->exists(['content_id' => $contentId]);
        $this->assertFalse($questionExists, 'コンテンツに紐づくテスト問題が削除されていない');
    }

    /**
     * 画像アップロード — 許可外拡張子の拒否テスト（S-1 回帰）
     *
     * uploadImage() に .php ファイルを送信 → [false] が返されること。
     * uploadImage() は FormProtection から解除済みで、is('ajax') チェック付き。
     */
    public function testUploadImageRejectsInvalidExtension(): void
    {
        $this->loginAsAdmin();

        $tmpFile = $this->createTempFile('php', 100);
        $uploadedFile = new \Laminas\Diactoros\UploadedFile(
            $tmpFile,
            filesize($tmpFile),
            UPLOAD_ERR_OK,
            'malicious.php'
        );

        // uploadImage() は is('ajax') をチェック
        $this->configRequest([
            'headers' => ['X-Requested-With' => 'XMLHttpRequest'],
            'files' => ['file' => $uploadedFile],
        ]);

        $this->post('/admin/contents/upload-image');
        $this->assertResponseOk();

        $body = (string)$this->_response->getBody();
        $decoded = json_decode($body, true);
        $this->assertNotNull($decoded, 'JSON レスポンスがパースできない');
        $this->assertFalse($decoded[0], '不正な拡張子のファイルが拒否されていない');

        // 一時ファイルのクリーンアップ
        @unlink($tmpFile);
    }

    /**
     * 画像アップロード — サイズ超過の拒否テスト（S-1 回帰）
     *
     * upload_image_maxsize（2MB）を超えるファイル → [false] が返されること。
     */
    public function testUploadImageRejectsOversize(): void
    {
        $this->loginAsAdmin();

        // 2MB + 1 バイトのファイルを作成
        $oversize = 1024 * 1024 * 2 + 1;
        $tmpFile = $this->createTempFile('png', $oversize);
        $uploadedFile = new \Laminas\Diactoros\UploadedFile(
            $tmpFile,
            filesize($tmpFile),
            UPLOAD_ERR_OK,
            'large_image.png'
        );

        $this->configRequest([
            'headers' => ['X-Requested-With' => 'XMLHttpRequest'],
            'files' => ['file' => $uploadedFile],
        ]);

        $this->post('/admin/contents/upload-image');
        $this->assertResponseOk();

        $body = (string)$this->_response->getBody();
        $decoded = json_decode($body, true);
        $this->assertNotNull($decoded, 'JSON レスポンスがパースできない');
        $this->assertFalse($decoded[0], 'サイズ超過ファイルが拒否されていない');

        // 一時ファイルのクリーンアップ
        @unlink($tmpFile);
    }

    /**
     * 画像アップロード — 正常な画像のテスト（S-1 回帰 + 実装バグ記録）
     *
     * 許可拡張子（.png）かつサイズ内であるにもかかわらず、
     * [false] が返される（已知のバグ）。
     *
     * 原因: src/Controller/Admin/ContentsController.php の
     *   $result = $file->moveTo($dest);
     *   $response = $result ? [$file_url] : [false];
     * において、Laminas\Diactoros\UploadedFile::moveTo() は void を返すため
     * $result は常に null（falsy）となり、$response は常に [false] になる。
     * 実際にはファイルは ROOT/files/ に保存されている。
     */
    public function testUploadImageAcceptsValidImage(): void
    {
        $this->loginAsAdmin();

        // 100 バイトのダミー PNG ファイル
        $tmpFile = $this->createTempFile('png', 100);
        $uploadedFile = new \Laminas\Diactoros\UploadedFile(
            $tmpFile,
            filesize($tmpFile),
            UPLOAD_ERR_OK,
            'test_image.png'
        );

        $this->configRequest([
            'headers' => ['X-Requested-With' => 'XMLHttpRequest'],
            'files' => ['file' => $uploadedFile],
        ]);

        $this->post('/admin/contents/upload-image');
        $this->assertResponseOk();

        $body = (string)$this->_response->getBody();
        $decoded = json_decode($body, true);
        $this->assertNotNull($decoded, 'JSON レスポンスがパースできない');

        // 拡張子・サイズ検証を通過したため、保存後の URL 配列が返る。
        $this->assertArrayHasKey(0, $decoded);
        $this->assertNotFalse($decoded[0], '許可画像は保存され URL が返ること');
        $this->assertStringContainsString('/contents/file_image/', $decoded[0]);

        // 保存されたファイルのクリーンアップ
        $this->cleanupUploadedFile($decoded[0], $tmpFile);
    }

    /**
     * 学習履歴表示テスト
     *
     * GET /admin/contents/record/{course_id}/{user_id} → 200
     */
    public function testRecord(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('履歴テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, '履歴テストコンテンツ');

        // Record 作成
        $recordsTable = $this->getTableLocator()->get('Records');
        $record = $recordsTable->newEntity([
            'course_id' => (int)$course->id,
            'user_id' => (int)$admin->id,
            'content_id' => (int)$content->id,
        ]);
        $recordsTable->save($record);

        // ContentsQuestion 作成
        $contentsQuestionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $question = $contentsQuestionsTable->newEntity([
            'content_id' => (int)$content->id,
            'question_type' => 'text',
            'body' => 'テスト問題',
            'sort_no' => 1,
        ]);
        $contentsQuestionsTable->save($question);

        // RecordsQuestion 作成
        $recordsQuestionsTable = $this->getTableLocator()->get('RecordsQuestions');
        $recordsQuestion = $recordsQuestionsTable->newEntity([
            'record_id' => (int)$record->id,
            'question_id' => (int)$question->id,
            'score' => 10,
        ]);
        $recordsQuestionsTable->save($recordsQuestion);

        $this->get("/admin/contents/record/{$course->id}/{$admin->id}");
        $this->assertResponseOk();
    }

    /**
     * 存在しないコースで学習履歴アクセステスト
     *
     * 存在しない course_id → 404
     */
    public function testRecordNotFound(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/contents/record/99999/1');
        $this->assertResponseCode(404);
    }

    /**
     * コンテンツコピー POST テスト
     *
     * POST /admin/contents/copy/{course_id}/{content_id} → リダイレクト ＋
     * DB にタイトルに「の複製」を含む新コンテンツが作成され、
     * kind/url/body/file_name が元と同一、course_id = コピー先、status = 0 であること。
     * また、テスト問題もコピーされること。
     */
    public function testCopyPost(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('コピーテストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, 'コピーソースコンテンツ');
        $contentId = (int)$content->id;

        // テスト問題作成
        $contentsQuestionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $question = $contentsQuestionsTable->newEntity([
            'content_id' => $contentId,
            'question_type' => 'text',
            'body' => 'テスト問題文',
            'title' => 'テスト問題タイトル',
            'sort_no' => 1,
        ]);
        $contentsQuestionsTable->save($question);

        $this->post("/admin/contents/copy/{$course->id}/{$contentId}");
        $this->assertRedirect();

        // DB 確認: コピーされたコンテンツ
        $contentsTable = $this->getTableLocator()->get('Contents');
        $copiedContent = $contentsTable->find()
            ->where(['title LIKE' => '%の複製'])
            ->order(['id' => 'DESC'])
            ->first();

        $this->assertNotNull($copiedContent, 'コピーされたコンテンツが見つからない');
        $this->assertStringContainsString('の複製', $copiedContent->title);
        $this->assertSame($content->kind, $copiedContent->kind);
        $this->assertSame($content->url, $copiedContent->url);
        $this->assertSame($content->body, $copiedContent->body);
        $this->assertSame($content->file_name, $copiedContent->file_name);
        $this->assertSame((int)$course->id, (int)$copiedContent->course_id);
        $this->assertSame(0, (int)$copiedContent->status);

        // DB 確認: テスト問題がコピーされている
        $copiedQuestion = $contentsQuestionsTable->find()
            ->where(['content_id' => (int)$copiedContent->id])
            ->first();

        $this->assertNotNull($copiedQuestion, 'コピーされたテスト問題が見つからない');
    }

    /**
     * コピー GET メソッド拒否テスト
     *
     * copy() は POST のみ受け付ける → 405
     */
    public function testCopyGetNotAllowed(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('メソッドテストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $this->get("/admin/contents/copy/{$course->id}/{$content->id}");
        $this->assertResponseCode(405);
    }

    /**
     * 並び替え AJAX テスト
     *
     * AJAX リクエストで order → 200 + レスポンスボディに "OK"
     */
    public function testOrderAjax(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('並び替えテストコース', (int)$admin->id);
        $content1 = $this->createContent((int)$course->id, (int)$admin->id, 'コンテンツ１');
        $content2 = $this->createContent((int)$course->id, (int)$admin->id, 'コンテンツ２');

        $this->configRequest([
            'headers' => ['X-Requested-With' => 'XMLHttpRequest'],
        ]);

        $this->post('/admin/contents/order', [
            'id_list' => [(int)$content1->id, (int)$content2->id],
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('OK');
    }

    /**
     * 並び替え 非 AJAX テスト
     *
     * 非 AJAX リクエストで order → 200 + ボディが空
     */
    public function testOrderNonAjax(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('非AJAX並び替えコース', (int)$admin->id);
        $content1 = $this->createContent((int)$course->id, (int)$admin->id, 'コンテンツ１');
        $content2 = $this->createContent((int)$course->id, (int)$admin->id, 'コンテンツ２');

        $this->post('/admin/contents/order', [
            'id_list' => [(int)$content1->id, (int)$content2->id],
        ]);
        $this->assertResponseOk();
    }

    /**
     * 削除 GET メソッド拒否テスト
     *
     * delete() は POST/DELETE のみ受け付ける → 405
     */
    public function testDeleteGetNotAllowed(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('削除メソッドコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $this->get("/admin/contents/delete/{$content->id}");
        $this->assertResponseCode(405);
    }

    /**
     * 編集フォーム表示テスト
     *
     * GET /admin/contents/edit/{course_id}/{content_id} → 200
     */
    public function testEditGet(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('編集フォームコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, '編集対象コンテンツ');

        $this->get("/admin/contents/edit/{$course->id}/{$content->id}");
        $this->assertResponseOk();
    }

    /**
     * プレビュー: Markdown コンテンツが HTML に変換されること
     *
     * Admin preview は AJAX で Markdown→HTML 変換後セッションに書き込む（autoRender=false）。
     * フロント側プレビューでセッションから取り出してサニタイズ済み HTML として描画されることを検証する。
     */
    public function testPreviewMarkdownConvertsToHtml(): void
    {
        $this->loginAsAdmin();

        // Admin preview は AJAX のみ処理し、Markdown→HTML 変換後にセッションに書き込む
        $this->configRequest([
            'headers' => ['X-Requested-With' => 'XMLHttpRequest'],
        ]);

        $this->post('/admin/contents/preview', [
            'content_body' => '# Hello',
            'content_kind' => 'markdown',
            'content_title' => 'テストプレビュー',
        ]);
        $this->assertResponseOk();

        // フロント側プレビューはセッションから内容を取り出し、
        // Markdown タイプの場合は MarkdownHelper->text() で描画する。
        // Admin preview が書き込むセッションデータを再現して描画を検証する。
        $this->session([
            'Iroha.preview_content' => [
                'id' => 0,
                'title' => 'テストプレビュー',
                'kind' => 'markdown',
                'url' => '',
                'body' => '# Hello',
                'course_id' => 0,
            ],
        ]);

        $this->get('/contents/preview');
        $this->assertResponseOk();
        $this->assertResponseContains('<h1>Hello</h1>');
    }

    /**
     * 追加(add) POST テスト — kind=markdown
     *
     * POST で Markdown コンテンツ作成 → リダイレクト ＋ DB に kind=markdown で保存されること。
     */
    public function testAddPostMarkdown(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('Markdown追加コース', (int)$admin->id);

        $this->post("/admin/contents/add/{$course->id}", [
            'title' => 'Markdownコンテンツ',
            'kind' => 'markdown',
            'body' => '# Markdown本文',
        ]);
        $this->assertRedirect();

        $contentsTable = $this->getTableLocator()->get('Contents');
        $content = $contentsTable->find()->where(['title' => 'Markdownコンテンツ'])->first();
        $this->assertNotNull($content, 'Markdown コンテンツが DB に保存されていない');
        $this->assertSame('markdown', $content->kind);
        $this->assertSame('# Markdown本文', $content->body);
    }

    /**
     * 編集(edit) POST テスト — kind=markdown
     *
     * 既存の HTML コンテンツを kind=markdown に変更して保存。
     */
    public function testEditPostMarkdown(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('Markdown編集コース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, '元のコンテンツ');

        $this->post("/admin/contents/edit/{$course->id}/{$content->id}", [
            'title' => 'Markdownに変更',
            'kind' => 'markdown',
            'body' => '# 変更後のMarkdown',
        ]);
        $this->assertRedirect();

        $updated = $this->getTableLocator()->get('Contents')->get((int)$content->id);
        $this->assertSame('markdown', $updated->kind);
        $this->assertSame('# 変更後のMarkdown', $updated->body);
    }

    /**
     * コンテンツコピー POST テスト — kind=markdown
     *
     * Markdown コンテンツをコピー → kind と body が元と同一であること。
     */
    public function testCopyPostMarkdown(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('Markdownコピーコース', (int)$admin->id);

        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'title' => 'コピーソースMarkdown',
            'course_id' => $course->id,
            'user_id' => $admin->id,
            'kind' => 'markdown',
            'body' => '# コピー元本文',
            'sort_no' => 1,
        ]);
        $content = $contentsTable->save($entity);
        $this->assertNotFalse($content, 'Markdown コンテンツの保存に失敗');
        $contentId = (int)$content->id;

        $this->post("/admin/contents/copy/{$course->id}/{$contentId}");
        $this->assertRedirect();

        $copiedContent = $contentsTable->find()
            ->where(['title LIKE' => '%の複製'])
            ->orderBy(['id' => 'DESC'])
            ->first();

        $this->assertNotNull($copiedContent, 'コピーされた Markdown コンテンツが見つからない');
        $this->assertSame('markdown', $copiedContent->kind);
        $this->assertSame('# コピー元本文', $copiedContent->body);
    }
}
