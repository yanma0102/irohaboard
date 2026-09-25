<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\ListRecordsTool;
use App\Service\AccessControlService;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * list_records ツールのテスト
 *
 * スタッフの user_id / course_id フィルタ・一般ユーザの自己限定（API と同一挙動）・
 * ページング meta を検証する。
 */
class ListRecordsToolTest extends TestCase
{
    private ListRecordsTool $tool;

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

        $this->tool = new ListRecordsTool(
            new AccessControlService($this->getTableLocator()->get('Users')->getConnection()),
        );
    }

    /**
     * oauth.user_id を _meta に持つ実 RequestContext を構築する
     */
    private function makeContext(int $userId, string $role = 'user'): RequestContext
    {
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_records',
                'arguments' => [],
                '_meta' => [
                    'oauth' => [
                        'oauth.user_id' => $userId,
                        'oauth.role' => $role,
                        'oauth.name' => 'テストユーザ',
                        'oauth.token_id' => 1,
                    ],
                ],
            ],
        ]);

        return new RequestContext(
            $this->createStub(SessionInterface::class),
            $request,
        );
    }

    private function createUser(string $username = 'recuser', string $role = 'user'): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $entity = $usersTable->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テストユーザ',
            'role' => $role,
            'email' => $username . '@example.com',
        ]);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    private function createCourse(): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $entity = $coursesTable->newEntity([
            'title' => 'レコード用コース',
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

    private function createContent(int $courseId): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => 1,
            'title' => 'レコード用コンテンツ',
            'kind' => 'html',
            'body' => '<p>本文</p>',
            'status' => 1,
            'sort_no' => 1,
        ]);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    private function createRecord(
        int $userId,
        int $courseId,
        int $contentId,
        array $overrides = [],
    ): EntityInterface {
        $recordsTable = $this->getTableLocator()->get('Records');
        $entity = $recordsTable->newEntity(array_merge([
            'course_id' => $courseId,
            'user_id' => $userId,
            'content_id' => $contentId,
            'full_score' => 100,
            'pass_score' => 60,
            'score' => 80,
            'is_passed' => 1,
            'is_complete' => 1,
            'progress' => 100,
            'understanding' => 3,
            'study_sec' => 600,
        ], $overrides));
        $result = $recordsTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    // ----------------------------------------------------------------
    // スタッフのフィルタ
    // ----------------------------------------------------------------

    /**
     * スタッフは全ユーザのレコードを無フィルタで参照できる
     */
    public function testStaffSeesRecordsOfAllUsers(): void
    {
        $course = $this->createCourse();
        $content = $this->createContent((int)$course->id);
        $userA = $this->createUser('recA');
        $userB = $this->createUser('recB');
        $admin = $this->createUser('recadmin', 'admin');
        $this->createRecord((int)$userA->id, (int)$course->id, (int)$content->id);
        $this->createRecord((int)$userB->id, (int)$course->id, (int)$content->id);

        $result = $this->tool->__invoke($this->makeContext((int)$admin->id, 'admin'));

        $this->assertArrayHasKey('data', $result);
        $this->assertSame(2, $result['meta']['total']);
        $userIds = array_column($result['data'], 'user_id');
        $this->assertContains((int)$userA->id, $userIds);
        $this->assertContains((int)$userB->id, $userIds);
    }

    /**
     * スタッフの user_id フィルタ
     */
    public function testStaffFilterByUserId(): void
    {
        $course = $this->createCourse();
        $content = $this->createContent((int)$course->id);
        $userA = $this->createUser('recA2');
        $userB = $this->createUser('recB2');
        $admin = $this->createUser('recadmin2', 'admin');
        $this->createRecord((int)$userA->id, (int)$course->id, (int)$content->id);
        $this->createRecord((int)$userB->id, (int)$course->id, (int)$content->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            null,
            (int)$userB->id,
        );

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame((int)$userB->id, (int)$result['data'][0]['user_id']);
    }

    /**
     * スタッフの course_id フィルタ
     */
    public function testStaffFilterByCourseId(): void
    {
        $course1 = $this->createCourse();
        $course2 = $this->createCourse();
        $content1 = $this->createContent((int)$course1->id);
        $content2 = $this->createContent((int)$course2->id);
        $user = $this->createUser('recC');
        $admin = $this->createUser('recadmin3', 'admin');
        $this->createRecord((int)$user->id, (int)$course1->id, (int)$content1->id);
        $this->createRecord((int)$user->id, (int)$course2->id, (int)$content2->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course2->id,
        );

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame((int)$course2->id, (int)$result['data'][0]['course_id']);
    }

    // ----------------------------------------------------------------
    // 一般ユーザの自己限定
    // ----------------------------------------------------------------

    /**
     * 一般ユーザは自分のレコードのみ（データ非空）を取得する
     */
    public function testNonStaffSeesOwnRecordsOnly(): void
    {
        $course = $this->createCourse();
        $content = $this->createContent((int)$course->id);
        $me = $this->createUser('recMe');
        $other = $this->createUser('recOther');
        $this->createRecord((int)$me->id, (int)$course->id, (int)$content->id);
        $this->createRecord((int)$other->id, (int)$course->id, (int)$content->id);

        $result = $this->tool->__invoke($this->makeContext((int)$me->id));

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame((int)$me->id, (int)$result['data'][0]['user_id']);
    }

    /**
     * 一般ユーザが他ユーザの user_id を指定しても自己に強制される（API と同一挙動）
     */
    public function testNonStaffForeignUserIdForcedToSelf(): void
    {
        $course = $this->createCourse();
        $content = $this->createContent((int)$course->id);
        $me = $this->createUser('recMe2');
        $other = $this->createUser('recOther2');
        $this->createRecord((int)$me->id, (int)$course->id, (int)$content->id);
        $this->createRecord((int)$other->id, (int)$course->id, (int)$content->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$me->id),
            null,
            (int)$other->id,
        );

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame((int)$me->id, (int)$result['data'][0]['user_id']);
    }

    /**
     * 履歴なし → 空 data + meta.total=0
     */
    public function testEmptyResultMeta(): void
    {
        $user = $this->createUser('recEmpty');

        $result = $this->tool->__invoke($this->makeContext((int)$user->id));

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['meta']['total']);
        $this->assertSame(0, $result['meta']['count']);
        $this->assertSame(1, $result['meta']['page']);
    }
}
