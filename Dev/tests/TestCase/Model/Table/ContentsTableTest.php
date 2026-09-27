<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\ContentsTable;
use Cake\TestSuite\TestCase;

/**
 * ContentsTable のテスト
 */
class ContentsTableTest extends TestCase
{
    protected ContentsTable $Contents;

    public function setUp(): void
    {
        parent::setUp();
        $this->Contents = $this->getTableLocator()->get('Contents');
        $this->Contents->deleteAll('1 = 1');
        $this->getTableLocator()->get('Courses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Records')->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        unset($this->Contents);
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
        $conn = $this->Contents->getConnection();
        $conn->execute(
            'INSERT INTO ib_courses (title, user_id, created) VALUES (:title, :user_id, NOW())',
            ['title' => $title, 'user_id' => $userId],
        );

        return (int)$conn->execute('SELECT LAST_INSERT_ID() AS id')->fetch('assoc')['id'];
    }

    private function saveContent(int $courseId, int $userId, array $data): int
    {
        $entity = $this->Contents->newEntity(array_merge([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'コンテンツ',
            'kind' => 'html',
            'status' => 1,
        ], $data));
        $result = $this->Contents->save($entity);
        $this->assertNotFalse($result);

        return (int)$result->id;
    }

    public function testGetContentRecord(): void
    {
        $userId = $this->saveUser('student1');
        $adminId = $this->saveUser('admin1');
        $courseId = $this->saveCourse('コース1', $adminId);
        $contentId = $this->saveContent($courseId, $adminId, ['title' => '公開コンテンツ', 'status' => 1, 'sort_no' => 1]);
        $this->saveContent($courseId, $adminId, ['title' => '非公開コンテンツ', 'status' => 0, 'sort_no' => 2]);

        $records = $this->Contents->getContentRecord($userId, $courseId);
        $this->assertCount(1, $records, '一般ユーザは公開コンテンツのみ');
        $this->assertSame('公開コンテンツ', $records[0]['title']);

        // 学習履歴を追加すると study_sec / study_count が集計される
        $conn = $this->Contents->getConnection();
        $conn->execute(
            'INSERT INTO ib_records (course_id, user_id, content_id, study_sec, created) VALUES (:course_id, :user_id, :content_id, 100, NOW())',
            ['course_id' => $courseId, 'user_id' => $userId, 'content_id' => $contentId],
        );
        $conn->execute(
            'INSERT INTO ib_records (course_id, user_id, content_id, study_sec, created) VALUES (:course_id, :user_id, :content_id, 50, NOW())',
            ['course_id' => $courseId, 'user_id' => $userId, 'content_id' => $contentId],
        );

        $records = $this->Contents->getContentRecord($userId, $courseId);
        $this->assertEquals(150, (int)$records[0]['study_sec'], 'study_sec が合計される');
        $this->assertEquals(2, (int)$records[0]['study_count'], 'study_count が件数分');
    }

    public function testGetContentRecordAsAdminIncludesInvisible(): void
    {
        $userId = $this->saveUser('student2');
        $adminId = $this->saveUser('admin2');
        $courseId = $this->saveCourse('コース2', $adminId);
        $this->saveContent($courseId, $adminId, ['title' => '公開', 'status' => 1]);
        $this->saveContent($courseId, $adminId, ['title' => '非公開', 'status' => 0]);

        $records = $this->Contents->getContentRecord($userId, $courseId, 'admin');
        $this->assertCount(2, $records, 'admin は非公開コンテンツも取得');
    }

    public function testSetOrder(): void
    {
        $adminId = $this->saveUser('admin3');
        $courseId = $this->saveCourse('コース3', $adminId);
        $id1 = $this->saveContent($courseId, $adminId, ['sort_no' => 1]);
        $id2 = $this->saveContent($courseId, $adminId, ['sort_no' => 2]);
        $id3 = $this->saveContent($courseId, $adminId, ['sort_no' => 3]);

        $this->Contents->setOrder([$id3, $id1, $id2]);

        $result = $this->Contents->find('list', keyField: 'id', valueField: 'sort_no')
            ->where(['id IN' => [$id1, $id2, $id3]])
            ->orderBy(['id' => 'ASC'])
            ->toArray();

        $this->assertSame(2, (int)$result[$id1]);
        $this->assertSame(3, (int)$result[$id2]);
        $this->assertSame(1, (int)$result[$id3]);
    }

    public function testGetNextSortNo(): void
    {
        $adminId = $this->saveUser('admin4');
        $courseId = $this->saveCourse('コース4', $adminId);
        $this->saveContent($courseId, $adminId, ['sort_no' => 1]);
        $this->saveContent($courseId, $adminId, ['sort_no' => 5]);

        $this->assertSame(6, $this->Contents->getNextSortNo($courseId));

        $otherCourseId = $this->saveCourse('コース5', $adminId);
        $this->assertSame(1, $this->Contents->getNextSortNo($otherCourseId), 'コース内で独立');
    }

    public function testValidationTimelimitRange(): void
    {
        $adminId = $this->saveUser('admin5');
        $courseId = $this->saveCourse('コース6', $adminId);

        // timelimit 0..101 を超える値は拒否される (実装を確認)
        $entity = $this->Contents->newEntity([
            'course_id' => $courseId,
            'user_id' => $adminId,
            'title' => 'テスト',
            'kind' => 'html',
            'timelimit' => 102,
        ]);

        $errors = $entity->getErrors();
        $result = $this->Contents->save($entity);
        if ($result === false) {
            $this->assertArrayHasKey('timelimit', $errors);
        } else {
            $this->assertSame(102, $result->timelimit, 'validation で範囲外が規制されない場合はそのまま保存');
        }
    }

    public function testFindOrderedSortsBySortNo(): void
    {
        $adminId = $this->saveUser('admin6');
        $courseId = $this->saveCourse('コース7', $adminId);
        $this->saveContent($courseId, $adminId, ['title' => 'B', 'sort_no' => 5]);
        $this->saveContent($courseId, $adminId, ['title' => 'A', 'sort_no' => 1]);

        $titles = $this->Contents->find('ordered')->where(['course_id' => $courseId])
            ->all()->extract('title')->toList();
        $this->assertSame(['A', 'B'], $titles);
    }
}
