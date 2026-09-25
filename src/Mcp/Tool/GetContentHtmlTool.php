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
use App\Utility\MarkdownRenderer;
use Cake\ORM\TableRegistry;
use Mcp\Server\RequestContext;

/**
 * get_content_html ツール: レンダリング済み HTML を返す
 */
class GetContentHtmlTool
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
     * コンテンツをレンダリングした HTML を返す。
     * kind=markdown は Markdown→HTML 変換＋サニタイズ済み。
     * kind=html は未サニタイズの生 HTML（G-9: Phase 3 で対応）。
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
                return ['error' => 'Content not found.'];
            }
        }

        $body = (string)($content->body ?? '');
        $html = match ((string)$content->kind) {
            'markdown' => MarkdownRenderer::toHtml($body),
            'html' => $body, // 未サニタイズ（G-9: Phase 3 で HTMLPurifier を適用）
            'text' => nl2br(h($body)),
            default => h($body),
        };

        return [
            'data' => [
                'id' => $content->id,
                'course_id' => $content->course_id,
                'title' => $content->title,
                'kind' => $content->kind,
                'html' => $html,
            ],
        ];
    }
}
