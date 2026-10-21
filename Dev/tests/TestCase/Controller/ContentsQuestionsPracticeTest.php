<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Cake\Utility\Hash;

/**
 * カスタム受験（練習）機能の統合テスト
 *
 * 練習は履歴を保存せず、設定に応じた出題・採点・結果表示を行う。
 */
class ContentsQuestionsPracticeTest extends TestCase
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
     * 指定ユーザでログイン状態を再現する
     */
    private function loginAs(EntityInterface $user): void
    {
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
    }

    /**
     * ユーザを作成してログインする
     */
    private function loginAsUser(string $username = 'testuser'): EntityInterface
    {
        $user = $this->createUser($username);
        $this->loginAs($user);

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
     * テスト用コンテンツを作成
     */
    private function createTestContent(
        int $courseId,
        int $userId,
        bool $allowCustomTest = true,
        ?int $practiceTimelimitMax = null,
        int $status = 1,
    ): EntityInterface {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テストコンテンツ',
            'kind' => 'test',
            'status' => $status,
            'pass_rate' => 60,
            'question_count' => 0,
            'allow_custom_test' => $allowCustomTest ? 1 : 0,
            'practice_timelimit_max' => $practiceTimelimitMax,
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
        string $title,
        ?string $category = null,
        string $correct = '1',
        int $score = 10,
        int $sortNo = 1,
        ?string $explain = null,
    ): EntityInterface {
        $contentsQuestionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $entity = $contentsQuestionsTable->newEntity([
            'content_id' => $contentId,
            'question_type' => 'single',
            'title' => $title,
            'category' => $category,
            'body' => $title . 'の本文',
            'options' => '選択肢A|選択肢B|選択肢C',
            'correct' => $correct,
            'score' => $score,
            'explain' => $explain,
            'sort_no' => $sortNo,
        ]);
        $result = $contentsQuestionsTable->save($entity);
        $this->assertNotFalse($result, '問題の保存に失敗');

        return $result;
    }

    /**
     * 直前のリクエストで書き込まれたセッションを次のリクエストへ引き継ぐ
     *
     * IntegrationTestTrait はリクエストごとにセッションを作り直すため、
     * コントローラが保存した練習状態を次のリクエストで参照するには明示的に渡す。
     */
    private function persistSession(): void
    {
        // Session::read() は終了済みセッションを開始し直して $_SESSION を消すため、
        // リクエスト後のデータは $_SESSION から直接取得する。
        if (!empty($_SESSION)) {
            $this->_session = $_SESSION + $this->_session;
        }
    }

    /**
     * 練習の状態・結果をセッションへ直接設定する
     */
    private function setPracticeSession(int $userId, int $contentId, string $suffix, array $data): void
    {
        $this->session([
            'Iroha' => [
                'Practice' => [
                    $userId => [
                        $contentId => [
                            $suffix => $data,
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * 練習のセッションキー
     */
    private function stateKey(int $userId, int $contentId): string
    {
        return "Iroha.Practice.{$userId}.{$contentId}.state";
    }

    /**
     * 練習結果のセッションキー
     */
    private function resultKey(int $userId, int $contentId): string
    {
        return "Iroha.Practice.{$userId}.{$contentId}.result";
    }

    /**
     * ログイン・受講登録・コンテンツ作成まで行う
     *
     * @return array{0: \Cake\Datasource\EntityInterface, 1: \Cake\Datasource\EntityInterface}
     */
    private function prepareContent(
        bool $allowCustomTest = true,
        ?int $practiceTimelimitMax = null,
    ): array {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent(
            (int)$course->id,
            (int)$user->id,
            $allowCustomTest,
            $practiceTimelimitMax,
        );

        return [$user, $content];
    }

    /**
     * 練習の設定を POST する
     */
    private function postPractice(int $contentId, array $data)
    {
        return $this->post("/contents-questions/practice/{$contentId}", $data + [
            'question_count' => '0',
            'timelimit' => '0',
            'order' => 'random',
            'wrong_mode' => '2',
        ]);
    }

    // =========================================================================
    // 権限・アクセス制御
    // =========================================================================

    /**
     * allow_custom_test = 0 の場合は設定画面・開始とも 404
     */
    public function testPracticeRequiresAllowCustomTest(): void
    {
        [, $content] = $this->prepareContent(false);
        $this->createQuestion((int)$content->id, '問題1');

        $this->get("/contents-questions/practice/{$content->id}");
        $this->assertResponseCode(404);

        $this->postPractice((int)$content->id, ['categories' => []]);
        $this->assertResponseCode(404);
    }

    /**
     * コース未受講ユーザは 404
     */
    public function testPracticeRequiresCourseRight(): void
    {
        $this->loginAsUser();
        $owner = $this->createUser('owner');
        $course = $this->createCourse((int)$owner->id);
        $content = $this->createTestContent((int)$course->id, (int)$owner->id);
        $this->createQuestion((int)$content->id, '問題1');

        $this->get("/contents-questions/practice/{$content->id}");
        $this->assertResponseCode(404);
    }

    /**
     * テスト以外のコンテンツは 404
     */
    public function testPracticeRequiresTestContent(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);

        $contentsTable = $this->getTableLocator()->get('Contents');
        $content = $contentsTable->save($contentsTable->newEntity([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'title' => '学習コンテンツ',
            'kind' => 'text',
            'body' => '本文',
            'status' => 1,
            'allow_custom_test' => 1,
        ]));

        $this->get("/contents-questions/practice/{$content->id}");
        $this->assertResponseCode(404);
    }

    /**
     * カテゴリと問題数が設定画面に表示される
     */
    public function testPracticeSettingsPageShowsCategories(): void
    {
        [, $content] = $this->prepareContent();
        $this->createQuestion((int)$content->id, 'A1', '第1章', '1', 10, 1);
        $this->createQuestion((int)$content->id, 'A2', '第1章', '1', 10, 2);
        $this->createQuestion((int)$content->id, 'B1', '第2章', '1', 10, 3);
        $this->createQuestion((int)$content->id, 'N1', null, '1', 10, 4);

        $this->get("/contents-questions/practice/{$content->id}");

        $this->assertResponseOk();
        $this->assertResponseContains('第1章');
        $this->assertResponseContains('第2章');
        $this->assertResponseContains('未分類');
        $this->assertResponseContains('練習（記録されません）');
    }

    // =========================================================================
    // 設定の検証
    // =========================================================================

    /**
     * 有効な設定で開始すると状態が保存され、受験画面へリダイレクトする
     */
    public function testPracticeStartStoresState(): void
    {
        [$user, $content] = $this->prepareContent();
        $question1 = $this->createQuestion((int)$content->id, 'A1', '第1章', '1', 10, 1);
        $question2 = $this->createQuestion((int)$content->id, 'A2', '第1章', '1', 10, 2);
        $this->createQuestion((int)$content->id, 'B1', '第2章', '1', 10, 3);

        $this->postPractice((int)$content->id, [
            'categories' => ['第1章'],
            'question_count' => '2',
            'timelimit' => '5',
            'order' => 'sort',
            'wrong_mode' => '1',
        ]);

        $this->assertRedirectContains('/contents-questions/practice-run/' . $content->id);
        $this->assertSessionHasKey($this->stateKey((int)$user->id, (int)$content->id));

        $state = Hash::get($_SESSION, $this->stateKey((int)$user->id, (int)$content->id));
        $this->assertSame([(int)$question1->id, (int)$question2->id], $state['question_id_list']);
        $this->assertSame('sort', $state['settings']['order']);
        $this->assertSame(5, $state['settings']['timelimit']);

        $this->persistSession();
        $this->get("/contents-questions/practice-run/{$content->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('A1');
        $this->assertResponseContains('A2');
        $this->assertResponseNotContains('B1');
        $this->assertResponseContains("parseInt('5') * 60");
    }

    /**
     * 負数・範囲外・非数値の出題数はエラー
     */
    public function testPracticeStartRejectsInvalidCount(): void
    {
        [$user, $content] = $this->prepareContent();
        $this->createQuestion((int)$content->id, 'A1', '第1章');
        $this->createQuestion((int)$content->id, 'A2', '第1章');
        $this->createQuestion((int)$content->id, 'A3', '第1章');

        foreach (['-1', '99', 'abc'] as $count) {
            $this->postPractice((int)$content->id, ['question_count' => $count]);

            $this->assertResponseOk();
            $this->assertResponseContains('出題数');
            $this->assertSessionNotHasKey($this->stateKey((int)$user->id, (int)$content->id));
        }
    }

    /**
     * 上限超過・非数値・上限設定時の 0 はエラー
     */
    public function testPracticeStartRejectsInvalidTimelimit(): void
    {
        [$user, $content] = $this->prepareContent(true, 10);
        $this->createQuestion((int)$content->id, 'A1', '第1章');

        foreach (['11', 'abc', '0'] as $timelimit) {
            $this->postPractice((int)$content->id, ['timelimit' => $timelimit]);

            $this->assertResponseOk();
            $this->assertResponseContains('制限時間');
            $this->assertSessionNotHasKey($this->stateKey((int)$user->id, (int)$content->id));
        }
    }

    /**
     * 存在しないカテゴリはエラー
     */
    public function testPracticeStartRejectsUnknownCategory(): void
    {
        [$user, $content] = $this->prepareContent();
        $this->createQuestion((int)$content->id, 'A1', '第1章');

        $otherContent = $this->createTestContent((int)$content->course_id, (int)$content->user_id);
        $this->createQuestion((int)$otherContent->id, 'OTHER', '他コンテンツ');

        foreach (['第9章', '他コンテンツ'] as $category) {
            $this->postPractice((int)$content->id, ['categories' => [$category]]);

            $this->assertResponseOk();
            $this->assertResponseContains('存在しないカテゴリ');
            $this->assertSessionNotHasKey($this->stateKey((int)$user->id, (int)$content->id));
        }
    }

    /**
     * 列挙値以外の出題順・不正解時の表示はエラー
     */
    public function testPracticeStartRejectsInvalidOrderAndWrongMode(): void
    {
        [$user, $content] = $this->prepareContent();
        $this->createQuestion((int)$content->id, 'A1', '第1章');

        $this->postPractice((int)$content->id, ['order' => 'foo']);
        $this->assertResponseOk();
        $this->assertResponseContains('出題順');

        $this->postPractice((int)$content->id, ['wrong_mode' => '9']);
        $this->assertResponseOk();
        $this->assertResponseContains('不正解時の表示');
        $this->assertSessionNotHasKey($this->stateKey((int)$user->id, (int)$content->id));
    }

    // =========================================================================
    // 受験画面
    // =========================================================================

    /**
     * 保存した出題リスト・順序で表示される
     */
    public function testPracticeRunUsesStoredQuestionList(): void
    {
        [$user, $content] = $this->prepareContent();
        $question1 = $this->createQuestion((int)$content->id, 'A1', '第1章', '1', 10, 1);
        $question2 = $this->createQuestion((int)$content->id, 'A2', '第1章', '1', 10, 2);

        $this->setPracticeSession((int)$user->id, (int)$content->id, 'state', [
            'settings' => [
                'categories' => ['第1章'],
                'question_count' => 2,
                'timelimit' => 0,
                'order' => 'sort',
                'wrong_mode' => 2,
                'available_count' => 2,
            ],
            'question_id_list' => [(int)$question2->id, (int)$question1->id],
            'started_at' => time(),
        ]);

        $this->get("/contents-questions/practice-run/{$content->id}");

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        $this->assertLessThan(strpos($body, 'A1'), strpos($body, 'A2'));
        $this->assertResponseContains('name="question_id_list"');
        $this->assertResponseContains($question2->id . ',' . $question1->id);
    }

    /**
     * 状態がない場合は設定画面へ
     */
    public function testPracticeRunRedirectsWithoutState(): void
    {
        [, $content] = $this->prepareContent();
        $this->createQuestion((int)$content->id, 'A1', '第1章');

        $this->get("/contents-questions/practice-run/{$content->id}");

        $this->assertRedirectContains('/contents-questions/practice/' . $content->id);
    }

    /**
     * 24 時間を超えた状態は破棄される
     */
    public function testPracticeRunExpiresStaleState(): void
    {
        [$user, $content] = $this->prepareContent();
        $question = $this->createQuestion((int)$content->id, 'A1', '第1章');

        $this->setPracticeSession((int)$user->id, (int)$content->id, 'state', [
            'settings' => [
                'categories' => ['第1章'],
                'question_count' => 1,
                'timelimit' => 0,
                'order' => 'random',
                'wrong_mode' => 2,
                'available_count' => 1,
            ],
            'question_id_list' => [(int)$question->id],
            'started_at' => time() - (24 * 60 * 60) - 1,
        ]);

        $this->get("/contents-questions/practice-run/{$content->id}");

        $this->assertRedirectContains('/contents-questions/practice/' . $content->id);
        $this->assertSessionNotHasKey($this->stateKey((int)$user->id, (int)$content->id));
    }

    /**
     * 別ユーザの状態・結果は参照できない（キーにユーザIDを含む）
     */
    public function testPracticeStateIsUserScoped(): void
    {
        $userA = $this->loginAsUser('usera');
        $course = $this->createCourse((int)$userA->id);
        $this->enrollUser((int)$userA->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id, (int)$userA->id);
        $this->createQuestion((int)$content->id, 'A1', '第1章');

        $this->postPractice((int)$content->id, ['categories' => ['第1章']]);
        $this->assertSessionHasKey($this->stateKey((int)$userA->id, (int)$content->id));
        $this->persistSession();

        $userB = $this->createUser('userb');
        $this->enrollUser((int)$userB->id, (int)$course->id);
        $this->loginAs($userB);

        $this->get("/contents-questions/practice-run/{$content->id}");

        $this->assertRedirectContains('/contents-questions/practice/' . $content->id);
        $this->assertSessionNotHasKey($this->stateKey((int)$userB->id, (int)$content->id));
        $this->assertSessionHasKey($this->stateKey((int)$userA->id, (int)$content->id));
    }

    // =========================================================================
    // 採点・結果
    // =========================================================================

    /**
     * 採点しても ib_records / ib_records_questions は増えない
     */
    public function testPracticeGradeSavesNoRecord(): void
    {
        [$user, $content] = $this->prepareContent();
        $question1 = $this->createQuestion((int)$content->id, 'A1', '第1章', '1', 10, 1);
        $question2 = $this->createQuestion((int)$content->id, 'A2', '第1章', '1', 10, 2);

        $this->postPractice((int)$content->id, ['categories' => ['第1章'], 'question_count' => '2']);
        $state = Hash::get($_SESSION, $this->stateKey((int)$user->id, (int)$content->id));
        $this->persistSession();

        $this->post("/contents-questions/practice-grade/{$content->id}", [
            'question_id_list' => implode(',', $state['question_id_list']),
            'answer_' . $question1->id => '1',
            'answer_' . $question2->id => '1',
            'ContentsQuestion' => ['study_sec' => 30],
        ]);

        $this->assertRedirectContains('/contents-questions/practice-result/' . $content->id);
        $this->assertSame(0, $this->getTableLocator()->get('Records')->find()->count());
        $this->assertSame(0, $this->getTableLocator()->get('RecordsQuestions')->find()->count());
        $this->assertSessionHasKey($this->resultKey((int)$user->id, (int)$content->id));
        $this->assertSessionNotHasKey($this->stateKey((int)$user->id, (int)$content->id));
    }

    /**
     * 状態と異なる出題リストでは採点しない
     */
    public function testPracticeGradeRejectsTamperedQuestionList(): void
    {
        [$user, $content] = $this->prepareContent();
        $question1 = $this->createQuestion((int)$content->id, 'A1', '第1章', '1', 10, 1);
        $question2 = $this->createQuestion((int)$content->id, 'A2', '第1章', '1', 10, 2);

        $this->postPractice((int)$content->id, ['categories' => ['第1章'], 'question_count' => '2']);
        $state = Hash::get($_SESSION, $this->stateKey((int)$user->id, (int)$content->id));
        $this->persistSession();

        $tampered = array_reverse($state['question_id_list']);

        $this->post("/contents-questions/practice-grade/{$content->id}", [
            'question_id_list' => implode(',', $tampered),
            'answer_' . $question1->id => '1',
            'answer_' . $question2->id => '1',
            'ContentsQuestion' => ['study_sec' => 30],
        ]);

        $this->assertRedirectContains('/contents-questions/practice/' . $content->id);
        $this->assertSessionNotHasKey($this->resultKey((int)$user->id, (int)$content->id));
        $this->assertSame(0, $this->getTableLocator()->get('Records')->find()->count());
    }

    /**
     * 単一選択・複数選択の得点と正解数が正しい
     */
    public function testPracticeGradeComputesScore(): void
    {
        [$user, $content] = $this->prepareContent();
        $question1 = $this->createQuestion((int)$content->id, 'A1', '第1章', '1', 10, 1);
        $question2 = $this->createQuestion((int)$content->id, 'A2', '第1章', '1,2', 20, 2);
        $question3 = $this->createQuestion((int)$content->id, 'A3', '第1章', '1', 10, 3);

        $this->postPractice((int)$content->id, ['categories' => ['第1章'], 'question_count' => '3']);
        $state = Hash::get($_SESSION, $this->stateKey((int)$user->id, (int)$content->id));
        $this->persistSession();

        $this->post("/contents-questions/practice-grade/{$content->id}", [
            'question_id_list' => implode(',', $state['question_id_list']),
            'answer_' . $question1->id => '1',
            'answer_' . $question2->id => ['1', '2'],
            'answer_' . $question3->id => '2',
            'ContentsQuestion' => ['study_sec' => 30],
        ]);

        $result = Hash::get($_SESSION, $this->resultKey((int)$user->id, (int)$content->id));
        $this->assertSame(30, $result['my_score']);
        $this->assertSame(40, $result['full_score']);
        $this->assertSame(2, $result['correct_count']);
        $this->assertSame(3, $result['question_count']);
    }

    /**
     * 結果画面に得点・正解数・解説が表示される
     */
    public function testPracticeResultShowsScoreAndExplanations(): void
    {
        [$user, $content] = $this->prepareContent();
        $question1 = $this->createQuestion((int)$content->id, 'A1', '第1章', '1', 10, 1, '正解の解説です');
        $question2 = $this->createQuestion((int)$content->id, 'A2', '第1章', '1', 10, 2, '不正解の解説です');

        $this->postPractice((int)$content->id, [
            'categories' => ['第1章'],
            'question_count' => '2',
            'wrong_mode' => '1',
        ]);
        $state = Hash::get($_SESSION, $this->stateKey((int)$user->id, (int)$content->id));
        $this->persistSession();

        $this->post("/contents-questions/practice-grade/{$content->id}", [
            'question_id_list' => implode(',', $state['question_id_list']),
            'answer_' . $question1->id => '1',
            'answer_' . $question2->id => '2',
            'ContentsQuestion' => ['study_sec' => 30],
        ]);
        $this->persistSession();

        $this->get("/contents-questions/practice-result/{$content->id}");

        $this->assertResponseOk();
        $this->assertResponseContains('練習結果（記録されません）');
        $this->assertResponseContains('10 / 20');
        $this->assertResponseContains('1 / 2');
        $this->assertResponseContains('正解の解説です');
        $this->assertResponseContains('不正解の解説です');
        $this->assertResponseContains('正解');
        $this->assertResponseContains('不正解');
    }

    /**
     * 結果がない場合は設定画面へ
     */
    public function testPracticeResultRedirectsWithoutResult(): void
    {
        [, $content] = $this->prepareContent();
        $this->createQuestion((int)$content->id, 'A1', '第1章');

        $this->get("/contents-questions/practice-result/{$content->id}");

        $this->assertRedirectContains('/contents-questions/practice/' . $content->id);
    }

    /**
     * 結果画面の「もう一度練習」用に、設定画面が直前の設定を引き継ぐ
     */
    public function testPracticeSettingsPrefillsPreviousResult(): void
    {
        [$user, $content] = $this->prepareContent();
        $question = $this->createQuestion((int)$content->id, 'A1', '第1章');

        $this->setPracticeSession((int)$user->id, (int)$content->id, 'result', [
            'settings' => [
                'categories' => ['第1章'],
                'question_count' => 1,
                'timelimit' => 0,
                'order' => 'sort',
                'wrong_mode' => 1,
                'available_count' => 1,
            ],
            'question_id_list' => [(int)$question->id],
            'details' => [],
            'full_score' => 0,
            'my_score' => 0,
            'correct_count' => 0,
            'question_count' => 0,
            'study_sec' => 0,
            'finished_at' => time(),
        ]);

        $this->get("/contents-questions/practice/{$content->id}");

        $this->assertResponseOk();
        $this->assertResponseRegExp('/name="order"[^>]*value="sort"[^>]*checked/');
        $this->assertResponseRegExp('/name="timelimit"[^>]*>.*<option value="0" selected/s');
    }

    /**
     * 正式受験は従来どおり記録される（回帰）
     */
    public function testOfficialTestStillSavesRecord(): void
    {
        [, $content] = $this->prepareContent();
        $question = $this->createQuestion((int)$content->id, 'A1', '第1章', '1', 10, 1);

        $this->post("/contents-questions/index/{$content->id}", [
            'question_id_list' => (string)$question->id,
            'answer_' . $question->id => '1',
            'ContentsQuestion' => ['study_sec' => 60],
        ]);

        $this->assertRedirect();
        $this->assertSame(1, $this->getTableLocator()->get('Records')->find()->count());
    }
}
