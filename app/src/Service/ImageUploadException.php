<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Service;

use RuntimeException;

/**
 * 画像アップロードの検証・保存失敗を理由付きで表す例外。
 *
 * 呼び出し元ごとに扱いが異なるため、機械可読な理由（reason）と
 * 利用者向けメッセージ（getPublicMessage）を保持する。
 *
 * - admin: Flash メッセージ
 * - REST API: HTTP ステータス（400 / 413 / 415 / 500）
 * - MCP: ToolCallException
 */
class ImageUploadException extends RuntimeException
{
    /**
     * ファイルが指定されていない
     */
    public const REASON_NO_FILE = 'no_file';

    /**
     * 許可されていない拡張子
     */
    public const REASON_EXTENSION = 'extension_not_allowed';

    /**
     * サイズ上限超過
     */
    public const REASON_TOO_LARGE = 'too_large';

    /**
     * 実体が画像ではない
     */
    public const REASON_NOT_IMAGE = 'not_an_image';

    /**
     * 保存に失敗した
     */
    public const REASON_SAVE_FAILED = 'save_failed';

    /**
     * 理由ごとの利用者向けメッセージ
     *
     * @var array<string, string>
     */
    private const MESSAGES = [
        self::REASON_NO_FILE => 'ファイルが指定されていません',
        self::REASON_EXTENSION => '許可されていない形式です（対応: .png/.gif/.jpg/.jpeg）',
        self::REASON_TOO_LARGE => 'サイズ上限を超えています',
        self::REASON_NOT_IMAGE => '画像ファイルではありません',
        self::REASON_SAVE_FAILED => 'アップロードに失敗しました',
    ];

    /**
     * @param string $reason 理由（REASON_* 定数）
     * @param string|null $internalMessage ログ用の詳細（未指定時は公開メッセージ）
     */
    public function __construct(
        private string $reason,
        ?string $internalMessage = null,
    ) {
        parent::__construct($internalMessage ?? $this->getPublicMessage());
    }

    /**
     * 機械可読な理由を取得する
     *
     * @return string REASON_* 定数
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * 利用者向けメッセージを取得する
     *
     * @return string メッセージ
     */
    public function getPublicMessage(): string
    {
        return self::MESSAGES[$this->reason] ?? 'アップロードに失敗しました';
    }
}
