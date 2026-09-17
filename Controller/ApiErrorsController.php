<?php
/**
 * iroha Board REST API フォールバックコントローラ
 *
 * 定義されていない /api 配下のパスや、HTTPメソッドが一致しないリクエストに対して
 * HTML ではなく JSON の 404 を返す。
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

App::uses('ApiBaseController', 'Controller');

class ApiErrorsController extends ApiBaseController
{
	/**
	 * @var array
	 */
	public $uses = [];

	/**
	 * 未定義エンドポイントは認証不要で 404 を返す
	 * @var array
	 */
	protected $allowUnauthenticated = ['notFound'];

	/**
	 * 未定義エンドポイント
	 *
	 * @return void
	 */
	public function notFound()
	{
		$this->fail(404, 'Endpoint not found');
	}
}
