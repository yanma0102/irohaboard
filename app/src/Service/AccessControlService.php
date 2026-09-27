<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Service;

use Cake\Datasource\ConnectionInterface;

/**
 * コースアクセス制御・ロール判定の共通サービス。
 *
 * BaseController の生 SQL ロジックを移植し、
 * REST API と MCP ツールの双方から利用可能にする。
 */
class AccessControlService
{
    /**
     * @param \Cake\Datasource\ConnectionInterface $connection DB コネクション
     */
    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    /**
     * 使用中のコネクションを取得する
     *
     * @return \Cake\Datasource\ConnectionInterface
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * スタッフロール判定（BaseController::isStaff() と同一ロジック）
     *
     * @param string $role ロール
     * @return bool スタッフの場合 true
     */
    public function isStaff(string $role): bool
    {
        return in_array($role, ['admin', 'manager', 'editor', 'teacher'], true);
    }

    /**
     * 指定ユーザが受講可能なコースIDを取得する
     *
     * ib_users_courses と ib_groups_courses/ib_users_groups の UNION。
     * BaseController::accessibleCourseIds() と同一ロジック。
     *
     * @param int $userId ユーザID
     * @return array<int> コースIDの配列
     */
    public function accessibleCourseIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $sql = <<<EOF
SELECT course_id
  FROM ib_users_courses
 WHERE user_id = :user_id
UNION
SELECT gc.course_id
  FROM ib_groups_courses gc
 INNER JOIN ib_users_groups ug ON gc.group_id = ug.group_id
 WHERE ug.user_id = :user_id
EOF;

        $rows = $this->connection->execute($sql, ['user_id' => $userId])->fetchAll('assoc');

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row['course_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * 指定ユーザが指定コースにアクセスできるか
     *
     * staff ロールは全コースにアクセス可能（Web 管理画面と同一）。
     *
     * @param int $userId ユーザID
     * @param int $courseId コースID
     * @param string $role ロール（省略時は一般ユーザ扱い）
     * @return bool アクセス可能な場合 true
     */
    public function canAccessCourse(int $userId, int $courseId, string $role = ''): bool
    {
        if ($this->isStaff($role)) {
            return true;
        }

        return in_array($courseId, $this->accessibleCourseIds($userId), true);
    }

    /**
     * 指定ユーザが所属するグループIDを取得する
     *
     * BaseController::currentUserGroupIds() と同一ロジック。
     *
     * @param int $userId ユーザID
     * @return array<int> グループIDの配列
     */
    public function currentUserGroupIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $sql = 'SELECT group_id FROM ib_users_groups WHERE user_id = :user_id';
        $rows = $this->connection->execute($sql, ['user_id' => $userId])->fetchAll('assoc');

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row['group_id'];
        }

        return array_values(array_unique($ids));
    }
}
