<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * D-27 回帰テスト: 記述式(text)設問を含むテストの「結果を見る」ページが 500 にならないこと
 *
 * 修正内容: templates/ContentsQuestions/index.php 162-165行付近の
 * explode(',', $correct) で空文字要素が生じる問題への対処。
 *
 * テストシナリオ:
 *  1. 記述式設問（correct=''）のみのレコード → 500 にならない
 *  2. 選択式設問（単一正解 correct='1'）のレコード → 500 にならない
 *  3. 選択式設問（複数正解 correct='1,3'）のレコード → 500 にならない
 *  4. 3種類を混在させたレコード → 500 にならない
 */
class ContentsQuestionsResultViewTest extends TestCase
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
     * テスト用コンテンツを作成（テスト種別）
     */
    private function createTestContent(
        int $courseId,
        int $userId,
        int $status = 1,
    ): \Cake\Datasource\EntityInterface {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テストコンテンツ',
            'kind' => 'test',
            'status' => $status,
            'pass_rate' => 60,
            'question_count' => 0,
            'wrong_mode' => 1,
        ]);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'コンテンツの保存に失敗');

        return $result;
    }

    /**
     * 記述式(text)問題を作成（correct=''）
     */
    private function createTextQuestion(
        int $contentId,
        int $sortNo = 1,
    ): \Cake\Datasource\EntityInterface {
        $contentsQuestionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $entity = $contentsQuestionsTable->newEntity([
            'content_id' => $contentId,
            'question_type' => 'text',
            'title' => '記述式テスト問題',
            'body' => '記述式テスト問題の本文',
            'options' => null,
            'correct' => '',
            'score' => 10,
            'sort_no' => $sortNo,
        ]);
        $result = $contentsQuestionsTable->save($entity);
        $this->assertNotFalse($result, '記述式問題の保存に失敗');

        return $result;
    }

    /**
     * 単一正解の選択式(single)問題を作成（correct='1'）
     */
    private function createSingleChoiceQuestion(
        int $contentId,
        int $sortNo = 1,
    ): \Cake\Datasource\EntityInterface {
        $contentsQuestionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $entity = $contentsQuestionsTable->newEntity([
            'content_id' => $contentId,
            'question_type' => 'single',
            'title' => '単一選択テスト問題',
            'body' => '単一選択テスト問題の本文',
            'options' => '選択肢A|選択肢B|選択肢C',
            'correct' => '1',
            'score' => 10,
            'sort_no' => $sortNo,
        ]);
        $result = $contentsQuestionsTable->save($entity);
        $this->assertNotFalse($result, '単一選択問題の保存に失敗');

        return $result;
    }

    /**
     * 複数正解の選択式(single)問題を作成（correct='1,3'）
     */
    private function createMultiChoiceQuestion(
        int $contentId,
        int $sortNo = 1,
    ): \Cake\Datasource\EntityInterface {
        $contentsQuestionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $entity = $contentsQuestionsTable->newEntity([
            'content_id' => $contentId,
            'question_type' => 'single',
            'title' => '複数選択テスト問題',
            'body' => '複数選択テスト問題の本文',
            'options' => '選択肢A|選択肢B|選択肢C',
            'correct' => '1,3',
            'score' => 10,
            'sort_no' => $sortNo,
        ]);
        $result = $contentsQuestionsTable->save($entity);
        $this->assertNotFalse($result, '複数選択問題の保存に失敗');

        return $result;
    }

    /**
     * テスト結果レコードを作成
     */
    private function createRecord(
        int $courseId,
        int $userId,
        int $contentId,
    ): \Cake\Datasource\EntityInterface {
        $recordsTable = $this->getTableLocator()->get('Records');
        $entity = $recordsTable->newEmptyEntity();
        $entity = $recordsTable->patchEntity($entity, [
            'user_id' => $userId,
            'course_id' => $courseId,
            'content_id' => $contentId,
            'full_score' => 10,
            'pass_score' => 6,
            'score' => 10,
            'is_passed' => 1,
            'is_complete' => 1,
            'study_sec' => 100,
        ]);
        $result = $recordsTable->save($entity);
        $this->assertNotFalse($result, 'テスト結果レコードの保存に失敗');

        return $result;
    }

    /**
     * テスト結果の個別問題レコードを作成
     */
    private function createRecordsQuestion(
        int $recordId,
        int $questionId,
        string $answer = '',
        string $correct = '',
        int $isCorrect = 1,
        int $score = 10,
    ): \Cake\Datasource\EntityInterface {
        $recordsQuestionsTable = $this->getTableLocator()->get('RecordsQuestions');
        $entity = $recordsQuestionsTable->newEmptyEntity();
        $entity = $recordsQuestionsTable->patchEntity($entity, [
            'record_id' => $recordId,
            'question_id' => $questionId,
            'answer' => $answer,
            'correct' => $correct,
            'is_correct' => $isCorrect,
            'score' => $score,
        ]);
        $result = $recordsQuestionsTable->save($entity);
        $this->assertNotFalse($result, 'テスト結果個別問題レコードの保存に失敗');

        return $result;
    }

    // =========================================================================
    // 記述式設問の結果表示テスト（D-27 の直接的な再現ケース）
    // =========================================================================

    /**
     * 記述式設問のみのテスト結果が 500 にならないこと
     *
     * D-27 の根本原因: correct='' → explode(',', '') = [''] →
     * $option_list['' - 1] で TypeError が発生していた
     */
    public function testRecordViewWithTextQuestionOnly(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id);

        // 記述式問題（correct=''）
        $question = $this->createTextQuestion((int)$content->id, 1);

        // テスト結果レコード作成
        $record = $this->createRecord((int)$course->id, (int)$user->id, (int)$content->id);

        // 記述式設問の結果レコード（answer='' で正解なし）
        $this->createRecordsQuestion(
            (int)$record->id,
            (int)$question->id,
            'テスト回答テキスト',
            '',
            0,
            10,
        );

        // 結果ページへのアクセス（500 にならないことを検証）
        $this->get("/contents-questions/record/{$content->id}/{$record->id}");
        $this->assertResponseOk();
        $this->assertResponseCode(200);
    }

    // =========================================================================
    // 選択式（単一正解）設問の結果表示テスト
    // =========================================================================

    /**
     * 単一正解の選択式設問の結果が 500 にならないこと
     */
    public function testRecordViewWithSingleChoiceQuestion(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id);

        // 単一正解の選択式問題（correct='1'）
        $question = $this->createSingleChoiceQuestion((int)$content->id, 1);

        // テスト結果レコード作成
        $record = $this->createRecord((int)$course->id, (int)$user->id, (int)$content->id);

        // 正解した場合
        $this->createRecordsQuestion(
            (int)$record->id,
            (int)$question->id,
            '1',
            '1',
            1,
            10,
        );

        $this->get("/contents-questions/record/{$content->id}/{$record->id}");
        $this->assertResponseOk();
        $this->assertResponseCode(200);
    }

    // =========================================================================
    // 選択式（複数正解）設問の結果表示テスト
    // =========================================================================

    /**
     * 複数正解の選択式設問の結果が 500 にならないこと
     */
    public function testRecordViewWithMultiChoiceQuestion(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id);

        // 複数正解の選択式問題（correct='1,3'）
        $question = $this->createMultiChoiceQuestion((int)$content->id, 1);

        // テスト結果レコード作成
        $record = $this->createRecord((int)$course->id, (int)$user->id, (int)$content->id);

        // 正解した場合
        $this->createRecordsQuestion(
            (int)$record->id,
            (int)$question->id,
            '1,3',
            '1,3',
            1,
            10,
        );

        $this->get("/contents-questions/record/{$content->id}/{$record->id}");
        $this->assertResponseOk();
        $this->assertResponseCode(200);
    }

    // =========================================================================
    // 混合設問（記述式＋選択式）の結果表示テスト
    // =========================================================================

    /**
     * 記述式と選択式が混在するテスト結果が 500 にならないこと
     *
     * 実運用で最もよくあるケース: 記述式と選択式の問題がテスト内で混在
     */
    public function testRecordViewWithMixedQuestionTypes(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id);

        // 問題を3つ作成: 記述式、単一正解、複数正解
        $textQuestion    = $this->createTextQuestion((int)$content->id, 1);
        $singleQuestion  = $this->createSingleChoiceQuestion((int)$content->id, 2);
        $multiQuestion   = $this->createMultiChoiceQuestion((int)$content->id, 3);

        // テスト結果レコード作成
        $record = $this->createRecord((int)$course->id, (int)$user->id, (int)$content->id);

        // 各問題の結果レコード
        $this->createRecordsQuestion(
            (int)$record->id,
            (int)$textQuestion->id,
            'テスト回答テキスト',
            '',
            0,
            10,
        );

        $this->createRecordsQuestion(
            (int)$record->id,
            (int)$singleQuestion->id,
            '1',
            '1',
            1,
            10,
        );

        $this->createRecordsQuestion(
            (int)$record->id,
            (int)$multiQuestion->id,
            '1,3',
            '1,3',
            1,
            10,
        );

        // 結果ページへのアクセス（500 にならないことを検証）
        $this->get("/contents-questions/record/{$content->id}/{$record->id}");
        $this->assertResponseOk();
        $this->assertResponseCode(200);
        // 混合設問ページに各問題が含まれていることを確認
        $this->assertResponseContains('記述式テスト問題');
        $this->assertResponseContains('単一選択テスト問題');
        $this->assertResponseContains('複数選択テスト問題');
    }

    // =========================================================================
    // 不正解時の結果表示テスト
    // =========================================================================

    /**
     * 記述式設問で不正解の場合も 500 にならないこと
     */
    public function testRecordViewWithTextQuestionIncorrect(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id);

        $question = $this->createTextQuestion((int)$content->id, 1);

        $record = $this->createRecord((int)$course->id, (int)$user->id, (int)$content->id);

        // 不正解の場合
        $this->createRecordsQuestion(
            (int)$record->id,
            (int)$question->id,
            '間違った回答',
            '',
            0,
            0,
        );

        $this->get("/contents-questions/record/{$content->id}/{$record->id}");
        $this->assertResponseOk();
        $this->assertResponseCode(200);
    }

    /**
     * 選択式設問で不正解の場合も 500 にならないこと
     */
    public function testRecordViewWithSingleChoiceQuestionIncorrect(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$user->id);

        $question = $this->createSingleChoiceQuestion((int)$content->id, 1);

        $record = $this->createRecord((int)$course->id, (int)$user->id, (int)$content->id);

        // 不正解（正解は1だが2を選択）
        $this->createRecordsQuestion(
            (int)$record->id,
            (int)$question->id,
            '2',
            '1',
            0,
            0,
        );

        $this->get("/contents-questions/record/{$content->id}/{$record->id}");
        $this->assertResponseOk();
        $this->assertResponseCode(200);
    }
}
