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
use Cake\Core\Configure;
use Cake\Database\Expression\QueryExpression;
use Cake\Http\Exception\NotFoundException;

/**
 * Infos Controller (Admin)
 *
 * CakePHP 5 版
 */
class InfosController extends AppController
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
     * お知らせ一覧を表示（管理画面）
     *
     * @return void
     */
    public function index(): void
    {
        $infosTable = $this->fetchTable('Infos');

        $query = $infosTable->find()
            ->select($infosTable)
            ->select([
                'group_title' => new QueryExpression(
                    "(SELECT GROUP_CONCAT(g.title ORDER BY g.id SEPARATOR ', ') AS group_title FROM ib_infos_groups ug INNER JOIN ib_groups g ON g.id = ug.group_id WHERE ug.info_id = Infos.id GROUP BY ug.info_id)"
                ),
            ])
            ->orderBy([$infosTable->aliasField('created') => 'DESC']);

        $this->paginate = [
            'limit' => 20,
        ];

        $infos = $this->paginate($query);

        $this->set(compact('infos'));
    }

    /**
     * お知らせの追加（edit に委譲）
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
     * お知らせの編集
     *
     * @param int|string|null $info_id 編集するお知らせのID
     * @return \Cake\Http\Response|null
     */
    public function edit($info_id = null): ?\Cake\Http\Response
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
            $info = $info_id !== null ? $infosTable->get((int)$info_id) : $infosTable->newEmptyEntity();
        }

        $groups = $this->fetchTable('Groups')->find('list');
        $this->set(compact('info', 'groups'));

        return null;
    }

    /**
     * お知らせを削除
     *
     * @param int|string|null $info_id 削除するお知らせのID
     * @return \Cake\Http\Response|null
     */
    public function delete($info_id = null): ?\Cake\Http\Response
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
