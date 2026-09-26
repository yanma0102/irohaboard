<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * D-29 回帰テスト: RecordsController::add() の学習記録保存
 *
 * JS 動的フォーム（_csrfToken + データのみ）で POST しても
 * FormProtection に拒否されず、ib_records にレコードが生成されることを検証する。
 * また、CSRF トークン無し/不正な POST は依然として拒否されることを担保する。
 */
class RecordsSaveTest extends TestCase
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
     * テスト用コンテンツを作成（学習コンテンツ）
     */
    private function createLearningContent(
        int $courseId,
        int $userId,
        int $status = 1,
    ): \Cake\Datasource\EntityInterface {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テスト学習コンテンツ',
            'kind' => 'learning',
            'status' => $status,
            'pass_rate' => 0,
            'question_count' => 0,
        ]);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'コンテンツの保存に失敗');

        return $result;
    }

    // =========================================================================
    // records/add アクションのテスト
    // =========================================================================

    /**
     * D-29: 正当な _csrfToken 付き POST で学習記録が保存されること
     *
     * JS 動的フォームと同じ形式（_csrfToken + データフィールドのみ、
     * _Token[fields] / _Token[unlocked] / _Token[debug] なし）で
     * POST しても FormProtection に拒否されず、302 リダイレクトが返されること。
     */
    public function testAddWithCsrfTokenSucceeds(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createLearningContent((int)$course->id, (int)$user->id);

        $this->post("/records/add/{$content->id}", [
            'is_complete' => 1,
            'study_sec' => 120,
            'understanding' => 3,
        ]);

        // ログインページへリダイレクトされないこと（= FormProtection に拒否されないこと）
        $this->assertRedirect();
        $this->assertDoesNotMatchRegularExpression('/\/users\/login/', (string)$this->_response->getHeaderLine('Location'));

        // ib_records にレコードが1件生成されること
        $recordsTable = $this->getTableLocator()->get('Records');
        $count = $recordsTable->find()->where([
            'content_id' => $content->id,
            'user_id' => $user->id,
        ])->count();
        $this->assertSame(1, $count, 'ib_records にレコードが1件も生成されない（D-29 の再発）');
    }

    /**
     * D-29: 保存された学習記録の各フィールド値が正しいこと
     */
    public function testAddRecordFieldValuesAreCorrect(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createLearningContent((int)$course->id, (int)$user->id);

        $this->post("/records/add/{$content->id}", [
            'is_complete' => 1,
            'study_sec' => 300,
            'understanding' => 5,
        ]);

        $this->assertRedirect();

        $recordsTable = $this->getTableLocator()->get('Records');
        $record = $recordsTable->find()->where([
            'content_id' => $content->id,
            'user_id' => $user->id,
        ])->first();

        $this->assertNotNull($record, 'レコードが存在しない');
        $this->assertSame((int)$user->id, (int)$record->user_id, 'user_id が一致しない');
        $this->assertSame((int)$course->id, (int)$record->course_id, 'course_id が一致しない');
        $this->assertSame((int)$content->id, (int)$record->content_id, 'content_id が一致しない');
        $this->assertSame(300, (int)$record->study_sec, 'study_sec が一致しない');
        $this->assertSame(5, (int)$record->understanding, 'understanding が一致しない');
        $this->assertSame(-1, (int)$record->is_passed, 'is_passed がデフォルト値 (-1) でない');
        $this->assertSame(1, (int)$record->is_complete, 'is_complete が一致しない');
    }

    /**
     * D-29: is_complete=0 の場合も正しく記録されること
     */
    public function testAddRecordWithIncomplete(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createLearningContent((int)$course->id, (int)$user->id);

        $this->post("/records/add/{$content->id}", [
            'is_complete' => 0,
            'study_sec' => 60,
            'understanding' => 2,
        ]);

        $this->assertRedirect();

        $recordsTable = $this->getTableLocator()->get('Records');
        $record = $recordsTable->find()->where([
            'content_id' => $content->id,
            'user_id' => $user->id,
        ])->first();

        $this->assertNotNull($record, 'レコードが存在しない');
        $this->assertSame(0, (int)$record->is_complete, 'is_complete が 0 でない');
        $this->assertSame(-1, (int)$record->is_passed, 'is_passed がデフォルト値 (-1) でない');
    }

    /**
     * D-29: CSRF トークン無しの POST は拒否されること（防御が弱まっていないことの担保）
     *
     * enableCsrfToken() を呼ばずに POST すると CsrfProtectionMiddleware により
     * 403 Forbidden (InvalidCsrfTokenException) が返されること。
     */
    public function testAddWithoutCsrfTokenIsRejected(): void
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

        // CSRF トークン・セキュリティトークンを有効にしない
        // （= _csrfToken なしで POST する）

        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createLearningContent((int)$course->id, (int)$user->id);

        $this->post("/records/add/{$content->id}", [
            'is_complete' => 1,
            'study_sec' => 120,
            'understanding' => 3,
        ]);

        // CsrfProtectionMiddleware により拒否されること (InvalidCsrfTokenException → 403)
        $this->assertResponseCode(403, 'CSRF トークンなしの POST が拒否されていない');

        // レコードが生成されないこと
        $recordsTable = $this->getTableLocator()->get('Records');
        $count = $recordsTable->find()->where([
            'content_id' => $content->id,
        ])->count();
        $this->assertSame(0, $count, 'CSRF トークンなしでレコードが生成されている');
    }

    /**
     * 不正な CSRF トークンの POST は拒否されること
     *
     * enableCsrfToken() で有効なトークンをセッションに設定した後、
     * POST ボディに異なる不正な _csrfToken を送信する。
     * CsrfProtectionMiddleware が cookie とボディのトークンを比較し、
     * 不一致で 403 Forbidden (InvalidCsrfTokenException) が返されること。
     */
    public function testAddWithInvalidCsrfTokenIsRejected(): void
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

        $course = $this->createCourse((int)$user->id);
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createLearningContent((int)$course->id, (int)$user->id);

        // 不正な CSRF トークンを POST ボディに送信
        // （enableCsrfToken が設定した正当な cookie とは異なる値）
        $this->post("/records/add/{$content->id}", [
            '_csrfToken' => 'completely_invalid_token_value',
            'is_complete' => 1,
            'study_sec' => 120,
            'understanding' => 3,
        ]);

        // 不正トークンにより拒否されること (InvalidCsrfTokenException → 403)
        $this->assertResponseCode(403, '不正な CSRF トークンが拒否されていない');

        // レコードが生成されないこと
        $recordsTable = $this->getTableLocator()->get('Records');
        $count = $recordsTable->find()->where([
            'content_id' => $content->id,
        ])->count();
        $this->assertSame(0, $count, '不正な CSRF トークンでレコードが生成されている');
    }

    /**
     * 存在しないコンテンツ ID で POST すると 404 が返されること
     */
    public function testAddWithNonexistentContentReturns404(): void
    {
        $this->loginAsUser();

        $this->post('/records/add/99999', [
            'is_complete' => 1,
            'study_sec' => 120,
            'understanding' => 3,
        ]);

        $this->assertResponseCode(404);
    }

    /**
     * コース未登録ユーザが POST すると 404 が返されること
     */
    public function testAddWithUnenrolledUserReturns404(): void
    {
        $user = $this->loginAsUser();
        $course = $this->createCourse((int)$user->id);
        // enrollUser を呼ばない
        $content = $this->createLearningContent((int)$course->id, (int)$user->id);

        $this->post("/records/add/{$content->id}", [
            'is_complete' => 1,
            'study_sec' => 120,
            'understanding' => 3,
        ]);

        $this->assertResponseCode(404);
    }

    /**
     * GET メソッドで /records/add にアクセスすると 405 が返されること
     */
    public function testAddGetMethodNotAllowed(): void
    {
        $this->loginAsUser();

        $this->get('/records/add/1');

        $this->assertResponseCode(405);
    }
}
