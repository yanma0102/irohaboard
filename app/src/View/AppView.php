<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\View;

use App\View\Helper\AppFormHelper;
use App\View\Helper\AppHtmlHelper;
use App\View\Helper\AppNumberHelper;
use Cake\View\View;

/**
 * AppView - 全てのビューの基底クラス
 *
 * CakePHP 5 版
 */
class AppView extends View
{
    /**
     * 初期化
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadHelper('Html', ['className' => AppHtmlHelper::class]);
        $this->loadHelper('Form', ['className' => AppFormHelper::class]);
        $this->loadHelper('Flash');
        $this->loadHelper('Paginator');
        $this->loadHelper('Url');
        $this->loadHelper('Number', ['className' => AppNumberHelper::class]);
        $this->loadHelper('AppView');
        $this->loadHelper('Markdown');
    }
}
