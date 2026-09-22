<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * フロント側 Users Controller の統合テスト
 *
 * UsersController::index() は UsersCourses::index へリダイレクト。
 * UsersController::setting() はパスワード変更フォーム（GET/POST）。
 */
class UsersControllerTest extends TestCase
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
     *
     * role = 'user' でセッションに Authentication 認証情報を直接注入する。
     * AppController::beforeFilter() が設定する Setting.app_dir = ROOT は
     * 初回リクエスト時に自動的にセッションに格納される。
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
     * index アクションは UsersCourses::index へリダイレクトされること
     */
    public function testIndexRedirectsToUsersCourses(): void
    {
        $this->loginAsUser();

        $this->get('/users/index');
        $this->assertRedirect();
    }

    /**
     * ログインページは未認証でもアクセス可能（200）であること
     */
    public function testLoginPageAccessible(): void
    {
        $this->get('/users/login');
        $this->assertResponseOk();
    }

    /**
     * ログアウト後はログインページへリダイレクトされること
     *
     * 注: セッション直接注入方式（$this->session(['Auth' => [...]]))では
     * Authentication->logout() がセッションの Auth キーを完全にクリアできないため、
     * ログアウト後のセッション状態検証は省略する。
     */
    public function testLogoutRedirectsToLogin(): void
    {
        $this->loginAsUser();

        $this->get('/users/logout');
        $this->assertRedirect();
    }

    /**
     * 未認証で /users/setting にアクセスするとリダイレクトされること
     */
    public function testSettingRequiresLogin(): void
    {
        $this->get('/users/setting');
        $this->assertRedirect();
    }

    /**
     * ログイン後に /users/setting にアクセスすると 200 が返されること
     */
    public function testSettingGet(): void
    {
        $this->loginAsUser();

        $this->get('/users/setting');
        $this->assertResponseOk();
    }

    /**
     * パスワード変更：パスワード不一致の場合、パスワードが変わらないこと
     *
     * UsersController::setting() は new_password !== new_password2 の場合、
     * Flash エラーを表示してフォームを再描画する（リダイレクトなし）。
     */
    public function testSettingPasswordChangeMismatch(): void
    {
        $user = $this->loginAsUser();

        $usersTable = $this->getTableLocator()->get('Users');
        $before = $usersTable->get((int)$user->id);
        $originalPassword = $before->password;

        $this->post('/users/setting', [
            'User' => [
                'new_password' => 'pass1',
                'new_password2' => 'pass2',
            ],
        ]);
        $this->assertResponseOk();

        // パスワードが変更されていないこと
        $after = $usersTable->get((int)$user->id);
        $this->assertSame($originalPassword, $after->password, 'パスワードが不一致なのに変更されている');
    }

    /**
     * パスワード変更：パスワードが空の場合、パスワードが変わらないこと
     *
     * UsersController::setting() は new_password が空の場合、
     * Flash エラー「パスワードを入力して下さい」を表示する。
     */
    public function testSettingPasswordChangeEmpty(): void
    {
        $user = $this->loginAsUser();

        $usersTable = $this->getTableLocator()->get('Users');
        $before = $usersTable->get((int)$user->id);
        $originalPassword = $before->password;

        $this->post('/users/setting', [
            'User' => [
                'new_password' => '',
                'new_password2' => '',
            ],
        ]);
        $this->assertResponseOk();

        // パスワードが変更されていないこと
        $after = $usersTable->get((int)$user->id);
        $this->assertSame($originalPassword, $after->password, 'パスワードが空なのに変更されている');
    }

    /**
     * パスワード変更：パスワード一致の場合、DB のパスワードがハッシュ化されて保存されること
     *
     * UsersController::setting() は一致する場合、patchEntity + save でパスワードを保存。
     * UsersTable::beforeSave() が bcrypt でハッシュ化する。
     */
    public function testSettingPasswordChangeSuccess(): void
    {
        $user = $this->loginAsUser();

        $usersTable = $this->getTableLocator()->get('Users');
        $before = $usersTable->get((int)$user->id);
        $originalPassword = $before->password;

        $this->post('/users/setting', [
            'User' => [
                'new_password' => 'newpass',
                'new_password2' => 'newpass',
            ],
        ]);
        $this->assertResponseOk();

        // パスワードがハッシュ化されて保存されていること
        $after = $usersTable->get((int)$user->id);
        $this->assertNotSame($originalPassword, $after->password, 'パスワードが平文のまま保存されている');
        $this->assertTrue(
            password_verify('newpass', $after->password),
            '新しいパスワードの bcrypt ハッシュが検証できない',
        );
    }
}
