<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Utility\MarkdownRenderer;
use Cake\View\Helper;

class MarkdownHelper extends Helper
{
    /**
     * Markdown をサニタイズ済み HTML に変換する
     *
     * @param string|null $markdown Markdown ソース
     * @return string HTML
     */
    public function text(?string $markdown): string
    {
        return MarkdownRenderer::toHtml($markdown);
    }
}
