<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Helper;

use App\View\Helper\MarkdownHelper;
use Cake\TestSuite\TestCase;
use Cake\View\View;

class MarkdownHelperTest extends TestCase
{
    private MarkdownHelper $helper;

    public function setUp(): void
    {
        parent::setUp();
        $view = new View();
        $this->helper = new MarkdownHelper($view);
    }

    // ----------------------------------------------------------------
    // text() — Markdown → サニタイズ済み HTML 変換
    // ----------------------------------------------------------------

    public function testTextNullReturnsEmpty(): void
    {
        $this->assertSame('', $this->helper->text(null));
    }

    public function testTextEmptyReturnsEmpty(): void
    {
        $this->assertSame('', $this->helper->text(''));
    }

    public function testTextHeading(): void
    {
        $result = $this->helper->text('# Hello');
        $this->assertStringContainsString('<h1>Hello</h1>', $result);
    }

    public function testTextInlineFormatting(): void
    {
        $result = $this->helper->text('**bold** and *italic*');
        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
    }

    public function testTextXssScriptTagRemoved(): void
    {
        $result = $this->helper->text('<script>alert("xss")</script>');
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testTextXssOnErrorRemoved(): void
    {
        $result = $this->helper->text('<img src=x onerror=alert(1)>');
        $this->assertStringNotContainsString('onerror', $result);
    }

    // ----------------------------------------------------------------
    // html() — 既存 HTML のサニタイズ（U-5 適用）
    // ----------------------------------------------------------------

    public function testHtmlNullReturnsEmpty(): void
    {
        $this->assertSame('', $this->helper->html(null));
    }

    public function testHtmlEmptyReturnsEmpty(): void
    {
        $this->assertSame('', $this->helper->html(''));
    }

    public function testHtmlSanitizesScriptTag(): void
    {
        $result = $this->helper->html('<p>ok</p><script>alert(1)</script>');
        $this->assertStringContainsString('<p>ok</p>', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testHtmlSanitizesOnErrorAttribute(): void
    {
        $result = $this->helper->html('<img src=x onerror=alert(1)>');
        $this->assertStringNotContainsString('onerror', $result);
    }

    public function testHtmlPreservesBenignHtml(): void
    {
        $body = '<p>段落</p><h2>見出し</h2>';
        $result = $this->helper->html($body);
        $this->assertSame($body, $result);
    }
}
