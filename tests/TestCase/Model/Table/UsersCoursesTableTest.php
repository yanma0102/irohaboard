<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\UsersCoursesTable;
use Cake\TestSuite\TestCase;

/**
 * UsersCoursesTable のテスト
 */
class UsersCoursesTableTest extends TestCase
{
    protected UsersCoursesTable $UsersCourses;

    public function setUp(): void
    {
        parent::setUp();
        $this->UsersCourses = $this->getTableLocator()->get('UsersCourses');
        $this->UsersCourses->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Courses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Groups')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersGroups')->deleteAll('1 = 1');
        $this->getTableLocator()->get('GroupsCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Contents')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Records')->deleteAll('1 = 1');
        $this->getTableLocator()->get('RecordsQuestions')->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        unset($this->UsersCourses);
        parent::tearDown();
    }

    private function saveUser(string $username): int
    {
        $Users = $this->getTableLocator()->get('Users');
        $user = $Users->save($Users->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => $username,
            'role' => 'user',
        ]));
        $this->assertNotFalse($user);

        return (int)$user->id;
    }

    private function saveCourse(string $title, int $userId): int
    {
        $Courses = $this->getTableLocator()->get('Courses');
        $conn = $Courses->getConnection();
        $conn->execute(
            'INSERT INTO ib_courses (title, user_id, created) VALUES (:title, :user_id, NOW())',
            ['title' => $title, 'user_id' => $userId]
        );

        return (int)$conn->execute('SELECT LAST_INSERT_ID() AS id')->fetch('assoc')['id'];
    }

    private function saveContent(int $courseId, int $userId, string $kind = 'html'): int
    {
        $Contents = $this->getTableLocator()->get('Contents');
        $content = $Contents->save($Contents->newEntity([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'コンテンツ',
            'kind' => $kind,
            'status' => 1,
        ]));
        $this->assertNotFalse($content);

        return (int)$content->id;
    }

    public function testGetCourseRecordForUserWithNoCourses(): void
    {
        $userId = $this->saveUser('courseuser1');

        $result = $this->UsersCourses->getCourseRecord($userId);
        $this->assertSame([], $result, '受講コースが無ければ空配列');
    }

    public function testGetCourseRecordByPersonalEnrollment(): void
    {
        $adminId = $this->saveUser('courseadmin1');
        $userId = $this->saveUser('courseuser2');
        $courseId = $this->saveCourse('個人受講コース', $adminId);

        // 個人受講登録
        $conn = $this->UsersCourses->getConnection();
        $conn->execute(
            'INSERT INTO ib_users_courses (user_id, course_id, created) VALUES (:user_id, :course_id, NOW())',
            ['user_id' => $userId, 'course_id' => $courseId]
        );

        $result = $this->UsersCourses->getCourseRecord($userId);
        $this->assertCount(1, $result);
        $this->assertSame('個人受講コース', $result[0]['title']);
        $this->assertEquals(0, (int)$result[0]['left_cnt'], '未学習コンテンツ0件');
    }

    public function testGetCourseRecordByGroupEnrollment(): void
    {
        $adminId = $this->saveUser('courseadmin2');
        $userId = $this->saveUser('courseuser3');
        $courseId = $this->saveCourse('グループ経由コース', $adminId);

        $conn = $this->UsersCourses->getConnection();
        $conn->execute('INSERT INTO ib_groups (title, created) VALUES ("グループ", NOW())');
        $groupId = (int)$conn->execute('SELECT LAST_INSERT_ID() AS id')->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO ib_users_groups (user_id, group_id, created) VALUES (:user_id, :group_id, NOW())',
            ['user_id' => $userId, 'group_id' => $groupId]
        );
        $conn->execute(
            'INSERT INTO ib_groups_courses (group_id, course_id, created) VALUES (:group_id, :course_id, NOW())',
            ['group_id' => $groupId, 'course_id' => $courseId]
        );

        $result = $this->UsersCourses->getCourseRecord($userId);
        $this->assertCount(1, $result, 'グループ経由の受講コースを取得');
        $this->assertSame('グループ経由コース', $result[0]['title']);
    }

    public function testGetCourseRecordWithLearningProgress(): void
    {
        $adminId = $this->saveUser('courseadmin3');
        $userId = $this->saveUser('courseuser4');
        $courseId = $this->saveCourse('学習履歴付きコース', $adminId);
        $contentId1 = $this->saveContent($courseId, $adminId, 'html');
        $contentId2 = $this->saveContent($courseId, $adminId, 'test');

        $conn = $this->UsersCourses->getConnection();
        $conn->execute(
            'INSERT INTO ib_users_courses (user_id, course_id, created) VALUES (:user_id, :course_id, NOW())',
            ['user_id' => $userId, 'course_id' => $courseId]
        );

        // 未学習時: left_cnt = 2 (html 1件 + test 1件)
        $result = $this->UsersCourses->getCourseRecord($userId);
        $this->assertEquals(2, (int)$result[0]['left_cnt']);

        // html を完了 → left_cnt = 1
        $conn->execute(
            'INSERT INTO ib_records (course_id, user_id, content_id, is_complete, created) VALUES (:course_id, :user_id, :content_id, 1, NOW())',
            ['course_id' => $courseId, 'user_id' => $userId, 'content_id' => $contentId1]
        );
        $result = $this->UsersCourses->getCourseRecord($userId);
        $this->assertEquals(1, (int)$result[0]['left_cnt']);
        $this->assertNotEmpty($result[0]['first_date']);
        $this->assertNotEmpty($result[0]['last_date']);

        // test を合格 → left_cnt = 0
        $conn->execute(
            'INSERT INTO ib_records (course_id, user_id, content_id, is_passed, created) VALUES (:course_id, :user_id, :content_id, 1, NOW())',
            ['course_id' => $courseId, 'user_id' => $userId, 'content_id' => $contentId2]
        );
        $result = $this->UsersCourses->getCourseRecord($userId);
        $this->assertEquals(0, (int)$result[0]['left_cnt']);
    }
}