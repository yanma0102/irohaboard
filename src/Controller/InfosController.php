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

use Cake\Core\Configure;
use Cake\Http\Exception\NotFoundException;

/**
 * Infos Controller
 *
 * CakePHP 5 版
 */
class InfosController extends AppController
{
    /**
     * お知らせ一覧を表示（受講者側）
     *
     * @return void
     */
    public function index(): void
    {
        $infosTable = $this->fetchTable('Infos');

        $query = $infosTable->getInfoOption($this->readAuthUser('id'));

        $infos = $this->paginate($query);

        $this->set(compact('infos'));
    }

    /**
     * お知らせの内容を表示
     *
     * @param int|string $info_id 表示するお知らせのID
     * @return void
     */
    public function view($info_id): void
    {
        $info_id = (int)$info_id;

        $infosTable = $this->fetchTable('Infos');

        if (!$infosTable->exists(['id' => $info_id])) {
            throw new NotFoundException(__('Invalid info'));
        }

        if (!$infosTable->hasRight($this->readAuthUser('id'), $info_id)) {
            throw new NotFoundException(__('Invalid access'));
        }

        $info = $infosTable->get($info_id);

        $this->set(compact('info'));
    }

    /**
     * お知らせ一覧を表示（管理画面）
     *
     * @return void
     */
    public function admin_index(): void
    {
        $infosTable = $this->fetchTable('Infos');

        $query = $infosTable->find()
            ->select(['*'])
            ->select([
                'InfoGroup.group_title' => $infosTable->find()
                    ->select(['group_concat(g.title ORDER BY g.id SEPARATOR \', \')'])
                    ->from(['ug' => 'ib_infos_groups'])
                    ->join([
                        'g' => [
                            'table' => 'ib_groups',
                            'type' => 'INNER',
                            'conditions' => ['g.id = ug.group_id'],
                        ],
                    ])
                    ->group(['ug.info_id'])
                    ->where(['ug.info_id' => $infosTable->aliasField('id')]),
            ])
            ->where([$infosTable->aliasField('deleted IS NULL')])
            ->order([$infosTable->aliasField('created') => 'DESC']);

        $this->paginate = [
            'limit' => 20,
        ];

        $infos = $this->paginate($query);

        $this->set(compact('infos'));
    }

    /**
     * お知らせの追加
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
     * お知らせの編集
     *
     * @param int|string|null $info_id 編集するお知らせのID
     * @return \Cake\Http\Response|null
     */
    public function admin_edit($info_id = null): ?\Cake\Http\Response
    {
        $infosTable = $this->fetchTable('Infos');

        if ($this->isEditPage() && $info_id !== null && !$infosTable->exists(['id' => $info_id])) {
            throw new NotFoundException(__('Invalid info'));
        }

        if ($this->request->is(['post', 'put'])) {
            if (Configure::read('demo_mode')) {
                return null;
            }

            $info = $infosTable->get((int)$info_id);
            $info = $infosTable->patchEntity($info, $this->request->getData());
            $info->user_id = $this->readAuthUser('id');

            if ($infosTable->save($info)) {
                $this->Flash->success(__('お知らせが保存されました'));

                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error(__('The info could not be saved. Please, try again.'));
            }
        } else {
            if ($info_id !== null) {
                $info = $infosTable->get((int)$info_id);
                $this->set(compact('info'));
            }
        }

        $groups = $this->fetchTable('Groups')->find('list');
        $this->set(compact('groups'));

        return null;
    }

    /**
     * お知らせを削除
     *
     * @param int|string|null $info_id 削除するお知らせのID
     * @return \Cake\Http\Response|null
     */
    public function admin_delete($info_id = null): ?\Cake\Http\Response
    {
        $infosTable = $this->fetchTable('Infos');

        if (!$infosTable->exists(['id' => $info_id])) {
            throw new NotFoundException(__('Invalid info'));
        }

        $this->request->allowMethod(['post', 'delete']);

        $info = $infosTable->get((int)$info_id);

        if ($infosTable->delete($info)) {
            $this->Flash->success(__('お知らせが削除されました'));
        } else {
            $this->Flash->error(__('The info could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
