<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * フロント側 EnquetesQuestions Controller の統合テスト
 *
 * EnquetesQuestionsController::index() はアンケートの入力画面を表示。
 * EnquetesQuestionsController::record() はアンケート結果を表示。
 *
 * ContentsQuestions テーブルを再利用（独立した ib_enquetes_questions テーブルは存在しない）。
 * 認証＋コース受講登録＋コンテンツ存在＋コンテンツ公開の各チェックあり。
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
     * テスト用コンテンツを作成（アンケート種別）
     */
    private function createEnqueteContent(
        int $courseId,
        int $userId,
        int $status = 1,
    ): \Cake\Datasource\EntityInterface {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テストアンケート',
            'kind' => 'enquete',
            'status' => $status,
        ]);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'コンテンツの保存に失敗');

        return $result;
    }

    /**
     * テスト用のアンケート質問を作成（ContentsQuestions テーブル利用）
     */
    private function createQuestion(
        int $contentId,
        string $questionType = 'choice',
        int $sortNo = 1,
    ): \Cake\Datasource\EntityInterface {
        $contentsQuestionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $entity = $contentsQuestionsTable->newEntity([
            'content_id' => $contentId,
            'question_type' => $questionType,
            'title' => 'テスト質問',
            'body' => 'テスト質問の本文',
            'options' => '選択肢A,選択肢B,選択肢C',
            'correct' => '1',
            'score' => 0,
            'sort_no' => $sortNo,
        ]);
        $result = $contentsQuestionsTable->save($entity);
        $this->assertNotFalse($result, '質問の保存に失敗');

        return $result;
    }

    // =========================================================================
    // index アクションのテスト
    // =========================================================================

    /**
     * 未認証で /enquetes-questions/index/{content_id} にアクセスするとリダイレクトされること
     */
    public function testIndexRequiresLogin(): void
    {
        $this->get('/enquetes-questions/index/1');
        $this->assertRedirect();
    }

    /**
     * ログイン＋受講登録済み＋公開コンテンツでアンケート画面が表示されること
     */
    public function testIndexWithContent(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createEnqueteContent((int)$course->id, (int)$user->id, 1);
        $this->createQuestion((int)$content->id);

        $this->get("/enquetes-questions/index/{$content->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('テスト質問');
    }

    /**
     * 存在しないコンテンツIDで index にアクセスすると 404 が返されること
     */
    public function testIndexNotFound(): void
    {
        $this->loginAsUser();

        $this->get('/enquetes-questions/index/99999');
        $this->assertResponseCode(404);
    }

    /**
     * 非公開コンテンツに一般ユーザがアクセスすると 404 が返されること
     */
    public function testIndexUnpublishedContent(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createEnqueteContent((int)$course->id, (int)$user->id, 0);
        $this->createQuestion((int)$content->id);

        $this->get("/enquetes-questions/index/{$content->id}");
        $this->assertResponseCode(404);
    }

    /**
     * コース未登録ユーザがアクセスすると 404 が返されること
     */
    public function testIndexUnenrolledCourse(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $content = $this->createEnqueteContent((int)$course->id, (int)$user->id, 1);
        $this->createQuestion((int)$content->id);

        $this->get("/enquetes-questions/index/{$content->id}");
        $this->assertResponseCode(404);
    }

    // =========================================================================
    // record アクションのテスト
    // =========================================================================

    /**
     * 存在しないレコード ID で record にアクセスするとエラーが返されること
     */
    public function testRecordNotFound(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createEnqueteContent((int)$course->id, (int)$user->id, 1);
        $this->createQuestion((int)$content->id);

        $this->get("/enquetes-questions/record/{$content->id}/99999");
        $this->assertResponseCode(404);
    }

    /**
     * 正常な record_id でアンケート結果が表示されること
     */
    public function testRecordWithValidRecord(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createEnqueteContent((int)$course->id, (int)$user->id, 1);
        $question = $this->createQuestion((int)$content->id);

        // レコードを作成（is_passed=2 はアンケートを示す）
        $recordsTable = $this->getTableLocator()->get('Records');
        $record = $recordsTable->newEntity([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'content_id' => $content->id,
            'is_passed' => 2,
            'is_complete' => 1,
        ]);
        $savedRecord = $recordsTable->save($record);
        $this->assertNotFalse($savedRecord, 'レコードの保存に失敗');

        // レコード問題を作成（is_correct=-1 はアンケート回答を示す）
        $recordsQuestionsTable = $this->getTableLocator()->get('RecordsQuestions');
        $rq = $recordsQuestionsTable->newEntity([
            'record_id' => $savedRecord->id,
            'question_id' => $question->id,
            'answer' => '1',
            'is_correct' => -1,
            'score' => 0,
        ]);
        $this->assertNotFalse($recordsQuestionsTable->save($rq), 'レコード問題の保存に失敗');

        $this->get("/enquetes-questions/record/{$content->id}/{$savedRecord->id}");
        $this->assertResponseOk();
    }
}
