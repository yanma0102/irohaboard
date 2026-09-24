<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\PagesController;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use ReflectionMethod;

/**
 * AppController::sanitizeCsvValue() のユニットテスト
 *
 * CSV 出力時の数式インジェクション防止ロジックを検証する
 */
class CsvSanitizeTest extends TestCase
{
    /**
     * sanitizeCsvValue を呼び出すためのリフレクション辅助
     *
     * @param string|null $value
     * @return string
     */
    private function callSanitize(?string $value): string
    {
        // AppController は抽象ではないため、サブクラス経由でテスト可能だが
        // ここではリフレクションで PagesController（AppController の子）を経由する
        $request = new ServerRequest();
        $controller = new PagesController($request);
        $method = new ReflectionMethod(PagesController::class, 'sanitizeCsvValue');
        $method->setAccessible(true);

        return $method->invoke($controller, $value);
    }

    /**
     * '=SUM(A1)' → "'=SUM(A1)"
     */
    public function testFormulaEquals(): void
    {
        $this->assertSame("'=SUM(A1)", $this->callSanitize('=SUM(A1)'));
    }

    /**
     * '+123' → "+123"
     */
    public function testFormulaPlus(): void
    {
        $this->assertSame("'+123", $this->callSanitize('+123'));
    }

    /**
     * '-1' → "'-1"
     */
    public function testFormulaMinus(): void
    {
        $this->assertSame("'-1", $this->callSanitize('-1'));
    }

    /**
     * '@cmd' → "'@cmd"
     */
    public function testFormulaAt(): void
    {
        $this->assertSame("'@cmd", $this->callSanitize('@cmd'));
    }

    /**
     * '  =x'（先頭空白）→ "'  =x"
     */
    public function testFormulaLeadingWhitespace(): void
    {
        $this->assertSame("'  =x", $this->callSanitize('  =x'));
    }

    /**
     * 'abc' → 'abc'（変化なし）
     */
    public function testSafeValueNoChange(): void
    {
        $this->assertSame('abc', $this->callSanitize('abc'));
    }

    /**
     * 空文字列 → 空文字列
     */
    public function testEmptyString(): void
    {
        $this->assertSame('', $this->callSanitize(''));
    }

    /**
     * null → 空文字列
     */
    public function testNull(): void
    {
        $this->assertSame('', $this->callSanitize(null));
    }
}
