<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Model\Table;

use Cake\Core\Configure;
use Cake\ORM\Query;
use Cake\Validation\Validator;

/**
 * Contents Model
 *
 * @property \App\Model\Table\CoursesTable&\Cake\ORM\Association\BelongsTo $Courses
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 *
 * @method \App\Model\Entity\Content newEmptyEntity()
 * @method \App\Model\Entity\Content newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\Content[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Content get($primaryKey, $options = [])
 * @method \App\Model\Entity\Content findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\Content patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\Content[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\Content|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\Content saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\Content[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\Content[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class ContentsTable extends AppTable
{
    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_contents');
        $this->setDisplayField('title');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Courses', [
            'foreignKey' => 'course_id',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
        ]);
    }

    /**
     * Default validation rules.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('course_id')
            ->requirePresence('course_id', 'create')
            ->notEmptyString('course_id');

        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('title')
            ->maxLength('title', 200)
            ->requirePresence('title', 'create')
            ->notEmptyString('title');

        $validator
            ->scalar('kind')
            ->maxLength('kind', 20)
            ->requirePresence('kind', 'create')
            ->notEmptyString('kind');

        $validator
            ->notBlank('status');

        $validator
            ->integer('timelimit')
            ->range('timelimit', [0, 101])
            ->allowEmptyString('timelimit');

        $validator
            ->integer('pass_rate')
            ->range('pass_rate', [0, 101])
            ->allowEmptyString('pass_rate');

        $validator
            ->integer('question_count')
            ->range('question_count', [0, 101])
            ->allowEmptyString('question_count');

        $validator
            ->numeric('sort_no');

        $contentKind = Configure::read('content_kind');
        if (!empty($contentKind)) {
            $validator
                ->inList('kind', array_keys($contentKind), 'Invalid content kind', function (array $context): bool {
                    $kind = $context['data']['kind'] ?? '';

                    return $kind !== '';
                });

            $bodyKinds = ['text', 'html', 'markdown'];
            $validator
                ->requirePresence('body', function (array $context) use ($bodyKinds): bool {
                    $kind = $context['data']['kind'] ?? '';

                    return in_array($kind, $bodyKinds, true);
                })
                ->allowEmptyString('body', null, function (array $context) use ($bodyKinds): bool {
                    $kind = $context['data']['kind'] ?? '';

                    return !in_array($kind, $bodyKinds, true);
                });
        }

        return $validator;
    }

    /**
     * 学習履歴付きコンテンツ一覧を取得
     *
     * @param int $userId   取得対象のユーザID
     * @param int $courseId 取得対象のコースID
     * @param string $role  取得者の権限（admin の場合、非公開のコンテンツも取得）
     * @return array 学習履歴付きコンテンツ一覧
     */
    public function getContentRecord(int $userId, int $courseId, string $role = 'user'): array
    {
        $sql = <<<EOF
 SELECT Content.*, first_date, last_date, record_id, Record.study_sec, Record.study_count,
       (SELECT understanding
          FROM ib_records h1
         WHERE h1.id = Record.record_id
         ORDER BY created
          DESC LIMIT 1) as understanding,
       (SELECT ifnull(is_passed, 0)
          FROM ib_records h2
         WHERE h2.id = Record.record_id
         ORDER BY created
          DESC LIMIT 1) as is_passed,
        CompleteRecord.is_complete
   FROM ib_contents Content
   LEFT OUTER JOIN # 全ての学習履歴の集計
       (SELECT h.content_id, h.user_id,
               MAX(DATE_FORMAT(created, '%Y/%m/%d')) as last_date,
               MIN(DATE_FORMAT(created, '%Y/%m/%d')) as first_date,
               MAX(id) as record_id,
               SUM(ifnull(study_sec, 0)) as study_sec,
               COUNT(*) as study_count
          FROM ib_records h
         WHERE h.user_id    = :user_id
           AND h.course_id  = :course_id
         GROUP BY h.content_id) Record
     ON Record.content_id  = Content.id
   LEFT OUTER JOIN # 完了した学習履歴の集計
       (SELECT r.content_id, 1 as is_complete
          FROM ib_records r
         INNER JOIN ib_contents c ON r.content_id = c.id AND r.course_id = c.course_id
         WHERE r.user_id    = :user_id
           AND r.course_id  = :course_id
           AND c.status = 1
           AND (
                 (c.kind != 'test' AND r.is_complete = 1) OR
                 (c.kind  = 'test' AND r.is_passed   = 1)
               )
         GROUP BY r.content_id) as CompleteRecord
     ON CompleteRecord.content_id = Content.id
  WHERE Content.course_id  = :course_id
    AND (status = 1 OR 'admin' = :role)
  ORDER BY Content.sort_no
EOF;

        $connection = $this->getConnection();
        $statement = $connection->execute($sql, [
            'user_id' => $userId,
            'course_id' => $courseId,
            'role' => $role,
        ]);

        return $statement->fetchAll('assoc');
    }

    /**
     * コンテンツの並べ替え
     *
     * @param array $idList コンテンツのIDリスト（並び順）
     */
    public function setOrder(array $idList): void
    {
        $connection = $this->getConnection();

        foreach ($idList as $index => $id) {
            $connection->execute(
                'UPDATE ib_contents SET sort_no = :sort_no WHERE id = :id',
                ['sort_no' => $index + 1, 'id' => $id]
            );
        }
    }

    /**
     * 新規追加時のコンテンツのソート番号を取得
     *
     * @param int $courseId コースID
     * @return int ソート番号
     */
    public function getNextSortNo(int $courseId): int
    {
        $query = $this->find();
        $data = $query->select(['max_sort_no' => $query->func()->max('sort_no')])
            ->where(['course_id' => $courseId])
            ->first();

        return (int)($data->max_sort_no ?? 0) + 1;
    }

    /**
     * デフォルトのソート順でクエリを返す
     */
    public function findOrdered(Query $query, array $options): Query
    {
        return $query->orderBy(['sort_no' => 'ASC']);
    }
}