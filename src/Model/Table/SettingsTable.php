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
 * Settings Model
 *
 * @method \App\Model\Entity\Setting newEmptyEntity()
 * @method \App\Model\Entity\Setting newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\Setting[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Setting get($primaryKey, $options = [])
 * @method \App\Model\Entity\Setting findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\Setting patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\Setting[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\Setting|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\Setting saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\Setting[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\Setting[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class SettingsTable extends AppTable
{
    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_settings');
        $this->setDisplayField('setting_name');
        $this->setPrimaryKey('id');
    }

    /**
     * Default validation rules.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('setting_key')
            ->maxLength('setting_key', 100)
            ->requirePresence('setting_key', 'create')
            ->notEmptyString('setting_key');

        $validator
            ->scalar('setting_name')
            ->maxLength('setting_name', 100)
            ->requirePresence('setting_name', 'create')
            ->notEmptyString('setting_name');

        $validator
            ->scalar('setting_value')
            ->maxLength('setting_value', 1000)
            ->requirePresence('setting_value', 'create')
            ->notEmptyString('setting_value');

        return $validator;
    }

    /**
     * システム設定の値のリストを取得
     *
     * @return array 設定値リスト（連想配列）
     */
    public function getSettings(): array
    {
        $result = [];

        $settings = $this->find()
            ->select(['setting_key', 'setting_value'])
            ->toArray();

        foreach ($settings as $setting) {
            $result[$setting->setting_key] = $setting->setting_value;
        }

        return $result;
    }

    /**
     * システム設定を保存
     *
     * setting_key は一意制約（uk_setting_key）を持つため、ON DUPLICATE KEY UPDATE で upsert する。
     * UPDATE のみだと未登録のキーは静かに無視され、「保存したのに反映されない」状態になるため。
     *
     * @param array $settings 保存する設定値リスト（連想配列）
     */
    public function setSettings(array $settings): void
    {
        $connection = $this->getConnection();

        foreach ($settings as $key => $value) {
            $connection->execute(
                'INSERT INTO ib_settings (setting_key, setting_name, setting_value)
                 VALUES (:setting_key, :setting_name, :setting_value)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                ['setting_key' => $key, 'setting_name' => $key, 'setting_value' => $value],
            );
        }
    }
}
