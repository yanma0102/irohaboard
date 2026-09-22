<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Admin SettingsController の統合テスト
 *
 * システム設定の表示・保存・demo_mode 時の拒否を検証する。
 */
class SettingsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * setUp
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->cleanTables();
        $this->seedSettings();
    }

    /**
     * 外部キー依存順でテーブルをクリーンアップ
     *
     * Settings テーブルも含める（設定行がテスト対象のため）。
     * cleanTables 後に seedSettings() で最低限の設定を作成する。
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
                'Settings',
            ] as $table
        ) {
            $this->getTableLocator()->get($table)->deleteAll('1 = 1');
        }
    }

    /**
     * Settings テーブルに最低限の設定行を作成
     *
     * 本番の初期設定に相当する 4 件を作成する。
     */
    private function seedSettings(): void
    {
        $settingsTable = $this->getTableLocator()->get('Settings');
        $records = [
            ['setting_key' => 'title', 'setting_name' => 'システム名', 'setting_value' => 'テストシステム'],
            ['setting_key' => 'copyright', 'setting_name' => 'コピーライト', 'setting_value' => 'Test Copyright'],
            ['setting_key' => 'color', 'setting_name' => 'テーマカラー', 'setting_value' => '#337ab7'],
            ['setting_key' => 'information', 'setting_name' => 'お知らせ', 'setting_value' => 'テストお知らせ'],
        ];
        foreach ($records as $record) {
            $entity = $settingsTable->newEntity($record);
            $settingsTable->save($entity);
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
     * 未認証アクセステスト
     */
    public function testUnauthenticatedAccess(): void
    {
        $this->get('/admin/settings');
        $this->assertRedirect();
    }

    /**
     * admin ログイン後 GET で設定画面が表示されること
     */
    public function testIndexGet(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/settings');
        $this->assertResponseOk();
        $this->assertResponseContains('テストシステム');
    }

    /**
     * POST で設定を保存し、DB に反映されること
     */
    public function testPostSavesSettings(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/settings', [
            'Setting' => [
                'title' => '更新後のシステム名',
                'copyright' => '更新後のコピーライト',
                'color' => '#ff0000',
                'information' => '更新後のお知らせ',
            ],
        ]);

        // POST 後は Flash 付きでビューを再描画（200）
        $this->assertResponseOk();

        // DB に反映されていること
        $settingsTable = $this->getTableLocator()->get('Settings');
        $settings = $settingsTable->getSettings();
        $this->assertSame('更新後のシステム名', $settings['title']);
        $this->assertSame('#ff0000', $settings['color']);
    }

    /**
     * POST 後にセッション Setting.* に値が書き込まれること
     */
    public function testPostWritesSessionSettings(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/settings', [
            'Setting' => [
                'title' => 'セッションテスト',
                'copyright' => 'セッションテスト著作権',
                'color' => '#00ff00',
                'information' => 'セッションお知らせ',
            ],
        ]);

        $this->assertResponseOk();
        $this->assertSession('セッションテスト', 'Setting.title');
        $this->assertSession('#00ff00', 'Setting.color');
        $this->assertSession('セッションお知らせ', 'Setting.information');
    }

    /**
     * POST 後に Flash 成功メッセージが表示されること
     */
    public function testPostShowsFlashSuccessMessage(): void
    {
        $this->loginAsAdmin();
        $this->enableRetainFlashMessages();

        $this->post('/admin/settings', [
            'Setting' => [
                'title' => 'フラッシュテスト',
                'copyright' => 'フラッシュ著作権',
                'color' => '#112233',
                'information' => 'フラッシュお知らせ',
            ],
        ]);

        $this->assertResponseOk();
        $this->assertFlashMessage('設定が保存されました');
    }

    /**
     * POST で title のみ更新した場合、他の設定値はシード値のまま残ること
     *
     * setSettings() は POST されたキーのみ UPDATE するため、
     * 未指定のキー（copyright など）は変更されない。
     */
    public function testPostWithPartialSettings(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/settings', [
            'Setting' => [
                'title' => '部分更新タイトル',
            ],
        ]);

        $this->assertResponseOk();

        $settingsTable = $this->getTableLocator()->get('Settings');
        $settings = $settingsTable->getSettings();

        // POST された title は更新されていること
        $this->assertSame('部分更新タイトル', $settings['title']);

        // 未指定の copyright はシード値のまま残っていること
        $this->assertSame('Test Copyright', $settings['copyright']);
    }

    /**
     * POST で空の Setting 配列を送信してもエラーにならず、既存値が保持されること
     */
    public function testPostWithEmptySettingArray(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/settings', [
            'Setting' => [],
        ]);

        // 空配列でも 200 でエラーにならないこと
        $this->assertResponseOk();

        $settingsTable = $this->getTableLocator()->get('Settings');
        $settings = $settingsTable->getSettings();

        // シード値がそのまま残っていること
        $this->assertSame('テストシステム', $settings['title']);
    }

    /**
     * demo_mode テスト — 現状の挙動を文書化
     *
     * SettingsController::index() は Configure::read('demo_mode') が true のとき
     * setSettings() を呼ばずに return null する（保存しない）。
     *
     * しかし、Application::bootstrap() で ib_config.php（demo_mode = false）が
     * リクエスト毎に再読み込みされるため、テスト内から Configure::write() で
     * 上書きしてもコントローラ到達時に元に戻ってしまう。
     *
     * ここでは、デフォルト状態（demo_mode = false）で POST が正常に保存されることを
     * 確認し、controller の demo_mode ガードパスは config 側の設定に依存することを
     * 文書化する。
     *
     * 【制限事項】
     * ib_config.php の demo_mode を true に変更しない限り、このガードを
     * テストで発動させる手段はない（src/config 変更禁止のため）。
     */
    public function testDemoModeDefaultAllowsSave(): void
    {
        $this->loginAsAdmin();

        // demo_mode は ib_config.php 由来。テスト環境では
        // Configure::write が Application bootstrap で上書きされる可能性があるため、
        // 値は falsy（null または false）であることを確認
        $this->assertEmpty(Configure::read('demo_mode'));

        // POST
        $this->post('/admin/settings', [
            'Setting' => [
                'title' => 'デフォルト保存テスト',
                'copyright' => 'デフォルト著作権',
                'color' => '#000000',
                'information' => 'デフォルトお知らせ',
            ],
        ]);

        $this->assertResponseOk();

        // demo_mode = false のため保存されること
        $settingsTable = $this->getTableLocator()->get('Settings');
        $settings = $settingsTable->getSettings();
        $this->assertSame('デフォルト保存テスト', $settings['title']);
    }
}
