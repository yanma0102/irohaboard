<?php
declare(strict_types=1);

namespace App\Test\TestCase\Utility;

use App\Utility\MarkdownRenderer;
use Cake\TestSuite\TestCase;
use ReflectionProperty;

class MarkdownRendererTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    public function testToHtmlNullReturnsEmpty(): void
    {
        $this->assertSame('', MarkdownRenderer::toHtml(null));
    }

    public function testToHtmlEmptyReturnsEmpty(): void
    {
        $this->assertSame('', MarkdownRenderer::toHtml(''));
    }

    public function testToHtmlHeading(): void
    {
        $result = MarkdownRenderer::toHtml('# Hello');
        $this->assertStringContainsString('<h1>Hello</h1>', $result);
    }

    public function testToHtmlInlineFormatting(): void
    {
        $result = MarkdownRenderer::toHtml('**bold** and *italic*');
        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
    }

    public function testToHtmlList(): void
    {
        $md = "- item1\n- item2\n- item3";
        $result = MarkdownRenderer::toHtml($md);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li>item1</li>', $result);
        $this->assertStringContainsString('<li>item3</li>', $result);
    }

    public function testToHtmlCodeBlock(): void
    {
        $md = "```\nfoo()\n```";
        $result = MarkdownRenderer::toHtml($md);
        $this->assertStringContainsString('<code>', $result);
    }

    public function testToHtmlTable(): void
    {
        $md = "| A | B |\n|---|---|\n| 1 | 2 |";
        $result = MarkdownRenderer::toHtml($md);
        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<td>1</td>', $result);
    }

    public function testToHtmlStrikethrough(): void
    {
        $result = MarkdownRenderer::toHtml('~~deleted~~');
        $this->assertStringContainsString('<del>deleted</del>', $result);
    }

    public function testToHtmlLink(): void
    {
        $result = MarkdownRenderer::toHtml('[link](https://example.com)');
        $this->assertStringContainsString('<a href="https://example.com"', $result);
    }

    public function testToHtmlImage(): void
    {
        $result = MarkdownRenderer::toHtml('![alt](https://example.com/img.png)');
        $this->assertStringContainsString('<img src="https://example.com/img.png"', $result);
        $this->assertStringContainsString('alt="alt"', $result);
    }

    public function testToHtmlXssScriptTagRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('<script>alert("xss")</script>');
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('</script>', $result);
    }

    public function testToHtmlXssOnErrorRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('<img src=x onerror=alert(1)>');
        $this->assertStringNotContainsString('onerror', $result);
    }

    public function testToHtmlXssJavascriptUrlRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('[click](javascript:alert(1))');
        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function testToHtmlXssIframeRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('<iframe src="https://evil.com"></iframe>');
        $this->assertStringNotContainsString('<iframe', $result);
    }

    public function testToHtmlXssStyleTagRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('<style>body{background:red}</style>');
        $this->assertStringNotContainsString('<style', $result);
    }

    public function testToHtmlXssEventHandlerRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('<div onclick="alert(1)">text</div>');
        $this->assertStringNotContainsString('onclick', $result);
    }

    public function testToHtmlAllowedTagsPreserved(): void
    {
        $md = '**bold** and [link](https://example.com)';
        $result = MarkdownRenderer::toHtml($md);
        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('href="https://example.com"', $result);
    }

    public function testToHtmlXssDataUriRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('![x](data:text/html,<script>alert(1)</script>)');
        $this->assertStringNotContainsString('data:', $result);
    }

    /**
     * HTMLPurifier が非対応の mark/details/summary を Allowed に含めていないこと。
     * 含めると定義ビルド時に E_USER_WARNING が発火し、DEBUG=true の HTTP 応答を
     * 破壊する（案A: 誤設定の修正の回帰テスト）。
     */
    public function testUnsupportedElementsEmitNoPurifierWarnings(): void
    {
        $property = new ReflectionProperty(MarkdownRenderer::class, 'purifier');
        $property->setValue(null, null);

        $warnings = [];
        set_error_handler(function (int $code, string $message) use (&$warnings): bool {
            if ($code === E_USER_WARNING) {
                $warnings[] = $message;

                return true;
            }

            return false;
        });

        try {
            $result = MarkdownRenderer::toHtml(
                '<p><mark>ハイ</mark></p><details><summary>見出し</summary>中身</details>',
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'HTMLPurifier の Element not supported 警告が発火しない');
        $this->assertStringNotContainsString('<mark', $result);
        $this->assertStringNotContainsString('<details', $result);
        $this->assertStringNotContainsString('<summary', $result);
        $this->assertStringContainsString('ハイ', $result);
        $this->assertStringContainsString('中身', $result);
    }
}
