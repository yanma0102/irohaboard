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
 * ConfigOverrides Model
 *
 * @method \App\Model\Entity\ConfigOverride newEmptyEntity()
 * @method \App\Model\Entity\ConfigOverride newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\ConfigOverride[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\ConfigOverride get($primaryKey, $options = [])
 * @method \App\Model\Entity\ConfigOverride findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\ConfigOverride patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\ConfigOverride[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\ConfigOverride|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\ConfigOverride saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\ConfigOverride[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\ConfigOverride[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class ConfigOverridesTable extends AppTable
{
    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_config_overrides');
        $this->setDisplayField('config_key');
        $this->setPrimaryKey('id');
    }

    /**
     * Default validation rules.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('config_key')
            ->maxLength('config_key', 100)
            ->requirePresence('config_key', 'create')
            ->notEmptyString('config_key');

        $validator
            ->scalar('config_value')
            ->requirePresence('config_value', 'create')
            ->notEmptyString('config_value');

        return $validator;
    }

    /**
     * 設定オーバーライドの全リストを取得
     *
     * @return array 設定値リスト（連想配列 ['key' => 'value']）
     */
    public function getOverrides(): array
    {
        $result = [];

        $overrides = $this->find()
            ->select(['config_key', 'config_value'])
            ->toArray();

        foreach ($overrides as $override) {
            $result[$override->config_key] = $override->config_value;
        }

        return $result;
    }

    /**
     * 設定オーバーライドを保存（存在しない場合は追加、存在する場合は更新）
     *
     * @param string $key 設定キー
     * @param string $value 設定値
     * @return void
     */
    public function saveOverride(string $key, string $value): void
    {
        $connection = $this->getConnection();

        // UPDATE rowCount は「値が実際に変化した行数」のみ返すため、
        // 同一値の再保存で rowCount=0 となり INSERT が重複する。
        // ON DUPLICATE KEY UPDATE で upsert を一本化する。
        $connection->execute(
            'INSERT INTO ib_config_overrides (config_key, config_value, created, modified)
             VALUES (:config_key, :config_value, NOW(), NOW())
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), modified = NOW()',
            ['config_key' => $key, 'config_value' => $value],
        );
    }

    /**
     * 設定オーバーライドを削除
     *
     * @param string $key 設定キー
     * @return void
     */
    public function deleteOverride(string $key): void
    {
        $connection = $this->getConnection();

        $connection->execute(
            'DELETE FROM ib_config_overrides WHERE config_key = :config_key',
            ['config_key' => $key],
        );
    }

    /**
     * 複数キーの設定オーバーライドを一括削除（カテゴリ別リセット用）
     *
     * @param array $keys 削除対象の設定キー配列
     * @return void
     */
    public function deleteByCategory(array $keys): void
    {
        if (empty($keys)) {
            return;
        }

        $connection = $this->getConnection();

        foreach ($keys as $key) {
            $connection->execute(
                'DELETE FROM ib_config_overrides WHERE config_key = :config_key',
                ['config_key' => $key],
            );
        }
    }
}
