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

    /**
     * 既に HTML な入力（kind='html'）を HTMLPurifier でサニタイズする。
     * text() は Markdown→HTML 変換を行うのに対し、本メソッドは Markdown 変換なしで
     * そのまま HTML をサニタイズする（U-5 適用: design 13 §9）。
     *
     * @param string|null $html 生 HTML
     * @return string サニタイズ済み HTML
     */
    public function html(?string $html): string
    {
        return MarkdownRenderer::purifyHtml($html);
    }
}
