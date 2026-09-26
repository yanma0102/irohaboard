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
     * @param string|int $info_id 表示するお知らせのID
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
}
