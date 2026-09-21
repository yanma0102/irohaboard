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
 * Infos Model
 *
 * @property \App\Model\Table\GroupsTable&\Cake\ORM\Association\BelongsToMany $Groups
 *
 * @method \App\Model\Entity\Info newEmptyEntity()
 * @method \App\Model\Entity\Info newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\Info[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Info get($primaryKey, $options = [])
 * @method \App\Model\Entity\Info findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\Info patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\Info[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\Info|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\Info saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\Info[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\Info[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class InfosTable extends AppTable
{
    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_infos');
        $this->setDisplayField('title');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsToMany('Groups', [
            'joinTable' => 'ib_infos_groups',
            'foreignKey' => 'info_id',
            'targetForeignKey' => 'group_id',
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
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        return $validator;
    }

    /**
     * お知らせ一覧を取得
     *
     * @param int $userId ユーザID
     * @param int|null $limit 取得件数
     * @return array お知らせ一覧
     */
    public function getInfos(int $userId, ?int $limit = null): array
    {
        $query = $this->find()
            ->where(['Infos.id IN' => $this->getInfoIdList($userId, $limit)])
            ->orderBy(['created' => 'DESC']);

        if ($limit) {
            $query->limit($limit);
        }

        return $query->all()->toArray();
    }

    /**
     * お知らせへのアクセス権限チェック
     *
     * @param int $userId   アクセス者のユーザID
     * @param int $infoId   アクセス先のお知らせのID
     * @return bool true: アクセス可能, false: アクセス不可
     */
    public function hasRight(int $userId, int $infoId): bool
    {
        $infoIdList = $this->getInfoIdList($userId);

        return in_array($infoId, $infoIdList, true);
    }

    /**
     * 閲覧可能なお知らせのIDリストを取得
     *
     * @param int $userId ユーザID
     * @param int|null $limit 取得件数
     * @return array お知らせIDリスト
     */
    private function getInfoIdList(int $userId, ?int $limit = null): array
    {
        $usersGroupsTable = \Cake\Datasource\FactoryLocator::get('Table')->get('UsersGroups');

        $userGroupIds = $usersGroupsTable->find('list', keyField: 'group_id', valueField: 'group_id')
            ->where(['user_id' => $userId])
            ->toArray();

        $conditions = ['IbInfosGroups.group_id IS NULL'];
        if (!empty($userGroupIds)) {
            $conditions[] = ['IbInfosGroups.group_id IN' => $userGroupIds];
        }

        $query = $this->find()
            ->select(['Infos.id'])
            ->leftJoinWith('Groups')
            ->where(['OR' => $conditions])
            ->groupBy(['Infos.id'])
            ->orderBy(['Infos.created' => 'DESC']);

        if ($limit) {
            $query->limit($limit);
        }

        $rows = $query->all()->toArray();

        $infoIdList = [];
        foreach ($rows as $row) {
            $infoIdList[] = (int)$row->id;
        }

        // 該当するお知らせIDが1件も存在しない場合、エラー防止のため、ダミーIDを追加
        if (count($infoIdList) == 0) {
            $infoIdList[] = 0;
        }

        return $infoIdList;
    }
}