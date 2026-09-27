<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use Cake\TestSuite\TestCase;

/**
 * AppTable のテスト
 */
class AppTableTest extends TestCase
{
    /**
     * @var \App\Model\Table\AppTable
     */
    protected $AppTable;

    public function setUp(): void
    {
        parent::setUp();
        $this->AppTable = $this->getTableLocator()->get('Groups');
    }

    public function tearDown(): void
    {
        unset($this->AppTable);
        parent::tearDown();
    }

    public function testAlphaNumericMBValid(): void
    {
        $this->assertTrue($this->AppTable->alphaNumericMB(['value' => 'abc123']), '英数字のみはOK');
        $this->assertTrue($this->AppTable->alphaNumericMB(['value' => 'ABC456']), '大文字英数字もOK');
        $this->assertTrue($this->AppTable->alphaNumericMB(['value' => 'Test99']), '混合英数字もOK');
    }

    public function testAlphaNumericMBInvalid(): void
    {
        $this->assertFalse($this->AppTable->alphaNumericMB(['value' => 'abc-123']), 'ハイフン含むはNG');
        $this->assertFalse($this->AppTable->alphaNumericMB(['value' => 'test@domain']), '@記号含むはNG');
        $this->assertFalse($this->AppTable->alphaNumericMB(['value' => '日本語test']), '日本語含むはNG');
        $this->assertFalse($this->AppTable->alphaNumericMB(['value' => '']), '空文字はNG');
    }
}
