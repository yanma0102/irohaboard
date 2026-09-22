<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Admin ワークフロー統合テスト
 *
 * 複数ステップにわたる CRUD フローおよび Cross-entity フローを検証する。
 */
class WorkflowControllerTest extends TestCase
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
                'InfosGroups',
                'Infos',
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
     * 指定ロールのユーザーをログイン状態にする
     */
    private function loginAsUser(string $role = 'user'): \Cake\Datasource\EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $entity = $usersTable->newEntity([
            'username' => 'testuser' . ucfirst($role),
            'password' => 'userpass',
            'name' => 'テストユーザー',
            'role' => $role,
            'email' => $role . '@example.com',
        ]);
        $user = $usersTable->save($entity);
        $this->assertNotFalse($user, 'ユーザーの保存に失敗');

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
     * テスト用グループを作成
     */
    private function createGroup(string $title): \Cake\Datasource\EntityInterface
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
     * テスト用お知らせを作成
     */
    private function createInfo(int $userId, string $title = 'テストお知らせ'): \Cake\Datasource\EntityInterface
    {
        $infosTable = $this->getTableLocator()->get('Infos');
        $entity = $infosTable->newEntity([
            'title' => $title,
            'body' => $title . 'の本文',
            'user_id' => $userId,
        ]);
        $result = $infosTable->save($entity);
        $this->assertNotFalse($result, 'お知らせの保存に失敗');

        return $result;
    }

    /**
     * コースの完全 CRUD ワークフロー
     *
     * 追加 → 一覧確認 → 編集 → 一覧確認 → 削除 → 一覧確認
     */
    public function testCourseFullWorkflow(): void
    {
        $admin = $this->loginAsAdmin();

        // Step 1: 追加
        $this->post('/admin/courses/add', [
            'title' => 'ワークフローコース',
            'introduction' => 'ワークフローの紹介文',
            'comment' => 'ワークフローのコメント',
        ]);
        $this->assertResponseCode(302);

        // Step 2: 一覧で確認
        $coursesTable = $this->getTableLocator()->get('Courses');
        $course = $coursesTable->find()->where(['title' => 'ワークフローコース'])->first();
        $this->assertNotNull($course, 'コースがDBに保存されていること');

        $this->get('/admin/courses');
        $this->assertResponseOk();
        $this->assertResponseContains('ワークフローコース');

        // Step 3: 編集
        $this->post("/admin/courses/edit/{$course->id}", [
            'id' => $course->id,
            'title' => '更新後ワークフローコース',
            'introduction' => '更新後の紹介文',
            'comment' => '更新後のコメント',
        ]);
        $this->assertResponseCode(302);

        // Step 4: 更新確認
        $updated = $coursesTable->get((int)$course->id);
        $this->assertSame('更新後ワークフローコース', $updated->title);

        $this->get('/admin/courses');
        $this->assertResponseOk();
        $this->assertResponseContains('更新後ワークフローコース');

        // Step 5: 削除
        $this->post("/admin/courses/delete/{$course->id}");
        $this->assertResponseCode(302);

        // Step 6: 削除確認
        $this->assertFalse($coursesTable->exists(['id' => $course->id]), 'コースが削除されていること');

        $this->get('/admin/courses');
        $this->assertResponseOk();
        $this->assertResponseNotContains('更新後ワークフローコース');
    }

    /**
     * グループの完全 CRUD ワークフロー
     *
     * 追加 → 一覧確認 → 編集 → 一覧確認 → 削除 → 一覧確認
     */
    public function testGroupFullWorkflow(): void
    {
        $this->loginAsAdmin();

        // Step 1: 追加
        $this->post('/admin/groups/add', [
            'title' => 'ワークフローグループ',
            'Course' => [],
            'comment' => 'ワークフローのコメント',
        ]);
        $this->assertResponseCode(302);

        // Step 2: 一覧で確認
        $groupsTable = $this->getTableLocator()->get('Groups');
        $group = $groupsTable->find()->where(['title' => 'ワークフローグループ'])->first();
        $this->assertNotNull($group, 'グループがDBに保存されていること');

        $this->get('/admin/groups');
        $this->assertResponseOk();
        $this->assertResponseContains('ワークフローグループ');

        // Step 3: 編集
        $this->post("/admin/groups/edit/{$group->id}", [
            'id' => $group->id,
            'title' => '更新後ワークフローグループ',
            'Course' => [],
            'comment' => '更新後のコメント',
        ]);
        $this->assertResponseCode(302);

        // Step 4: 更新確認
        $updated = $groupsTable->get((int)$group->id);
        $this->assertSame('更新後ワークフローグループ', $updated->title);

        $this->get('/admin/groups');
        $this->assertResponseOk();
        $this->assertResponseContains('更新後ワークフローグループ');

        // Step 5: 削除
        $this->post("/admin/groups/delete/{$group->id}");
        $this->assertResponseCode(302);

        // Step 6: 削除確認
        $this->assertFalse($groupsTable->exists(['id' => $group->id]), 'グループが削除されていること');

        $this->get('/admin/groups');
        $this->assertResponseOk();
        $this->assertResponseNotContains('更新後ワークフローグループ');
    }

    /**
     * ユーザーの完全 CRUD ワークフロー
     *
     * 追加 → 一覧確認 → 編集 → 削除
     */
    public function testUserFullWorkflow(): void
    {
        $this->loginAsAdmin();

        // Step 1: 追加
        $this->post('/admin/users/add', [
            'User' => [
                'username' => 'wfuser',
                'new_password' => 'wfpass',
                'name' => 'ワークフローユーザー',
                'role' => 'user',
                'email' => 'wfuser@example.com',
                'Group' => [],
                'Course' => [],
                'comment' => 'テストコメント',
            ],
        ]);
        $this->assertResponseCode(302);

        // Step 2: 一覧で確認
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->find()->where(['username' => 'wfuser'])->first();
        $this->assertNotNull($user, 'ユーザーがDBに保存されていること');
        $this->assertSame('ワークフローユーザー', $user->name);

        $this->get('/admin/users');
        $this->assertResponseOk();
        $this->assertResponseContains('wfuser');

        // Step 3: 編集
        $this->post("/admin/users/edit/{$user->id}", [
            'User' => [
                'id' => $user->id,
                'username' => 'wfuser',
                'name' => '更新後ワークフローユーザー',
                'role' => 'user',
                'email' => 'wfuser_updated@example.com',
                'Group' => [],
                'Course' => [],
                'comment' => '更新コメント',
            ],
        ]);
        $this->assertResponseCode(302);

        // Step 4: 削除
        $this->post("/admin/users/delete/{$user->id}");
        $this->assertResponseCode(302);

        // Step 5: 削除確認
        $this->assertFalse($usersTable->exists(['id' => $user->id]), 'ユーザーが削除されていること');
    }

    /**
     * お知らせの完全 CRUD ワークフロー
     *
     * 追加 → 一覧確認 → 編集 → 削除
     */
    public function testInfoFullWorkflow(): void
    {
        $admin = $this->loginAsAdmin();

        // Step 1: 追加
        $this->post('/admin/infos/add', [
            'title' => 'ワークフローお知らせ',
            'body' => 'ワークフローお知らせの本文',
            'Group' => [],
        ]);
        $this->assertResponseCode(302);

        // Step 2: 一覧で確認
        $infosTable = $this->getTableLocator()->get('Infos');
        $info = $infosTable->find()->where(['title' => 'ワークフローお知らせ'])->first();
        $this->assertNotNull($info, 'お知らせがDBに保存されていること');

        $this->get('/admin/infos');
        $this->assertResponseOk();
        $this->assertResponseContains('ワークフローお知らせ');

        // Step 3: 編集
        $this->post("/admin/infos/edit/{$info->id}", [
            'id' => $info->id,
            'title' => '更新後ワークフローお知らせ',
            'body' => '更新後の本文',
            'Group' => [],
        ]);
        $this->assertResponseCode(302);

        // Step 4: 更新確認
        $updated = $infosTable->get((int)$info->id);
        $this->assertSame('更新後ワークフローお知らせ', $updated->title);

        // Step 5: 削除
        $this->post("/admin/infos/delete/{$info->id}");
        $this->assertResponseCode(302);

        // Step 6: 削除確認
        $this->assertFalse($infosTable->exists(['id' => $info->id]), 'お知らせが削除されていること');
    }

    /**
     * コース検索ワークフロー
     *
     * 3つのコースを作成し、キーワード検索でフィルタリングされることを確認
     */
    public function testSearchCoursesWorkflow(): void
    {
        $admin = $this->loginAsAdmin();

        // Step 1: 3つのコースを作成
        $this->createCourse('PHP基礎コース', (int)$admin->id);
        $this->createCourse('JavaScript応用コース', (int)$admin->id);
        $this->createCourse('Python入門コース', (int)$admin->id);

        // Step 2: キーワード検索 → フィルタリング確認
        $this->get('/admin/courses?keyword=PHP');
        $this->assertResponseOk();
        $this->assertResponseContains('PHP基礎コース');
        $this->assertResponseNotContains('JavaScript応用コース');
        $this->assertResponseNotContains('Python入門コース');

        // Step 3: 別のキーワードで検索
        $this->get('/admin/courses?keyword=JavaScript');
        $this->assertResponseOk();
        $this->assertResponseContains('JavaScript応用コース');
        $this->assertResponseNotContains('PHP基礎コース');
        $this->assertResponseNotContains('Python入門コース');

        // Step 4: キーワードなしで全件表示
        $this->get('/admin/courses');
        $this->assertResponseOk();
        $this->assertResponseContains('PHP基礎コース');
        $this->assertResponseContains('JavaScript応用コース');
        $this->assertResponseContains('Python入門コース');
    }

    /**
     * グループ検索ワークフロー
     *
     * 複数グループを作成し、一覧表示が正しいことを確認
     */
    public function testSearchGroupsWorkflow(): void
    {
        $this->loginAsAdmin();

        // Step 1: 3つのグループを作成
        $this->createGroup('グループα');
        $this->createGroup('グループβ');
        $this->createGroup('グループγ');

        // Step 2: 一覧に全て表示されることを確認
        $this->get('/admin/groups');
        $this->assertResponseOk();
        $this->assertResponseContains('グループα');
        $this->assertResponseContains('グループβ');
        $this->assertResponseContains('グループγ');
    }

    /**
     * バリデーションリトライワークフロー
     *
     * 空データで追加 → バリデーションエラー（200）→ 正しいデータで追加 → 成功（302）
     */
    public function testValidationRetryWorkflow(): void
    {
        $this->loginAsAdmin();

        // Step 1: 空データで追加 → バリデーションエラーでフォーム再表示
        $this->post('/admin/courses/add', [
            'title' => '',
            'introduction' => '',
            'comment' => '',
        ]);
        $this->assertResponseCode(200);

        // Step 2: 正しいデータで再送信 → 成功
        $this->post('/admin/courses/add', [
            'title' => 'リトライコース',
            'introduction' => 'リトライコースの紹介文',
            'comment' => 'リトライコースのコメント',
        ]);
        $this->assertResponseCode(302);

        // Step 3: DB に保存されていることを確認
        $coursesTable = $this->getTableLocator()->get('Courses');
        $this->assertTrue(
            $coursesTable->exists(['title' => 'リトライコース']),
            'バリデーションリトライ後にコースがDBに保存されていること'
        );
    }

    /**
     * 管理者専用アクセワークフロー
     *
     * 未認証 → リダイレクト（302）
     * 一般ユーザー（role=user） → 管理画面アクセス不可
     */
    public function testAdminOnlyAccessWorkflow(): void
    {
        // Step 1: 未認証でアクセス → ログインページへリダイレクト
        $this->get('/admin/courses');
        $this->assertRedirect();

        // Step 2: 一般ユーザー（role=user）でログイン → 管理画面アクセス不可
        $this->loginAsUser('user');
        $this->get('/admin/courses');
        // role=user は staff ロールではないため beforeFilter でログアウトされリダイレクト
        $this->assertRedirect();
    }
}
