<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Query;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * ContentsQuestions Model
 *
 * @property \App\Model\Table\ContentsTable&\Cake\ORM\Association\BelongsTo $Contents
 * @method \App\Model\Entity\ContentsQuestion newEmptyEntity()
 * @method \App\Model\Entity\ContentsQuestion newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\ContentsQuestion[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\ContentsQuestion get($primaryKey, $options = [])
 * @method \App\Model\Entity\ContentsQuestion findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\ContentsQuestion patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\ContentsQuestion[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\ContentsQuestion|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\ContentsQuestion saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\ContentsQuestion[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\ContentsQuestion[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class ContentsQuestionsTable extends AppTable
{
    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_contents_questions');
        $this->setDisplayField('title');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Contents', [
            'foreignKey' => 'content_id',
        ]);
    }

    /**
     * Default validation rules.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('content_id')
            ->requirePresence('content_id', 'create')
            ->notEmptyString('content_id');

        $validator
            ->scalar('question_type')
            ->maxLength('question_type', 20)
            ->requirePresence('question_type', 'create')
            ->notEmptyString('question_type');

        $validator
            ->requirePresence('body', 'create')
            ->notBlank('body');

        $validator
            ->integer('score')
            ->range('score', [-1, 101])
            ->notEmptyString('score');

        $validator
            ->numeric('sort_no');

        return $validator;
    }

    /**
     * ビジネスルール（save 前の条件判定）
     *
     * question_type が single（選択形式）のときだけ correct の必須チェックを適用する。
     * text（記述式）では正解不要のため correct は空文字で正。
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            function (EntityInterface $entity) {
                if ($entity->get('question_type') === 'single') {
                    return !empty($entity->get('correct'));
                }

                return true;
            },
            'correctRequired',
            ['message' => '正解を選択してください'],
        );

        return $rules;
    }

    /**
     * 問題の並べ替え
     *
     * @param array $idList 問題のIDリスト（並び順）
     */
    public function setOrder(array $idList): void
    {
        $connection = $this->getConnection();

        foreach ($idList as $index => $id) {
            $connection->execute(
                'UPDATE ib_contents_questions SET sort_no = :sort_no WHERE id = :id',
                ['sort_no' => $index + 1, 'id' => $id],
            );
        }
    }

    /**
     * 新規追加時の問題のソート番号を取得
     *
     * @param int $contentId コンテンツ（テスト）のID
     * @return int ソート番号
     */
    public function getNextSortNo(int $contentId): int
    {
        $query = $this->find();
        $data = $query->select(['max_sort_no' => $query->func()->max('sort_no')])
            ->where(['content_id' => $contentId])
            ->first();

        return (int)($data->max_sort_no ?? 0) + 1;
    }

    /**
     * デフォルトのソート順でクエリを返す
     */
    public function findOrdered(Query $query, array $options): Query
    {
        return $query->orderBy(['sort_no' => 'ASC']);
    }
}
