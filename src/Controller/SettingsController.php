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

/**
 * Settings Controller
 *
 * CakePHP 5 版
 */
class SettingsController extends AppController
{
    /**
     * システム設定項目を表示
     *
     * @return \Cake\Http\Response|null
     */
    public function admin_index(): ?\Cake\Http\Response
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
