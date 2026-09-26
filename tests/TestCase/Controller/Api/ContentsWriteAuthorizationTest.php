<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * D-08: Staff のコンテンツ Write 権限テスト
 *
 * staff ロール（admin/manager/editor/teacher）は未受講コースでも
 * コンテンツの作成・更新・削除が可能（Web 管理画面と同一）。
 * 一般ユーザ（user）は受講済みコースのみアクセス可能。
 */
class ContentsWriteAuthorizationTest extends TestCase
{
    use IntegrationTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->getTableLocator()->get('UserTokens')->deleteAll('1 = 1');
        $this->getTableLocator()->get('ContentsQuestions')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Records')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Contents')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Courses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Logs')->deleteAll('1 = 1');
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    private function createUser(string $username, array $overrides = []): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $data = array_merge([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テスト太郎',
            'role' => 'user',
            'email' => $username . '@example.com',
        ], $overrides);

        $entity = $usersTable->newEntity($data);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result, "ユーザ {$username} の作成に失敗");

        return $result;
    }

    private function issueToken(string $username, string $password = 'testpass'): array
    {
        $this->post('/api/v1/auth/token', [
            'username' => $username,
            'password' => $password,
        ]);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body, 'レスポンスに data キーが存在する');

        return $body['data'];
    }

    private function createCourse(string $title, int $userId): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $entity = $coursesTable->newEntity([
            'title' => $title,
            'sort_no' => 1,
            'user_id' => $userId,
        ]);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result, "コース {$title} の作成に失敗");

        return $result;
    }

    private function assignCourse(int $userId, int $courseId): void
    {
        $table = $this->getTableLocator()->get('UsersCourses');
        $entity = $table->newEntity([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
        $this->assertNotFalse($table->save($entity), 'コース割当に失敗');
    }

    private function createContent(int $courseId, int $userId): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テストコンテンツ',
            'kind' => 'html',
            'body' => '<p>テスト</p>',
            'status' => 1,
            'sort_no' => 1,
        ]);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'コンテンツの作成に失敗');

        return $result;
    }

    // ----------------------------------------------------------------
    // staff ロール → 未受講コースでも Write 可能
    // ----------------------------------------------------------------

    /**
     * Data provider: staff ロール一覧
     *
     * @return array<string, array{string}>
     */
    public static function staffRoleProvider(): array
    {
        return [
            'admin' => ['admin'],
            'manager' => ['manager'],
            'editor' => ['editor'],
            'teacher' => ['teacher'],
        ];
    }

    /**
     * staff ロールは未受講コースにコンテンツを追加できる
     */
    #[DataProvider('staffRoleProvider')]
    public function testStaffCanAddContentToUnenrolledCourse(string $role): void
    {
        $staff = $this->createUser("staffadd{$role}", ['role' => $role]);
        $owner = $this->createUser("owneradd{$role}", ['role' => 'user']);
        $course = $this->createCourse("他人のコース_add_{$role}", (int)$owner->id);
        // staff はコース未受講

        $tokenData = $this->issueToken("staffadd{$role}");
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => "コンテンツ by {$role}",
            'kind' => 'html',
            'body' => '<p>test</p>',
        ]);
        $this->assertResponseCode(201, "{$role} は未受講コースにコンテンツを追加できる");
    }

    /**
     * staff ロールは未受講コースのコンテンツを更新できる
     */
    #[DataProvider('staffRoleProvider')]
    public function testStaffCanEditContentInUnenrolledCourse(string $role): void
    {
        $staff = $this->createUser("staffedit{$role}", ['role' => $role]);
        $owner = $this->createUser("owneredit{$role}", ['role' => 'user']);
        $course = $this->createCourse("他人のコース_edit_{$role}", (int)$owner->id);
        $content = $this->createContent((int)$course->id, (int)$owner->id);

        $tokenData = $this->issueToken("staffedit{$role}");
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/' . $content->id, [
            'title' => '更新テスト',
        ]);
        $this->assertResponseOk();
    }

    /**
     * staff ロールは未受講コースのコンテンツを削除できる
     */
    #[DataProvider('staffRoleProvider')]
    public function testStaffCanDeleteContentInUnenrolledCourse(string $role): void
    {
        $staff = $this->createUser("staffdel{$role}", ['role' => $role]);
        $owner = $this->createUser("ownerdel{$role}", ['role' => 'user']);
        $course = $this->createCourse("他人のコース_del_{$role}", (int)$owner->id);
        $content = $this->createContent((int)$course->id, (int)$owner->id);

        $tokenData = $this->issueToken("staffdel{$role}");
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/contents/' . $content->id);
        $this->assertResponseOk();
    }

    // ----------------------------------------------------------------
    // staff ロール → コース移動も可能
    // ----------------------------------------------------------------

    /**
     * staff ロールはコンテンツを未受講コースに移動できる
     */
    public function testStaffCanMoveContentToUnenrolledCourse(): void
    {
        $staff = $this->createUser('staffmove', ['role' => 'admin']);
        $owner = $this->createUser('ownermove', ['role' => 'user']);
        $course1 = $this->createCourse('元コース', (int)$owner->id);
        $course2 = $this->createCourse('先コース', (int)$owner->id);
        // staff は両コース未受講
        $content = $this->createContent((int)$course1->id, (int)$owner->id);

        $tokenData = $this->issueToken('staffmove');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/' . $content->id, [
            'course_id' => $course2->id,
        ]);
        $this->assertResponseOk();
    }

    // ----------------------------------------------------------------
    // 一般ユーザ（user）→ 未受講コースは 403
    // ----------------------------------------------------------------

    /**
     * 一般ユーザは未受講コースにコンテンツを追加できない
     */
    public function testUserCannotAddContentToUnenrolledCourse(): void
    {
        $user = $this->createUser('normaluseradd', ['role' => 'user']);
        $owner = $this->createUser('owneruseradd', ['role' => 'user']);
        $course = $this->createCourse('未受講コース', (int)$owner->id);
        // user はコース未受講

        $tokenData = $this->issueToken('normaluseradd');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'テスト',
            'kind' => 'html',
            'body' => '<p>test</p>',
        ]);
        $this->assertResponseCode(403);
    }

    /**
     * 一般ユーザは未受講コースのコンテンツを更新できない
     */
    public function testUserCannotEditContentInUnenrolledCourse(): void
    {
        $user = $this->createUser('normaluseredit', ['role' => 'user']);
        $owner = $this->createUser('owneruseredit', ['role' => 'user']);
        $course = $this->createCourse('未受講コース_edit', (int)$owner->id);
        $content = $this->createContent((int)$course->id, (int)$owner->id);

        $tokenData = $this->issueToken('normaluseredit');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/' . $content->id, [
            'title' => '更新テスト',
        ]);
        $this->assertResponseCode(403);
    }

    /**
     * 一般ユーザは未受講コースのコンテンツを削除できない
     */
    public function testUserCannotDeleteContentInUnenrolledCourse(): void
    {
        $user = $this->createUser('normaluserdel', ['role' => 'user']);
        $owner = $this->createUser('owneruserdel', ['role' => 'user']);
        $course = $this->createCourse('未受講コース_del', (int)$owner->id);
        $content = $this->createContent((int)$course->id, (int)$owner->id);

        $tokenData = $this->issueToken('normaluserdel');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/contents/' . $content->id);
        $this->assertResponseCode(403);
    }

    // ----------------------------------------------------------------
    // 一般ユーザ（user）→ 受講済みコースでもコンテンツ追加は不可
    // ----------------------------------------------------------------

    /**
     * 一般ユーザは受講済みコースでもコンテンツを追加できない（requireStaff で 403）
     */
    public function testUserCannotAddContentEvenIfEnrolled(): void
    {
        $user = $this->createUser('enrolleduseradd', ['role' => 'user']);
        $owner = $this->createUser('ownerenrolladd', ['role' => 'user']);
        $course = $this->createCourse('受講済みコース', (int)$owner->id);
        $this->assignCourse((int)$user->id, (int)$course->id);

        $tokenData = $this->issueToken('enrolleduseradd');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        // user ロールは requireStaff で 403 になる（コンテンツ追加は staff のみ）
        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'テスト',
            'kind' => 'html',
            'body' => '<p>test</p>',
        ]);
        $this->assertResponseCode(403);
    }
}
