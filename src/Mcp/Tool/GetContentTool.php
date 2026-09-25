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
use Cake\ORM\TableRegistry;
use Mcp\Server\RequestContext;

/**
 * get_content ツール: コンテンツのメタデータ＋本文を返す
 */
class GetContentTool
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
     * コンテンツのメタデータと本文を返す。
     * body は生データ（kind=markdown の場合は Markdown 原文、
     * kind=html の場合は未サニタイズの生 HTML — Phase 3 で対応、G-9）。
     * HTML が必要な場合は get_content_html を使うこと。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $contentId コンテンツID
     * @return array<string, mixed>
     */
    public function __invoke(RequestContext $context, int $content_id): array
    {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $content = $contentsTable->find()
            ->where(['id' => $content_id, 'deleted IS NULL'])
            ->first();

        if ($content === null) {
            return ['error' => 'Content not found.'];
        }

        $isStaff = $this->accessControl->isStaff($role);
        if (!$isStaff) {
            if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id)) {
                return ['error' => 'Access denied to this content.'];
            }
            if ((int)$content->status !== 1) {
                // 非公開コンテンツは一般ユーザに見せない（API と同一）
                return ['error' => 'Content not found.'];
            }
        }

        return [
            'data' => [
                'id' => $content->id,
                'course_id' => $content->course_id,
                'title' => $content->title,
                'kind' => $content->kind,
                'body' => $content->body,
                'status' => $content->status,
                'sort_no' => $content->sort_no,
                'created' => $content->created,
                'modified' => $content->modified,
            ],
        ];
    }
}
