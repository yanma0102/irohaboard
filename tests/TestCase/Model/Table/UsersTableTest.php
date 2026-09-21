<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\UsersTable;
use Cake\TestSuite\TestCase;

/**
 * UsersTable のテスト
 */
class UsersTableTest extends TestCase
{
    protected UsersTable $Users;

    public function setUp(): void
    {
        parent::setUp();
        $this->Users = $this->getTableLocator()->get('Users');
        $this->Users->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersGroups')->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        unset($this->Users);
        parent::tearDown();
    }

    private function getUserData(string $username = 'testuser', array $overrides = []): array
    {
        return array_merge([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テスト太郎',
            'role' => 'user',
            'email' => $username . '@example.com',
        ], $overrides);
    }

    public function testSaveHashesPassword(): void
    {
        $entity = $this->Users->newEntity($this->getUserData());
        $result = $this->Users->save($entity);

        $this->assertNotFalse($result);
        $this->assertStringStartsWith('$2y$', $result->password, 'パスワードが bcrypt ハッシュ化される');
        $this->assertNotSame('testpass', $result->password);
    }

    public function testSaveKeepsExistingHash(): void
    {
        $entity = $this->Users->newEntity($this->getUserData());
        $this->Users->save($entity);
        $id = $entity->id;
        $originalHash = $entity->password;

        // パスワードを送らない更新ならハッシュは保持される
        $loaded = $this->Users->get($id);
        $patchData = ['name' => '更新後'];
        $loaded = $this->Users->patchEntity($loaded, $patchData);
        $result = $this->Users->save($loaded);

        $this->assertNotFalse($result);
        $this->assertSame($originalHash, $this->Users->get($id)->password);
    }

    public function testFindAuthReturnsFieldsAndExcludesDeleted(): void
    {
        $entity = $this->Users->newEntity($this->getUserData('authuser01'));
        $this->Users->save($entity);
        $id = $entity->id;

        $found = $this->Users->find('auth')->where(['id' => $id])->first();

        $this->assertNotNull($found, '認証用ユーザを取得できる');
        $this->assertNotNull($found->password, '認証時に password が付与される');
        $this->assertStringStartsWith('$2y$', $found->password);

        // 削除済みユーザは除外される
        $deleted = $this->Users->newEntity($this->getUserData('authuser02', ['deleted' => new \Cake\I18n\DateTime()]));
        $this->Users->save($deleted);

        $search = $this->Users->find('auth')->where(['username' => 'authuser02'])->first();
        $this->assertNull($search, '削除済みユーザは対象外');
    }

    public function testIsUniqueValidation(): void
    {
        $this->Users->save($this->Users->newEntity($this->getUserData('dupuser')));

        $entity = $this->Users->newEntity($this->getUserData('dupuser'));
        $this->assertFalse($this->Users->save($entity), '重複するログインIDは保存不可');

        $errors = $entity->getErrors();
        $this->assertArrayHasKey('username', $errors);
        $this->assertSame('ログインIDが重複しています', $errors['username']['unique']);
    }

    public function testValidationRejectsShortUsername(): void
    {
        $entity = $this->Users->newEntity($this->getUserData('ab'));
        $this->assertFalse($this->Users->save($entity));

        $errors = $entity->getErrors();
        $this->assertArrayHasKey('username', $errors);
        $this->assertArrayHasKey('lengthBetween', $errors['username']);
        $this->assertArrayNotHasKey('alphanumeric', $errors['username'], '英数字のみなので alphanumeric は通る');
    }

    public function testValidationRejectsNonAlphanumericUsername(): void
    {
        $entity = $this->Users->newEntity($this->getUserData('abc-def123')); // ハイフン
        $this->assertFalse($this->Users->save($entity));

        $errors = $entity->getErrors();
        $this->assertArrayHasKey('username', $errors);
        $this->assertArrayHasKey('alphanumeric', $errors['username']);
        $this->assertArrayNotHasKey('lengthBetween', $errors['username'], '10文字のため長さは OK');
    }

    public function testDeleteUserRecords(): void
    {
        $user = $this->Users->save($this->Users->newEntity($this->getUserData('delrec01')));
        $this->assertNotFalse($user);
        $userId = (int)$user->id;

        $conn = $this->Users->getConnection();
        $conn->execute(
            'INSERT INTO ib_courses (title, user_id, created) VALUES (:title, :user_id, NOW())',
            ['title' => 'コースA', 'user_id' => $userId]
        );
        $courseId = (int)$conn->execute(
            'SELECT LAST_INSERT_ID() AS id'
        )->fetch('assoc')['id'];

        $conn->execute(
            'INSERT INTO ib_records (course_id, user_id, content_id, created) VALUES (:course_id, :user_id, 1, NOW())',
            ['course_id' => $courseId, 'user_id' => $userId]
        );
        $recordId = (int)$conn->execute(
            'SELECT LAST_INSERT_ID() AS id'
        )->fetch('assoc')['id'];

        $conn->execute(
            'INSERT INTO ib_records_questions (record_id, answer, created) VALUES (:record_id, "A", NOW())',
            ['record_id' => $recordId]
        );

        $this->assertSame(1, (int)$conn->execute('SELECT COUNT(*) c FROM ib_records WHERE user_id = :uid', ['uid' => $userId])->fetch('assoc')['c']);
        $this->assertSame(1, (int)$conn->execute('SELECT COUNT(*) c FROM ib_records_questions rq INNER JOIN ib_records r ON rq.record_id = r.id WHERE r.user_id = :uid', ['uid' => $userId])->fetch('assoc')['c']);

        $this->Users->deleteUserRecords($userId);

        $this->assertSame(0, (int)$conn->execute('SELECT COUNT(*) c FROM ib_records WHERE user_id = :uid', ['uid' => $userId])->fetch('assoc')['c']);
        $this->assertSame(0, (int)$conn->execute('SELECT COUNT(*) c FROM ib_records_questions rq INNER JOIN ib_records r ON rq.record_id = r.id WHERE r.user_id = :uid', ['uid' => $userId])->fetch('assoc')['c']);
    }

    public function testFindOrderedSortsByName(): void
    {
        $this->Users->save($this->Users->newEntity($this->getUserData('userb01', ['name' => 'Bさん'])));
        $this->Users->save($this->Users->newEntity($this->getUserData('usera01', ['name' => 'Aさん'])));

        $names = $this->Users->find('ordered')->all()->extract('name')->toList();
        $this->assertSame(['Aさん', 'Bさん'], $names);
    }
}