<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\CoursesTable;
use Cake\TestSuite\TestCase;

/**
 * CoursesTable のテスト
 */
class CoursesTableTest extends TestCase
{
    protected CoursesTable $Courses;

    public function setUp(): void
    {
        parent::setUp();
        $this->Courses = $this->getTableLocator()->get('Courses');
        $this->Courses->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Groups')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersGroups')->deleteAll('1 = 1');
        $this->getTableLocator()->get('GroupsCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Contents')->deleteAll('1 = 1');
        $this->getTableLocator()->get('ContentsQuestions')->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        unset($this->Courses);
        parent::tearDown();
    }

    private function saveUser(string $username): int
    {
        $Users = $this->getTableLocator()->get('Users');
        $user = $Users->save($Users->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'ユーザー ' . $username,
            'role' => 'user',
        ]));
        $this->assertNotFalse($user);

        return (int)$user->id;
    }

    private function saveCourse(string $title, int $userId): int
    {
        $conn = $this->Courses->getConnection();
        $conn->execute(
            'INSERT INTO ib_courses (title, user_id, created) VALUES (:title, :user_id, NOW())',
            ['title' => $title, 'user_id' => $userId]
        );

        return (int)$conn->execute('SELECT LAST_INSERT_ID() AS id')->fetch('assoc')['id'];
    }

    public function testSetOrder(): void
    {
        $userId = $this->saveUser('courseadmin');
        $id1 = $this->saveCourse('コース1', $userId);
        $id2 = $this->saveCourse('コース2', $userId);
        $id3 = $this->saveCourse('コース3', $userId);

        $this->Courses->setOrder([$id3, $id1, $id2]);

        $result = $this->Courses->find('list', keyField: 'id', valueField: 'sort_no')
            ->where(['id IN' => [$id1, $id2, $id3]])
            ->orderBy(['id' => 'ASC'])
            ->toArray();

        $this->assertSame(2, (int)$result[$id1], 'id1 は 2 番目');
        $this->assertSame(3, (int)$result[$id2], 'id2 は 3 番目');
        $this->assertSame(1, (int)$result[$id3], 'id3 は 1 番目');
    }

    public function testHasRightByUsersCourses(): void
    {
        $userId = $this->saveUser('rightuser1');
        $courseId = $this->saveCourse('コースA', $userId);

        $this->assertFalse($this->Courses->hasRight($userId, $courseId), '登録前は権限なし');

        $conn = $this->Courses->getConnection();
        $conn->execute(
            'INSERT INTO ib_users_courses (user_id, course_id, created) VALUES (:user_id, :course_id, NOW())',
            ['user_id' => $userId, 'course_id' => $courseId]
        );

        $this->assertTrue($this->Courses->hasRight($userId, $courseId), '個人登録で権限あり');
    }

    public function testHasRightByGroup(): void
    {
        $userId = $this->saveUser('rightuser2');
        $groupUserId = $this->saveUser('groupuser1');
        $courseId = $this->saveCourse('コースB', $groupUserId);

        $conn = $this->Courses->getConnection();
        $conn->execute(
            'INSERT INTO ib_groups (title, created) VALUES ("グループ1", NOW())'
        );
        $groupId = (int)$conn->execute('SELECT LAST_INSERT_ID() AS id')->fetch('assoc')['id'];

        // ユーザがグループ未所属 → 権限なし
        $this->assertFalse($this->Courses->hasRight($userId, $courseId));

        $conn->execute(
            'INSERT INTO ib_users_groups (user_id, group_id, created) VALUES (:user_id, :group_id, NOW())',
            ['user_id' => $userId, 'group_id' => $groupId]
        );
        $conn->execute(
            'INSERT INTO ib_groups_courses (group_id, course_id, created) VALUES (:group_id, :course_id, NOW())',
            ['group_id' => $groupId, 'course_id' => $courseId]
        );

        $this->assertTrue($this->Courses->hasRight($userId, $courseId), 'グループ経由で権限あり');
    }

    public function testDeleteCourseRemovesContentsAndQuestions(): void
    {
        $userId = $this->saveUser('courseadmin');
        $courseId = $this->saveCourse('削除対象', $userId);

        $conn = $this->Courses->getConnection();
        $conn->execute(
            'INSERT INTO ib_contents (course_id, user_id, title, kind, created) VALUES (:course_id, :user_id, "コンテンツ", "html", NOW())',
            ['course_id' => $courseId, 'user_id' => $userId]
        );
        $contentId = (int)$conn->execute('SELECT LAST_INSERT_ID() AS id')->fetch('assoc')['id'];

        $conn->execute(
            'INSERT INTO ib_contents_questions (content_id, question_type, title, body, correct, score, created) VALUES (:content_id, "text", "問1", "設問", "A", 10, NOW())',
            ['content_id' => $contentId]
        );

        $this->Courses->deleteCourse($courseId);

        $this->assertSame(0, (int)$conn->execute('SELECT COUNT(*) c FROM ib_courses')->fetch('assoc')['c']);
        $this->assertSame(0, (int)$conn->execute('SELECT COUNT(*) c FROM ib_contents')->fetch('assoc')['c']);
        $this->assertSame(0, (int)$conn->execute('SELECT COUNT(*) c FROM ib_contents_questions')->fetch('assoc')['c']);
    }

    public function testFindOrderedSortsBySortNo(): void
    {
        $userId = $this->saveUser('courseadmin');
        $conn = $this->Courses->getConnection();
        $conn->execute(
            'INSERT INTO ib_courses (title, user_id, sort_no, created) VALUES ("コースB", :user_id, 2, NOW())',
            ['user_id' => $userId]
        );
        $conn->execute(
            'INSERT INTO ib_courses (title, user_id, sort_no, created) VALUES ("コースA", :user_id, 1, NOW())',
            ['user_id' => $userId]
        );

        $titles = $this->Courses->find('ordered')->all()->extract('title')->toList();
        $this->assertSame(['コースA', 'コースB'], $titles);
    }
}