<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\SettingsTable;
use Cake\TestSuite\TestCase;

/**
 * SettingsTable のテスト
 */
class SettingsTableTest extends TestCase
{
    protected SettingsTable $Settings;

    public function setUp(): void
    {
        parent::setUp();
        $this->Settings = $this->getTableLocator()->get('Settings');
        $this->Settings->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        unset($this->Settings);
        parent::tearDown();
    }

    private function seedSettings(): void
    {
        $data = [
            ['setting_key' => 'title', 'setting_name' => 'システム名', 'setting_value' => 'eラーニング'],
            ['setting_key' => 'copyright', 'setting_name' => 'コピーライト', 'setting_value' => 'Copyright (C) 2026'],
            ['setting_key' => 'color', 'setting_name' => 'テーマカラー', 'setting_value' => '#337ab7'],
            ['setting_key' => 'information', 'setting_name' => 'お知らせ', 'setting_value' => '設定は管理画面から変更可能'],
        ];

        foreach ($data as $row) {
            $this->Settings->save($this->Settings->newEntity($row));
        }
    }

    public function testGetSettings(): void
    {
        $this->seedSettings();

        $settings = $this->Settings->getSettings();

        $this->assertSame('eラーニング', $settings['title']);
        $this->assertSame('Copyright (C) 2026', $settings['copyright']);
        $this->assertCount(4, $settings);
    }

    public function testSetSettings(): void
    {
        $this->seedSettings();

        $this->Settings->setSettings(['title' => '新システム名', 'color' => '#ff0000']);

        $settings = $this->Settings->getSettings();
        $this->assertSame('新システム名', $settings['title']);
        $this->assertSame('#ff0000', $settings['color']);
        $this->assertSame('Copyright (C) 2026', $settings['copyright'], '未指定の設定は変更されない');
    }

    public function testGetSettingsReturnsEmptyArrayIfNoRows(): void
    {
        $this->assertSame([], $this->Settings->getSettings());
    }
}