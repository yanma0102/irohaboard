<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Model\Table;

/**
 * InfosGroups Model
 *
 * @method \App\Model\Entity\InfosGroup newEmptyEntity()
 * @method \App\Model\Entity\InfosGroup newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\InfosGroup[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\InfosGroup get($primaryKey, $options = [])
 * @method \App\Model\Entity\InfosGroup findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\InfosGroup patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\InfosGroup[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\InfosGroup|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\InfosGroup saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\InfosGroup[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\InfosGroup[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class InfosGroupsTable extends AppTable
{
    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_infos_groups');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
    }
}