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
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * get_content_html ツール: レンダリング済み HTML を返す
 *
 * kind=markdown は Markdown→HTML 変換＋サニタイズ済み。
 * kind=html は HTMLPurifier でサニタイズして返す（U-5 適用済み: design 13 §9）。
 * kind=text は nl2br(h()) でエスケープ済み。
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
     * kind=markdown は MarkdownRenderer::toHtml() で Markdown→HTML 変換＋サニタイズ済み。
     * kind=html は MarkdownRenderer::purifyHtml() で HTMLPurifier サニタイズ済み（U-5 適用）。
     * kind=text は nl2br(h()) でエスケープ済み。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $content_id コンテンツID
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
            throw new ToolCallException('Content not found.');
        }

        $isStaff = $this->accessControl->isStaff($role);
        if (!$isStaff) {
            if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id, $role)) {
                throw new ToolCallException('Access denied to this content.');
            }
            if ((int)$content->status !== 1) {
                throw new ToolCallException('Content not found.');
            }
        }

        $body = (string)($content->body ?? '');
        $html = match ((string)$content->kind) {
            'markdown' => MarkdownRenderer::toHtml($body),
            'html' => MarkdownRenderer::purifyHtml($body),
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
