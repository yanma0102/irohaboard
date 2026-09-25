<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Test\TestCase\Controller;

use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use stdClass;

/**
 * MCP エンドポイント（POST /mcp）の統合テスト
 *
 * - 401（トークンなし・不正トークン）
 * - initialize ハンドシェイク + セッション運用（ハンドシェイクera）
 * - tools/list・tools/call（読み取り系ツール）
 * - 権限制御（受講生のアクセス制御）
 * - Content-Type 保持
 * - レート制限（60 req/min）
 * - モダン era（ステートレス）リクエスト
 */
class McpControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private const PROTOCOL_VERSION = '2025-03-26';
    private const MODERN_VERSION = '2026-07-28';

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
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    private function createUser(string $username = 'mcpuser', string $role = 'user'): EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $entity = $usersTable->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'MCPテスト',
            'role' => $role,
            'email' => $username . '@example.com',
        ]);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    private function issueToken(int $userId): string
    {
        $token = $this->getTableLocator()->get('UserTokens')->issueApiToken($userId);
        $this->assertNotNull($token, 'トークン発行に失敗');

        return $token;
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

    private function createContent(int $courseId, array $overrides = []): EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity(array_merge([
            'course_id' => $courseId,
            'user_id' => 1,
            'title' => 'MCPコンテンツ',
            'kind' => 'markdown',
            'body' => "# MCP\n\n本文",
            'status' => 1,
            'sort_no' => 1,
        ], $overrides));
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result);

        return $result;
    }

    /**
     * POST /mcp へ JSON-RPC ペイロードを送る
     *
     * @param array<string, string> $headers 追加ヘッダ（Authorization 等）
     * @param array<string, mixed> $payload JSON-RPC メッセージ
     * @return void
     */
    private function mcpRequest(array $headers, array $payload): void
    {
        $this->configRequest([
            'headers' => ['Content-Type' => 'application/json'] + $headers,
        ]);
        $this->post('/mcp', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * initialize → notifications/initialized のハンドシェイクを行い
     * セッションIDを返す
     */
    private function initializeSession(string $token): string
    {
        $auth = ['Authorization' => 'Bearer ' . $token];

        $this->mcpRequest($auth, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ]);
        $this->assertResponseOk();
        $this->assertSame(
            'application/json',
            explode(';', $this->_response->getHeaderLine('Content-Type'))[0],
            'Content-Type は application/json のまま保持される',
        );

        $sessionId = $this->_response->getHeaderLine('Mcp-Session-Id');
        $this->assertNotEmpty($sessionId, 'initialize 応答に Mcp-Session-Id が含まれる');

        $this->mcpRequest($auth + ['Mcp-Session-Id' => $sessionId], [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);
        if ($this->_response->getStatusCode() !== 202) {
            fwrite(STDERR, 'DEBUG status=' . $this->_response->getStatusCode() . ' body=' . (string)$this->_response->getBody() . ' ctype=' . $this->_response->getHeaderLine('Content-Type') . PHP_EOL);
        }
        $this->assertResponseCode(202);

        return $sessionId;
    }

    /**
     * tools/call を実行し、result を返す
     *
     * @return array<string, mixed>
     */
    private function callTool(
        string $token,
        string $sessionId,
        string $toolName,
        array $arguments = [],
    ): array {
        $this->mcpRequest([
            'Authorization' => 'Bearer ' . $token,
            'Mcp-Session-Id' => $sessionId,
        ], [
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments,
            ],
        ]);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('result', $body, 'tools/call は result を返す');

        return $body['result'];
    }

    /**
     * tools/call 応答の TextContent(JSON) をデコードする
     *
     * @return array<string, mixed>
     */
    private function decodeToolResult(array $result): array
    {
        $text = $result['content'][0]['text'];
        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded, 'ツール結果は JSON としてデコードできる');

        return $decoded;
    }

    // ----------------------------------------------------------------
    // 認証
    // ----------------------------------------------------------------

    /**
     * トークンなし initialize → 401 + WWW-Authenticate
     */
    public function testInitializeUnauthorizedWithoutToken(): void
    {
        $this->mcpRequest([], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ]);

        $this->assertResponseCode(401);
        $wwwAuthenticate = $this->_response->getHeaderLine('WWW-Authenticate');
        $this->assertStringContainsString('Bearer', $wwwAuthenticate);
        $this->assertStringContainsString('invalid_token', $wwwAuthenticate);
    }

    /**
     * 不正トークン initialize → 401
     */
    public function testInitializeUnauthorizedWithBadToken(): void
    {
        $this->mcpRequest(['Authorization' => 'Bearer not-a-valid-token'], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ]);

        $this->assertResponseCode(401);
        $this->assertStringContainsString(
            'invalid_token',
            $this->_response->getHeaderLine('WWW-Authenticate'),
        );
    }

    // ----------------------------------------------------------------
    // initialize / tools/list
    // ----------------------------------------------------------------

    /**
     * 有効トークンで initialize → serverInfo.name が 'iroha Board MCP'
     */
    public function testInitializeSuccess(): void
    {
        $user = $this->createUser('admin01', 'admin');
        $token = $this->issueToken((int)$user->id);

        $this->mcpRequest(['Authorization' => 'Bearer ' . $token], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ]);

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('iroha Board MCP', $body['result']['serverInfo']['name']);
        $this->assertNotEmpty($this->_response->getHeaderLine('Mcp-Session-Id'));
    }

    /**
     * tools/list → 読み取り系ツール7個 + 書き込み系ツール2個が返る
     */
    public function testToolsListContainsAllTools(): void
    {
        $user = $this->createUser('admin02', 'admin');
        $token = $this->issueToken((int)$user->id);
        $sessionId = $this->initializeSession($token);

        $this->mcpRequest([
            'Authorization' => 'Bearer ' . $token,
            'Mcp-Session-Id' => $sessionId,
        ], [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $names = array_column($body['result']['tools'], 'name');

        foreach (
            [
            'list_courses', 'get_course', 'list_contents', 'get_content',
            'get_content_html', 'list_records', 'get_user_profile',
            'create_content', 'update_content',
            ] as $expected
        ) {
            $this->assertContains($expected, $names, "{$expected} が登録されている");
        }

        $this->assertCount(9, $names, 'ツールは 9 個（読み取り7 + 書き込み2）');
    }

    // ----------------------------------------------------------------
    // ツール実行（権限制御込み）
    // ----------------------------------------------------------------

    /**
     * 受講生は list_contents で自分のコースのコンテンツを取得できる
     */
    public function testListContentsAccessible(): void
    {
        $user = $this->createUser('student01');
        $token = $this->issueToken((int)$user->id);
        $course = $this->createCourse();
        $this->enrollUser((int)$user->id, (int)$course->id);
        $this->createContent((int)$course->id, ['title' => '受講可能コンテンツ']);
        $sessionId = $this->initializeSession($token);

        $result = $this->callTool($token, $sessionId, 'list_contents', [
            'course_id' => (int)$course->id,
        ]);
        $data = $this->decodeToolResult($result);

        $this->assertArrayHasKey('data', $data);
        $this->assertSame('受講可能コンテンツ', $data['data'][0]['title']);
    }

    /**
     * 未受講の受講生は list_contents で Access denied
     */
    public function testListContentsAccessDenied(): void
    {
        $user = $this->createUser('student02');
        $token = $this->issueToken((int)$user->id);
        $course = $this->createCourse();
        $this->createContent((int)$course->id);
        $sessionId = $this->initializeSession($token);

        $result = $this->callTool($token, $sessionId, 'list_contents', [
            'course_id' => (int)$course->id,
        ]);
        $data = $this->decodeToolResult($result);

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Access denied', $data['error']);
    }

    /**
     * list_courses は受講生には受講可能コースのみ返す
     */
    public function testListCoursesReturnsEnrolledOnly(): void
    {
        $user = $this->createUser('student03');
        $token = $this->issueToken((int)$user->id);
        $enrolled = $this->createCourse('受講中コース');
        $this->createCourse('未受講コース');
        $this->enrollUser((int)$user->id, (int)$enrolled->id);
        $sessionId = $this->initializeSession($token);

        $result = $this->callTool($token, $sessionId, 'list_courses');
        $data = $this->decodeToolResult($result);

        $titles = array_column($data['data'], 'title');
        $this->assertContains('受講中コース', $titles);
        $this->assertNotContains('未受講コース', $titles);
    }

    /**
     * get_content は生の body を返し、get_content_html はレンダリング結果を返す
     */
    public function testGetContentAndHtml(): void
    {
        $user = $this->createUser('student04');
        $token = $this->issueToken((int)$user->id);
        $course = $this->createCourse();
        $this->enrollUser((int)$user->id, (int)$course->id);
        $content = $this->createContent((int)$course->id, [
            'kind' => 'markdown',
            'body' => "# 見出し\n\n<script>alert(1)</script>",
        ]);
        $sessionId = $this->initializeSession($token);

        $raw = $this->decodeToolResult($this->callTool(
            $token,
            $sessionId,
            'get_content',
            ['content_id' => (int)$content->id],
        ));
        $this->assertSame("# 見出し\n\n<script>alert(1)</script>", $raw['data']['body']);

        $rendered = $this->decodeToolResult($this->callTool(
            $token,
            $sessionId,
            'get_content_html',
            ['content_id' => (int)$content->id],
        ));
        $this->assertStringContainsString('<h1', $rendered['data']['html']);
        $this->assertStringNotContainsString('<script>', $rendered['data']['html']);
    }

    // ----------------------------------------------------------------
    // レート制限
    // ----------------------------------------------------------------

    /**
     * 直近1分に60リクエスト到達 → 429
     */
    public function testRateLimitReturns429(): void
    {
        $user = $this->createUser('ratelimit01');
        $token = $this->issueToken((int)$user->id);

        $logsTable = $this->getTableLocator()->get('Logs');
        $connection = $logsTable->getConnection();
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = [
                'log_type' => 'mcp_request',
                'log_content' => (string)$user->id,
                'user_id' => (int)$user->id,
                'user_ip' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'created' => date('Y-m-d H:i:s'),
            ];
        }
        foreach ($rows as $row) {
            $connection->insert($logsTable->getTable(), $row);
        }

        $this->mcpRequest(['Authorization' => 'Bearer ' . $token], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ]);

        $this->assertResponseCode(429);
        $this->assertNotEmpty($this->_response->getHeaderLine('Retry-After'));
    }

    // ----------------------------------------------------------------
    // モダン era（ステートレス）
    // ----------------------------------------------------------------

    /**
     * モダン era ヘッダ + _meta プロトコル宣言でセッションなし tools/list が可能
     */
    public function testModernStatelessToolsList(): void
    {
        $user = $this->createUser('admin03', 'admin');
        $token = $this->issueToken((int)$user->id);

        $this->mcpRequest([
            'Authorization' => 'Bearer ' . $token,
            'MCP-Protocol-Version' => self::MODERN_VERSION,
            'Mcp-Method' => 'tools/list',
        ], [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::MODERN_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
                ],
            ],
        ]);

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $names = array_column($body['result']['tools'], 'name');
        $this->assertContains('list_courses', $names);
        $this->assertContains('get_content', $names);
    }

    /**
     * モダン era でも認証必須（トークンなし → 401）
     */
    public function testModernStatelessUnauthorizedWithoutToken(): void
    {
        $this->mcpRequest([
            'MCP-Protocol-Version' => self::MODERN_VERSION,
            'Mcp-Method' => 'tools/list',
        ], [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::MODERN_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
                ],
            ],
        ]);

        $this->assertResponseCode(401);
    }

    /**
     * GET /mcp — SSE 非対応のため 405 + Allow（MCP Streamable HTTP 仕様）。
     *
     * ルート未定義の 404 はクライアント接続フローを壊すため、
     * Inspector / Claude Desktop 等が試行する GET には 405 を返す。
     */
    public function testGetReturns405MethodNotAllowed(): void
    {
        $this->get('/mcp');

        $this->assertResponseCode(405);
        $this->assertSame(
            'POST, DELETE, OPTIONS',
            $this->_response->getHeaderLine('Allow'),
        );
    }

    // ----------------------------------------------------------------
    // DELETE / セッション破棄
    // ----------------------------------------------------------------

    /**
     * DELETE /mcp でセッションを破棄できる（以降の使用は不可）
     */
    public function testDeleteSessionClosesSession(): void
    {
        $user = $this->createUser('deluser01', 'admin');
        $token = $this->issueToken((int)$user->id);
        $sessionId = $this->initializeSession($token);

        $this->configRequest([
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'Mcp-Session-Id' => $sessionId,
            ],
        ]);
        $this->delete('/mcp');
        $this->assertResponseCode(200);

        // 破棄後のセッション使用 → セッション不存在エラー
        $this->mcpRequest([
            'Authorization' => 'Bearer ' . $token,
            'Mcp-Session-Id' => $sessionId,
        ], [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);
        $this->assertNotSame(200, $this->_response->getStatusCode());
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertStringContainsString(
            'session',
            strtolower((string)$body['error']['message']),
        );
    }

    /**
     * DELETE /mcp は認証必須（トークンなし → 401）
     */
    public function testDeleteSessionUnauthorizedWithoutToken(): void
    {
        $this->configRequest([
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->delete('/mcp');

        $this->assertResponseCode(401);
    }

    // ----------------------------------------------------------------
    // OPTIONS / CORS preflight
    // ----------------------------------------------------------------

    /**
     * OPTIONS preflight（認証付き）→ 204 + Access-Control-Allow-Methods
     */
    public function testOptionsPreflightReturns204WithCorsHeaders(): void
    {
        $user = $this->createUser('optuser01', 'admin');
        $token = $this->issueToken((int)$user->id);

        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Origin' => 'http://localhost',
                'Access-Control-Request-Method' => 'POST',
            ],
        ]);
        $this->options('/mcp');

        $this->assertResponseCode(204);
        $this->assertStringContainsString(
            'POST',
            $this->_response->getHeaderLine('Access-Control-Allow-Methods'),
        );
    }

    /**
     * OPTIONS は認証必須（現挙動: トークンなし → 401）
     *
     * CORS は CorsMiddleware 既定（allowedOrigins 空 = ACAO 非付与）のため
     * ブラウザ跨 origin 利用は現状不可。preflight も認証を通す。
     */
    public function testOptionsUnauthorizedWithoutToken(): void
    {
        $this->configRequest([
            'headers' => [
                'Origin' => 'http://localhost',
                'Access-Control-Request-Method' => 'POST',
            ],
        ]);
        $this->options('/mcp');

        $this->assertResponseCode(401);
    }

    // ----------------------------------------------------------------
    // 異常系（必須引数欠落）
    // ----------------------------------------------------------------

    /**
     * 必須引数 content_id 欠落 → JSON-RPC エラー（-32602 Invalid params）
     */
    public function testMissingRequiredArgumentReturnsJsonRpcError(): void
    {
        $user = $this->createUser('missarg01', 'admin');
        $token = $this->issueToken((int)$user->id);
        $sessionId = $this->initializeSession($token);

        $this->mcpRequest([
            'Authorization' => 'Bearer ' . $token,
            'Mcp-Session-Id' => $sessionId,
        ], [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_content',
                'arguments' => [],
            ],
        ]);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('error', $body, '必須引数欠落は JSON-RPC エラーになる: ' . (string)$this->_response->getBody());
        $this->assertSame(-32602, $body['error']['code']);
    }

    // ----------------------------------------------------------------
    // モダン era（ステートレス）tools/call
    // ----------------------------------------------------------------

    /**
     * モダン era でもセッションなし tools/call が可能（Mcp-Method/Mcp-Name 必須）
     */
    public function testModernStatelessToolCall(): void
    {
        $user = $this->createUser('admin04', 'admin');
        $token = $this->issueToken((int)$user->id);
        $this->createCourse('モダンコース');

        $this->mcpRequest([
            'Authorization' => 'Bearer ' . $token,
            'MCP-Protocol-Version' => self::MODERN_VERSION,
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'list_courses',
        ], [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_courses',
                'arguments' => [],
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::MODERN_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
                ],
            ],
        ]);

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('result', $body, 'モダン tools/call は result を返す');
        $data = $this->decodeToolResult($body['result']);
        $this->assertSame('モダンコース', $data['data'][0]['title']);
    }

    // ----------------------------------------------------------------
    // レート制限（分離・窓復帰）
    // ----------------------------------------------------------------

    /**
     * レート制限はユーザー単位（他ユーザーは 429 の影響を受けない）
     */
    public function testRateLimitIsolatedPerUser(): void
    {
        $limited = $this->createUser('ratelimit02');
        $other = $this->createUser('ratelimit03');
        $tokenLimited = $this->issueToken((int)$limited->id);
        $tokenOther = $this->issueToken((int)$other->id);

        $logsTable = $this->getTableLocator()->get('Logs');
        $connection = $logsTable->getConnection();
        for ($i = 0; $i < 60; $i++) {
            $connection->insert($logsTable->getTable(), [
                'log_type' => 'mcp_request',
                'log_content' => (string)$limited->id,
                'user_id' => (int)$limited->id,
                'user_ip' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'created' => date('Y-m-d H:i:s'),
            ]);
        }

        $initialize = function (string $token): void {
            $this->mcpRequest(['Authorization' => 'Bearer ' . $token], [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => new stdClass(),
                    'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
                ],
            ]);
        };

        $initialize($tokenLimited);
        $this->assertResponseCode(429);

        $initialize($tokenOther);
        $this->assertResponseCode(200);
    }

    /**
     * 60秒を経過した記録はカウントされない（窓復帰）
     */
    public function testRateLimitWindowExpiryAllowsRequests(): void
    {
        $user = $this->createUser('ratelimit04');
        $token = $this->issueToken((int)$user->id);

        $logsTable = $this->getTableLocator()->get('Logs');
        $connection = $logsTable->getConnection();
        $old = date('Y-m-d H:i:s', time() - 61);
        for ($i = 0; $i < 60; $i++) {
            $connection->insert($logsTable->getTable(), [
                'log_type' => 'mcp_request',
                'log_content' => (string)$user->id,
                'user_id' => (int)$user->id,
                'user_ip' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'created' => $old,
            ]);
        }

        $this->mcpRequest(['Authorization' => 'Bearer ' . $token], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ]);

        $this->assertResponseOk();
        $this->assertNotEmpty($this->_response->getHeaderLine('Mcp-Session-Id'));
    }

    // ----------------------------------------------------------------
    // list_courses 空就学
    // ----------------------------------------------------------------

    /**
     * 未就学ユーザーの list_courses → 空 data + meta.total=0（エラーにしない）
     */
    public function testListCoursesEmptyForUnenrolledUser(): void
    {
        $user = $this->createUser('student05');
        $token = $this->issueToken((int)$user->id);
        $this->createCourse('関係ないコース');
        $sessionId = $this->initializeSession($token);

        $result = $this->callTool($token, $sessionId, 'list_courses');
        $data = $this->decodeToolResult($result);

        $this->assertSame([], $data['data']);
        $this->assertSame(0, $data['meta']['total']);
    }

    // ----------------------------------------------------------------
    // 書き込みツール（Phase 3）
    // ----------------------------------------------------------------

    /**
     * スタッフが create_content で作成し、get_content で本文を取得できる（ラウンドトリップ）
     */
    public function testCreateContentRoundTrip(): void
    {
        $admin = $this->createUser('wadmin01', 'admin');
        $token = $this->issueToken((int)$admin->id);
        $course = $this->createCourse('MCP作成コース');
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $sessionId = $this->initializeSession($token);

        $created = $this->decodeToolResult($this->callTool($token, $sessionId, 'create_content', [
            'course_id' => (int)$course->id,
            'title' => 'MCPで作ったコンテンツ',
            'kind' => 'markdown',
            'body' => "# MCP作成\n\n**太字**",
            'status' => 1,
        ]));
        $this->assertArrayHasKey('data', $created);
        $contentId = (int)$created['data']['id'];

        $fetched = $this->decodeToolResult($this->callTool($token, $sessionId, 'get_content', [
            'content_id' => $contentId,
        ]));
        $this->assertSame('MCPで作ったコンテンツ', $fetched['data']['title']);
        $this->assertSame("# MCP作成\n\n**太字**", $fetched['data']['body']);

        // 部分更新（タイトルのみ）
        $updated = $this->decodeToolResult($this->callTool($token, $sessionId, 'update_content', [
            'content_id' => $contentId,
            'title' => '更新後タイトル',
        ]));
        $this->assertSame('更新後タイトル', $updated['data']['title']);

        $refetched = $this->decodeToolResult($this->callTool($token, $sessionId, 'get_content', [
            'content_id' => $contentId,
        ]));
        $this->assertSame('更新後タイトル', $refetched['data']['title']);
        $this->assertSame("# MCP作成\n\n**太字**", $refetched['data']['body'], 'body は部分更新で不変');
    }

    /**
     * 非スタッフの create_content → 'Only staff members can create content.'
     */
    public function testCreateContentDeniedForNonStaff(): void
    {
        $user = $this->createUser('wuser01');
        $token = $this->issueToken((int)$user->id);
        $course = $this->createCourse();
        $this->enrollUser((int)$user->id, (int)$course->id);
        $sessionId = $this->initializeSession($token);

        $data = $this->decodeToolResult($this->callTool($token, $sessionId, 'create_content', [
            'course_id' => (int)$course->id,
            'title' => '試し',
            'kind' => 'html',
            'body' => '<p>x</p>',
        ]));

        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Only staff members can create content.', $data['error']);
    }

    /**
     * 書き込みツール: 20 リクエスト/分 → 21 回目で 429（読み取りカウンタと分離）
     */
    public function testWriteRateLimitReturns429After20(): void
    {
        $admin = $this->createUser('wadmin02', 'admin');
        $token = $this->issueToken((int)$admin->id);
        $course = $this->createCourse();
        $this->enrollUser((int)$admin->id, (int)$course->id);

        $logsTable = $this->getTableLocator()->get('Logs');
        $connection = $logsTable->getConnection();
        for ($i = 0; $i < 20; $i++) {
            $connection->insert($logsTable->getTable(), [
                'log_type' => 'mcp_write',
                'log_content' => (string)$admin->id,
                'user_id' => (int)$admin->id,
                'user_ip' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'created' => date('Y-m-d H:i:s'),
            ]);
        }

        // initialize（読み取り）は書き込みカウンタの影響を受けない
        $sessionId = $this->initializeSession($token);

        $this->mcpRequest([
            'Authorization' => 'Bearer ' . $token,
            'Mcp-Session-Id' => $sessionId,
        ], [
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_content',
                'arguments' => [
                    'course_id' => (int)$course->id,
                    'title' => '限界テスト',
                    'kind' => 'html',
                    'body' => '<p>x</p>',
                ],
            ],
        ]);

        $this->assertResponseCode(429);
        $this->assertNotEmpty($this->_response->getHeaderLine('Retry-After'));
    }

    /**
     * 書き込み成功時は ib_logs に mcp_write が記録される
     */
    public function testWriteToolCallLoggedAsWriteCounter(): void
    {
        $admin = $this->createUser('wadmin03', 'admin');
        $token = $this->issueToken((int)$admin->id);
        $course = $this->createCourse();
        $this->enrollUser((int)$admin->id, (int)$course->id);
        $sessionId = $this->initializeSession($token);

        $this->callTool($token, $sessionId, 'create_content', [
            'course_id' => (int)$course->id,
            'title' => 'カウンタ確認',
            'kind' => 'html',
            'body' => '<p>x</p>',
        ]);

        $writeCount = $this->getTableLocator()->get('Logs')->find()->where([
            'log_type' => 'mcp_write',
            'log_content' => (string)$admin->id,
        ])->count();
        $this->assertSame(1, $writeCount);

        // tools/call の読み取りカウンタには記録されない
        // （読み取りは initialize + notifications/initialized の 2 件のみ）
        $readCount = $this->getTableLocator()->get('Logs')->find()->where([
            'log_type' => 'mcp_request',
            'log_content' => (string)$admin->id,
        ])->count();
        $this->assertSame(2, $readCount, 'ハンドシェイク 2 件のみ（tools/call は読み取りに計上しない）');
    }
}
