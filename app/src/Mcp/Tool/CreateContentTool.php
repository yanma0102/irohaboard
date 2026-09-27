<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Mcp\Tool;

use App\Service\AccessControlService;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * create_content ツール: コンテンツ作成（スタッフのみ）
 */
class CreateContentTool
{
    use HasOAuthContextTrait;

    /**
     * @param \App\Service\AccessControlService $accessControl 権限チェックサービス
     */
    public function __construct(
        private AccessControlService $accessControl,
    ) {
    }

    /**
     * コンテンツを新規作成する（スタッフのみ・コース参加必須）。
     * kind=markdown の場合、body は Markdown 原文。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $courseId コースID
     * @param string $title タイトル
     * @param string $kind コンテンツ種別（content_kind 設定キー）
     * @param string $body 本文（kind=markdown/html は必須）
     * @param int $status 公開状態（0=下書き, 1=公開）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_content',
        description: 'Create a new content in a course (staff only, course membership required). '
            . 'kind=markdown: body is the Markdown source.',
    )]
    public function __invoke(
        RequestContext $context,
        int $course_id,
        string $title,
        #[Schema(enum: ['label', 'html', 'markdown', 'movie', 'url', 'file', 'test', 'enquete'])]
        string $kind,
        string $body = '',
        int $status = 0,
    ): array {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can create content.');
        }

        if (!$this->accessControl->canAccessCourse($userId, $course_id, $role)) {
            throw new ToolCallException('Access denied to this course.');
        }

        // 設定ベースの妥当性検証（Schema enum の二重防御）
        $allowedKinds = array_keys((array)Configure::read('content_kind', []));
        if (!in_array($kind, $allowedKinds, true)) {
            throw new ToolCallException('Invalid kind. Allowed: ' . implode(', ', $allowedKinds));
        }

        $contentsTable = TableRegistry::getTableLocator()->get('Contents');

        $entity = $contentsTable->newEntity([
            'course_id' => $course_id,
            'user_id' => $userId,
            'title' => $title,
            'kind' => $kind,
            'body' => $body,
            'status' => $status,
            'sort_no' => $contentsTable->getNextSortNo($course_id),
        ]);

        if (!$contentsTable->save($entity)) {
            throw new ToolCallException(
                json_encode(['error' => 'Validation failed.', 'details' => $entity->getErrors()], JSON_UNESCAPED_UNICODE),
            );
        }

        return [
            'data' => [
                'id' => $entity->id,
                'course_id' => $entity->course_id,
                'title' => $entity->title,
                'kind' => $entity->kind,
                'status' => $entity->status,
                'sort_no' => $entity->sort_no,
            ],
        ];
    }
}
