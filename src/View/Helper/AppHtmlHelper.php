<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper\HtmlHelper;

/**
 * CakePHP 2 compatible addCrumb/getCrumbs on top of CakePHP 5 HtmlHelper.
 */
class AppHtmlHelper extends HtmlHelper
{
    /**
     * @var array<int, array{name: string, url: mixed, options: array}> Breadcrumb trail
     */
    protected array $_crumbs = [];

    /**
     * Adds a breadcrumb element (CakePHP 2 compatible).
     *
     * @param string $name The name/title of the crumb.
     * @param mixed  $link  The URL or false for no link.
     * @param array  $options Options for the link.
     * @return void
     */
    public function addCrumb(string $name, mixed $link = null, array $options = []): void
    {
        $this->_crumbs[] = [
            'name'    => $name,
            'url'     => $link,
            'options' => $options,
        ];
    }

    /**
     * Returns breadcrumb trail as HTML string (CakePHP 2 compatible).
     *
     * @param string|false $separator Separator string between crumbs.
     * @param mixed        $startText Text to prepend before the first crumb (if not false).
     * @return string HTML string of the breadcrumb trail.
     */
    public function getCrumbs(string|false $separator = '&raquo;', mixed $startText = false): string
    {
        if (empty($this->_crumbs)) {
            return '';
        }

        $out = [];

        if (is_string($startText) && $startText !== '') {
            $out[] = $startText;
        }

        $count = count($this->_crumbs);

        foreach ($this->_crumbs as $i => $crumb) {
            $isLast = ($i === $count - 1);

            if ($isLast) {
                // Last crumb: text only (no link), matching Cake2 behaviour
                $out[] = $this->tag('span', $crumb['name']);
            } else {
                // Non-last crumb: link
                $options = $crumb['options'];
                $url = $crumb['url'] ?? null;
                if ($url !== null) {
                    $out[] = $this->link($crumb['name'], $url, $options);
                } else {
                    // No URL — plain text
                    $out[] = $crumb['name'];
                }
            }
        }

        if ($separator !== false && $separator !== '') {
            return implode(' ' . $separator . ' ', $out);
        }

        return implode(' ', $out);
    }
}
