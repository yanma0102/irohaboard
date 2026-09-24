<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Admin ContentsQuestionsController の統合テスト
 *
 * テスト問題の CRUD・認証・404 を検証する。
 * Contents と Courses の親データを作成してからテストする。
 */
class ContentsQuestionsControllerTest extends TestCase
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
    private function createContent(int $courseId, int $userId, string $title = 'テストコンテンツ', string $kind = 'test'): \Cake\Datasource\EntityInterface
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
     * テスト用問題を作成
     */
    private function createQuestion(int $contentId, string $title = 'テスト問題', int $sortNo = 1): \Cake\Datasource\EntityInterface
    {
        $table = $this->getTableLocator()->get('ContentsQuestions');
        $entity = $table->newEntity([
            'content_id' => $contentId,
            'question_type' => 'single',
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
        $this->get('/admin/contents-questions/index/1');
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

        $this->get("/admin/contents-questions/index/{$content->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('一覧テスト問題');
    }

    /**
     * 一覧表示テスト — 存在しない content_id で 404
     */
    public function testIndexNotFound(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/contents-questions/index/99999');
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

        $this->get("/admin/contents-questions/add/{$content->id}");
        $this->assertResponseOk();
    }

    /**
     * 追加(add) POST テスト
     *
     * フォームは null エンティティで作成されるため POST データはフラット（モデルプレフィックスなし）。
     * Controller::edit() で $this->request->withData('ContentsQuestions', $data) とラップされる。
     *
     * 注: ContentsQuestions/add のテンプレートには question_type フィールドが存在しない
     * （実装バグ — add 時に question_type が送信されずバリデーションエラーになる）。
     * テストでは issue を回避するため question_type を明示的に含める。
     */
    public function testAddPost(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('新規問題コース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $this->post("/admin/contents-questions/add/{$content->id}", [
            'title' => '新規問題',
            'body' => '新規問題の本文',
            'question_type' => 'single',
            'options' => 'A|B|C',
            'correct' => '1',
            'score' => 10,
            'comment' => '',
        ]);
        $this->assertRedirect();

        // DB 確認
        $table = $this->getTableLocator()->get('ContentsQuestions');
        $question = $table->find()->where(['title' => '新規問題'])->first();
        $this->assertNotNull($question, '問題が DB に保存されていない');
        $this->assertSame((int)$content->id, (int)$question->content_id, 'content_id が正しく設定されていない');
        $this->assertSame(1, (int)$question->sort_no, 'sort_no が正しく設定されていない');
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

        $this->get("/admin/contents-questions/edit/{$content->id}/{$question->id}");
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

        $this->post("/admin/contents-questions/edit/{$content->id}/{$question->id}", [
            'id' => $question->id,
            'title' => '更新後の問題タイトル',
            'body' => '更新後の本文',
            'options' => 'X|Y|Z',
            'correct' => '2',
            'score' => 20,
            'comment' => '更新後の備考',
        ]);
        $this->assertRedirect();

        // DB 確認
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

        $this->get("/admin/contents-questions/edit/{$content->id}/99999");
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

        $this->post("/admin/contents-questions/delete/{$questionId}");
        $this->assertRedirect();

        $exists = $this->getTableLocator()->get('ContentsQuestions')->exists(['id' => $questionId]);
        $this->assertFalse($exists, '問題が DB から削除されていない');
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

        $this->get("/admin/contents-questions/delete/{$question->id}");
        $this->assertResponseCode(405);
    }

    /**
     * 削除(delete) — 存在しない ID で 404
     */
    public function testDeleteNotFound(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/contents-questions/delete/99999');
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

        $this->post('/admin/contents-questions/order', [
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

        $this->post('/admin/contents-questions/order', [
            'id_list' => [],
        ]);
        $this->assertResponseOk();
    }

    /**
     * record アクション — テスト結果を表示できること
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
        $rq = $recordsQuestionsTable->newEntity([
            'record_id' => (int)$savedRecord->id,
            'question_id' => (int)$question->id,
            'answer' => '1',
            'correct' => '1',
            'is_correct' => 1,
            'score' => 10,
            'created' => date('Y-m-d H:i:s'),
        ]);
        $savedRq = $recordsQuestionsTable->save($rq);
        $this->assertNotFalse($savedRq, 'RecordsQuestions の保存に失敗');

        $this->get("/admin/contents-questions/record/{$content->id}/{$savedRecord->id}");
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

        $recordsQuestionsTable = $this->getTableLocator()->get('RecordsQuestions');
        $rq = $recordsQuestionsTable->newEntity([
            'record_id' => (int)$savedRecord->id,
            'question_id' => (int)$question->id,
            'answer' => '1',
            'correct' => '1',
            'is_correct' => 1,
            'score' => 10,
            'created' => date('Y-m-d H:i:s'),
        ]);
        $savedRq = $recordsQuestionsTable->save($rq);
        $this->assertNotFalse($savedRq, 'RecordsQuestions の保存に失敗');

        $this->get("/admin/contents-questions/record/99999/{$savedRecord->id}");
        $this->assertResponseCode(404);
    }
}
