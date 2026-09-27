<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * API Contract & Permission Tests
 *
 * Verifies:
 *  1. Error contract shape (code + message, correct HTTP status)
 *  2. Method-not-allowed behaviour
 *  3. Table-driven permission matrix across roles
 *  4. Token edge cases (expired, revoked, malformed)
 */
class ApiContractTest extends TestCase
{
    use IntegrationTestTrait;

    // ----------------------------------------------------------------
    // setUp / tearDown
    // ----------------------------------------------------------------

    public function setUp(): void
    {
        parent::setUp();

        // Clean all tables that tests touch (order matters for FK)
        $this->getTableLocator()->get('UserTokens')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Records')->deleteAll('1 = 1');
        $this->getTableLocator()->get('ContentsQuestions')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Contents')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Courses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Logs')->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    // ----------------------------------------------------------------
    // Helper methods (same pattern as existing tests)
    // ----------------------------------------------------------------

    /**
     * Create a test user via TableRegistry.
     *
     * NOTE: username must be purely alphanumeric (a-zA-Z0-9),
     *       4-32 chars — enforced by UsersTable validation.
     */
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

    /**
     * Issue a token via POST /api/v1/auth/token and return the response data
     */
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

    /**
     * Authenticate request with Bearer token
     */
    private function authAs(string $token): void
    {
        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
    }

    /**
     * Create a test course
     */
    private function createCourse(string $title, int $userId, array $overrides = []): EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $data = array_merge([
            'title' => $title,
            'sort_no' => 1,
            'user_id' => $userId,
        ], $overrides);

