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
 * UsersGroups Model
 *
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 * @property \App\Model\Table\GroupsTable&\Cake\ORM\Association\BelongsTo $Groups
 *
 * @method \App\Model\Entity\UsersGroup newEmptyEntity()
 * @method \App\Model\Entity\UsersGroup newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\UsersGroup[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\UsersGroup get($primaryKey, $options = [])
 * @method \App\Model\Entity\UsersGroup findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\UsersGroup patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\UsersGroup[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\UsersGroup|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\UsersGroup saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\UsersGroup[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\UsersGroup[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class UsersGroupsTable extends AppTable
{
    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_users_groups');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
        ]);
        $this->belongsTo('Groups', [
            'foreignKey' => 'group_id',
        ]);
    }

    /**
     * Default validation rules.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->integer('group_id')
            ->requirePresence('group_id', 'create')
            ->notEmptyString('group_id');

        return $validator;
    }
}