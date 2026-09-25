<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Model\Table;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Query;
use Cake\Validation\Validator;

/**
 * Users Model
 *
 * @property \App\Model\Table\CoursesTable&\Cake\ORM\Association\BelongsToMany $Courses
 * @property \App\Model\Table\GroupsTable&\Cake\ORM\Association\BelongsToMany $Groups
 *
 * @method \App\Model\Entity\User newEmptyEntity()
 * @method \App\Model\Entity\User newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\User[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\User get($primaryKey, $options = [])
 * @method \App\Model\Entity\User findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\User patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\User[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\User|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\User saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\User[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\User[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class UsersTable extends AppTable
{
    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_users');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsToMany('Courses', [
            'joinTable' => 'ib_users_courses',
            'foreignKey' => 'user_id',
            'targetForeignKey' => 'course_id',
            'saveStrategy' => 'replace',
        ]);
        $this->belongsToMany('Groups', [
            'joinTable' => 'ib_users_groups',
            'foreignKey' => 'user_id',
            'targetForeignKey' => 'group_id',
            'saveStrategy' => 'replace',
        ]);
    }

    /**
     * Default validation rules.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('username')
            ->maxLength('username', 50)
            ->requirePresence('username', 'create')
            ->notEmptyString('username')
            ->add('username', 'unique', [
                'rule' => 'validateUnique',
                'provider' => 'table',
                'message' => 'ログインIDが重複しています',
            ])
            ->add('username', 'alphanumeric', [
                'rule' => ['custom', '/^[a-zA-Z0-9]+$/'],
                'message' => 'ログインIDは英数字で入力して下さい',
            ])
            ->add('username', 'lengthBetween', [
                'rule' => ['lengthBetween', 4, 32],
                'message' => 'ログインIDは4文字以上32文字以内で入力して下さい',
            ]);

        $validator
            ->scalar('name')
            ->maxLength('name', 50)
            ->requirePresence('name', 'create')
            ->notEmptyString('name', '氏名が入力されていません');

        $validator
            ->scalar('role')
            ->maxLength('role', 20)
            ->requirePresence('role', 'create')
            ->notEmptyString('role', '権限が指定されていません');

        $validator
            ->add('password', 'alphanumeric', [
                'rule' => ['custom', '/^[a-zA-Z0-9]+$/'],
                'message' => 'パスワードは英数字で入力して下さい',
                'allowEmpty' => true,
            ])
            ->add('password', 'lengthBetween', [
                'rule' => ['lengthBetween', 4, 32],
                'message' => 'パスワードは4文字以上32文字以内で入力して下さい',
                'allowEmpty' => true,
            ]);

        $validator
            ->add('new_password', 'alphanumeric', [
                'rule' => ['custom', '/^[a-zA-Z0-9]+$/'],
                'message' => 'パスワードは英数字で入力して下さい',
                'allowEmpty' => true,
            ])
            ->add('new_password', 'lengthBetween', [
                'rule' => ['lengthBetween', 4, 32],
                'message' => 'パスワードは4文字以上32文字以内で入力して下さい',
                'allowEmpty' => true,
            ]);

        return $validator;
    }

    /**
     * 認証用 finder（Authentication plugin の OrmResolver 用）
     *
     * 削除済みユーザ・無効ユーザを除外し、id/username/password を返す
     */
    public function findAuth(Query $query, array $options): Query
    {
        return $query
            ->select(['id', 'username', 'password', 'role', 'name'])
            ->where(['deleted IS NULL']);
    }

    /**
     * beforeSave イベント
     * パスワードが平文の場合、bcrypt ハッシュに変換
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if ($entity->has('password') && !empty($entity->get('password'))) {
            $password = $entity->get('password');

            // 既に bcrypt ハッシュ（$2y$ 等）の場合は再ハッシュしない
            if (is_string($password) && substr($password, 0, 1) !== '$') {
                $entity->set('password', password_hash($password, PASSWORD_BCRYPT));
            }
        }
    }

    /**
     * 学習履歴の削除
     *
     * @param int $userId 学習履歴を削除するユーザのID
     */
    public function deleteUserRecords(int $userId): void
    {
        $connection = $this->getConnection();

        // 学習履歴詳細レコードを先に削除
        $connection->execute(
            'DELETE FROM ib_records_questions WHERE record_id IN (SELECT id FROM ib_records WHERE user_id = :user_id)',
            ['user_id' => $userId]
        );

        // 学習履歴レコードを削除
        $connection->execute(
            'DELETE FROM ib_records WHERE user_id = :user_id',
            ['user_id' => $userId]
        );
    }

    /**
     * デフォルトのソート順でクエリを返す
     */
    public function findOrdered(Query $query, array $options): Query
    {
        return $query->orderBy(['name' => 'ASC']);
    }
}