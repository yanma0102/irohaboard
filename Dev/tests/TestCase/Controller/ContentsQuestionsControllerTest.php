<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * フロント側 ContentsQuestions Controller の統合テスト
 *
 * ContentsQuestionsController::index() はテスト問題の出題画面を表示。
 * ContentsQuestionsController::record() はテスト結果を表示。
 *
 * 認証＋コース受講登録＋コンテンツ存在＋コンテンツ公開の各チェックあり。
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
     * テスト用コンテンツを作成（テスト種別）
     */
    private function createTestContent(
        int $courseId,
        int $userId,
        int $status = 1,
        int $questionCount = 0,
    ): EntityInterface {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テストコンテンツ',
            'kind' => 'test',
            'status' => $status,
            'pass_rate' => 60,
            'question_count' => $questionCount,
        ]);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'コンテンツの保存に失敗');

        return $result;
    }

    /**
     * テスト用の問題を作成
     */
    private function createQuestion(
        int $contentId,
        string $questionType = 'choice',
        int $sortNo = 1,
        string $title = 'テスト問題',
    ): EntityInterface {
        $contentsQuestionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $entity = $contentsQuestionsTable->newEntity([
            'content_id' => $contentId,
            'question_type' => $questionType,
            'title' => $title,
            'body' => $title . 'の本文',
            'options' => '選択肢A,選択肢B,選択肢C',
            'correct' => '1',
            'score' => 10,
            'sort_no' => $sortNo,
        ]);
        $result = $contentsQuestionsTable->save($entity);
        $this->assertNotFalse($result, '問題の保存に失敗');

        return $result;
    }

    // =========================================================================
    // index アクションのテスト
    // =========================================================================

    /**
     * 未認証で /contents-questions/index/{content_id} にアクセスするとリダイレクトされること
     */
    public function testIndexRequiresLogin(): void
    {
        $this->get('/contents-questions/index/1');
        $this->assertRedirect();
    }

    /**
     * ログイン＋受講登録済み＋公開コンテンツで問題一覧が表示されること
     */
    public function testIndexWithContent(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id, 1, 0);
        $this->createQuestion((int)$content->id);

        $this->get("/contents-questions/index/{$content->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('テスト問題');
    }

    /**
     * 存在しないコンテンツIDで index にアクセスすると 404 が返されること
     */
    public function testIndexNotFound(): void
    {
        $this->loginAsUser();

        $this->get('/contents-questions/index/99999');
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
        $content = $this->createTestContent((int)$course->id, (int)$user->id, 0);
        $this->createQuestion((int)$content->id);

        $this->get("/contents-questions/index/{$content->id}");
        $this->assertResponseCode(404);
    }

    /**
     * コース未登録ユーザがアクセスすると 404 が返されること
     */
    public function testIndexUnenrolledCourse(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id, 1);
        $this->createQuestion((int)$content->id);

        $this->get("/contents-questions/index/{$content->id}");
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
        $content = $this->createTestContent((int)$course->id, (int)$user->id, 1);
        $this->createQuestion((int)$content->id);

        $this->get("/contents-questions/record/{$content->id}/99999");
        $this->assertResponseCode(404);
    }

    // =========================================================================
    // 出題リストの復元（セッション切れ対策）
    // =========================================================================

    /**
     * セッションに出題リストが無い（セッション切れ相当）状態でも、
     * リクエストで指定した出題リストと同じ問題が同じ順で出題されること
     */
    public function testIndexRestoresRequestedQuestionSet(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id, 1, 2);
        $question1 = $this->createQuestion((int)$content->id, 'choice', 1, '問題1');
        $this->createQuestion((int)$content->id, 'choice', 2, '問題2');
        $question3 = $this->createQuestion((int)$content->id, 'choice', 3, '問題3');

        $this->get("/contents-questions/index/{$content->id}?question_id_list={$question3->id},{$question1->id}");

        $this->assertResponseOk();
        $this->assertResponseContains('問題3');
        $this->assertResponseContains('問題1');
        $this->assertResponseNotContains('問題2');

        // 出題順（サーバが解決した出題リスト）が指定どおりであること
        $this->assertResponseRegExp(
            '/name="question_id_list"[^>]*value="' . $question3->id . ',' . $question1->id . '"/',
        );
    }

    /**
     * セッションの出題リストは従来どおり使用されること
     */
    public function testIndexUsesSessionQuestionIdList(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id, 1, 2);
        $question1 = $this->createQuestion((int)$content->id, 'choice', 1, '問題1');
        $question2 = $this->createQuestion((int)$content->id, 'choice', 2, '問題2');
        $this->createQuestion((int)$content->id, 'choice', 3, '問題3');

        $this->session([
            'Iroha' => [
                'RondomQuestions' => [
                    (int)$content->id => [
                        'id_list' => [(int)$question1->id, (int)$question2->id],
                    ],
                ],
            ],
        ]);

        $this->get("/contents-questions/index/{$content->id}");

        $this->assertResponseOk();
        $this->assertResponseContains('問題1');
        $this->assertResponseContains('問題2');
        $this->assertResponseNotContains('問題3');
    }

    /**
     * 不正な出題リスト（問題数の不一致・他コンテンツの問題・重複）は無視されること
     */
    public function testIndexIgnoresInvalidRequestedQuestionSet(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id, 1, 2);
        $this->createQuestion((int)$content->id, 'choice', 1, '問題1');
        $question2 = $this->createQuestion((int)$content->id, 'choice', 2, '問題2');
        $question3 = $this->createQuestion((int)$content->id, 'choice', 3, '問題3');

        // 他コンテンツの問題
        $otherContent = $this->createTestContent((int)$course->id, (int)$user->id, 1, 0);
        $otherQuestion = $this->createQuestion((int)$otherContent->id, 'choice', 1, '他コンテンツの問題');

        // 出題数（2問）と一致しない
        $this->get("/contents-questions/index/{$content->id}?question_id_list={$question3->id}");
        $this->assertResponseOk();
        $this->assertResponseNotRegExp(
            '/name="question_id_list"[^>]*value="' . $question3->id . '"/',
        );

        // 他コンテンツの問題を含む
        $this->get("/contents-questions/index/{$content->id}?question_id_list={$otherQuestion->id},{$question2->id}");
        $this->assertResponseOk();
        $this->assertResponseNotContains('他コンテンツの問題');

        // 重複を含む
        $this->get("/contents-questions/index/{$content->id}?question_id_list={$question2->id},{$question2->id}");
        $this->assertResponseOk();
        $this->assertResponseNotContains('他コンテンツの問題');
    }

    /**
     * Cookie に退避した出題リストでも、最初のリクエストで同じ出題内容が表示されること
     *
     * セッション切れ後に再ログインしてテスト画面を開く経路。以前は退避した出題で
     * 開き直す再読込（2 回目のリクエスト）を要したが、Cookie から復元できるため
     * 1 回のリクエストで完結する。
     */
    public function testIndexRestoresQuestionSetFromCookie(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id, 1, 2);
        $question1 = $this->createQuestion((int)$content->id, 'choice', 1, '問題1');
        $this->createQuestion((int)$content->id, 'choice', 2, '問題2');
        $question3 = $this->createQuestion((int)$content->id, 'choice', 3, '問題3');

        $this->cookie(
            'IrohaTestState_' . $content->id,
            $user->id . '-' . $question3->id . '-' . $question1->id,
        );

        $this->get("/contents-questions/index/{$content->id}");

        $this->assertResponseOk();
        $this->assertResponseContains('問題3');
        $this->assertResponseContains('問題1');
        $this->assertResponseNotContains('問題2');
        $this->assertResponseRegExp(
            '/name="question_id_list"[^>]*value="' . $question3->id . ',' . $question1->id . '"/',
        );
    }

    /**
     * 他ユーザの Cookie（ユーザID 不一致）に退避された出題リストは採用されないこと
     */
    public function testIndexIgnoresOtherUserCookie(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id, 1, 2);
        $question1 = $this->createQuestion((int)$content->id, 'choice', 1, '問題1');
        $question2 = $this->createQuestion((int)$content->id, 'choice', 2, '問題2');
        $question3 = $this->createQuestion((int)$content->id, 'choice', 3, '問題3');

        // セッションの出題リストを基準に、Cookie が採用されたかを判定する
        $this->session([
            'Iroha' => [
                'RondomQuestions' => [
                    (int)$content->id => [
                        'id_list' => [(int)$question1->id, (int)$question2->id],
                    ],
                ],
            ],
        ]);
        $this->cookie(
            'IrohaTestState_' . $content->id,
            ((int)$user->id + 1) . '-' . $question3->id . '-' . $question1->id,
        );

        $this->get("/contents-questions/index/{$content->id}");

        $this->assertResponseOk();
        $this->assertResponseContains('問題1');
        $this->assertResponseContains('問題2');
        $this->assertResponseNotContains('問題3');
    }

    /**
     * 採点完了時に、Cookie に退避した出題リストが削除されること
     */
    public function testPostDeletesTestStateCookie(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id, 1, 1);
        $question = $this->createQuestion((int)$content->id, 'choice', 1, '問題1');

        $this->cookie('IrohaTestState_' . $content->id, $user->id . '-' . $question->id);

        $this->post("/contents-questions/index/{$content->id}", [
            'question_id_list' => (string)$question->id,
            'answer_' . $question->id => '1',
            'ContentsQuestion' => ['study_sec' => 60],
        ]);

        $this->assertRedirect();
        $this->assertCookieNotSet('IrohaTestState_' . $content->id);
    }

    /**
     * セッションに出題リストが無くても、送信された出題リストで採点されること
     *
     * セッション切れでログイン画面へ遷移した後、ブラウザへ退避した回答を
     * 送信し直した場合の経路（出題の再抽選で誤採点にならないこと）を検証する。
     */
    public function testPostGradesUsingSubmittedQuestionIdList(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id, 1, 1);
        $this->createQuestion((int)$content->id, 'choice', 1, '問題1');
        $question2 = $this->createQuestion((int)$content->id, 'choice', 2, '問題2');

        $this->post("/contents-questions/index/{$content->id}", [
            'question_id_list' => (string)$question2->id,
            'answer_' . $question2->id => '1',
            'ContentsQuestion' => ['study_sec' => 120],
        ]);

        $this->assertRedirect();

        $records = $this->getTableLocator()->get('Records')->find()->all();
        $this->assertCount(1, $records);
        $this->assertSame(10, (int)$records->first()->score);
        $this->assertSame(10, (int)$records->first()->full_score);

        $recordsQuestions = $this->getTableLocator()->get('RecordsQuestions')->find()->all();
        $this->assertCount(1, $recordsQuestions);
        $this->assertSame((int)$question2->id, (int)$recordsQuestions->first()->question_id);
        $this->assertSame(1, (int)$recordsQuestions->first()->is_correct);
    }
}
