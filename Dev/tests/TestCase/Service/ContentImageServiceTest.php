<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Service;

use App\Service\ContentImageService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;

/**
 * ContentImageService のテスト
 *
 * 本文からの画像ファイル名抽出と、参照テーブル（ib_content_images）の同期、
 * およびコンテンツ保存・削除時の自動同期（モデルイベント）を検証する。
 */
class ContentImageServiceTest extends TestCase
{
    /**
     * @var \Cake\Database\Connection
     */
    private $connection;

    private ContentImageService $service;

    public function setUp(): void
    {
        parent::setUp();

        Configure::load('ib_config');

        $this->connection = $this->getTableLocator()->get('Contents')->getConnection();
        $this->cleanTables();

        $this->service = new ContentImageService($this->connection);
    }

    public function tearDown(): void
    {
        $this->cleanTables();

        parent::tearDown();
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
            ] as $table
        ) {
            $this->getTableLocator()->get($table)->deleteAll('1 = 1');
        }

        $this->connection->execute('DELETE FROM ib_content_images');
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    private function createUser(string $username, string $role = 'user'): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $entity = $usersTable->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => $username . 'の名前',
            'role' => $role,
            'email' => $username . '@example.com',
        ]);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result, 'ユーザの保存に失敗');

        return $result;
    }

    private function createCourse(int $userId, string $title = 'テストコース'): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $entity = $coursesTable->newEntity([
            'title' => $title,
            'user_id' => $userId,
        ]);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result, 'コースの保存に失敗');

        return $result;
    }

    private function createContent(int $courseId, int $userId, ?string $body, int $status = 1): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テストコンテンツ',
            'kind' => 'markdown',
            'body' => $body,
            'status' => $status,
            'sort_no' => 1,
        ]);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'コンテンツの保存に失敗');

        return $result;
    }

    /**
     * ib_content_images の該当行数を返す
     */
    private function countReferences(int $contentId, ?string $fileName = null): int
    {
        $params = ['content_id' => $contentId];
        $sql = 'SELECT COUNT(*) AS cnt FROM ib_content_images WHERE content_id = :content_id';
        if ($fileName !== null) {
            $sql .= ' AND file_name = :file_name';
            $params['file_name'] = $fileName;
        }

        $row = $this->connection->execute($sql, $params)->fetch('assoc');

        return (int)($row['cnt'] ?? 0);
    }

    // ----------------------------------------------------------------
    // 抽出
    // ----------------------------------------------------------------

    public function testExtractFileNamesFromMarkdownAndHtml(): void
    {
        $body = '<p>text</p>![alt](http://localhost:8082/contents/file-image/20260101000000aaaa.png)'
            . '<img src="/contents/file-image/20260101000000bbbb.jpg">';

        $this->assertSame(
            ['20260101000000aaaa.png', '20260101000000bbbb.jpg'],
            $this->service->extractFileNames($body),
        );
    }

    public function testExtractFileNamesFromLegacyUploadsUrl(): void
    {
        $body = '<img src="/uploads/20260101000000cccc.gif">';

        $this->assertSame(['20260101000000cccc.gif'], $this->service->extractFileNames($body));
    }

    public function testExtractFileNamesIgnoresNonImageExtensions(): void
    {
        $body = '<a href="/uploads/20260101000000dddd.pdf">pdf</a>'
            . '<video src="/contents/file-image/20260101000000eeee.mp4"></video>'
            . '![ok](/contents/file-image/20260101000000ffff.png)';

        $this->assertSame(['20260101000000ffff.png'], $this->service->extractFileNames($body));
    }

    public function testExtractFileNamesDeduplicates(): void
    {
        $body = '![a](/contents/file-image/20260101000000gggg.png)![b](/contents/file-image/20260101000000gggg.png)';

        $this->assertSame(['20260101000000gggg.png'], $this->service->extractFileNames($body));
    }

    public function testExtractFileNamesReturnsEmptyForEmptyBody(): void
    {
        $this->assertSame([], $this->service->extractFileNames(null));
        $this->assertSame([], $this->service->extractFileNames(''));
        $this->assertSame([], $this->service->extractFileNames('<p>画像なし</p>'));
    }

    // ----------------------------------------------------------------
    // 同期
    // ----------------------------------------------------------------

    public function testSyncForContentInsertsReferences(): void
    {
        $this->service->syncForContent(10, 3, '![a](/contents/file-image/20260101000000hhhh.png)');

        $this->assertSame(1, $this->countReferences(10, '20260101000000hhhh.png'));
    }

    public function testSyncForContentReplacesExistingReferences(): void
    {
        $this->service->syncForContent(11, 3, '![a](/contents/file-image/20260101000000iiii.png)');
        $this->assertSame(1, $this->countReferences(11));

        $this->service->syncForContent(11, 3, '![b](/contents/file-image/20260101000000jjjj.png)');

        $this->assertSame(1, $this->countReferences(11));
        $this->assertSame(0, $this->countReferences(11, '20260101000000iiii.png'));
        $this->assertSame(1, $this->countReferences(11, '20260101000000jjjj.png'));
    }

    public function testSyncForContentRemovesReferencesWhenBodyHasNoImage(): void
    {
        $this->service->syncForContent(12, 3, '![a](/contents/image-missing.png)');
        $this->service->syncForContent(12, 3, '<p>画像なし</p>');

        $this->assertSame(0, $this->countReferences(12));
    }

    public function testDeleteForContent(): void
    {
        $this->service->syncForContent(13, 3, '![a](/contents/file-image/20260101000000kkkk.png)');
        $this->service->deleteForContent(13);

        $this->assertSame(0, $this->countReferences(13));
    }

    public function testDeleteForCourse(): void
    {
        $this->service->syncForContent(14, 5, '![a](/contents/file-image/20260101000000llll.png)');
        $this->service->syncForContent(15, 6, '![b](/contents/file-image/20260101000000mmmm.png)');

        $this->service->deleteForCourse(5);

        $this->assertSame(0, $this->countReferences(14));
        $this->assertSame(1, $this->countReferences(15));
    }

    // ----------------------------------------------------------------
    // モデルイベント連携
    // ----------------------------------------------------------------

    public function testSavingContentSyncsReferences(): void
    {
        $user = $this->createUser('imgrefuser');
        $course = $this->createCourse((int)$user->id);

        $content = $this->createContent(
            (int)$course->id,
            (int)$user->id,
            '![a](/contents/file-image/20260101000000nnnn.png)',
        );

        $this->assertSame(1, $this->countReferences((int)$content->id, '20260101000000nnnn.png'));
    }

    public function testDeletingContentRemovesReferences(): void
    {
        $user = $this->createUser('imgrefdel');
        $course = $this->createCourse((int)$user->id);
        $content = $this->createContent(
            (int)$course->id,
            (int)$user->id,
            '![a](/contents/file-image/20260101000000oooo.png)',
        );
        $this->assertSame(1, $this->countReferences((int)$content->id));

        $this->getTableLocator()->get('Contents')->delete($content);

        $this->assertSame(0, $this->countReferences((int)$content->id));
    }

    // ----------------------------------------------------------------
    // 参照の問い合わせ
    // ----------------------------------------------------------------

    public function testFindReferencesReturnsCourseAndStatus(): void
    {
        $user = $this->createUser('imgrefind');
        $course = $this->createCourse((int)$user->id);
        $content = $this->createContent(
            (int)$course->id,
            (int)$user->id,
            '![a](/contents/file-image/20260101000000pppp.png)',
            0,
        );

        $references = $this->service->findReferences('20260101000000pppp.png');

        $this->assertCount(1, $references);
        $this->assertSame((int)$content->id, $references[0]['content_id']);
        $this->assertSame((int)$course->id, $references[0]['course_id']);
        $this->assertSame(0, $references[0]['status']);
    }

    public function testFindReferencesIgnoresDanglingRows(): void
    {
        $user = $this->createUser('imgreffree');
        $course = $this->createCourse((int)$user->id);
        $content = $this->createContent(
            (int)$course->id,
            (int)$user->id,
            '![a](/contents/file-image/20260101000000qqqq.png)',
        );

        // コンテンツだけを残骸として消す（参照行は残る）
        $this->connection->execute('DELETE FROM ib_contents WHERE id = :id', ['id' => (int)$content->id]);

        $this->assertSame([], $this->service->findReferences('20260101000000qqqq.png'));
    }

    public function testScanReferencedFileNames(): void
    {
        $user = $this->createUser('imgscan');
        $course = $this->createCourse((int)$user->id);
        $this->createContent((int)$course->id, (int)$user->id, '![a](/contents/file-image/20260101000000rrrr.png)');
        $this->createContent((int)$course->id, (int)$user->id, '![b](/contents/file-image/20260101000000ssss.png)');

        $names = $this->service->scanReferencedFileNames();
        sort($names);

        $this->assertSame(['20260101000000rrrr.png', '20260101000000ssss.png'], $names);
    }

    public function testRebuildAllRestoresReferences(): void
    {
        $user = $this->createUser('imgrebuild');
        $course = $this->createCourse((int)$user->id);
        $content = $this->createContent(
            (int)$course->id,
            (int)$user->id,
            '![a](/contents/file-image/20260101000000tttt.png)',
        );

        // 遡及展開前の状態を再現
        $this->connection->execute('DELETE FROM ib_content_images');
        $this->assertSame(0, $this->countReferences((int)$content->id));

        $processed = $this->service->rebuildAll();

        $this->assertSame(1, $processed);
        $this->assertSame(1, $this->countReferences((int)$content->id, '20260101000000tttt.png'));
    }
}
