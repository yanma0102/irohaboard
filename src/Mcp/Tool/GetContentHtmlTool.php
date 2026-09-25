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
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Exception;
use Mcp\Server\RequestContext;

/**
 * get_content_html ツール: レンダリング済み HTML を返す
 */
class GetContentHtmlTool
{
    use HasOAuthContextTrait;

    /**
     * html kind サニタイズ導入前のログ評価（U-5: Phase 3 でログ評価から開始）で
     * 使用する ib_logs の log_type。
     *
     * HTMLPurifier 適用で内容が変化するコンテンツを 1 回だけ記録し、
     * 影響範囲を把握してから適用フェーズに進む。
     */
    public const SANITIZE_EVAL_LOG_TYPE = 'html_sanitize_candidate';

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
     * kind=html は未サニタイズの生 HTML のまま返すが、HTMLPurifier 適用時に
     * 変化する場合は ib_logs に評価ログを記録する（U-5 段階導入のログ評価）。
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
            'html' => $this->evaluateHtmlSanitization((int)$content->id, $body, $userId),
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

    /**
     * kind=html のログ評価（影判定）: 応答は生 HTML のまま返し、
     * HTMLPurifier 適用で内容が変わる場合のみ 1 回 ib_logs に記録する。
     *
     * @param int $contentId コンテンツID
     * @param string $body 生 HTML
     * @param int $userId 呼び出したユーザID
     * @return string 生 HTML（応答値は変更しない）
     */
    private function evaluateHtmlSanitization(int $contentId, string $body, int $userId): string
    {
        try {
            if (MarkdownRenderer::purifyHtml($body) === $body) {
                return $body;
            }

            $logsTable = TableRegistry::getTableLocator()->get('Logs');
            $exists = $logsTable->find()
                ->where([
                    'log_type' => self::SANITIZE_EVAL_LOG_TYPE,
                    'log_content' => (string)$contentId,
                ])
                ->count();

            if ($exists === 0) {
                $logsTable->save($logsTable->newEntity([
                    'log_type' => self::SANITIZE_EVAL_LOG_TYPE,
                    'log_content' => (string)$contentId,
                    'user_id' => $userId,
                    'created' => new DateTime(),
                ]));
            }
        } catch (Exception $e) {
            // 評価ログの失敗は応答に影響させない
        }

        return $body;
    }
}
