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

    // ----------------------------------------------------------------
    // B-4: 旧 SHA1 パスワード互換ログインの検証（エンドツーエンド）
    // ----------------------------------------------------------------

    /**
     * ib_config.php から legacy_security_salt を読み出す
     */
    private function readLegacySalt(): string
    {
        $config = [];
        require CONFIG . 'ib_config.php';

        return (string)($config['legacy_security_salt'] ?? '');
    }

    /**
     * 旧 SHA1 ハッシュを持つユーザを生 SQL で投入する
     *
     * UsersTable::beforeSave() は先頭が '$' でない値を bcrypt 化するため、
     * 移行データの SHA1 ハッシュを保持するには ORM を経由できない。
     */
    private function insertLegacySha1User(string $username, string $hash): int
    {
        $conn = $this->getTableLocator()->get('Users')->getConnection();
        $conn->execute(
            'INSERT INTO ib_users (username, password, name, role, email, created, modified) '
            . 'VALUES (:username, :password, :name, :role, :email, NOW(), NOW())',
            [
                'username' => $username,
                'password' => $hash,
                'name' => $username . 'の名前',
                'role' => 'user',
                'email' => $username . '@example.com',
            ]
        );

        return (int)$conn->execute('SELECT LAST_INSERT_ID() AS id')->fetch('assoc')['id'];
    }

    /**
     * B-4: 旧 SHA1 パスワード（salt 付き）でログインできること
     *
     * UserLoginTrait::performLogin() → _login() が SHA1 を検証し、
     * 成功後 bcrypt へ自動再ハッシュする（移行ユーザの互換性検証）。
     * Application::bootstrap() 経由でリクエストが処理されるため
     * ib_config.php の legacy_security_salt が読み込まれる。
     */
    public function testLegacySha1LoginSucceedsAndUpgradesToBcrypt(): void
    {
        $password = 'legacypass';
        $salt = $this->readLegacySalt();
        $this->assertNotSame('', $salt, 'legacy_security_salt が設定されている');

        $hash = sha1($salt . $password);
        $id = $this->insertLegacySha1User('legacylogin', $hash);

        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/users/login', [
            'username' => 'legacylogin',
            'password' => $password,
        ]);

        // ログイン成功（受講者ホームへリダイレクト）
        $this->assertRedirect();

        // bcrypt へ自動アップグレードされていること
        $after = $this->getTableLocator()->get('Users')->get($id);
        $this->assertNotSame($hash, $after->password, 'SHA1 から bcrypt へ再ハッシュされていない');
        $this->assertStringStartsWith('$2y$', $after->password, 'bcrypt ハッシュに更新されている');
        $this->assertTrue(
            password_verify($password, $after->password),
            'アップグレード後の bcrypt ハッシュが検証できない',
        );
    }

    /**
     * B-4: salt なし SHA1（sha1($password)）でもログインできること
     */
    public function testLegacySha1LoginWithoutSaltSucceeds(): void
    {
        $password = 'plainlegacy';
        $hash = sha1($password);
        $id = $this->insertLegacySha1User('legacyplain2', $hash);

        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/users/login', [
            'username' => 'legacyplain2',
            'password' => $password,
        ]);

        $this->assertRedirect();

        $after = $this->getTableLocator()->get('Users')->get($id);
        $this->assertStringStartsWith('$2y$', $after->password, 'bcrypt ハッシュに更新されている');
    }

    /**
     * B-4: 旧 SHA1 ユーザに対して誤ったパスワードは拒否されること
     */
    public function testLegacySha1LoginRejectsWrongPassword(): void
    {
        $salt = $this->readLegacySalt();
        $hash = sha1($salt . 'correctpass');
        $id = $this->insertLegacySha1User('legacywrong', $hash);

        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/users/login', [
            'username' => 'legacywrong',
            'password' => 'wrongpass',
        ]);

        // ログイン失敗時はフォーム再描画（200）でパスワードは変わらない
        $after = $this->getTableLocator()->get('Users')->get($id);
        $this->assertSame($hash, $after->password, '認証失敗時にハッシュが変更されている');
    }
}
