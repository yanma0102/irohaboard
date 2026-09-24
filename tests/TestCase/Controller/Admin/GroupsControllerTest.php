<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

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
            'Course' => [],
            'comment' => '新規グループのコメント',
        ]);

        $this->assertResponseCode(302);

        $groupsTable = $this->getTableLocator()->get('Groups');
        $this->assertTrue(
            $groupsTable->exists(['title' => '新規グループ']),
            'add でグループが DB に保存されること'
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
            'Course' => [],
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
            'Course' => [],
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
            'Course' => [],
            'comment' => '',
        ]);
        $this->assertResponseOk();
    }
}
