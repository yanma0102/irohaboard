<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Admin InfosController の統合テスト
 *
 * お知らせの CRUD と未認証アクセス・404 を検証する。
 *
 * 注: InfosController::add() は edit() に委譲するが、
 * edit() 内で POST 時に (int)null = 0 → $infosTable->get(0) を呼び出すため、
 * 新規追加時に RecordNotFoundException (404) になる既知のバグがある。
 */
class InfosControllerTest extends TestCase
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
     * 未認証アクセステスト
     */
    public function testUnauthenticatedAccess(): void
    {
        $this->get('/admin/infos');
        $this->assertRedirect();
    }

    /**
     * 一覧表示テスト
     */
    public function testIndex(): void
    {
        $admin = $this->loginAsAdmin();
        $this->createInfo((int)$admin->id, '一覧テストお知らせ');

        $this->get('/admin/infos');
        $this->assertResponseOk();
        $this->assertResponseContains('一覧テストお知らせ');
    }

    /**
     * 一覧が空でも 200 が返ること
     */
    public function testIndexEmpty(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/infos');
        $this->assertResponseOk();
    }

    /**
     * 追加画面表示テスト（GET /admin/infos/add）
     *
     * add() は edit() に委譲し、GET 時は newEmptyEntity() を使うため 200。
     */
    public function testAddGet(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/infos/add');
        $this->assertResponseOk();
    }

    /**
     * 追加(add) POST テスト
     *
     * add() は edit($info_id=null) に委譲するが、edit() は POST 時に
     * $info_id が null なら newEmptyEntity() を使うため、正常に保存される。
     */
    public function testAddPost(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/infos/add', [
            'title' => '新規お知らせ',
            'body' => '新規お知らせの本文',
            'Group' => [],
        ]);

        // 保存成功時は index へリダイレクト
        $this->assertResponseCode(302);

        $table = $this->getTableLocator()->get('Infos');
        $info = $table->find()->where(['title' => '新規お知らせ'])->first();
        $this->assertNotNull($info, 'お知らせが保存されていること');
        $this->assertSame('新規お知らせの本文', $info->body);
    }

    /**
     * 編集(edit) GET テスト
     */
    public function testEditGet(): void
    {
        $admin = $this->loginAsAdmin();
        $info = $this->createInfo((int)$admin->id, '編集対象お知らせ');

        $this->get("/admin/infos/edit/{$info->id}");
        $this->assertResponseOk();
    }

    /**
     * 編集(edit) POST テスト
     */
    public function testEditPost(): void
    {
        $admin = $this->loginAsAdmin();
        $info = $this->createInfo((int)$admin->id, '元のお知らせタイトル');

        $this->post("/admin/infos/edit/{$info->id}", [
            'id' => $info->id,
            'title' => '更新後のタイトル',
            'body' => '更新後の本文',
            'Group' => [],
        ]);
        $this->assertRedirect();

        // DB 確認
        $updated = $this->getTableLocator()->get('Infos')->get((int)$info->id);
        $this->assertSame('更新後のタイトル', $updated->title);
    }

    /**
     * 編集(edit) POST テスト — demo_mode 時の挙動（文書化）
     *
     * SettingsController と同様、Application::bootstrap() で ib_config.php
     * （demo_mode = false）がリクエスト毎に再読み込みされるため、
     * テスト内から Configure::write('demo_mode', true) で上書きしても
     * コントローラ到達時に元に戻る。controller は demo_mode=false で
     * 保存 → リダイレクト 302 となる。
     *
     * 【制限事項】ib_config.php の demo_mode を true に変更しない限り、
     * このガードをテストで発動させる手段はない（src 変更禁止のため）。
     */
    public function testEditPostDemoModeBootstrapOverride(): void
    {
        $admin = $this->loginAsAdmin();
        $info = $this->createInfo((int)$admin->id, 'demo_modeテスト');

        \Cake\Core\Configure::write('demo_mode', true);

        $this->post("/admin/infos/edit/{$info->id}", [
            'id' => $info->id,
            'title' => '変更されない',
            'body' => '変更されない本文',
            'Group' => [],
        ]);

        // bootstrap で demo_mode が上書きされるため、保存が実行されて
        // リダイレクト（302）になる（現状の挙動）
        $this->assertRedirect();

        \Cake\Core\Configure::delete('demo_mode');
    }

    /**
     * 編集(edit) GET — 存在しない ID で 404
     */
    public function testEditGetNotFound(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/infos/edit/99999');
        $this->assertResponseCode(404);
    }

    /**
     * 削除(delete) POST テスト
     */
    public function testDeletePost(): void
    {
        $admin = $this->loginAsAdmin();
        $info = $this->createInfo((int)$admin->id, '削除対象お知らせ');
        $infoId = (int)$info->id;

        $this->post("/admin/infos/delete/{$infoId}");
        $this->assertRedirect();

        $exists = $this->getTableLocator()->get('Infos')->exists(['id' => $infoId]);
        $this->assertFalse($exists, 'お知らせが DB から削除されていない');
    }

    /**
     * 削除(delete) GET メソッドでは削除できないこと（allowMethod(['post','delete'])）
     */
    public function testDeleteGetNotAllowed(): void
    {
        $admin = $this->loginAsAdmin();
        $info = $this->createInfo((int)$admin->id, 'GET削除テスト');

        $this->get("/admin/infos/delete/{$info->id}");
        $this->assertResponseCode(405);
    }

    /**
     * 削除(delete) — 存在しない ID で 404
     */
    public function testDeleteNotFound(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/infos/delete/99999');
        $this->assertResponseCode(404);
    }

    /**
     * グループ関連のお知らせ一覧表示テスト
     */
    public function testIndexWithGroupAssociation(): void
    {
        $admin = $this->loginAsAdmin();

        // グループ作成
        $groupsTable = $this->getTableLocator()->get('Groups');
        $group = $groupsTable->newEntity([
            'title' => 'テストグループ',
            'comment' => 'テストグループのコメント',
        ]);
        $groupsTable->save($group);

        // お知らせ作成
        $info = $this->createInfo((int)$admin->id, 'グループ付きお知らせ');

        // お知らせにグループを関連付け
        $infosGroupsTable = $this->getTableLocator()->get('InfosGroups');
        $entity = $infosGroupsTable->newEntity([
            'info_id' => $info->id,
            'group_id' => $group->id,
        ]);
        $infosGroupsTable->save($entity);

        $this->get('/admin/infos');
        $this->assertResponseOk();
        $this->assertResponseContains('グループ付きお知らせ');
    }
}
