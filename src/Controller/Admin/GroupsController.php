<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Controller\Admin;

use App\Controller\AppController;
use Cake\Database\Expression\QueryExpression;
use Cake\Http\Exception\NotFoundException;

/**
 * Groups Controller (Admin)
 *
 * CakePHP 5 版
 */
class GroupsController extends AppController
{
    /**
     * 初期化
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->FormProtection->unlockActions([]);
    }

    /**
     * グループ一覧を表示
     *
     * @return void
     */
    public function index(): void
    {
        $groupsTable = $this->fetchTable('Groups');

        $query = $groupsTable->find()
            ->select($groupsTable)
            ->select([
                'course_title' => new QueryExpression(
                    "(SELECT GROUP_CONCAT(c.title ORDER BY c.id SEPARATOR ', ') AS course_title FROM ib_groups_courses gc INNER JOIN ib_courses c ON c.id = gc.course_id WHERE gc.group_id = Groups.id GROUP BY gc.group_id)"
                ),
            ])
            ->where([$groupsTable->aliasField('deleted IS NULL')])
            ->orderBy([$groupsTable->aliasField('created') => 'DESC']);

        $this->paginate = [
            'limit' => 20,
        ];

        $groups = $this->paginate($query);
        $this->set(compact('groups'));
    }

    /**
     * グループの追加（edit に委譲）
     *
     * @return \Cake\Http\Response|null
     */
    public function add(): ?\Cake\Http\Response
    {
        $result = $this->edit();
        if ($result !== null) {
            return $result;
        }
        $this->viewBuilder()->setTemplate('edit');

        return null;
    }

    /**
     * グループの編集
     *
     * @param int|string|null $group_id 編集するグループのID
     * @return \Cake\Http\Response|null
     */
    public function edit($group_id = null): ?\Cake\Http\Response
    {
        $groupsTable = $this->fetchTable('Groups');

        if ($this->isEditPage() && $group_id !== null && !$groupsTable->exists(['id' => $group_id])) {
            throw new NotFoundException(__('Invalid group'));
        }

        if ($this->request->is(['post', 'put'])) {
            $groupData = $this->request->getData();

            // フォームの「Course」フィールドをORMの courses._ids 形式に変換
            $courseIds = (array)($groupData['Course'] ?? []);
            unset($groupData['Course']);
            $groupData['courses'] = ['_ids' => $courseIds];

            $group = $group_id !== null
                ? $groupsTable->get((int)$group_id)
                : $groupsTable->newEmptyEntity();
            $group = $groupsTable->patchEntity($group, $groupData);

            if ($groupsTable->save($group)) {
                $this->Flash->success(__('グループ情報を保存しました'));

                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error(__('The group could not be saved. Please, try again.'));
            }
        } else {
            $group = $group_id !== null ? $groupsTable->get((int)$group_id) : $groupsTable->newEmptyEntity();
        }

        $courses = $this->fetchTable('Courses')->find('list');
        $this->set(compact('group', 'courses'));

        return null;
    }

    /**
     * グループの削除
     *
     * @param int|string|null $group_id 削除するグループのID
     * @return \Cake\Http\Response|null
     */
    public function delete($group_id = null): ?\Cake\Http\Response
    {
        $groupsTable = $this->fetchTable('Groups');

        if (!$groupsTable->exists(['id' => $group_id])) {
            throw new NotFoundException(__('Invalid group'));
        }

        $this->request->allowMethod(['post', 'delete']);

        $group = $groupsTable->get((int)$group_id);

        if ($groupsTable->delete($group)) {
            $this->Flash->success(__('グループ情報を削除しました'));
        } else {
            $this->Flash->error(__('The group could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