        $entity = $coursesTable->newEntity($data);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result, "コース {$title} の作成に失敗");

        return $result;
    }

    /**
     * Create test content
     */
    private function createContent(int $courseId, int $userId, array $overrides = []): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $data = array_merge([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テストコンテンツ',
            'kind' => 'html',
            'body' => '<p>テスト</p>',
            'status' => 1,
            'sort_no' => 1,
        ], $overrides);

        $entity = $contentsTable->newEntity($data);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'コンテンツの作成に失敗');

        return $result;
    }

    /**
     * Assign a course to a user
     */
    private function assignCourse(int $userId, int $courseId): void
    {
        $table = $this->getTableLocator()->get('UsersCourses');
        $entity = $table->newEntity([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
        $this->assertNotFalse($table->save($entity), 'コース割当に失敗');
    }

    /**
     * Assert the JSON error contract shape.
     * Verifies: top-level "error" key, integer "code", string "message".
     */
    private function assertErrorContract(array $body, int $expectedCode, string $expectedMessage): void
    {
        $this->assertArrayHasKey('error', $body, 'Response must have top-level "error" key');
        $this->assertIsArray($body['error'], 'error must be an array');
        $this->assertArrayHasKey('code', $body['error'], 'error must have "code"');
        $this->assertSame($expectedCode, $body['error']['code'], 'error.code must match HTTP status');
        $this->assertArrayHasKey('message', $body['error'], 'error must have "message"');
        $this->assertSame($expectedMessage, $body['error']['message']);
    }

    // ================================================================
    // 1. ERROR CONTRACT SHAPE
    // ================================================================

    /**
     * 401 Unauthenticated — missing Authorization header entirely
     */
    public function testErrorContract401Unauthenticated(): void
    {
        $this->get('/api/v1/users');

        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertErrorContract($body, 401, 'Authorization header is missing');
    }

    /**
     * 403 Forbidden — user role hitting a manager-only endpoint
     */
    public function testErrorContract403Forbidden(): void
    {
        $user = $this->createUser('forbiddenusr', ['role' => 'user']);
        $tokenData = $this->issueToken('forbiddenusr');
        $this->authAs($tokenData['token']);

        $this->post('/api/v1/users', [
            'username' => 'shouldnotexist',
            'password' => 'pass',
            'name' => 'Forbidden',
        ]);

        $this->assertResponseCode(403);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertErrorContract($body, 403, 'This action requires an admin or manager role');
    }

    /**
     * 404 Not Found — unknown /api/v1/* route
     */
    public function testErrorContract404UnknownRoute(): void
    {
        $this->get('/api/v1/__nope__');

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertErrorContract($body, 404, 'Endpoint not found');
    }

    /**
     * 404 Not Found — known endpoint, non-existent resource
     */
    public function testErrorContract404ResourceNotFound(): void
    {
        $admin = $this->createUser('admin404', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin404');
        $this->authAs($tokenData['token']);

        $this->get('/api/v1/users/99999');

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertErrorContract($body, 404, 'User not found');
    }

    /**
     * 400 Validation failure — POST /api/v1/users with missing required fields
     */
    public function testErrorContract400ValidationFailure(): void
    {
        $admin = $this->createUser('adminval', ['role' => 'admin']);
        $tokenData = $this->issueToken('adminval');
        $this->authAs($tokenData['token']);

        // POST without required 'username' field
        $this->post('/api/v1/users', [
            'name' => '名前のみ',
        ]);

        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertErrorContract($body, 400, 'Validation failed');

        // Verify 'errors' key is present (validation details)
        $this->assertArrayHasKey('errors', $body['error'], 'Validation error should include "errors" details');
    }

    /**
     * 400 Missing password — POST /api/v1/auth/token without password
     */
    public function testErrorContract400MissingField(): void
    {
        $this->post('/api/v1/auth/token', [
            'username' => 'someone',
        ]);

        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertErrorContract($body, 400, 'username and password are required');
    }

    // ================================================================
    // 2. METHOD-NOT-ALLOWED
    // ================================================================

    /**
     * POST /api/v1/records → 404 (not 405)
     *
     * Records routes only define GET /records and GET /records/{id}.
     * The app's catch-all route `/api/*` maps undefined paths to
     * Errors::notFound, which returns 404 before CakePHP's built-in
     * method-not-allowed check fires. This documents the current
     * application behaviour.
     */
    public function testMethodNotAllowedPostRecords(): void
    {
        $admin = $this->createUser('adminmna', ['role' => 'admin']);
        $tokenData = $this->issueToken('adminmna');
        $this->authAs($tokenData['token']);

        $this->post('/api/v1/records', [
            'course_id' => 1,
            'content_id' => 1,
            'score' => 80,
        ]);

        // DOCUMENTED BEHAVIOUR: The catch-all /api/* route sends this to
        // ErrorsController::notFound → 404, not 405.
        $this->assertResponseCode(404);
    }

    /**
     * PUT /api/v1/records/1 → 404 (not 405)
     *
     * Same as above — catch-all route returns 404 for undefined method
     * on an existing path prefix.
     */
    public function testMethodNotAllowedPutRecords(): void
    {
        $admin = $this->createUser('admimna2', ['role' => 'admin']);
        $tokenData = $this->issueToken('admimna2');
        $this->authAs($tokenData['token']);

        $this->put('/api/v1/records/1', [
            'score' => 90,
        ]);

        // DOCUMENTED BEHAVIOUR: catch-all → 404
        $this->assertResponseCode(404);
    }

    /**
     * DELETE /api/v1/records/1 → 404 (not 405)
     */
    public function testMethodNotAllowedDeleteRecords(): void
    {
        $admin = $this->createUser('admimna3', ['role' => 'admin']);
        $tokenData = $this->issueToken('admimna3');
        $this->authAs($tokenData['token']);

        $this->delete('/api/v1/records/1');

        // DOCUMENTED BEHAVIOUR: catch-all → 404
        $this->assertResponseCode(404);
    }

    // ================================================================
    // 3. PERMISSION MATRIX (table-driven)
    // ================================================================

    /**
     * Data provider for role → expected status on POST /api/v1/users
     *
     * admin, manager → 201 (allowed)
     * editor, teacher, user → 403 (forbidden)
     *
     * @return array<string, array{string, int}>
     */
    public static function addUserRoleProvider(): array
    {
        return [
            'admin' => ['admin', 201],
            'manager' => ['manager', 201],
            'editor' => ['editor', 403],
            'teacher' => ['teacher', 403],
            'user' => ['user', 403],
        ];
    }

    /**
     * POST /api/v1/users — manager+ allowed, others 403
     */
    #[DataProvider('addUserRoleProvider')]
    public function testPermissionPostUsers(string $role, int $expectedStatus): void
    {
        $actor = $this->createUser("permadduser{$role}", ['role' => $role]);
        $tokenData = $this->issueToken("permadduser{$role}");
        $this->authAs($tokenData['token']);

        $this->post('/api/v1/users', [
            'username' => "createdby{$role}",
            'password' => 'pass123',
            'name' => "Created by {$role}",
            'role' => 'user',
        ]);

        $this->assertResponseCode($expectedStatus);

        // Verify error shape for 403
        if ($expectedStatus === 403) {
            $body = json_decode((string)$this->_response->getBody(), true);
            $this->assertErrorContract($body, 403, 'This action requires an admin or manager role');
        }
    }

    /**
     * DELETE /api/v1/users/{id} — permission + self-deletion forbidden
     *
     * admin, manager → 200 (deleting another user)
     * editor, teacher, user → 403
     *
     * @return array<string, array{string, int}>
     */
    public static function deleteUserRoleProvider(): array
    {
        return [
            'admin' => ['admin', 200],
            'manager' => ['manager', 200],
            'editor' => ['editor', 403],
            'teacher' => ['teacher', 403],
            'user' => ['user', 403],
        ];
    }

    /**
     * @dataProvider deleteUserRoleProvider
     */
    #[DataProvider('deleteUserRoleProvider')]
    public function testPermissionDeleteUsers(string $role, int $expectedStatus): void
    {
        // Actor with the given role
        $actor = $this->createUser("permdeluser{$role}", ['role' => $role]);
        // Target user to be deleted
        $target = $this->createUser("permtarget{$role}", ['role' => 'user']);

        $tokenData = $this->issueToken("permdeluser{$role}");
        $this->authAs($tokenData['token']);

        $this->delete('/api/v1/users/' . $target->id);

        $this->assertResponseCode($expectedStatus);

        if ($expectedStatus === 403) {
            $body = json_decode((string)$this->_response->getBody(), true);
            $this->assertErrorContract($body, 403, 'This action requires an admin or manager role');
        }
    }

    /**
     * Self-deletion must be forbidden even for admin
     */
    public function testDeleteSelfForbiddenEvenForAdmin(): void
    {
        $admin = $this->createUser('adminselfdel', ['role' => 'admin']);
        $tokenData = $this->issueToken('adminselfdel');
        $this->authAs($tokenData['token']);

        // Fetch the admin's own ID
        $usersTable = $this->getTableLocator()->get('Users');
        $adminEntity = $usersTable->find()->where(['username' => 'adminselfdel'])->firstOrFail();

        $this->delete('/api/v1/users/' . $adminEntity->id);

        // Self-deletion returns 400 "Cannot delete your own account"
        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertErrorContract($body, 400, 'Cannot delete your own account');
    }

    // ---- Contents write permissions ----

    /**
     * Data provider for role → expected status on POST /api/v1/contents
     *
     * Staff (admin/manager/editor/teacher) → 201 (created)
     * User → 403
     *
     * @return array<string, array{string, int}>
     */
    public static function contentsCreateRoleProvider(): array
    {
        return [
            'admin' => ['admin', 201],
            'manager' => ['manager', 201],
            'editor' => ['editor', 201],
            'teacher' => ['teacher', 201],
            'user' => ['user', 403],
        ];
    }

    /**
     * Data provider for role → expected status on PUT/DELETE /api/v1/contents/{id}
     *
     * Staff (admin/manager/editor/teacher) → 200 (updated/deleted)
     * User → 403
     *
     * @return array<string, array{string, int}>
     */
    public static function contentsWriteRoleProvider(): array
    {
        return [
            'admin' => ['admin', 200],
            'manager' => ['manager', 200],
            'editor' => ['editor', 200],
            'teacher' => ['teacher', 200],
            'user' => ['user', 403],
        ];
    }

    /**
     * POST /api/v1/contents — staff allowed, user 403
     */
    #[DataProvider('contentsCreateRoleProvider')]
    public function testPermissionPostContents(string $role, int $expectedStatus): void
    {
        $actor = $this->createUser("permaddcont{$role}", ['role' => $role]);
        $course = $this->createCourse("permcourse{$role}", (int)$actor->id);
        $this->assignCourse((int)$actor->id, (int)$course->id);

        $tokenData = $this->issueToken("permaddcont{$role}");
        $this->authAs($tokenData['token']);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => "コンテンツ by {$role}",
            'kind' => 'html',
            'body' => '<p>test</p>',
        ]);

        $this->assertResponseCode($expectedStatus);

        if ($expectedStatus === 403) {
            $body = json_decode((string)$this->_response->getBody(), true);
            $this->assertErrorContract($body, 403, 'This action requires a staff role');
        }
    }

    /**
     * PUT /api/v1/contents/{id} — staff allowed, user 403
     */
    #[DataProvider('contentsWriteRoleProvider')]
    public function testPermissionPutContents(string $role, int $expectedStatus): void
    {
        // Setup: an admin creates a course and content
        $admin = $this->createUser('setupadmincont', ['role' => 'admin']);
        $course = $this->createCourse('setupcourse', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        // Actor with the given role
        $actor = $this->createUser("permeditcont{$role}", ['role' => $role]);
        // Staff need access to the course
        if ($role !== 'user') {
            $this->assignCourse((int)$actor->id, (int)$course->id);
        }

        $tokenData = $this->issueToken("permeditcont{$role}");
        $this->authAs($tokenData['token']);

        $this->put('/api/v1/contents/' . $content->id, [
            'title' => 'Updated by ' . $role,
        ]);

        $this->assertResponseCode($expectedStatus);

        if ($expectedStatus === 403) {
            $body = json_decode((string)$this->_response->getBody(), true);
            $this->assertErrorContract($body, 403, 'This action requires a staff role');
        }
    }

    /**
     * DELETE /api/v1/contents/{id} — staff allowed, user 403
     */
    #[DataProvider('contentsWriteRoleProvider')]
    public function testPermissionDeleteContents(string $role, int $expectedStatus): void
    {
        // Setup: an admin creates a course and content
        $admin = $this->createUser('setupadmindel', ['role' => 'admin']);
        $course = $this->createCourse('setupcoursedel', (int)$admin->id);
        $this->assignCourse((int)$admin->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        // Actor with the given role
        $actor = $this->createUser("permdelcont{$role}", ['role' => $role]);
        if ($role !== 'user') {
            $this->assignCourse((int)$actor->id, (int)$course->id);
        }

        $tokenData = $this->issueToken("permdelcont{$role}");
        $this->authAs($tokenData['token']);

        $this->delete('/api/v1/contents/' . $content->id);

        $this->assertResponseCode($expectedStatus);

        if ($expectedStatus === 403) {
            $body = json_decode((string)$this->_response->getBody(), true);
            $this->assertErrorContract($body, 403, 'This action requires a staff role');
        }
    }

    // ---- Courses read permissions ----

    /**
     * GET /api/v1/courses — staff sees all, user sees only accessible
     */
    public function testGetCoursesStaffSeesAll(): void
    {
        $admin = $this->createUser('admincrs1', ['role' => 'admin']);
        $course1 = $this->createCourse('全コース1', (int)$admin->id);
        $course2 = $this->createCourse('全コース2', (int)$admin->id);

        $tokenData = $this->issueToken('admincrs1');
        $this->authAs($tokenData['token']);

        $this->get('/api/v1/courses');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        // Staff should see both courses
        $this->assertGreaterThanOrEqual(2, $body['meta']['total'], 'Staff should see all courses');
    }

    /**
     * GET /api/v1/courses — user sees only accessible courses
     */
    public function testGetCoursesUserSeesOnlyAccessible(): void
    {
        $admin = $this->createUser('admincrs2', ['role' => 'admin']);
        $user = $this->createUser('usercrs1', ['role' => 'user']);

        $courseAccessible = $this->createCourse('アクセス可能コース', (int)$admin->id);
        $courseInaccessible = $this->createCourse('アクセス不可コース', (int)$admin->id);

        // Only assign accessible course to user
        $this->assignCourse((int)$user->id, (int)$courseAccessible->id);

        $tokenData = $this->issueToken('usercrs1');
        $this->authAs($tokenData['token']);

        $this->get('/api/v1/courses');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame(1, $body['meta']['total'], 'User should only see assigned courses');
        $this->assertSame('アクセス可能コース', $body['data'][0]['title']);
    }

    /**
     * GET /api/v1/courses — user with no courses gets empty list
     */
    public function testGetCoursesUserNoCoursesReturnsEmpty(): void
    {
        $admin = $this->createUser('admincrs3', ['role' => 'admin']);
        $user = $this->createUser('usercrs2', ['role' => 'user']);

        $this->createCourse('存在するが未割当', (int)$admin->id);

        $tokenData = $this->issueToken('usercrs2');
        $this->authAs($tokenData['token']);

        $this->get('/api/v1/courses');
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertCount(0, $body['data']);
    }

    // ================================================================
    // 4. TOKEN EDGE CASES
    // ================================================================

    /**
     * Expired token → 401
     */
    public function testExpiredTokenReturns401(): void
    {
        $user = $this->createUser('expiredusr', ['role' => 'user']);

        // Issue a valid token, then manually back-date its expiry
        $tokenData = $this->issueToken('expiredusr');
        $validToken = $tokenData['token'];

        $parts = explode(':', $validToken);
        $userTokensTable = $this->getTableLocator()->get('UserTokens');
        $userTokensTable->updateQuery()
            ->set(['expired' => date('Y-m-d H:i:s', strtotime('-1 day'))])
            ->where(['token_selector' => $parts[0]])
            ->execute();

        $this->authAs($validToken);
        $this->get('/api/v1/users');

        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertErrorContract($body, 401, 'Invalid or expired API token');
    }

    /**
     * Revoked token → 401
     */
    public function testRevokedTokenReturns401(): void
    {
        $user = $this->createUser('revokedusr', ['role' => 'user']);

        // Issue a valid token, then revoke it
        $tokenData = $this->issueToken('revokedusr');
        $validToken = $tokenData['token'];

        $parts = explode(':', $validToken);
        $userTokensTable = $this->getTableLocator()->get('UserTokens');
        $userTokensTable->updateQuery()
            ->set(['revoked' => date('Y-m-d H:i:s')])
            ->where(['token_selector' => $parts[0]])
            ->execute();

        $this->authAs($validToken);
        $this->get('/api/v1/users');

        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertErrorContract($body, 401, 'Invalid or expired API token');
    }

    /**
     * Malformed Authorization header — missing Bearer scheme → 401
     */
    public function testMalformedHeaderMissingBearerScheme(): void
    {
        $this->configRequest([
            'headers' => ['Authorization' => 'Basic abc123'],
        ]);
        $this->get('/api/v1/users');

        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertErrorContract($body, 401, 'Authorization header must use the Bearer scheme');
    }

    /**
     * Malformed Authorization header — "Bearer " with trailing space, no token
     *
     * The regex `/^Bearer\s+(.+)$/i` requires at least one char after whitespace,
     * so "Bearer " (only whitespace after Bearer) does NOT match → scheme error.
     */
    public function testMalformedHeaderBearerNoToken(): void
    {
        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer '],
        ]);
        $this->get('/api/v1/users');

        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        // "Bearer " matches the preg_match but (.) requires 1+ chars after \s+
        // so it falls through to the scheme error.
        $this->assertErrorContract($body, 401, 'Authorization header must use the Bearer scheme');
    }

    /**
     * Malformed Authorization header — just "Bearer" word, no space
     */
    public function testMalformedHeaderBearerAlone(): void
    {
        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer'],
        ]);
        $this->get('/api/v1/users');

        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertErrorContract($body, 401, 'Authorization header must use the Bearer scheme');
    }

    /**
     * Totally bogus token string → 401
     */
    public function testTotallyBogusTokenReturns401(): void
    {
        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer notarealtoken'],
        ]);
        $this->get('/api/v1/users');

        $this->assertResponseCode(401);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertErrorContract($body, 401, 'Invalid or expired API token');
    }

    // ================================================================
    // 5. BONUS: error shape sanity checks
    // ================================================================

    /**
     * Unknown /api/v1 route returns proper error shape with 404
     */
    public function testUnknownApiRouteErrorShape(): void
    {
        $this->get('/api/v1/completely/unknown/path');

        $this->assertResponseCode(404);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertArrayHasKey('code', $body['error']);
        $this->assertArrayHasKey('message', $body['error']);
        $this->assertSame(404, $body['error']['code']);
        $this->assertIsString($body['error']['message']);
        $this->assertNotEmpty($body['error']['message']);
    }

    /**
     * Error response does NOT include "data" key — it always uses "error"
     */
    public function testErrorResponsesNeverIncludeDataKey(): void
    {
        $this->get('/api/v1/users');

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertArrayNotHasKey('data', $body, 'Error responses must not include "data" key');
    }
}
