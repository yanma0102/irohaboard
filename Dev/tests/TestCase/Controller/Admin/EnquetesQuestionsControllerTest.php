<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Admin EnquetesQuestionsController の統合テスト
 *
 * アンケート問題の CRUD・認証・404 を検証する。
 * ContentsQuestionsController とほぼ同型だが、question_type ラジオ入力を持つ。
 * 同じ ib_contents_questions テーブルを使用する。
 */
class EnquetesQuestionsControllerTest extends TestCase
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
    private function createAdminUser(): EntityInterface
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
    private function loginAsAdmin(): EntityInterface
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
    private function createCourse(string $title, int $userId): EntityInterface
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
    private function createContent(int $courseId, int $userId, string $title = 'テストコンテンツ', string $kind = 'enquete'): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'title' => $title,
            'course_id' => $courseId,
            'user_id' => $userId,
            'kind' => $kind,
            'body' => $title . 'の本文',
            'sort_no' => 1,
        ]);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'コンテンツの保存に失敗');

        return $result;
    }

    /**
     * テスト用アンケート問題を作成
     */
    private function createQuestion(int $contentId, string $title = 'テスト問題', int $sortNo = 1, string $questionType = 'single'): EntityInterface
    {
        $table = $this->getTableLocator()->get('ContentsQuestions');
        $entity = $table->newEntity([
            'content_id' => $contentId,
            'question_type' => $questionType,
            'title' => $title,
            'body' => $title . 'の本文',
            'options' => '選択肢A|選択肢B|選択肢C',
            'correct' => '1',
            'score' => 10,
            'sort_no' => $sortNo,
        ]);
        $result = $table->save($entity);
        $this->assertNotFalse($result, '問題の保存に失敗');

        return $result;
    }

    /**
     * 未認証アクセステスト
     */
    public function testUnauthenticatedAccess(): void
    {
        $this->get('/admin/enquetes-questions/index/1');
        $this->assertRedirect();
    }

    /**
     * 一覧表示テスト
     */
    public function testIndex(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $this->createQuestion((int)$content->id, '一覧テスト問題');

        $this->get("/admin/enquetes-questions/index/{$content->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('一覧テスト問題');
    }

    /**
     * 一覧表示テスト — 存在しない content_id で 404
     */
    public function testIndexNotFound(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/enquetes-questions/index/99999');
        $this->assertResponseCode(404);
    }

    /**
     * 追加画面表示テスト
     */
    public function testAddGet(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('追加テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $this->get("/admin/enquetes-questions/add/{$content->id}");
        $this->assertResponseOk();
    }

    /**
     * 追加(add) POST テスト
     *
     * フォームは null エンティティで作成されるため POST データはフラット（モデルプレフィックスなし）。
     * Controller::edit() で $this->request->withData('ContentsQuestions', $data) とラップされる。
     */
    public function testAddPost(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('新規問題コース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $this->post("/admin/enquetes-questions/add/{$content->id}", [
            'title' => '新規アンケート問題',
            'body' => '新規アンケートの本文',
            'question_type' => 'single',
            'options' => 'A|B|C',
            'correct' => '1',
            'score' => 10,
            'comment' => '',
        ]);
        $this->assertRedirect();

        // DB 確認
        $table = $this->getTableLocator()->get('ContentsQuestions');
        $question = $table->find()->where(['title' => '新規アンケート問題'])->first();
        $this->assertNotNull($question, 'アンケート問題が DB に保存されていない');
        $this->assertSame((int)$content->id, (int)$question->content_id);
        $this->assertSame(1, (int)$question->sort_no);
    }

    /**
     * 追加(add) POST テスト — question_type が multi の場合
     */
    public function testAddPostMultiType(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('マルチタイプコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $this->post("/admin/enquetes-questions/add/{$content->id}", [
            'title' => 'マルチ選択問題',
            'body' => 'マルチ選択の本文',
            'question_type' => 'multi',
            'options' => 'X|Y|Z',
            'correct' => '1,2',
            'score' => 20,
            'comment' => '',
        ]);
        $this->assertRedirect();

        $table = $this->getTableLocator()->get('ContentsQuestions');
        $question = $table->find()->where(['title' => 'マルチ選択問題'])->first();
        $this->assertNotNull($question);
        $this->assertSame('multi', $question->question_type);
    }

    /**
     * 追加(add) POST テスト — question_type が text の場合
     *
     * text 型で options が空文字でも、edit() は $question_id === null の分岐で
     * newEmptyEntity() を使い、content_id/user_id/sort_no を補完するため正常に保存される。
     */
    public function testAddPostTextType(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('テキストタイプコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $this->post("/admin/enquetes-questions/add/{$content->id}", [
            'title' => 'テキスト回答問題',
            'body' => 'テキスト回答の本文',
            'question_type' => 'text',
            'options' => '',
            'correct' => '',
            'score' => 1,
            'comment' => '',
        ]);

        // 保存成功時は index へリダイレクト
        $this->assertResponseCode(302);

        $table = $this->getTableLocator()->get('ContentsQuestions');
        $question = $table->find()->where(['title' => 'テキスト回答問題'])->first();
        $this->assertNotNull($question, 'テキスト型の質問が保存されていること');
        $this->assertSame('text', $question->question_type);
        $this->assertSame((int)$content->id, (int)$question->content_id);
    }

    /**
     * 編集(edit) GET テスト
     */
    public function testEditGet(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('編集テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $question = $this->createQuestion((int)$content->id, '編集対象問題');

        $this->get("/admin/enquetes-questions/edit/{$content->id}/{$question->id}");
        $this->assertResponseOk();
    }

    /**
     * 編集(edit) POST テスト
     */
    public function testEditPost(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('編集POSTコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $question = $this->createQuestion((int)$content->id, '元の問題タイトル');

        $this->post("/admin/enquetes-questions/edit/{$content->id}/{$question->id}", [
            'id' => $question->id,
            'title' => '更新後の問題タイトル',
            'body' => '更新後の本文',
            'question_type' => 'single',
            'options' => 'X|Y|Z',
            'correct' => '2',
            'score' => 20,
            'comment' => '更新後の備考',
        ]);
        $this->assertRedirect();

        $updated = $this->getTableLocator()->get('ContentsQuestions')->get((int)$question->id);
        $this->assertSame('更新後の問題タイトル', $updated->title);
        $this->assertSame(20, (int)$updated->score);
    }

    /**
     * 編集(edit) GET — 存在しない問題 ID で 404
     */
    public function testEditGetNotFound(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('存在チェックコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $this->get("/admin/enquetes-questions/edit/{$content->id}/99999");
        $this->assertResponseCode(404);
    }

    /**
     * 削除(delete) POST テスト
     */
    public function testDeletePost(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('削除テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $question = $this->createQuestion((int)$content->id, '削除対象問題');
        $questionId = (int)$question->id;

        $this->post("/admin/enquetes-questions/delete/{$questionId}");
        $this->assertRedirect();

        $exists = $this->getTableLocator()->get('ContentsQuestions')->exists(['id' => $questionId]);
        $this->assertFalse($exists, 'アンケート問題が DB から削除されていない');
    }

    /**
     * 削除(delete) GET メソッドでは削除できないこと
     */
    public function testDeleteGetNotAllowed(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('GET削除テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $question = $this->createQuestion((int)$content->id, 'GET削除テスト問題');

        $this->get("/admin/enquetes-questions/delete/{$question->id}");
        $this->assertResponseCode(405);
    }

    /**
     * 削除(delete) — 存在しない ID で 404
     */
    public function testDeleteNotFound(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/enquetes-questions/delete/99999');
        $this->assertResponseCode(404);
    }

    /**
     * order アクション — Ajax リクエストで 200 + "OK" を返すこと
     */
    public function testOrderAjax(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('並べ替えコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $q1 = $this->createQuestion((int)$content->id, '問題1', 1);
        $q2 = $this->createQuestion((int)$content->id, '問題2', 2);

        $this->configRequest([
            'headers' => ['X-Requested-With' => 'XMLHttpRequest'],
        ]);

        $this->post('/admin/enquetes-questions/order', [
            'id_list' => [(int)$q2->id, (int)$q1->id],
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('OK');
    }

    /**
     * order アクション — 非 Ajax リクエストでは空文字列を返すこと
     */
    public function testOrderNonAjax(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/enquetes-questions/order', [
            'id_list' => [],
        ]);
        $this->assertResponseOk();
    }

    /**
     * record アクション — アンケート結果を表示すること
     */
    public function testRecord(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('結果表示コース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $question = $this->createQuestion((int)$content->id, '結果表示問題');

        $recordsTable = $this->getTableLocator()->get('Records');
        $record = $recordsTable->newEntity([
            'course_id' => (int)$course->id,
            'user_id' => (int)$admin->id,
            'content_id' => (int)$content->id,
            'full_score' => 100,
            'score' => 80,
            'created' => date('Y-m-d H:i:s'),
        ]);
        $savedRecord = $recordsTable->save($record);
        $this->assertNotFalse($savedRecord, 'レコードの保存に失敗');

        $recordsQuestionsTable = $this->getTableLocator()->get('RecordsQuestions');
        $recordsQuestion = $recordsQuestionsTable->newEntity([
            'record_id' => (int)$savedRecord->id,
            'question_id' => (int)$question->id,
            'answer' => '1',
            'correct' => '1',
            'is_correct' => 1,
            'score' => 10,
            'created' => date('Y-m-d H:i:s'),
        ]);
        $savedRecordsQuestion = $recordsQuestionsTable->save($recordsQuestion);
        $this->assertNotFalse($savedRecordsQuestion, 'レコード問題の保存に失敗');

        $this->get("/admin/enquetes-questions/record/{$content->id}/{$savedRecord->id}");
        $this->assertResponseOk();
    }

    /**
     * record アクション — 存在しない content_id で 404
     */
    public function testRecordNotFound(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('結果404コース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $question = $this->createQuestion((int)$content->id, '結果404問題');

        $recordsTable = $this->getTableLocator()->get('Records');
        $record = $recordsTable->newEntity([
            'course_id' => (int)$course->id,
            'user_id' => (int)$admin->id,
            'content_id' => (int)$content->id,
            'full_score' => 100,
            'score' => 80,
            'created' => date('Y-m-d H:i:s'),
        ]);
        $savedRecord = $recordsTable->save($record);
        $this->assertNotFalse($savedRecord, 'レコードの保存に失敗');

        $this->get("/admin/enquetes-questions/record/99999/{$savedRecord->id}");
        $this->assertResponseCode(404);
    }

    // ----------------------------------------------------------------
    // D-03 demo_mode 回帰テスト
    // ----------------------------------------------------------------

    /**
     * D-03: edit アクションに demo_mode ガードが存在すること
     */
    public function testEditHasDemoModeGuard(): void
    {
        $source = file_get_contents(ROOT . '/src/Controller/Admin/EnquetesQuestionsController.php');
        preg_match('/public function edit\b.*?^    \}/ms', $source, $editMatch);
        $this->assertNotEmpty($editMatch, 'edit メソッドが見つからない');
        $this->assertStringContainsString("Configure::read('demo_mode')", $editMatch[0], 'edit に demo_mode ガードが存在すること');
    }

    /**
     * D-03: delete アクションに demo_mode ガードが存在すること
     */
    public function testDeleteHasDemoModeGuard(): void
    {
        $source = file_get_contents(ROOT . '/src/Controller/Admin/EnquetesQuestionsController.php');
        preg_match('/public function delete\b.*?^    \}/ms', $source, $deleteMatch);
        $this->assertNotEmpty($deleteMatch, 'delete メソッドが見つからない');
        $this->assertStringContainsString("Configure::read('demo_mode')", $deleteMatch[0], 'delete に demo_mode ガードが存在すること');
    }

    /**
     * D-03: order アクションに demo_mode ガードが存在すること
     */
    public function testOrderHasDemoModeGuard(): void
    {
        $source = file_get_contents(ROOT . '/src/Controller/Admin/EnquetesQuestionsController.php');
        preg_match('/public function order\b.*?^    \}/ms', $source, $orderMatch);
        $this->assertNotEmpty($orderMatch, 'order メソッドが見つからない');
        $this->assertStringContainsString("Configure::read('demo_mode')", $orderMatch[0], 'order に demo_mode ガードが存在すること');
    }
}
