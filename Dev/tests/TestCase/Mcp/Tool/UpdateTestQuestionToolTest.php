<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\UpdateTestQuestionTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * update_test_question ツールのテスト
 *
 * 権限（staff / コース参加）・部分更新・バリデーション・入力正規化を検証する。
 */
class UpdateTestQuestionToolTest extends TestCase
{
    private UpdateTestQuestionTool $tool;

    public function setUp(): void
    {
        parent::setUp();

        // Application::bootstrap() 相当（単体テストは Application を起動しないため）
        Configure::load('ib_config');

        foreach (
            [
            'UserTokens', 'Users', 'UsersCourses', 'GroupsCourses',
            'UsersGroups', 'Groups', 'Courses', 'Contents', 'ContentsQuestions',
            'Records', 'Logs',
            ] as $table
        ) {
            $this->getTableLocator()->get($table)->deleteAll('1 = 1');
        }

        $this->tool = new UpdateTestQuestionTool(
            new AccessControlService($this->getTableLocator()->get('Users')->getConnection()),
        );
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

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
                'name' => 'update_test_question',
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

    private function createUser(string $username = 'tooluser', string $role = 'user'): EntityInterface
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
            'title' => 'テストコース',
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

    private function createTestContent(int $courseId): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'course_id' => $courseId,
            'user_id' => 1,
            'title' => 'テストコンテンツ',
            'kind' => 'test',
            'body' => '',
            'status' => 1,
            'sort_no' => 1,
        ]);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    private function createQuestion(int $contentId, array $overrides = []): EntityInterface
    {
        $questionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $entity = $questionsTable->newEntity(array_merge([
            'content_id' => $contentId,
            'title' => 'テスト問題',
            'body' => '本文',
            'question_type' => 'single',
            'options' => 'A|B|C',
            'correct' => '1',
            'score' => 10,
            'sort_no' => 1,
        ], $overrides));
        $result = $questionsTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    // ----------------------------------------------------------------
    // 正常系
    // ----------------------------------------------------------------

    /**
     * スタッフが部分更新できる（タイトルのみ変更）
     */
    public function testStaffPartialUpdateTitle(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('upadmin01', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);
        $question = $this->createQuestion((int)$content->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$question->id,
            '更新後タイトル',
        );

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('更新後タイトル', $result['data']['title']);
        // 指定していないフィールドは変更されない
        $this->assertSame('本文', $result['data']['body']);

        $saved = $this->getTableLocator()->get('ContentsQuestions')->get((int)$question->id);
        $this->assertSame('更新後タイトル', $saved->title);
    }

    /**
     * options と correct を配列で更新できる
     */
    public function testUpdateOptionsAndCorrect(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('upadmin02', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);
        $question = $this->createQuestion((int)$content->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$question->id,
            null,
            null,
            null,
            ['X', 'Y', 'Z'],
            [2, 3],
        );

        $this->assertSame(['X', 'Y', 'Z'], $result['data']['options']);
        $this->assertSame([2, 3], $result['data']['correct']);

        $saved = $this->getTableLocator()->get('ContentsQuestions')->get((int)$question->id);
        $this->assertSame('X|Y|Z', $saved->options);
        $this->assertSame('2,3', $saved->correct);
    }

    /**
     * body だけ更新できる
     */
    public function testUpdateBody(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('upadmin03', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);
        $question = $this->createQuestion((int)$content->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$question->id,
            null,
            '新しい本文',
        );

        $this->assertSame('新しい本文', $result['data']['body']);
    }

    /**
     * question_type を single から text に変更し、options/correct をクリアできる
     */
    public function testChangeTypeToText(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('upadmin04', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);
        $question = $this->createQuestion((int)$content->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$question->id,
            null,
            null,
            'text',
            [],
            [],
        );

        $this->assertSame('text', $result['data']['question_type']);
        $this->assertNull($result['data']['options']);
        $this->assertSame([], $result['data']['correct']);
    }

    // ----------------------------------------------------------------
    // 権限制御
    // ----------------------------------------------------------------

    /**
     * 非スタッフは更新できない
     */
    public function testNonStaffDenied(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('upuser01');
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);
        $question = $this->createQuestion((int)$content->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Only staff members can update test questions.');

        $this->tool->__invoke(
            $this->makeContext((int)$user->id),
            (int)$question->id,
            '改ざんタイトル',
        );
    }

    /**
     * 存在しない問題 → Question not found
     */
    public function testNotFound(): void
    {
        $admin = $this->createUser('upadmin05', 'admin');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Question not found.');

        $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            999999,
            'タイトル',
        );
    }

    // ----------------------------------------------------------------
    // バリデーション
    // ----------------------------------------------------------------

    /**
     * 更新フィールドなし → No fields to update
     */
    public function testNoFieldsToUpdate(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('upadmin06', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);
        $question = $this->createQuestion((int)$content->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No fields to update.');

        $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$question->id,
        );
    }

    /**
     * options に | が含まれる場合は拒否
     */
    public function testOptionsWithPipeRejected(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('upadmin07', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);
        $question = $this->createQuestion((int)$content->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('pipe character');

        $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$question->id,
            null,
            null,
            null,
            ['A', 'B|C'],
            [1],
        );
    }

    /**
     * single に変更するときに correct が空なら拒否
     */
    public function testChangeToSingleWithoutCorrectRejected(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('upadmin08', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);
        // text 型の問題を作成
        $question = $this->createQuestion((int)$content->id, [
            'question_type' => 'text',
            'options' => null,
            'correct' => '',
        ]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('correct is required when question_type is single');

        $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$question->id,
            null,
            null,
            'single',
            ['A', 'B'],
            [],
        );
    }
}
