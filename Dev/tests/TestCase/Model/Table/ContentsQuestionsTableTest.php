<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\ContentsQuestionsTable;
use Cake\TestSuite\TestCase;

/**
 * ContentsQuestionsTable のテスト
 */
class ContentsQuestionsTableTest extends TestCase
{
    protected ContentsQuestionsTable $ContentsQuestions;

    public function setUp(): void
    {
        parent::setUp();
        $this->ContentsQuestions = $this->getTableLocator()->get('ContentsQuestions');
        $this->ContentsQuestions->deleteAll('1 = 1');
        $this->getTableLocator()->get('Contents')->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        unset($this->ContentsQuestions);
        parent::tearDown();
    }

    private function saveQuestion(int $contentId, array $data): int
    {
        $entity = $this->ContentsQuestions->newEntity(array_merge([
            'content_id' => $contentId,
            'question_type' => 'text',
            'title' => '質問',
            'body' => '設問文',
            'correct' => 'A',
            'score' => 10,
        ], $data));
        $result = $this->ContentsQuestions->save($entity);
        $this->assertNotFalse($result);

        return (int)$result->id;
    }

    public function testSetOrder(): void
    {
        $contentId = 1;
        $id1 = $this->saveQuestion($contentId, ['sort_no' => 1]);
        $id2 = $this->saveQuestion($contentId, ['sort_no' => 2]);
        $id3 = $this->saveQuestion($contentId, ['sort_no' => 3]);

        $this->ContentsQuestions->setOrder([$id3, $id1, $id2]);

        $result = $this->ContentsQuestions->find('list', keyField: 'id', valueField: 'sort_no')
            ->where(['id IN' => [$id1, $id2, $id3]])
            ->orderBy(['id' => 'ASC'])
            ->toArray();

        $this->assertSame(2, (int)$result[$id1]);
        $this->assertSame(3, (int)$result[$id2]);
        $this->assertSame(1, (int)$result[$id3]);
    }

    public function testGetNextSortNo(): void
    {
        $this->saveQuestion(1, ['sort_no' => 1]);
        $this->saveQuestion(1, ['sort_no' => 4]);

        $this->assertSame(5, $this->ContentsQuestions->getNextSortNo(1));
        $this->assertSame(1, $this->ContentsQuestions->getNextSortNo(2), 'content_id ごとに独立');
    }

    public function testValidationScoreRange(): void
    {
        // 101 超はエラー
        $entity = $this->ContentsQuestions->newEntity([
            'content_id' => 1,
            'question_type' => 'text',
            'body' => '設問',
            'correct' => 'A',
            'score' => 200,
        ]);
        $this->assertFalse($this->ContentsQuestions->save($entity));
        $errors = $entity->getErrors();
        $this->assertArrayHasKey('score', $errors);

        // 範囲内 (10) は保存可能
        $id = $this->saveQuestion(1, ['score' => 10]);
        $this->assertNotEmpty($id);
    }

    public function testFindOrderedSortsBySortNo(): void
    {
        $this->saveQuestion(1, ['title' => 'B', 'sort_no' => 5]);
        $this->saveQuestion(1, ['title' => 'A', 'sort_no' => 1]);

        $titles = $this->ContentsQuestions->find('ordered')->where(['content_id' => 1])
            ->all()->extract('title')->toList();
        $this->assertSame(['A', 'B'], $titles);
    }
}
