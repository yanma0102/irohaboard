<?php
declare(strict_types=1);

namespace App\Utility;

use HTMLPurifier;
use HTMLPurifier_Config;
use League\CommonMark\Environment\Environment;
use League\CommonMark\MarkdownConverter;

class MarkdownRenderer
{
    private static ?MarkdownConverter $converter = null;
    private static ?HTMLPurifier $purifier = null;

    /**
     * Markdown コンバータを遅延初期化して返す（シングルトン）
     */
    private static function getConverter(): MarkdownConverter
    {
        if (self::$converter === null) {
            self::$converter = new MarkdownConverter(Environment::createGFMEnvironment());
        }

        return self::$converter;
    }

    /**
     * HTMLPurifier を遅延初期化して返す（シングルトン）
     */
    private static function getPurifier(): HTMLPurifier
    {
        if (self::$purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', implode(',', [
                'p', 'br', 'hr',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'strong', 'em', 'del', 'ins', 'code', 'pre', 'sup', 'sub', 'abbr',
                'ul', 'ol', 'li', 'dl', 'dt', 'dd',
                'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
                'blockquote',
                'a[href|title|target]', 'img[src|alt|title|width|height]',
            ]));
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('Attr.EnableID', true);
            $config->set('Cache.DefinitionImpl', null);
            self::$purifier = new HTMLPurifier($config);
        }

        return self::$purifier;
    }

    /**
     * Markdown をサニタイズ済み HTML に変換する
     *
     * @param string|null $markdown Markdown ソース
     * @return string サニタイズ済み HTML
     */
    public static function toHtml(?string $markdown): string
    {
        if ($markdown === null || $markdown === '') {
            return '';
        }

        $html = self::getConverter()->convert($markdown)->getContent();

        return self::getPurifier()->purify($html);
    }

    /**
     * 生 HTML を HTMLPurifier でサニタイズする（Markdown 変換なし）
     *
     * kind='html' の段階的サニタイズ導入（U-5: Phase 3 でログ評価から開始）で
     * 使う予定の関数。現時点ではログ評価（影判定）のみに使用する。
     *
     * @param string|null $html 生 HTML
     * @return string サニタイズ済み HTML
     */
    public static function purifyHtml(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        return self::getPurifier()->purify($html);
    }
}
