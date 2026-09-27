<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Service;

use App\Service\AccessControlService;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;

/**
 * AccessControlService のテスト
 *
 * BaseController から抽出したロール判定・コースアクセス判定の
 * 共通ロジック（REST API / MCP 共用）を検証する。
 */
class AccessControlServiceTest extends TestCase
{
    private AccessControlService $accessControl;

    public function setUp(): void
    {
        parent::setUp();

        foreach (
            [
            'UserTokens', 'Users', 'UsersCourses', 'GroupsCourses',
            'UsersGroups', 'Groups', 'Courses', 'Contents', 'Records', 'Logs',
            ] as $table
        ) {
            $this->getTableLocator()->get($table)->deleteAll('1 = 1');
        }

        $this->accessControl = new AccessControlService(
            $this->getTableLocator()->get('Users')->getConnection(),
        );
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    private function createUser(string $username = 'testuser', string $role = 'user'): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $entity = $usersTable->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テスト太郎',
            'role' => $role,
            'email' => $username . '@example.com',
        ]);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    private function createCourse(string $title = 'テストコース'): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $entity = $coursesTable->newEntity([
            'title' => $title,
            'introduction' => 'テスト用コースです',
            'opened' => '2024-01-01',
            'comment' => 'コメント',
            'sort_no' => 1,
            'user_id' => 1,
        ]);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    private function enrollUser(int $userId, int $courseId): void
    {
        $table = $this->getTableLocator()->get('UsersCourses');
        $entity = $table->newEntity([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
        $this->assertNotFalse($table->save($entity));
    }

    private function createGroupAndEnroll(int $userId, int $courseId): int
    {
        $groupsTable = $this->getTableLocator()->get('Groups');
        $group = $groupsTable->newEntity([
            'title' => 'テストグループ' . $userId . '-' . $courseId,
            'comment' => '',
        ]);
        $this->assertNotFalse($groupsTable->save($group));
        $groupId = (int)$group->id;

        $usersGroups = $this->getTableLocator()->get('UsersGroups');
        $this->assertNotFalse($usersGroups->save($usersGroups->newEntity([
            'user_id' => $userId,
            'group_id' => $groupId,
        ])));

        $groupsCourses = $this->getTableLocator()->get('GroupsCourses');
        $this->assertNotFalse($groupsCourses->save($groupsCourses->newEntity([
            'group_id' => $groupId,
            'course_id' => $courseId,
        ])));

        return $groupId;
    }

    // ----------------------------------------------------------------
    // isStaff
    // ----------------------------------------------------------------

    /**
     * スタッフロール（admin / manager / editor / teacher）は true
     */
    public function testIsStaffRoles(): void
    {
        foreach (['admin', 'manager', 'editor', 'teacher'] as $role) {
            $this->assertTrue($this->accessControl->isStaff($role), "{$role} はスタッフ");
        }
    }

    /**
     * 一般ロール（user 等）は false
     */
    public function testIsStaffGeneralUser(): void
    {
        $this->assertFalse($this->accessControl->isStaff('user'));
        $this->assertFalse($this->accessControl->isStaff('guest'));
        $this->assertFalse($this->accessControl->isStaff(''));
    }

    // ----------------------------------------------------------------
    // accessibleCourseIds
    // ----------------------------------------------------------------

    /**
     * 直接登録されたコースを取得できる（int キャスト）
     */
    public function testAccessibleCourseIdsDirectEnrollment(): void
    {
        $user = $this->createElementaryUser();
        $course = $this->createCourse();
        $this->enrollUser((int)$user->id, (int)$course->id);

        $ids = $this->accessControl->accessibleCourseIds((int)$user->id);

        $this->assertSame([(int)$course->id], $ids);
    }

    /**
     * グループ経由の登録コースも取得できる（UNION）
     */
    public function testAccessibleCourseIdsViaGroup(): void
    {
        $user = $this->createElementaryUser();
        $course = $this->createCourse();
        $this->createGroupAndEnroll((int)$user->id, (int)$course->id);

        $ids = $this->accessControl->accessibleCourseIds((int)$user->id);

        $this->assertSame([(int)$course->id], $ids);
    }

    /**
     * 未登録ユーザは空配列
     */
    public function testAccessibleCourseIdsNone(): void
    {
        $user = $this->createElementaryUser();

        $this->assertSame([], $this->accessControl->accessibleCourseIds((int)$user->id));
    }

    /**
     * 直接＋グループ登録が重複しても一意化される
     */
    public function testAccessibleCourseIdsDeduplicated(): void
    {
        $user = $this->createElementaryUser();
        $course = $this->createCourse();
        $this->enrollUser((int)$user->id, (int)$course->id);
        $this->createGroupAndEnroll((int)$user->id, (int)$course->id);

        $ids = $this->accessControl->accessibleCourseIds((int)$user->id);

        $this->assertSame([(int)$course->id], $ids);
    }

    // ----------------------------------------------------------------
    // canAccessCourse
    // ----------------------------------------------------------------

    /**
     * 登録済みコースにはアクセス可能
     */
    public function testCanAccessCourseGranted(): void
    {
        $user = $this->createElementaryUser();
        $course = $this->createCourse();
        $this->enrollUser((int)$user->id, (int)$course->id);

        $this->assertTrue(
            $this->accessControl->canAccessCourse((int)$user->id, (int)$course->id),
        );
    }

    /**
     * 未登録コースにはアクセス不可
     */
    public function testCanAccessCourseDenied(): void
    {
        $user = $this->createElementaryUser();
        $course = $this->createCourse();

        $this->assertFalse(
            $this->accessControl->canAccessCourse((int)$user->id, (int)$course->id),
        );
    }

    // ----------------------------------------------------------------
    // currentUserGroupIds
    // ----------------------------------------------------------------

    /**
     * 所属グループIDを取得できる
     */
    public function testCurrentUserGroupIds(): void
    {
        $user = $this->createElementaryUser();
        $course = $this->createCourse();
        $groupId = $this->createGroupAndEnroll((int)$user->id, (int)$course->id);

        $this->assertSame([$groupId], $this->accessControl->currentUserGroupIds((int)$user->id));
    }

    /**
     * グループ未所属は空配列
     */
    public function testCurrentUserGroupIdsEmpty(): void
    {
        $user = $this->createElementaryUser();

        $this->assertSame([], $this->accessControl->currentUserGroupIds((int)$user->id));
    }

    /**
     * 一般ユーザを作成する（内部用・ユーザー名は連番で一意）
     */
    private function createElementaryUser(): EntityInterface
    {
        static $seq = 0;
        $seq++;

        return $this->createUser('acuser' . $seq);
    }
}
