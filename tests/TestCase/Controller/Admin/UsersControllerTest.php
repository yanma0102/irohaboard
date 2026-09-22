<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Admin UsersController の統合テスト
 *
 * UsersController::edit() は $user_id === null の場合に newEntity() を正しく使い、
 * 新規追加が正常に動作する（Courses/Groups のバグとは異なる）。
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
     * テスト用ユーザーを作成（admin ロール以外）
     */
    private function createUser(string $username, string $role = 'user'): \Cake\Datasource\EntityInterface
    {
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
     * 未認証アクセステスト
     */
    public function testUnauthenticatedAccess(): void
    {
        $this->get('/admin/users');
        $this->assertRedirect();
    }

    /**
     * 一覧表示テスト
     */
    public function testIndex(): void
    {
        $this->loginAsAdmin();
        $this->createUser('testuser1');

        $this->get('/admin/users');
        $this->assertResponseOk();
        $this->assertResponseContains('testuser1');
    }

    /**
     * 追加(add) POST テスト
     *
     * UsersController::edit() は $user_id === null を正しく処理し、
     * newEntity() で新規ユーザーを作成する。
     */
    public function testAddPost(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/users/add', [
            'User' => [
                'username' => 'newuser',
                'new_password' => 'newpass',
                'name' => '新規テストユーザー',
                'role' => 'user',
                'email' => 'newuser@example.com',
                'Group' => [],
                'Course' => [],
                'comment' => 'テストコメント',
            ],
        ]);
        $this->assertRedirect();

        // DB 確認
        $usersTable = $this->getTableLocator()->get('Users');
        $this->assertTrue($usersTable->exists(['username' => 'newuser']), '新規ユーザーが DB に保存されていない');

        $saved = $usersTable->find()->where(['username' => 'newuser'])->first();
        $this->assertSame('新規テストユーザー', $saved->name);
        $this->assertSame('user', $saved->role);
        $this->assertSame('newuser@example.com', $saved->email);
    }

    /**
     * 追加(add) POST テスト（パスワードのハッシュ化確認）
     */
    public function testAddPostPasswordHashed(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/users/add', [
            'User' => [
                'username' => 'hashuser',
                'new_password' => 'plainpass',
                'name' => 'ハッシュテスト',
                'role' => 'user',
                'email' => 'hash@example.com',
                'Group' => [],
                'Course' => [],
                'comment' => '',
            ],
        ]);
        $this->assertRedirect();

        $usersTable = $this->getTableLocator()->get('Users');
        $saved = $usersTable->find()->where(['username' => 'hashuser'])->first();
        $this->assertNotSame('plainpass', $saved->password, 'パスワードが平文のまま保存されている');
        $this->assertTrue(password_verify('plainpass', $saved->password), 'パスワードの bcrypt ハッシュが検証できない');
    }

    /**
     * 編集(edit) POST テスト
     */
    public function testEditPost(): void
    {
        $this->loginAsAdmin();
        $user = $this->createUser('edituser');

        $this->post("/admin/users/edit/{$user->id}", [
            'User' => [
                'id' => $user->id,
                'username' => 'edituser',
                'name' => '更新後の名前',
                'role' => 'user',
                'email' => 'updated@example.com',
                'Group' => [],
                'Course' => [],
                'comment' => '更新コメント',
            ],
        ]);
        $this->assertRedirect();

        $updated = $this->getTableLocator()->get('Users')->get((int)$user->id);
        $this->assertSame('更新後の名前', $updated->name);
    }

    /**
     * 編集(edit) POST テスト（パスワード変更なし）
     */
    public function testEditPostWithoutPasswordChange(): void
    {
        $this->loginAsAdmin();
        $user = $this->createUser('pwduser');

        $usersTable = $this->getTableLocator()->get('Users');
        $before = $usersTable->get((int)$user->id);
        $originalPassword = $before->password;

        $this->post("/admin/users/edit/{$user->id}", [
            'User' => [
                'id' => $user->id,
                'username' => 'pwduser',
                'name' => 'パスワード変更なしテスト',
                'role' => 'user',
                'email' => 'pwd@example.com',
                'Group' => [],
                'Course' => [],
                'comment' => '',
            ],
        ]);
        $this->assertRedirect();

        $after = $usersTable->get((int)$user->id);
        $this->assertSame($originalPassword, $after->password, 'パスワードが変更されている');
    }

    /**
     * 削除(delete) POST テスト
     */
    public function testDeletePost(): void
    {
        $this->loginAsAdmin();
        $user = $this->createUser('deleteuser');
        $userId = (int)$user->id;

        $this->post("/admin/users/delete/{$userId}");
        $this->assertRedirect();

        $exists = $this->getTableLocator()->get('Users')->exists(['id' => $userId]);
        $this->assertFalse($exists, 'ユーザーが DB から削除されていない');
    }

    /**
     * 学習履歴クリア(clear) POST テスト
     */
    public function testClearPost(): void
    {
        $this->loginAsAdmin();
        $user = $this->createUser('clearuser');

        $this->post("/admin/users/clear/{$user->id}");
        $this->assertRedirect();
    }

    /**
     * 編集(edit) GET テスト（存在しないユーザー）
     */
    public function testEditGetNotFound(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/users/edit/99999');
        $this->assertResponseCode(404);
    }

    /**
     * 学習履歴クリア(clear) テスト — Records テーブルが空になることを確認
     */
    public function testClearLearningHistory(): void
    {
        $this->loginAsAdmin();
        $user = $this->createUser('clearhistuser');

        // テスト用の Course / Content / Record を raw SQL で作成
        $connection = $this->getTableLocator()->get('Users')->getConnection();
        $connection->execute(
            'INSERT INTO ib_courses (title, sort_no, user_id, created, modified) VALUES (:title, 0, :user_id, NOW(), NOW())',
            ['title' => 'テストコース', 'user_id' => (int)$user->id]
        );
        $courseId = (int)$connection->execute('SELECT LAST_INSERT_ID()')->fetch()[0];

        $connection->execute(
            'INSERT INTO ib_contents (course_id, user_id, title, kind, body, sort_no, comment, created, modified) VALUES (:course_id, :user_id, :title, :kind, :body, 0, :comment, NOW(), NOW())',
            ['course_id' => $courseId, 'user_id' => (int)$user->id, 'title' => 'テストコンテンツ', 'kind' => 'text', 'body' => 'テスト内容', 'comment' => '']
        );
        $contentId = (int)$connection->execute('SELECT LAST_INSERT_ID()')->fetch()[0];

        $recordsTable = $this->getTableLocator()->get('Records');
        $connection->execute(
            'INSERT INTO ib_records (user_id, course_id, content_id, created) VALUES (:user_id, :course_id, :content_id, NOW())',
            ['user_id' => (int)$user->id, 'course_id' => $courseId, 'content_id' => $contentId]
        );
        $this->assertTrue($recordsTable->exists(['user_id' => $user->id]), '学習履歴が存在する');

        // clear を実行
        $this->post("/admin/users/clear/{$user->id}");
        $this->assertRedirect();

        // Records が削除されていることを確認
        $this->assertFalse(
            $recordsTable->exists(['user_id' => $user->id]),
            '学習履歴がクリアされていない'
        );
    }

    /**
     * clear の GET メソッドは許可されないこと（405）
     */
    public function testClearGetNotAllowed(): void
    {
        $this->loginAsAdmin();
        $user = $this->createUser('cleargetuser');

        $this->get("/admin/users/clear/{$user->id}");
        $this->assertResponseCode(405);
    }

    /**
     * 重複ユーザー名で追加 → バリデーションエラー（フォーム再表示 200）
     */
    public function testAddDuplicateUsername(): void
    {
        $this->loginAsAdmin();
        $this->createUser('dupuser');

        $this->post('/admin/users/add', [
            'User' => [
                'username' => 'dupuser',
                'new_password' => 'newpass',
                'name' => '重複テスト',
                'role' => 'user',
                'email' => 'dup@example.com',
                'Group' => [],
                'Course' => [],
                'comment' => '',
            ],
        ]);
        $this->assertResponseOk();
    }

    /**
     * role を空で追加 → バリデーションエラー（フォーム再表示 200）
     */
    public function testAddInvalidRole(): void
    {
        $this->loginAsAdmin();

        $this->post('/admin/users/add', [
            'User' => [
                'username' => 'noroleuser',
                'new_password' => 'newpass',
                'name' => 'ロール空テスト',
                'role' => '',
                'email' => 'norole@example.com',
                'Group' => [],
                'Course' => [],
                'comment' => '',
            ],
        ]);
        $this->assertResponseOk();
    }

    /**
     * 編集(edit) POST — パスワードを空のまま変更なしで更新
     */
    public function testEditPasswordUnchanged(): void
    {
        $this->loginAsAdmin();
        $user = $this->createUser('pwdunchanged');

        $usersTable = $this->getTableLocator()->get('Users');
        $before = $usersTable->get((int)$user->id);
        $originalPassword = $before->password;

        $this->post("/admin/users/edit/{$user->id}", [
            'User' => [
                'id' => $user->id,
                'username' => 'pwdunchanged',
                'name' => 'パスワード未変更テスト',
                'role' => 'user',
                'email' => 'pwdunchanged@example.com',
                'Group' => [],
                'Course' => [],
                'comment' => '',
                // new_password を送信しない（パスワード変更なし）
            ],
        ]);
        $this->assertRedirect();

        $after = $usersTable->get((int)$user->id);
        $this->assertSame($originalPassword, $after->password, 'パスワードが変更されている');
    }

    /**
     * setting ページでパスワード変更 → パスワードが更新されること
     *
     * UsersController::setting() は保存成功時に null を返すため 200 レスポンス。
     */
    public function testSettingChangePassword(): void
    {
        $admin = $this->loginAsAdmin();

        $this->post('/admin/users/setting', [
            'User' => [
                'new_password' => 'newadminpass',
                'new_password2' => 'newadminpass',
            ],
        ]);
        $this->assertResponseOk();

        $usersTable = $this->getTableLocator()->get('Users');
        $updated = $usersTable->get((int)$admin->id);
        $this->assertTrue(
            password_verify('newadminpass', $updated->password),
            'パスワードが更新されていない'
        );
    }
}
