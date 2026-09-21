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
 * Courses Model
 *
 * @property \App\Model\Table\ContentsTable&\Cake\ORM\Association\HasMany $Contents
 *
 * @method \App\Model\Entity\Course newEmptyEntity()
 * @method \App\Model\Entity\Course newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\Course[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Course get($primaryKey, $options = [])
 * @method \App\Model\Entity\Course findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\Course patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\Course[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\Course|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\Course saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\Course[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\Course[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class CoursesTable extends AppTable
{
    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_courses');
        $this->setDisplayField('title');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Contents', [
            'foreignKey' => 'course_id',
            'dependent' => false,
        ]);
    }

    /**
     * Default validation rules.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('id')
            ->allowEmptyString('id', null, 'create');

        $validator
            ->scalar('title')
            ->maxLength('title', 200)
            ->requirePresence('title', 'create')
            ->notEmptyString('title');

        $validator
            ->integer('sort_no')
            ->numeric('sort_no');

        return $validator;
    }

    /**
     * コースの並べ替え
     *
     * @param array $idList コースのIDリスト（並び順）
     */
    public function setOrder(array $idList): void
    {
        $connection = $this->getConnection();

        foreach ($idList as $index => $id) {
            $connection->execute(
                'UPDATE ib_courses SET sort_no = :sort_no WHERE id = :id',
                ['sort_no' => $index + 1, 'id' => $id]
            );
        }
    }

    /**
     * コースへのアクセス権限チェック
     *
     * @param int $userId   アクセス者のユーザID
     * @param int $courseId アクセス先のコースのID
     * @return bool true: アクセス可能, false: アクセス不可
     */
    public function hasRight(int $userId, int $courseId): bool
    {
        $connection = $this->getConnection();
        $params = [
            'user_id' => $userId,
            'course_id' => $courseId,
        ];

        // 個人受講登録チェック
        $data = $connection->execute(
            'SELECT COUNT(*) as cnt FROM ib_users_courses WHERE course_id = :course_id AND user_id = :user_id',
            $params
        )->fetch('assoc');

        if ((int)($data['cnt'] ?? 0) > 0) {
            return true;
        }

        // グループ経由の受講登録チェック
        $data = $connection->execute(
            'SELECT COUNT(*) as cnt
               FROM ib_groups_courses gc
              INNER JOIN ib_users_groups ug ON gc.group_id = ug.group_id AND ug.user_id = :user_id
              WHERE gc.course_id = :course_id',
            $params
        )->fetch('assoc');

        return (int)($data['cnt'] ?? 0) > 0;
    }

    /**
     * コースの削除（テスト問題・コンテンツ・コースを順に削除）
     *
     * @param int $courseId 削除するコースのID
     */
    public function deleteCourse(int $courseId): void
    {
        $connection = $this->getConnection();

        // テスト問題の削除
        $connection->execute(
            'DELETE FROM ib_contents_questions WHERE content_id IN (SELECT id FROM ib_contents WHERE course_id = :course_id)',
            ['course_id' => $courseId]
        );

        // コンテンツの削除
        $connection->execute(
            'DELETE FROM ib_contents WHERE course_id = :course_id',
            ['course_id' => $courseId]
        );

        // コースの削除
        $connection->execute(
            'DELETE FROM ib_courses WHERE id = :course_id',
            ['course_id' => $courseId]
        );
    }

    /**
     * デフォルトのソート順でクエリを返す
     */
    public function findOrdered(Query $query, array $options): Query
    {
        return $query->orderBy(['sort_no' => 'ASC']);
    }
}