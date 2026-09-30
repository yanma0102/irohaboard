<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\CreateTestQuestionTool;
use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * create_test_question ツールのテスト
 *
 * 権限（staff / コース参加）・バリデーション・入力正規化・sort_no 自動採番を検証する。
 */
class CreateTestQuestionToolTest extends TestCase
{
    private CreateTestQuestionTool $tool;

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

        $this->tool = new CreateTestQuestionTool(
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
                'name' => 'create_test_question',
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

    // ----------------------------------------------------------------
    // 正常系
    // ----------------------------------------------------------------

    /**
     * スタッフが single 型の問題を新規作成できる（options/correct 変換）
     */
    public function testStaffCreatesSingleQuestion(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('qadmin01', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            '問題1',
            '1+1は？',
            'single',
            ['1', '2', '3'],
            [2],
            10,
            '足し算',
            '基礎',
            null,
        );

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('問題1', $result['data']['title']);
        $this->assertSame('single', $result['data']['question_type']);
        $this->assertSame(['1', '2', '3'], $result['data']['options']);
        $this->assertSame([2], $result['data']['correct']);
        $this->assertSame(10, $result['data']['score']);
        $this->assertSame(1, $result['data']['sort_no']);
        $this->assertIsInt($result['data']['id']);

        // DB に保存された値を検証（options は pipe 区切り、correct は comma 区切り）
        $saved = $this->getTableLocator()->get('ContentsQuestions')->get((int)$result['data']['id']);
        $this->assertSame('1|2|3', $saved->options);
        $this->assertSame('2', $saved->correct);
        $this->assertSame((int)$content->id, (int)$saved->content_id);
    }

    /**
     * スタッフが text 型の問題を新規作成できる（options/correct なし）
     */
    public function testStaffCreatesTextQuestion(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('qadmin02', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            '記述問題',
            '自分の意見を書きなさい。',
            'text',
        );

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('text', $result['data']['question_type']);
        $this->assertNull($result['data']['options']);
        $this->assertSame([], $result['data']['correct']);

        $saved = $this->getTableLocator()->get('ContentsQuestions')->get((int)$result['data']['id']);
        $this->assertNull($saved->options);
        $this->assertSame('', $saved->correct);
    }

    /**
     * sort_no は自動採番される（既存の末尾 + 1）
     */
    public function testSortNoAutoIncrement(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('qadmin03', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);

        // 既存問題を投入
        $questionsTable = $this->getTableLocator()->get('ContentsQuestions');
        $existing = $questionsTable->newEntity([
            'content_id' => (int)$content->id,
            'title' => '既存問題',
            'body' => '本文',
            'question_type' => 'single',
            'options' => 'A|B',
            'correct' => '1',
            'sort_no' => 3,
        ]);
        $this->assertNotFalse($questionsTable->save($existing));

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            '追加問題',
            '新しい問題',
            'text',
        );

        $this->assertSame(4, $result['data']['sort_no']);
    }

    /**
     * 複数の正解を選択できる（multiple choice）
     */
    public function testMultipleCorrectAnswers(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('qadmin04', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);

        $result = $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            '複数正解問題',
            '正しいものを2つ選べ',
            'single',
            ['ア', 'イ', 'ウ', 'エ'],
            [1, 3],
            20,
        );

        $this->assertSame([1, 3], $result['data']['correct']);

        $saved = $this->getTableLocator()->get('ContentsQuestions')->get((int)$result['data']['id']);
        $this->assertSame('1,3', $saved->correct);
    }

    // ----------------------------------------------------------------
    // 権限制御
    // ----------------------------------------------------------------

    /**
     * 非スタッフは作成できない
     */
    public function testNonStaffDenied(): void
    {
        $course = $this->createCourse();
        $user = $this->createUser('quser01');
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Only staff members can create test questions.');

        $this->tool->__invoke(
            $this->makeContext((int)$user->id),
            (int)$content->id,
            '問題',
            '本文',
        );
    }

    /**
     * 存在しない content は拒否される
     */
    public function testContentNotFound(): void
    {
        $admin = $this->createUser('qadmin05', 'admin');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Content not found.');

        $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            999999,
            '問題',
            '本文',
        );
    }

    /**
     * kind != test のコンテンツには問題を作成できない
     */
    public function testNonTestContentRejected(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('qadmin06', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);

        // kind=html のコンテンツを作成
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity([
            'course_id' => (int)$course->id,
            'user_id' => (int)$admin->id,
            'title' => '通常コンテンツ',
            'kind' => 'html',
            'body' => '<p>本文</p>',
            'status' => 1,
            'sort_no' => 1,
        ]);
        $content = $contentsTable->save($entity);
        $this->assertNotFalse($content);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not a test');

        $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            '問題',
            '本文',
        );
    }

    // ----------------------------------------------------------------
    // バリデーション
    // ----------------------------------------------------------------

    /**
     * options に | が含まれる場合は拒否
     */
    public function testOptionsWithPipeRejected(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('qadmin07', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('pipe character');

        $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            '問題',
            '本文',
            'single',
            ['A', 'B|C', 'D'],
            [1],
        );
    }

    /**
     * single で correct が空なら拒否
     */
    public function testSingleWithoutCorrectRejected(): void
    {
        $course = $this->createCourse();
        $admin = $this->createUser('qadmin08', 'admin');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $content = $this->createTestContent((int)$course->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('correct is required for question_type=single');

        $this->tool->__invoke(
            $this->makeContext((int)$admin->id, 'admin'),
            (int)$content->id,
            '問題',
            '本文',
            'single',
            ['A', 'B'],
            [],
        );
    }
}
