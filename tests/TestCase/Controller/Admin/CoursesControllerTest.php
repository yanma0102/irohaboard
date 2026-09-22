<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Admin CoursesController の統合テスト
 *
 * CoursesController::add() は edit() に委譲するが、
 * edit() 内で (int)null = 0 → $coursesTable->get(0) を呼び出すため、
 * 新規追加時に RecordNotFoundException (404) になる既知のバグがある。
 */
class CoursesControllerTest extends TestCase
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
     *
     * Users テーブルに admin ロールのユーザーを作成し、
     * セッションに Authentication 認証情報を直接注入する。
     * Session 認証子はセッションキー 'Auth' から Identity を復元するため、
     * そこにユーザー情報をセットすれば認証済み状態になる。
     */
    private function loginAsAdmin(): \Cake\Datasource\EntityInterface
    {
        $admin = $this->createAdminUser();

        // Authentication.Session 認証子が読むセッションキー 'Auth' に直接書き込み
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
     * 未認証アクセステスト
     *
     * 管理画面未認証でアクセスするとログインページへリダイレクトされること
     */
    public function testUnauthenticatedAccess(): void
    {
        $this->get('/admin/courses');
        $this->assertRedirect();
    }

    /**
     * 一覧表示テスト
     *
     * admin ログイン状態で GET /admin/courses → 200。
     * 保存済みコースのタイトルがレスポンス本文に含まれること。
     */
    public function testIndex(): void
    {
        $admin = $this->loginAsAdmin();
        $this->createCourse('CakePHP入門コース', (int)$admin->id);

        $this->get('/admin/courses');
        $this->assertResponseOk();
        $this->assertResponseContains('CakePHP入門コース');
    }

    /**
     * 検索テスト
     *
     * GET /admin/courses?keyword=<一部> → 該当コースのみ含まれ、非該当が含まれないこと。
     * G-1 の回帰テスト。
     */
    public function testSearch(): void
    {
        $admin = $this->loginAsAdmin();
        $this->createCourse('PHP入門コース', (int)$admin->id);
        $this->createCourse('JavaScript基礎コース', (int)$admin->id);

        $this->get('/admin/courses?keyword=PHP');
        $this->assertResponseOk();
        $this->assertResponseContains('PHP入門コース');
        $this->assertResponseNotContains('JavaScript基礎コース');
    }

    /**
     * 検索テスト（非該当を含まない）
     */
    public function testSearchNoResults(): void
    {
        $admin = $this->loginAsAdmin();
        $this->createCourse('Python入門コース', (int)$admin->id);

        $this->get('/admin/courses?keyword=Rust');
        $this->assertResponseOk();
        $this->assertResponseNotContains('Python入門コース');
    }

    /**
     * 追加(add) POST テスト
     *
     * 新規コースが DB に保存され、一覧へリダイレクトされること。
     * 以前は add() → edit() → get((int)null) = get(0) で 404 になるバグが
     * あったが、edit() の POST 分岐を id の有無で分岐するよう修正済み。
     */
    public function testAddPost(): void
    {
        $admin = $this->loginAsAdmin();

        $this->post('/admin/courses/add', [
            'title' => '新規コース',
            'introduction' => '新規コースの紹介文',
            'comment' => '新規コースのコメント',
        ]);

        $this->assertResponseCode(302);

        $coursesTable = $this->getTableLocator()->get('Courses');
        $course = $coursesTable->find()->where(['title' => '新規コース'])->first();
        $this->assertNotNull($course, 'add でコースが DB に保存されること');
        $this->assertSame((int)$admin->id, (int)$course->user_id, '作成者が記録されること');
    }

    /**
     * 編集(edit) POST テスト
     *
     * タイトル変更が DB に反映されること。
     */
    public function testEditPost(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('元のコース名', (int)$admin->id);

        $this->post("/admin/courses/edit/{$course->id}", [
            'id' => $course->id,
            'title' => '更新後のコース名',
            'introduction' => '更新後の紹介文',
            'comment' => '更新後のコメント',
        ]);
        $this->assertRedirect();

        // DB 確認
        $updated = $this->getTableLocator()->get('Courses')->get((int)$course->id);
        $this->assertSame('更新後のコース名', $updated->title);
    }

    /**
     * 削除(delete) POST テスト
     *
     * 該当コースが DB から削除されること。
     */
    public function testDeletePost(): void
    {
        $admin = $this->loginAsAdmin();
        $course = $this->createCourse('削除対象コース', (int)$admin->id);
        $courseId = (int)$course->id;

        $this->post("/admin/courses/delete/{$courseId}");
        $this->assertRedirect();

        // DB 確認
        $exists = $this->getTableLocator()->get('Courses')->exists(['id' => $courseId]);
        $this->assertFalse($exists, 'コースが DB から削除されていない');
    }

    /**
     * 編集(edit) GET テスト（存在しないコース）
     *
     * 存在しないコースの編集ページにアクセス → 404 になること。
     */
    public function testEditGetNotFound(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/courses/edit/99999');
        $this->assertResponseCode(404);
    }
}
