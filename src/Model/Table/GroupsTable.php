<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Model\Table;

use Cake\ORM\Query;
use Cake\Validation\Validator;

/**
 * Groups Model
 *
 * @property \App\Model\Table\CoursesTable&\Cake\ORM\Association\BelongsToMany $Courses
 *
 * @method \App\Model\Entity\Group newEmptyEntity()
 * @method \App\Model\Entity\Group newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\Group[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Group get($primaryKey, $options = [])
 * @method \App\Model\Entity\Group findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\Group patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\Group[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\Group|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\Group saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\Group[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\Group[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class GroupsTable extends AppTable
{
    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_groups');
        $this->setDisplayField('title');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsToMany('Courses', [
            'joinTable' => 'ib_groups_courses',
            'foreignKey' => 'group_id',
            'targetForeignKey' => 'course_id',
        ]);
    }

    /**
     * Default validation rules.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('title')
            ->maxLength('title', 200)
            ->requirePresence('title', 'create')
            ->notEmptyString('title');

        $validator
            ->integer('status')
            ->notEmptyString('status');

        return $validator;
    }

    /**
     * 指定したグループに所属するユーザ ID リストを取得
     *
     * @param int $groupId グループID
     * @return array ユーザIDリスト
     */
    public function getUserIdByGroupID(int $groupId): array
    {
        $usersGroupsTable = \Cake\Datasource\FactoryLocator::get('Table')->get('UsersGroups');

        return $usersGroupsTable->find('list', keyField: 'user_id', valueField: 'user_id')
            ->where(['group_id' => $groupId])
            ->toArray();
    }

    /**
     * デフォルトのソート順でクエリを返す
     */
    public function findOrdered(Query $query, array $options): Query
    {
        return $query->orderBy(['title' => 'ASC']);
    }
}