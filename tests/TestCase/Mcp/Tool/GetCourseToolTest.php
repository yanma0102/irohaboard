<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\GetCourseTool;
use App\Service\AccessControlService;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * get_course ツールのテスト
 *
 * staff 迂回・未受講拒否・不存在・削除済みコースを検証する。
 */
class GetCourseToolTest extends TestCase
{
    private GetCourseTool $tool;

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

        $this->tool = new GetCourseTool(
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
                'name' => 'get_course',
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

    private function createUser(string $username = 'courseuser', string $role = 'user'): EntityInterface
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
        $this->assertNotFalse($table->save($table->newEntity([
            'user_id' => $userId,
            'course_id' => $courseId,
        ])));
    }

    // ----------------------------------------------------------------
    // 正常系
    // ----------------------------------------------------------------

    /**
     * スタッフは未受講のコースも取得できる
     */
    public function testStaffCanAccessAnyCourse(): void
    {
        $course = $this->createCourse('スタッフ参照');
        $admin = $this->createUser('cadmin01', 'admin');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
        );

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('スタッフ参照', $result['data']['title']);
    }

    /**
     * 受講生は受講中のコースを取得できる
     */
    public function testEnrolledStudentCanAccess(): void
    {
        $course = $this->createCourse('受講中コース');
        $user = $this->createUser('cstudent01');
        $this->enrollUser((int)$user->id, (int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$user->id),
            (int)$course->id,
        );

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('受講中コース', $result['data']['title']);
        $this->assertSame((int)$course->id, $result['data']['id']);
    }

    // ----------------------------------------------------------------
    // 権限制御・異常系
    // ----------------------------------------------------------------

    /**
     * 未受講の受講生 → Access denied
     */
    public function testNonEnrolledStudentDenied(): void
    {
        $course = $this->createCourse('未受講コース');
        $user = $this->createUser('cstranger01');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$user->id),
            (int)$course->id,
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Access denied', $result['error']);
    }

    /**
     * 存在しないコース → Course not found
     */
    public function testCourseNotFound(): void
    {
        $admin = $this->createUser('cadmin02', 'admin');

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            999999,
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Course not found.', $result['error']);
    }

    /**
     * 削除済み（ソフトデリート）コースは staff でも Course not found
     */
    public function testDeletedCourseHiddenEvenForStaff(): void
    {
        $course = $this->createCourse('削除対象コース');
        $admin = $this->createUser('cadmin03', 'admin');

        $coursesTable = $this->getTableLocator()->get('Courses');
        $course->deleted = date('Y-m-d H:i:s');
        $this->assertNotFalse($coursesTable->save($course));

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$course->id,
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Course not found.', $result['error']);
    }
}
