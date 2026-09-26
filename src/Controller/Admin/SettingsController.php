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
use Cake\Http\Response;

/**
 * Settings Controller (Admin)
 *
 * CakePHP 5 版
 */
class SettingsController extends AppController
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
     * システム設定項目を表示
     *
     * @return \Cake\Http\Response|null
     */
    public function index(): ?Response
    {
        $settingsTable = $this->fetchTable('Settings');

        if ($this->request->is(['post', 'put'])) {
            if (Configure::read('demo_mode')) {
                return null;
            }

            $settingsTable->setSettings($this->getData('Setting'));

            foreach ($this->getData('Setting') as $key => $value) {
                $this->writeSession('Setting.' . $key, $value);
            }

            $this->Flash->success(__('設定が保存されました'));
        }

        $settings = $settingsTable->getSettings();
        $colors = Configure::read('theme_colors');

        $this->set(compact('settings', 'colors'));

        return null;
    }
}
