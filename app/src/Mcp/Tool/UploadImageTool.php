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
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * upload_image ツール: 画像を保存して配信 URL を返す（スタッフのみ）
 */
class UploadImageTool
{
    use HasOAuthContextTrait;
    use HandlesImageDataTrait;

    /**
     * @param \App\Service\AccessControlService $accessControl 権限チェックサービス
     */
    public function __construct(
        private AccessControlService $accessControl,
    ) {
    }

    /**
     * base64 画像を保存し、Markdown に埋め込める URL を返す。
     *
     * @param \Mcp\Server\RequestContext $context リクエストコンテキスト（自動注入）
     * @param int $course_id コースID（アクセス権の検証に使用）
     * @param string $file_name 元ファイル名（拡張子の判定に使用）
     * @param string $data base64 文字列または data URL
     * @param string|null $alt 代替テキスト（省略可）
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'upload_image',
        description: 'Upload an image and return its public URL. Staff only. '
            . 'Use the returned url in Markdown: ![alt](url). '
            . 'Allowed: png, gif, jpg, jpeg; max upload_image_maxsize.',
    )]
    public function __invoke(
        RequestContext $context,
        int $course_id,
        string $file_name,
        string $data,
        ?string $alt = null,
    ): array {
        $userId = $this->getUserId($context);
        $role = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can upload images.');
        }

        if (!$this->accessControl->canAccessCourse($userId, $course_id, $role)) {
            throw new ToolCallException('Access denied to this course.');
        }

        $stored = $this->storeImage($data, $file_name);

        return [
            'data' => [
                'file_name' => $stored['file_name'],
                'url' => $stored['url'],
                'size' => $stored['size'],
                'mime' => $stored['mime'],
                'alt' => $alt,
            ],
        ];
    }
}
