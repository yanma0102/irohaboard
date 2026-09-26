<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * D-30 回帰テスト: アンケート送信時に ib_records_questions が保存されることを確認
 *
 * 修正前: EnquetesQuestionsController::index() の POST 処理で
 *   $details[] に score が含まれておらず、RecordsQuestionsTable の
 *   validation（score 必須）が原因で save() が false を返し、
 *   records_questions テーブルに1件も保存されなかった。
 *
 * 修正後: $cq->score を details に含め、save() の戻り値も検査する。
 */
class EnquetesQuestionsRecordSaveTest extends TestCase
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
    ): EntityInterface {
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
    private function loginAsUser(): EntityInterface
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
    private function createCourse(int $userId, string $title = 'テストコース'): EntityInterface
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
    ): EntityInterface {
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
        string $questionType = 'single',
        int $sortNo = 1,
    ): EntityInterface {
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

    /**
     * アンケートを POST 送信するためのヘルパー
     */
    private function submitEnquete(int $contentId, array $answers): void
    {
        $data = [];
        foreach ($answers as $questionId => $answer) {
            $data['answer_' . $questionId] = $answer;
        }
        $data['ContentsQuestion']['study_sec'] = 0;

        $this->post("/enquetes-questions/index/{$contentId}", $data);
    }

    // =========================================================================
    // D-30 回帰テスト
    // =========================================================================

    /**
     * アンケートに回答を送信すると ib_records と ib_records_questions の両方に
     * レコードが保存されること（D-30 の中核）
     */
    public function testEnqueteSubmitSavesBothRecordAndRecordQuestions(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createEnqueteContent((int)$course->id, (int)$user->id);
        $question = $this->createQuestion((int)$content->id);

        $this->submitEnquete((int)$content->id, [(int)$question->id => '1']);

        $this->assertRedirect();

        // ib_records にレコードが存在すること
        $recordsTable = $this->getTableLocator()->get('Records');
        $recordCount = $recordsTable->find()->where([
            'user_id' => $user->id,
            'content_id' => $content->id,
        ])->count();
        $this->assertGreaterThan(0, $recordCount, 'ib_records にレコードが保存されていない');

        // ib_records_questions にレコードが存在すること（D-30 の中核）
        $recordsQuestionsTable = $this->getTableLocator()->get('RecordsQuestions');
        $record = $recordsTable->find()->where([
            'user_id' => $user->id,
            'content_id' => $content->id,
        ])->first();
        $rqCount = $recordsQuestionsTable->find()->where([
            'record_id' => $record->id,
        ])->count();
        $this->assertGreaterThan(0, $rqCount, 'ib_records_questions にレコードが保存されていない（D-30 不具合）');
    }

    /**
     * 保存された ib_records_questions の行数が設問数と一致すること
     */
    public function testRecordQuestionsCountMatchesQuestionCount(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createEnqueteContent((int)$course->id, (int)$user->id);

        // 質問を3つ作成
        $q1 = $this->createQuestion((int)$content->id, 'single', 1);
        $q2 = $this->createQuestion((int)$content->id, 'single', 2);
        $q3 = $this->createQuestion((int)$content->id, 'single', 3);

        $this->submitEnquete((int)$content->id, [
            (int)$q1->id => '1',
            (int)$q2->id => '2',
            (int)$q3->id => '3',
        ]);

        $this->assertRedirect();

        $recordsTable = $this->getTableLocator()->get('Records');
        $record = $recordsTable->find()->where([
            'user_id' => $user->id,
            'content_id' => $content->id,
        ])->first();

        $recordsQuestionsTable = $this->getTableLocator()->get('RecordsQuestions');
        $rqCount = $recordsQuestionsTable->find()->where([
            'record_id' => $record->id,
        ])->count();
        $this->assertEquals(3, $rqCount, '保存された records_questions の行数が設問数と一致しない');
    }

    /**
     * 各行の question_id / answer / is_correct / score が意が意図した値であること
     */
    public function testRecordQuestionsFieldValuesAreCorrect(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createEnqueteContent((int)$course->id, (int)$user->id);

        $q1 = $this->createQuestion((int)$content->id, 'single', 1);
        $q2 = $this->createQuestion((int)$content->id, 'single', 2);

        $this->submitEnquete((int)$content->id, [
            (int)$q1->id => '1',
            (int)$q2->id => '2',
        ]);

        $this->assertRedirect();

        $recordsTable = $this->getTableLocator()->get('Records');
        $record = $recordsTable->find()->where([
            'user_id' => $user->id,
            'content_id' => $content->id,
        ])->first();

        $recordsQuestionsTable = $this->getTableLocator()->get('RecordsQuestions');
        $rqs = $recordsQuestionsTable->find()
            ->where(['record_id' => $record->id])
            ->orderBy(['question_id' => 'ASC'])
            ->all()
            ->toArray();

        $this->assertCount(2, $rqs);

        // 1件目
        $this->assertEquals($q1->id, $rqs[0]->question_id, 'question_id が一致しない');
        $this->assertEquals('1', $rqs[0]->answer, 'answer が一致しない');
        $this->assertEquals(-1, $rqs[0]->is_correct, 'is_correct が -1 でない');
        $this->assertEquals(0, $rqs[0]->score, 'score が 0 でない');

        // 2件目
        $this->assertEquals($q2->id, $rqs[1]->question_id, 'question_id が一致しない');
        $this->assertEquals('2', $rqs[1]->answer, 'answer が一致しない');
        $this->assertEquals(-1, $rqs[1]->is_correct, 'is_correct が -1 でない');
        $this->assertEquals(0, $rqs[1]->score, 'score が 0 でない');
    }

    /**
     * サクセスメッセージが表示されること
     */
    public function testEnqueteSubmitShowsSuccessMessage(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createEnqueteContent((int)$course->id, (int)$user->id);
        $question = $this->createQuestion((int)$content->id);

        $this->submitEnquete((int)$content->id, [(int)$question->id => '1']);

        $this->assertRedirect();
        $this->assertSession(
            __('回答内容を送信しました'),
            'Flash.flash.0.message',
        );
    }

    /**
     * record アクションで結果が正しく表示されること
     * （ib_records_questions にデータが正しく保存されているため、結果画面も正常に表示される）
     */
    public function testEnqueteRecordPageShowsAfterSubmit(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createEnqueteContent((int)$course->id, (int)$user->id);
        $question = $this->createQuestion((int)$content->id);

        $this->submitEnquete((int)$content->id, [(int)$question->id => '1']);
        $this->assertRedirect();

        $recordsTable = $this->getTableLocator()->get('Records');
        $record = $recordsTable->find()->where([
            'user_id' => $user->id,
            'content_id' => $content->id,
        ])->first();

        $this->get("/enquetes-questions/record/{$content->id}/{$record->id}");
        $this->assertResponseOk();
    }

    /**
     * D-03: EnquetesQuestionsController::index() の POST 処理に demo_mode ガードが
     *       保存処理より前に存在すること
     *
     * Application::bootstrap() が毎リクエストで config/ib_config.php を再読込するため、
     * 統合テストでは demo_mode を実行時に true にできない。よってガードの存在と位置を
     * ソースコードで検証する。
     */
    public function testEnqueteSubmitHasDemoModeGuard(): void
    {
        $source = file_get_contents(ROOT . '/src/Controller/EnquetesQuestionsController.php');

        // index メソッド部分を切り出し
        preg_match('/public function index\b.*?^    \}/ms', $source, $match);
        $this->assertNotEmpty($match, 'index メソッドが見つからない');

        $methodSource = $match[0];

        // demo_mode ガードの存在確認
        $this->assertStringContainsString(
            "Configure::read('demo_mode')",
            $methodSource,
            'index に demo_mode ガードが存在すること',
        );

        // 保存処理より前にガードがあること
        $guardPos = strpos($methodSource, "Configure::read('demo_mode')");
        $detailsPos = strpos($methodSource, '$details = []');
        $this->assertNotFalse($guardPos, 'demo_mode ガードが見つからない');
        $this->assertNotFalse($detailsPos, '保存処理 ($details = []) が見つからない');
        $this->assertLessThan($detailsPos, $guardPos, 'demo_mode ガードは保存処理より前に位置すること');
    }
}
