<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Admin GroupsController の統合テスト
 *
 * GroupsController::add() は edit() に委譲するが、
 * edit() 内で (int)null = 0 → $groupsTable->get(0) を呼び出すため、
 * 新規追加時に RecordNotFoundException (404) になる既知のバグがある。
 */
class GroupsControllerTest extends TestCase
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
     * テスト用グループを作成
     */
    private function createGroup(string $title): EntityInterface
    {
        $groupsTable = $this->getTableLocator()->get('Groups');
        $entity = $groupsTable->newEntity([
            'title' => $title,
            'comment' => $title . 'のコメント',
        ]);
        $result = $groupsTable->save($entity);
        $this->assertNotFalse($result, 'グループの保存に失敗');

        return $result;
    }

    /**
     * 未認証アクセステスト
     */
    public function testUnauthenticatedAccess(): void
    {
        $this->get('/admin/groups');
        $this->assertRedirect();
    }

    /**
     * 一覧表示テスト
     */
    public function testIndex(): void
    {
        $this->loginAsAdmin();
        $this->createGroup('テストグループA');

        $this->get('/admin/groups');
        $this->assertResponseOk();
        $this->assertResponseContains('テストグループA');
    }

    /**
     * 追加(add) GET テスト
     *
     * add() は edit() に委譲し、GET 時は newEmptyEntity() を使うため 200。
     */
    public function testAddGet(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/groups/add');
        $this->assertResponseOk();
    }

    /**
     * 追加(add) POST テスト
     *
     * 新規グループが DB に保存され、一覧へリダイレクトされること。
     * 以前は add() → edit() → get((int)null) = get(0) で 404 になるバグが
     * あったが、edit() の POST 分岐を id の有無で分岐するよう修正済み。
     */
    public function testAddPost(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/groups/add', [
            'title' => '新規グループ',
            'courses' => ['_ids' => []],
            'comment' => '新規グループのコメント',
        ]);

        $this->assertResponseCode(302);

        $groupsTable = $this->getTableLocator()->get('Groups');
        $this->assertTrue(
            $groupsTable->exists(['title' => '新規グループ']),
            'add でグループが DB に保存されること',
        );
    }

    /**
     * 編集(edit) POST テスト
     */
    public function testEditPost(): void
    {
        $this->loginAsAdmin();
        $group = $this->createGroup('元のグループ名');

        $this->post("/admin/groups/edit/{$group->id}", [
            'id' => $group->id,
            'title' => '更新後のグループ名',
            'courses' => ['_ids' => []],
            'comment' => '更新後のコメント',
        ]);
        $this->assertRedirect();

        $updated = $this->getTableLocator()->get('Groups')->get((int)$group->id);
        $this->assertSame('更新後のグループ名', $updated->title);
    }

    /**
     * 削除(delete) POST テスト
     */
    public function testDeletePost(): void
    {
        $this->loginAsAdmin();
        $group = $this->createGroup('削除対象グループ');
        $groupId = (int)$group->id;

        $this->post("/admin/groups/delete/{$groupId}");
        $this->assertRedirect();

        $exists = $this->getTableLocator()->get('Groups')->exists(['id' => $groupId]);
        $this->assertFalse($exists, 'グループが DB から削除されていない');
    }

    /**
     * 検索テスト（Groups に.keyword 機能はないが、index が正常に返ること）
     */
    public function testIndexWithMultipleGroups(): void
    {
        $this->loginAsAdmin();
        $this->createGroup('グループA');
        $this->createGroup('グループB');

        $this->get('/admin/groups');
        $this->assertResponseOk();
        $this->assertResponseContains('グループA');
        $this->assertResponseContains('グループB');
    }

    /**
     * 編集(edit) GET テスト（存在しないグループ）
     */
    public function testEditGetNotFound(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/groups/edit/99999');
        $this->assertResponseCode(404);
    }

    /**
     * 存在しないグループの削除 → 404
     */
    public function testDeleteNotFound(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/groups/delete/99999');
        $this->assertResponseCode(404);
    }

    /**
     * 追加(add) POST — タイトル空でバリデーションエラー（フォーム再表示 200）
     */
    public function testAddValidationErrors(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/groups/add', [
            'title' => '',
            'courses' => ['_ids' => []],
            'comment' => '',
        ]);
        $this->assertResponseOk();
    }

    /**
     * 編集(edit) POST — タイトル空でバリデーションエラー（フォーム再表示 200）
     */
    public function testEditValidationErrors(): void
    {
        $this->loginAsAdmin();
        $group = $this->createGroup('バリデーションテスト');

        $this->post("/admin/groups/edit/{$group->id}", [
            'id' => $group->id,
            'title' => '',
            'courses' => ['_ids' => []],
            'comment' => '',
        ]);
        $this->assertResponseOk();
    }

    /**
     * グループ編集フォームの受講コース欄が複数選択できること
     */
    public function testEditFormCourseSelectIsMultiple(): void
    {
        $this->loginAsAdmin();
        $group = $this->createGroup('複数選択グループ');

        $this->get("/admin/groups/edit/{$group->id}");
        $this->assertResponseOk();

        $html = $this->_getBodyAsString();
        preg_match('/<select[^>]*name="courses\[_ids\]\[\]"[^>]*>/i', $html, $matches);
        $this->assertNotEmpty($matches, '受講コースの select が出力されていない');
        $this->assertStringContainsString('multiple', $matches[0], '受講コースは複数選択できること');
        $this->assertStringContainsString('id="courses-ids"', $matches[0], 'courses-ids の id が付与されること');
    }

    /**
     * グループ編集でコースを割り当てると ib_groups_courses に保存されること
     *
     * 以前はフォームの「Course」フィールドが ORM の courses._ids 形式に
     * 変換されず、コースの割り当てが保存されなかった（ログインしてもコースが出ない）。
     */
    public function testEditPostSavesCourseAssignment(): void
    {
        $admin = $this->loginAsAdmin();
        $group = $this->createGroup('コース割当グループ');

        $coursesTable = $this->getTableLocator()->get('Courses');
        $course = $coursesTable->save($coursesTable->newEntity([
            'title' => '割当テストコース',
            'user_id' => $admin->id,
        ]));
        $this->assertNotFalse($course, 'コースの保存に失敗');

        $this->post("/admin/groups/edit/{$group->id}", [
            'id' => $group->id,
            'title' => 'コース割当グループ',
            'courses' => ['_ids' => [$course->id]],
            'comment' => '',
        ]);
        $this->assertRedirect();

        $this->assertTrue(
            $this->getTableLocator()->get('GroupsCourses')
                ->exists(['group_id' => $group->id, 'course_id' => $course->id]),
            'グループにコースが紐付くこと',
        );
    }

    /**
     * グループ編集フォームで既存のコース割当が選択済みで表示されること
     *
     * コントローラの get() に contain(['Courses']) が無いと、courses._ids の
     * 現在値が取得できず、そのまま保存すると既存の割当が消える（データロス）。
     * その回帰ガード。
     */
    public function testEditFormPreselectsAssignedCourses(): void
    {
        $admin = $this->loginAsAdmin();
        $group = $this->createGroup('プリセレクトグループ');

        $coursesTable = $this->getTableLocator()->get('Courses');
        $course = $coursesTable->save($coursesTable->newEntity([
            'title' => 'プリセレクトコース',
            'user_id' => $admin->id,
        ]));
        $this->assertNotFalse($course, 'コースの保存に失敗');

        $groupsTable = $this->getTableLocator()->get('Groups');
        $group = $groupsTable->patchEntity($group, ['courses' => ['_ids' => [$course->id]]]);
        $this->assertNotFalse($groupsTable->save($group), 'グループのコース割当保存に失敗');

        $this->get("/admin/groups/edit/{$group->id}");
        $this->assertResponseOk();

        $html = $this->_getBodyAsString();
        preg_match('/<option value="' . $course->id . '"[^>]*>/i', $html, $matches);
        $this->assertNotEmpty($matches, 'コースの option が出力されていない');
        $this->assertStringContainsString('selected', $matches[0], '既存のコース割当が選択済みであること');
    }

    // ----------------------------------------------------------------
    // D-03 demo_mode 回帰テスト
    // ----------------------------------------------------------------

    /**
     * D-03: edit アクションに demo_mode ガードが存在すること
     *
     * IntegrationTestTrait 経由のリクエストでは Application::bootstrap() が
     * Configure::load('ib_config') を呼び、demo_mode を false にリセットするため、
     * ソースコードにガードが存在することを確認する。
     */
    public function testEditHasDemoModeGuard(): void
    {
        $source = file_get_contents(ROOT . '/src/Controller/Admin/GroupsController.php');
        $this->assertStringContainsString("Configure::read('demo_mode')", $source, 'edit に demo_mode ガードが存在すること');
    }

    /**
     * D-03: delete アクションに demo_mode ガードが存在すること
     */
    public function testDeleteHasDemoModeGuard(): void
    {
        $source = file_get_contents(ROOT . '/src/Controller/Admin/GroupsController.php');
        // delete メソッド内に Configure::read('demo_mode') が含まれること
        preg_match('/public function delete\b.*?\}/s', $source, $deleteMatch);
        $this->assertNotEmpty($deleteMatch, 'delete メソッドが見つからない');
        $this->assertStringContainsString("Configure::read('demo_mode')", $deleteMatch[0], 'delete に demo_mode ガードが存在すること');
    }
}
