<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Mcp;

use App\Mcp\Tool\CreateContentTool;
use App\Mcp\Tool\GetContentHtmlTool;
use App\Mcp\Tool\GetContentTool;
use App\Mcp\Tool\GetCourseTool;
use App\Mcp\Tool\GetUserProfileTool;
use App\Mcp\Tool\ListContentsTool;
use App\Mcp\Tool\ListCoursesTool;
use App\Mcp\Tool\ListRecordsTool;
use App\Mcp\Tool\UpdateContentTool;
use App\Service\AccessControlService;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;

/**
 * MCP Server の構築・ツール登録を担当するファクトリ。
 *
 * pre-1.0 の mcp/sdk への依存は src/Mcp/ に閉じ込め、
 * アプリケーション層（Controller/Service）からの直接依存を避ける。
 *
 * v0.8.1 検証: addTool() にオブジェクト直渡しは不可（HandlerResolver が
 * Closure|array|string を要求）。[$instance, '__invoke'] 形式で登録し、
 * name / description は明示引数で渡す（#[McpTool] 属性の name は
 * 明示登録時に読み取られないため）。
 */
class McpServerFactory
{
    /**
     * サーバ名（initialize 応答の serverInfo.name として返る）
     */
    public const SERVER_NAME = 'iroha Board MCP';

    /**
     * サーババージョン
     */
    public const SERVER_VERSION = '1.0.0';

    /**
     * MCP セッションの有効秒数
     */
    public const SESSION_TTL = 3600;

    /**
     * ツール登録済みの Server インスタンスを生成する。
     *
     * @param \App\Service\AccessControlService $accessControl 権限チェックサービス
     * @return \Mcp\Server
     */
    public function create(AccessControlService $accessControl): Server
    {
        return Server::builder()
            ->setServerInfo(self::SERVER_NAME, self::SERVER_VERSION)
            // HTTP リクエスト毎に Server を新規構築するため、既定の
            // InMemorySessionStore では initialize で発行した
            // Mcp-Session-Id を次のリクエストで検証できない（"Session not
            // found"）。ディスク永続の FileSessionStore で跨リクエスト
            // にセッションを保持する。
            ->setSession(new FileSessionStore(TMP . 'mcp-sessions', self::SESSION_TTL))
            // --- Read-Only ツール（Phase 2） ---
            ->addTool(
                [new ListCoursesTool($accessControl), '__invoke'],
                name: 'list_courses',
                description: 'List courses accessible to the authenticated user '
                    . '(staff see all courses). Supports pagination.',
            )
            ->addTool(
                [new GetCourseTool($accessControl), '__invoke'],
                name: 'get_course',
                description: 'Get details of a single course. Requires course access.',
            )
            ->addTool(
                [new ListContentsTool($accessControl), '__invoke'],
                name: 'list_contents',
                description: 'List contents of a course with pagination and optional kind filter. '
                    . 'Non-staff users only see published contents of accessible courses.',
            )
            ->addTool(
                [new GetContentTool($accessControl), '__invoke'],
                name: 'get_content',
                description: 'Get content metadata and raw body. For kind=markdown the body is '
                    . 'the Markdown source; for kind=html it is unsanitized raw HTML.',
            )
            ->addTool(
                [new GetContentHtmlTool($accessControl), '__invoke'],
                name: 'get_content_html',
                description: 'Get rendered HTML for a content. kind=markdown is converted '
                    . 'and sanitized; kind=html returns raw unsanitized HTML.',
            )
            ->addTool(
                [new ListRecordsTool($accessControl), '__invoke'],
                name: 'list_records',
                description: 'List learning records. Non-staff users only see their own records; '
                    . 'staff can filter by user_id and course_id.',
            )
            ->addTool(
                [new GetUserProfileTool($accessControl), '__invoke'],
                name: 'get_user_profile',
                description: 'Get a user profile. Defaults to the authenticated user; '
                    . 'staff can query any user by user_id.',
            )
            // --- Write ツール（Phase 3） ---
            ->addTool(
                [new CreateContentTool($accessControl), '__invoke'],
                name: 'create_content',
                description: 'Create a new content in a course (staff only, course membership required). '
                    . 'kind=markdown: body is the Markdown source.',
            )
            ->addTool(
                [new UpdateContentTool($accessControl), '__invoke'],
                name: 'update_content',
                description: 'Update fields of an existing content (staff only, course membership required). '
                    . 'Only the provided fields are changed (partial update).',
            )
            ->build();
    }
}
