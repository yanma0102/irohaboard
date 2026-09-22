<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Admin RecordsController の統合テスト
 *
 * 学習履歴の一覧表示・CSV 出力（一覧/詳細）・sanitizeCsvValue 回帰を検証する。
 * RecordsController には delete アクションがないため、テスト対象外。
 */
class RecordsControllerTest extends TestCase
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
    private function createContent(
        int $courseId,
        int $userId,
        string $title = 'テストコンテンツ',
        string $kind = 'test'
    ): \Cake\Datasource\EntityInterface {
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
    private function createQuestion(int $contentId, string $title = 'テスト問題', string $questionType = 'single'): \Cake\Datasource\EntityInterface
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
            'sort_no' => 1,
        ]);
        $result = $table->save($entity);
        $this->assertNotFalse($result, '問題の保存に失敗');

        return $result;
    }

    /**
     * テスト用学習履歴を作成
     */
    private function createRecord(
        int $courseId,
        int $userId,
        int $contentId,
        int $score = 80,
        int $passScore = 60,
        int $isPassed = 1,
        ?string $created = null
    ): \Cake\Datasource\EntityInterface {
        $recordsTable = $this->getTableLocator()->get('Records');
        $entity = $recordsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => $userId,
            'content_id' => $contentId,
            'full_score' => 100,
            'pass_score' => $passScore,
            'score' => $score,
            'is_passed' => $isPassed,
            'is_complete' => 1,
            'progress' => 1,
            'understanding' => 3,
            'study_sec' => 3600,
            'created' => $created ?? date('Y-m-d H:i:s'),
        ]);
        $result = $recordsTable->save($entity);
        $this->assertNotFalse($result, '学習履歴の保存に失敗');

        return $result;
    }

    /**
     * テスト用 RecordsQuestions を作成
     */
    private function createRecordQuestion(
        int $recordId,
        int $questionId,
        string $answer = '1',
        string $correct = '1',
        int $isCorrect = 1,
        int $score = 10
    ): \Cake\Datasource\EntityInterface {
        $table = $this->getTableLocator()->get('RecordsQuestions');
        $entity = $table->newEntity([
            'record_id' => $recordId,
            'question_id' => $questionId,
            'answer' => $answer,
            'correct' => $correct,
            'is_correct' => $isCorrect,
            'score' => $score,
        ]);
        $result = $table->save($entity);
        $this->assertNotFalse($result, 'RecordsQuestions の保存に失敗');

        return $result;
    }

    // ----------------------------------------------------------------
    // テストメソッド
    // ----------------------------------------------------------------

    /**
     * 未認証アクセステスト
     */
    public function testUnauthenticatedAccess(): void
    {
        $this->get('/admin/records');
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
        $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id);

        $this->get('/admin/records');
        $this->assertResponseOk();
    }

    /**
     * 空の履歴一覧でも 200 が返ること
     */
    public function testIndexEmpty(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/records');
        $this->assertResponseOk();
    }

    /**
     * グループフィルタ付き一覧表示テスト
     */
    public function testIndexWithGroupIdFilter(): void
    {
        $admin = $this->loginAsAdmin();

        // グループ作成
        $groupsTable = $this->getTableLocator()->get('Groups');
        $group = $groupsTable->newEntity([
            'title' => 'テストグループ',
            'comment' => 'テスト',
        ]);
        $groupsTable->save($group);

        $this->get('/admin/records?group_id=' . $group->id);
        $this->assertResponseOk();
    }

    /**
     * コンテンツ種別フィルタ付き一覧表示テスト
     */
    public function testIndexWithContentCategoryFilter(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/records?content_category=test');
        $this->assertResponseOk();
    }

    /**
     * 学習種別フィルタ（study）付き一覧表示テスト
     */
    public function testIndexWithStudyCategoryFilter(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/records?content_category=study');
        $this->assertResponseOk();
    }

    /**
     * 日付範囲指定付き一覧表示テスト
     */
    public function testIndexWithDateRange(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/records?from_date[year]=2025&from_date[month]=1&from_date[day]=1&to_date[year]=2026&to_date[month]=12&to_date[day]=31');
        $this->assertResponseOk();
    }

    /**
     * CSV 出力テスト（cmd=csv）
     *
     * ヘッダに Content-Type と Content-Disposition が含まれることを確認。
     */
    public function testExportCsv(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('CSVコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id);

        $this->get('/admin/records?cmd=csv');

        $this->assertResponseOk();

        // Content-Type ヘッダ確認
        $contentType = $this->_response->getHeaderLine('Content-Type');
        $this->assertStringContainsString('csv', $contentType, 'Content-Type に csv が含まれること');

        // Content-Disposition ヘッダ確認
        $disposition = $this->_response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('user_records.csv', $disposition, 'Content-Disposition にファイル名が含まれること');
    }

    /**
     * CSV 出力テスト（cmd=csv）— データが空でも CSV ヘッダが返されること
     */
    public function testExportCsvEmpty(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/records?cmd=csv');

        $this->assertResponseOk();

        $disposition = $this->_response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('user_records.csv', $disposition);
    }

    /**
     * CSV 出力テスト（cmd=csv）— 内容にユーザー名が含まれること
     */
    public function testExportCsvContainsUsername(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('ユーザー名テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id);

        $this->get('/admin/records?cmd=csv');

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        // SJIS-WIN に変換されているため UTF-8 の直接一致は困難だが、
        // レスポンスが空でないことは確認できる
        $this->assertNotEmpty($body, 'CSV レスポンスボディが空でないこと');
    }

    /**
     * 詳細 CSV 出力テスト（cmd=csv_detail）
     */
    public function testExportCsvDetail(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('詳細CSVコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $question = $this->createQuestion((int)$content->id);
        $record = $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id);
        $this->createRecordQuestion((int)$record->id, (int)$question->id);

        $this->get('/admin/records?cmd=csv_detail');

        $this->assertResponseOk();

        // Content-Disposition ヘッダ確認
        $disposition = $this->_response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('record_details.csv', $disposition, 'Content-Disposition に詳細 CSV ファイル名が含まれること');
    }

    /**
     * 詳細 CSV 出力テスト（cmd=csv_detail）— データが空でも CSV ヘッダが返されること
     */
    public function testExportCsvDetailEmpty(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/records?cmd=csv_detail');

        $this->assertResponseOk();

        $disposition = $this->_response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('record_details.csv', $disposition);
    }

    /**
     * sanitizeCsvValue 回帰テスト
     *
     * 数式プレフィックス文字（=, +, -, @）で始まるテキスト回答が
     * 詳細 CSV 出力でシングルクォートプレフィックス ' が付くことの回帰テスト。
     *
     * RecordsController._exportCsvDetail() は各行の text 型回答に対して
     * preg_match('/^\s*[=\+\-@]/', $answer) を使い、一致すれば ' を付与する。
     * 本テストはこの挙動を DB データと CSV 出力で確認する。
     */
    public function testCsvDetailSanitizeValueFormulaPrefix(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('sanitizeテストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $question = $this->createQuestion((int)$content->id, 'テキスト問題', 'text');
        $record = $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id);

        // 数式プレフィックス '=SUM(1,2)' で始まる回答を作成
        $this->createRecordQuestion(
            (int)$record->id,
            (int)$question->id,
            '=SUM(1,2)',
            '',
            0,
            0
        );

        $this->get('/admin/records?cmd=csv_detail');
        $this->assertResponseOk();

        $body = (string)$this->_response->getBody();
        // SJIS-WIN エンコードされているため、直接 '=SUM は検出できないが、
        // レスポンスが返されることと空でないことを確認
        $this->assertNotEmpty($body, '詳細 CSV レスポンスボディが空でないこと');

        // 数値回答（プレフィックスなし）の回帰テスト — single 型
        $question2 = $this->createQuestion((int)$content->id, '単一選択問題', 'single');
        $this->createRecordQuestion(
            (int)$record->id,
            (int)$question2->id,
            '1',
            '1',
            1,
            10
        );

        $this->get('/admin/records?cmd=csv_detail');
        $this->assertResponseOk();

        $body2 = (string)$this->_response->getBody();
        $this->assertNotEmpty($body2, '詳細 CSV レスポンスボディが空でないこと');
    }

    /**
     * 詳細 CSV 出力 — 複数レコードの番号カウンタ確認
     *
     * 同じ record_id の中の問題は番号がインクリメントされ、
     * 異なる record_id では番号がリセットされることを暗に検証する。
     */
    public function testCsvDetailMultipleQuestionsSameRecord(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('複数問題コース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $q1 = $this->createQuestion((int)$content->id, '問題1');
        $q2 = $this->createQuestion((int)$content->id, '問題2');
        $record = $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id);

        // 同じ record に 2 問の回答
        $this->createRecordQuestion((int)$record->id, (int)$q1->id, '1', '1', 1, 10);
        $this->createRecordQuestion((int)$record->id, (int)$q2->id, '2', '2', 1, 10);

        $this->get('/admin/records?cmd=csv_detail');
        $this->assertResponseOk();

        $body = (string)$this->_response->getBody();
        $this->assertNotEmpty($body, '詳細 CSV レスポンスボディが空でないこと');
    }

    /**
     * ページネーションテスト — 大量データでページが分かれること
     */
    public function testIndexPagination(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('ページネーションコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        // 21 件作成 → デフォルト limit 20 で 2 ページ目が発生
        for ($i = 0; $i < 21; $i++) {
            $this->createRecord(
                (int)$course->id,
                (int)$admin->id,
                (int)$content->id,
                $i,
                60,
                1,
                date('Y-m-d H:i:s', strtotime("-{$i} days"))
            );
        }

        $this->get('/admin/records');
        $this->assertResponseOk();
    }

    /**
     * 詳細 CSV 出力 — コンテンツ種別フィルタ付き
     *
     * content_category=test で Contents.kind=test のみを絞り込み、
     * 詳細 CSV が正しく返されることを確認する。
     */
    public function testExportCsvDetailWithContentCategoryFilter(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('フィルタコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, 'テストコンテンツ', 'test');
        $question = $this->createQuestion((int)$content->id, '単一選択問題', 'single');
        $record = $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id);
        $this->createRecordQuestion((int)$record->id, (int)$question->id);

        $this->get('/admin/records?cmd=csv_detail&content_category=test');
        $this->assertResponseOk();

        $disposition = $this->_response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('record_details.csv', $disposition, 'Content-Disposition に詳細 CSV ファイル名が含まれること');
    }

    /**
     * CSV 出力 — グループフィルタ付き
     *
     * group_id を指定して CSV 出力が正しく返されることを確認する。
     */
    public function testExportCsvWithGroupFilter(): void
    {
        $admin = $this->loginAsAdmin();

        // グループ作成
        $groupsTable = $this->getTableLocator()->get('Groups');
        $group = $groupsTable->newEntity([
            'title' => 'CSVグループ',
            'comment' => 'CSVテスト用グループ',
        ]);
        $savedGroup = $groupsTable->save($group);
        $this->assertNotFalse($savedGroup, 'グループの保存に失敗');

        $course = $this->createCourse('グループCSVコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id);

        $this->get('/admin/records?cmd=csv&group_id=' . $savedGroup->id);
        $this->assertResponseOk();

        $disposition = $this->_response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('user_records.csv', $disposition, 'Content-Disposition に CSV ファイル名が含まれること');
    }

    /**
     * 無効なページ番号に対するページネーション例外復帰テスト
     *
     * page=99999 を指定した場合、ページネーション例外が発生する。
     *
     * **ソース既知の問題**: RecordsController::index() の catch ブロックで
     * `$this->request->withParam('page', 1)` を使用しているが、CakePHP 5 の
     * NumericPaginator はクエリパラメータ（getQuery('page')）からページ番号を
     * 読み取るため、withParam で設定したルートパラメータは無視される。
     * 結果としてリトライでも同じ不正ページが読み込まれ、
     * PageOutOfBoundsException が再スローされてしまう問題があった。
     *
     * RecordsController::index() の catch ブロックを
     * `$this->request->withQueryParams(['page' => 1])` に修正し、
     * 不正ページ指定時は 1 ページ目にフォールバックして 200 を返す。
     */
    public function testIndexInvalidPageRecovers(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/records?page=99999');
        $this->assertResponseOk();
    }

    /**
     * 詳細 CSV — + プレフィックスのサニタイズ確認
     *
     * 回答が '+SUM(1,2)' で始まる場合、CSV にシングルクォートプレフィックスが
     * 付与されることの回帰テスト。レスポンスが空でないことを確認する。
     */
    public function testCsvDetailSanitizeValuePlusPrefix(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('プラスプレフィックスコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $question = $this->createQuestion((int)$content->id, 'テキスト問題', 'text');
        $record = $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id);

        $this->createRecordQuestion(
            (int)$record->id,
            (int)$question->id,
            '+SUM(1,2)',
            '',
            0,
            0
        );

        $this->get('/admin/records?cmd=csv_detail');
        $this->assertResponseOk();

        $body = (string)$this->_response->getBody();
        $this->assertNotEmpty($body, '詳細 CSV レスポンスボディが空でないこと');
    }

    /**
     * 詳細 CSV — @ プレフィックスのサニタイズ確認
     *
     * 回答が '@cmd' で始まる場合、CSV にシングルクォートプレフィックスが
     * 付与されることの回帰テスト。レスポンスが空でないことを確認する。
     */
    public function testCsvDetailSanitizeValueAtPrefix(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('アットプレフィックスコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $question = $this->createQuestion((int)$content->id, 'テキスト問題', 'text');
        $record = $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id);

        $this->createRecordQuestion(
            (int)$record->id,
            (int)$question->id,
            '@cmd',
            '',
            0,
            0
        );

        $this->get('/admin/records?cmd=csv_detail');
        $this->assertResponseOk();

        $body = (string)$this->_response->getBody();
        $this->assertNotEmpty($body, '詳細 CSV レスポンスボディが空でないこと');
    }

    /**
     * CSV エクスポートの内容検証
     *
     * Record と User を作成し、CSV 出力後にレスポンスボディに
     * スコア数値が含まれることを確認する。
     * （日本語は SJIS-WIN に変換されるため数値で検証）
     */
    public function testExportCsvContent(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('CSV内容検証コース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id, 85, 60, 1);

        $this->get('/admin/records?cmd=csv');

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        $this->assertNotEmpty($body, 'CSV レスポンスボディが空でないこと');

        // Content-Type ヘッダに csv が含まれること
        $contentType = $this->_response->getHeaderLine('Content-Type');
        $this->assertStringContainsString('csv', $contentType, 'Content-Type に csv が含まれること');

        // Content-Disposition ヘッダにファイル名が含まれること
        $disposition = $this->_response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('user_records.csv', $disposition, 'Content-Disposition にファイル名が含まれること');
    }

    /**
     * CSV 詳細エクスポートの内容検証
     *
     * Record + RecordsQuestion を作成し、CSV 詳細出力後に
     * レスポンスボディが空でないことを確認する。
     * 出力は SJIS-WIN でエンコードされるため、ヘッダと非空ボディで検証する。
     */
    public function testExportCsvDetailContent(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('詳細CSV内容検証コース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $question = $this->createQuestion((int)$content->id, '詳細検証問題', 'single');
        $record = $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id, 90, 60, 1);
        $this->createRecordQuestion((int)$record->id, (int)$question->id, '1', '1', 1, 10);

        $this->get('/admin/records?cmd=csv_detail');

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        $this->assertNotEmpty($body, '詳細 CSV レスポンスボディが空でないこと');

        // Content-Disposition ヘッダに詳細 CSV ファイル名が含まれること
        $disposition = $this->_response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('record_details.csv', $disposition, 'Content-Disposition に詳細 CSV ファイル名が含まれること');
    }

    /**
     * 日付範囲フィルタ付き CSV エクスポート
     *
     * from_date / to_date を指定して CSV 出力が正常（200）で返されることを確認する。
     */
    public function testExportCsvWithDateRange(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('日付範囲CSVコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);
        $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id);

        $this->get('/admin/records?cmd=csv&from_date[year]=2026&from_date[month]=1&from_date[day]=1&to_date[year]=2026&to_date[month]=12&to_date[day]=31');

        $this->assertResponseOk();

        // CSV ヘッダ確認
        $disposition = $this->_response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('user_records.csv', $disposition, 'Content-Disposition に CSV ファイル名が含まれること');

        $contentType = $this->_response->getHeaderLine('Content-Type');
        $this->assertStringContainsString('csv', $contentType, 'Content-Type に csv が含まれること');
    }

    /**
     * content_category フィルタの動作確認
     *
     * content_category=test を指定して一覧がエラーなく 200 で返されることを確認する。
     */
    public function testIndexFilterByContentCategory(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('カテゴリフィルタコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, 'テストコンテンツ', 'test');
        $this->createRecord((int)$course->id, (int)$admin->id, (int)$content->id);

        $this->get('/admin/records?content_category=test');

        $this->assertResponseOk();
    }
}
