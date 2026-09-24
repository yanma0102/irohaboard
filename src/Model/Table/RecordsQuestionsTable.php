<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Model\Table;

use Cake\Validation\Validator;

/**
 * RecordsQuestions Model
 *
 * @property \App\Model\Table\RecordsTable&\Cake\ORM\Association\BelongsTo $Records
 * @property \App\Model\Table\ContentsQuestionsTable&\Cake\ORM\Association\BelongsTo $ContentsQuestions
 *
 * @method \App\Model\Entity\RecordsQuestion newEmptyEntity()
 * @method \App\Model\Entity\RecordsQuestion newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\RecordsQuestion[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\RecordsQuestion get($primaryKey, $options = [])
 * @method \App\Model\Entity\RecordsQuestion findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\RecordsQuestion patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\RecordsQuestion[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\RecordsQuestion|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\RecordsQuestion saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\RecordsQuestion[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\RecordsQuestion[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class RecordsQuestionsTable extends AppTable
{
    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_records_questions');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->belongsTo('Records', [
            'foreignKey' => 'record_id',
        ]);
        $this->belongsTo('ContentsQuestions', [
            'foreignKey' => 'question_id',
        ]);
    }

    /**
     * Default validation rules.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('record_id')
            ->requirePresence('record_id', 'create')
            ->notEmptyString('record_id');

        $validator
            ->integer('question_id')
            ->requirePresence('question_id', 'create')
            ->notEmptyString('question_id');

        $validator
            ->integer('score')
            ->requirePresence('score', 'create')
            ->notEmptyString('score');

        $validator
            ->scalar('answer')
            ->maxLength('answer', 2000)
            ->allowEmptyString('answer');

        $validator
            ->scalar('correct')
            ->maxLength('correct', 200)
            ->allowEmptyString('correct');

        return $validator;
    }
}