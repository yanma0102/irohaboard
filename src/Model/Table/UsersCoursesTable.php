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
 * UsersCourses Model
 *
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 * @property \App\Model\Table\CoursesTable&\Cake\ORM\Association\BelongsTo $Courses
 *
 * @method \App\Model\Entity\UsersCourse newEmptyEntity()
 * @method \App\Model\Entity\UsersCourse newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\UsersCourse[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\UsersCourse get($primaryKey, $options = [])
 * @method \App\Model\Entity\UsersCourse findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\UsersCourse patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\UsersCourse[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\UsersCourse|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\UsersCourse saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\UsersCourse[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\UsersCourse[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class UsersCoursesTable extends AppTable
{
    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_users_courses');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
        ]);
        $this->belongsTo('Courses', [
            'foreignKey' => 'course_id',
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
            ->integer('course_id')
            ->requirePresence('course_id', 'create')
            ->notEmptyString('course_id');

        return $validator;
    }

    /**
     * 学習履歴付き受講コース一覧を取得
     *
     * @param int $userId ユーザのID
     * @return array 受講コース一覧
     */
    public function getCourseRecord(int $userId): array
    {
        $sql = <<<EOF
 SELECT Course.*, Record.first_date, Record.last_date,
       (ifnull(ContentCount.content_cnt, 0) - ifnull(CompleteCount.complete_cnt, 0)) as left_cnt
   FROM ib_courses Course
   LEFT OUTER JOIN
       (SELECT h.course_id, h.user_id,
               MAX(DATE_FORMAT(created, '%Y/%m/%d')) as last_date,
               MIN(DATE_FORMAT(created, '%Y/%m/%d')) as first_date
          FROM ib_records h
         WHERE h.user_id = :user_id
         GROUP BY h.course_id, h.user_id) Record
     ON Record.course_id   = Course.id
    AND Record.user_id     = :user_id
   LEFT OUTER JOIN
        (SELECT course_id, COUNT(*) as complete_cnt
           FROM
            (SELECT r.course_id, r.content_id, COUNT(*) as cnt
               FROM ib_records r
              INNER JOIN ib_contents c ON r.content_id = c.id AND r.course_id = c.course_id
              WHERE r.user_id = :user_id
                AND c.status = 1
                AND (
                      (c.kind != 'test' AND r.is_complete = 1) OR
                      (c.kind  = 'test' AND r.is_passed   = 1)
                    )
              GROUP BY r.course_id, r.content_id) as c
           GROUP BY course_id) CompleteCount
     ON CompleteCount.course_id = Course.id
   LEFT OUTER JOIN
        (SELECT course_id, COUNT(*) as content_cnt
           FROM ib_contents
          WHERE kind NOT IN ('label', 'file')
            AND status = 1
          GROUP BY course_id) ContentCount
     ON ContentCount.course_id   = Course.id
  WHERE id IN (SELECT course_id FROM ib_users_groups ug INNER JOIN ib_groups_courses gc ON ug.group_id = gc.group_id WHERE user_id = :user_id)
     OR id IN (SELECT course_id FROM ib_users_courses WHERE user_id = :user_id)
  ORDER BY Course.sort_no asc
EOF;

        $connection = $this->getConnection();
        $statement = $connection->execute($sql, ['user_id' => $userId]);

        return $statement->fetchAll('assoc');
    }
}