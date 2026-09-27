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
 * update_content ツール: コンテンツ更新（スタッフのみ・部分更新）
 */
class UpdateContentTool
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
     * 既存コンテンツを部分更新する（スタッフのみ・コース参加必須）。
     * 指定されたフィールドのみ更新される。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $contentId コンテンツID
     * @param string|null $title タイトル（省略時は変更なし）
     * @param string|null $kind コンテンツ種別（省略時は変更なし）
     * @param string|null $body 本文（省略時は変更なし）
     * @param int|null $status 公開状態（0=下書き, 1=公開）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'update_content',
        description: 'Update fields of an existing content (staff only, course membership required). '
            . 'Only the provided fields are changed (partial update).',
    )]
    public function __invoke(
        RequestContext $context,
        int $content_id,
        ?string $title = null,
        #[Schema(enum: ['label', 'html', 'markdown', 'movie', 'url', 'file', 'test', 'enquete'])]
        ?string $kind = null,
        ?string $body = null,
        ?int $status = null,
    ): array {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can update content.');
        }

        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $content = $contentsTable->find()
            ->where(['id' => $content_id, 'deleted IS NULL'])
            ->first();

        if ($content === null) {
            throw new ToolCallException('Content not found.');
        }

        if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id, $role)) {
            throw new ToolCallException('Access denied to this content.');
        }

        // 設定ベースの妥当性検証（Schema enum の二重防御）
        if ($kind !== null) {
            $allowedKinds = array_keys((array)Configure::read('content_kind', []));
            if (!in_array($kind, $allowedKinds, true)) {
                throw new ToolCallException('Invalid kind. Allowed: ' . implode(', ', $allowedKinds));
            }
        }

        $patchData = [];
        if ($title !== null) {
            $patchData['title'] = $title;
        }
        if ($kind !== null) {
            $patchData['kind'] = $kind;
        }
        if ($body !== null) {
            $patchData['body'] = $body;
        }
        if ($status !== null) {
            $patchData['status'] = $status;
        }

        if ($patchData === []) {
            throw new ToolCallException('No fields to update.');
        }

        // patchEntity のバリデーションコンテキストには既存 kind が入らないため、
        // body 空が許可される抜けをツール側で塞ぐ（ContentsTable ルールと同一条件）
        $effectiveKind = $kind ?? (string)$content->kind;
        if (
            array_key_exists('body', $patchData)
            && $body === ''
            && in_array($effectiveKind, ['text', 'html', 'markdown'], true)
        ) {
            throw new ToolCallException(
                json_encode([
                    'error' => 'Validation failed.',
                    'details' => ['body' => ['allowEmpty' => 'This field cannot be empty']],
                ], JSON_UNESCAPED_UNICODE),
            );
        }

        $entity = $contentsTable->patchEntity($content, $patchData);

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
