<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\RecordsTable;
use Cake\TestSuite\TestCase;

/**
 * RecordsTable のテスト
 */
class RecordsTableTest extends TestCase
{
    protected RecordsTable $Records;

    public function setUp(): void
    {
        parent::setUp();
        $this->Records = $this->getTableLocator()->get('Records');
        $this->Records->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        unset($this->Records);
        parent::tearDown();
    }

    public function testValidationDefault(): void
    {
        $entity = $this->Records->newEntity([]);
        $this->assertTrue($entity->hasErrors(), '必須フィールドが空ならバリデーションエラーになる');

        $errors = $entity->getErrors();
        $this->assertArrayHasKey('course_id', $errors, 'course_id にエラーがある');
        $this->assertArrayHasKey('user_id', $errors, 'user_id にエラーがある');
        $this->assertArrayHasKey('content_id', $errors, 'content_id にエラーがある');
    }

    public function testValidationSuccess(): void
    {
        $entity = $this->Records->newEntity([
            'course_id' => 1,
            'user_id' => 1,
            'content_id' => 1,
        ]);
        $this->assertFalse($entity->hasErrors(), '有効なデータならバリデーションエラーがない');
    }
}
