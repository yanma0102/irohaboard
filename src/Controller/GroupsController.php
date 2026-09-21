<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Controller;

use Cake\Http\Exception\NotFoundException;

/**
 * Groups Controller
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
        $this->FormProtection->unlockActions(['admin_order']);
    }

    /**
     * グループ一覧を表示
     *
     * @return void
     */
    public function admin_index(): void
    {
        $groupsTable = $this->fetchTable('Groups');

        $query = $groupsTable->find()
            ->select(['*'])
            ->select([
                'GroupCourse.course_title' => $groupsTable->find()
                    ->select(['group_concat(c.title ORDER BY c.id SEPARATOR \', \')'])
                    ->from(['gc' => 'ib_groups_courses'])
                    ->join([
                        'c' => [
                            'table' => 'ib_courses',
                            'type' => 'INNER',
                            'conditions' => ['c.id = gc.course_id'],
                        ],
                    ])
                    ->group(['gc.group_id'])
                    ->where(['gc.group_id' => \Cake\ORM\TableRegistry::getTableLocator()->get('Groups')->aliasField('id')]),
            ])
            ->where([$groupsTable->aliasField('deleted IS NULL')])
            ->order([$groupsTable->aliasField('created') => 'DESC']);

        $this->paginate = [
            'limit' => 20,
        ];

        $groups = $this->paginate($query);
        $this->set(compact('groups'));
    }

    /**
     * グループの追加
     *
     * @return \Cake\Http\Response|null
     */
    public function admin_add(): ?\Cake\Http\Response
    {
        $this->admin_edit();
        $this->viewBuilder()->setOption('template', 'admin_edit');

        return null;
    }

    /**
     * グループの編集
     *
     * @param int|string|null $group_id 編集するグループのID
     * @return \Cake\Http\Response|null
     */
    public function admin_edit($group_id = null): ?\Cake\Http\Response
    {
        $groupsTable = $this->fetchTable('Groups');

        if ($this->isEditPage() && $group_id !== null && !$groupsTable->exists(['id' => $group_id])) {
            throw new NotFoundException(__('Invalid group'));
        }

        if ($this->request->is(['post', 'put'])) {
            $group = $groupsTable->get((int)$group_id);
            $group = $groupsTable->patchEntity($group, $this->request->getData());

            if ($groupsTable->save($group)) {
                $this->Flash->success(__('グループ情報を保存しました'));

                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error(__('The group could not be saved. Please, try again.'));
            }
        } else {
            $group = $groupsTable->get((int)$group_id);
            $this->set(compact('group'));
        }

        $courses = $this->fetchTable('Courses')->find('list');
        $this->set(compact('courses'));

        return null;
    }

    /**
     * グループの削除
     *
     * @param int|string|null $group_id 削除するグループのID
     * @return \Cake\Http\Response|null
     */
    public function admin_delete($group_id = null): ?\Cake\Http\Response
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
