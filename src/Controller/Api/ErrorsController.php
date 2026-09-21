<?php
declare(strict_types=1);

/**
 * iroha Board REST API
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Controller\Api;

/**
 * ApiErrors Controller
 *
 * 未定義の API ルートに対する JSON 404 応答
 */
class ErrorsController extends BaseController
{
    /**
     * 未定義ルートのハンドリング
     *
     * @return \Cake\Http\Response
     */
    public function notFound(): \Cake\Http\Response
    {
        return $this->fail(404, 'Endpoint not found');
    }
}
