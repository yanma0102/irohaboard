<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Mcp;

use App\Mcp\Tool\AssignGroupCourseTool;
use App\Mcp\Tool\AssignGroupUserTool;
use App\Mcp\Tool\AssignUserCourseTool;
use App\Mcp\Tool\ChangeUserPasswordTool;
use App\Mcp\Tool\CopyContentTool;
use App\Mcp\Tool\CreateContentTool;
use App\Mcp\Tool\CreateCourseTool;
use App\Mcp\Tool\CreateGroupTool;
use App\Mcp\Tool\CreateRecordTool;
use App\Mcp\Tool\CreateTestQuestionTool;
use App\Mcp\Tool\CreateUserTool;
use App\Mcp\Tool\DeleteContentTool;
use App\Mcp\Tool\DeleteCourseTool;
use App\Mcp\Tool\DeleteGroupTool;
use App\Mcp\Tool\DeleteTestQuestionTool;
use App\Mcp\Tool\DeleteUserTool;
use App\Mcp\Tool\GetContentHtmlTool;
use App\Mcp\Tool\GetContentTool;
use App\Mcp\Tool\GetCourseTool;
use App\Mcp\Tool\GetUserProfileTool;
use App\Mcp\Tool\ListContentsTool;
use App\Mcp\Tool\ListCoursesTool;
use App\Mcp\Tool\ListRecordsTool;
use App\Mcp\Tool\ReorderContentsTool;
use App\Mcp\Tool\UnassignGroupCourseTool;
use App\Mcp\Tool\UnassignGroupUserTool;
use App\Mcp\Tool\UnassignUserCourseTool;
use App\Mcp\Tool\UpdateContentTool;
use App\Mcp\Tool\UpdateCourseTool;
use App\Mcp\Tool\UpdateGroupTool;
use App\Mcp\Tool\UpdateTestQuestionTool;
use App\Mcp\Tool\UpdateUserTool;
use App\Mcp\Tool\UploadImageTool;
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
            ->addTool(
                [new CreateTestQuestionTool($accessControl), '__invoke'],
                name: 'create_test_question',
                description: 'Create a test question in a test content (staff only, course membership required). '
                    . 'For question_type=single, provide options and correct (1-based indexes); for text, omit them.',
            )
            ->addTool(
                [new UpdateTestQuestionTool($accessControl), '__invoke'],
                name: 'update_test_question',
                description: 'Update fields of an existing test question (staff only, course membership required). '
                    . 'Only provided fields are changed. options/correct are arrays.',
            )
            // --- 画像ツール（Phase 4） ---
            ->addTool(
                [new UploadImageTool($accessControl), '__invoke'],
                name: 'upload_image',
                description: 'Upload an image and return its public URL. Staff only. '
                    . 'Use the returned url in Markdown: ![alt](url). '
                    . 'Allowed: png, gif, jpg, jpeg; max upload_image_maxsize.',
            )
            // --- コンテンツ・履歴の管理ツール（Phase 5） ---
            ->addTool(
                [new DeleteContentTool($accessControl), '__invoke'],
                name: 'delete_content',
                description: 'Delete a content (staff only, course membership required). '
                    . 'Related test questions are also deleted.',
            )
            ->addTool(
                [new ReorderContentsTool($accessControl), '__invoke'],
                name: 'reorder_contents',
                description: 'Reorder contents in a course (staff only, course membership required). '
                    . 'content_ids must list every content of the course in the desired order.',
            )
            ->addTool(
                [new CopyContentTool($accessControl), '__invoke'],
                name: 'copy_content',
                description: 'Copy a content and its test questions (staff only, course membership required). '
                    . 'The copy is created as a draft (status=0) with "の複製" appended to the title.',
            )
            ->addTool(
                [new DeleteTestQuestionTool($accessControl), '__invoke'],
                name: 'delete_test_question',
                description: 'Delete a test question (staff only, course membership required).',
            )
            ->addTool(
                [new CreateRecordTool($accessControl), '__invoke'],
                name: 'create_record',
                description: 'Record learning progress for the authenticated user (non-test content only). '
                    . 'Access to the course is required; unpublished content can only be recorded by admins.',
            )
            // --- コース・ユーザ・グループの管理ツール（Phase 6） ---
            ->addTool(
                [new CreateCourseTool($accessControl), '__invoke'],
                name: 'create_course',
                description: 'Create a course (admin or manager only). '
                    . 'sort_no is auto-assigned when omitted.',
            )
            ->addTool(
                [new UpdateCourseTool($accessControl), '__invoke'],
                name: 'update_course',
                description: 'Update fields of an existing course (admin or manager only). '
                    . 'Only the provided fields are changed.',
            )
            ->addTool(
                [new DeleteCourseTool($accessControl), '__invoke'],
                name: 'delete_course',
                description: 'Delete a course and its contents (admin or manager only).',
            )
            ->addTool(
                [new CreateUserTool($accessControl), '__invoke'],
                name: 'create_user',
                description: 'Create a user (admin or manager only). '
                    . 'role defaults to the schema default; password is hashed by the model.',
            )
            ->addTool(
                [new UpdateUserTool($accessControl), '__invoke'],
                name: 'update_user',
                description: 'Update fields of an existing user (admin or manager only). '
                    . 'Administrator protection rules are enforced (no self role change, '
                    . 'no self deactivation, no deactivation of the last active administrator).',
            )
            ->addTool(
                [new ChangeUserPasswordTool($accessControl), '__invoke'],
                name: 'change_user_password',
                description: 'Change a user password and revoke all of that user\'s tokens '
                    . '(admin or manager only). Administrators can only be modified by administrators.',
            )
            ->addTool(
                [new DeleteUserTool($accessControl), '__invoke'],
                name: 'delete_user',
                description: 'Delete a user (admin or manager only). You cannot delete your own account.',
            )
            ->addTool(
                [new AssignUserCourseTool($accessControl), '__invoke'],
                name: 'assign_user_course',
                description: 'Assign a course to a user (admin or manager only). Idempotent.',
            )
            ->addTool(
                [new UnassignUserCourseTool($accessControl), '__invoke'],
                name: 'unassign_user_course',
                description: 'Remove a course assignment from a user (admin or manager only).',
            )
            // --- グループ管理ツール（Phase 6） ---
            ->addTool(
                [new CreateGroupTool($accessControl), '__invoke'],
                name: 'create_group',
                description: 'Create a group (admin or manager only).',
            )
            ->addTool(
                [new UpdateGroupTool($accessControl), '__invoke'],
                name: 'update_group',
                description: 'Update fields of an existing group (admin or manager only). '
                    . 'Only the provided fields are changed.',
            )
            ->addTool(
                [new DeleteGroupTool($accessControl), '__invoke'],
                name: 'delete_group',
                description: 'Delete a group and its user/course assignments (admin or manager only).',
            )
            ->addTool(
                [new AssignGroupUserTool($accessControl), '__invoke'],
                name: 'assign_group_user',
                description: 'Assign a user to a group (admin or manager only). Idempotent.',
            )
            ->addTool(
                [new UnassignGroupUserTool($accessControl), '__invoke'],
                name: 'unassign_group_user',
                description: 'Remove a user from a group (admin or manager only).',
            )
            ->addTool(
                [new AssignGroupCourseTool($accessControl), '__invoke'],
                name: 'assign_group_course',
                description: 'Assign a course to a group (admin or manager only). Idempotent.',
            )
            ->addTool(
                [new UnassignGroupCourseTool($accessControl), '__invoke'],
                name: 'unassign_group_course',
                description: 'Remove a course assignment from a group (admin or manager only).',
            )
            ->build();
    }
}
