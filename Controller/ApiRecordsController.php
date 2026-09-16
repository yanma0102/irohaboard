<?php
/**
 * iroha Board REST API 学習記録コントローラ
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

App::uses('ApiBaseController', 'Controller');

/**
 * ApiRecords Controller
 * 学習記録の一覧・詳細を提供する
 */
class ApiRecordsController extends ApiBaseController
{
	/**
	 * 使用モデル
	 * @var array
	 */
	public $uses = ['Record'];

	/**
	 * 学習記録一覧を取得する
	 *
	 * @return CakeResponse
	 */
	public function index()
	{
		$conditions = [];

		if($this->isStaff())
		{
			// スタッフは指定条件で参照可能
			$userId = $this->queryParam('user_id');
			if($userId !== null)
				$conditions['Record.user_id'] = (int)$userId;

			$courseId = $this->queryParam('course_id');
			if($courseId !== null)
				$conditions['Record.course_id'] = (int)$courseId;

			$contentId = $this->queryParam('content_id');
			if($contentId !== null)
				$conditions['Record.content_id'] = (int)$contentId;
		}
		else
		{
			// 一般ユーザは自分のレコードのみ
			$conditions['Record.user_id'] = $this->currentUserId();
		}

		// 日付範囲フィルタ
		$from = $this->queryParam('from');
		if($from !== null)
			$conditions['Record.created >='] = $from;

		$to = $this->queryParam('to');
		if($to !== null)
			$conditions['Record.created <='] = $to;

		$fields = [
			'Record.id', 'Record.course_id', 'Record.user_id',
			'Record.content_id', 'Record.full_score', 'Record.pass_score',
			'Record.score', 'Record.is_passed', 'Record.is_complete',
			'Record.progress', 'Record.understanding', 'Record.study_sec',
			'Record.created',
		];

		$order = ['Record.id' => 'desc'];

		list($rows, $meta) = $this->paginatedList($this->Record, [
			'conditions' => $conditions,
			'fields' => $fields,
			'order' => $order,
		]);

		return $this->okList($rows, $meta);
	}

	/**
	 * 学習記録詳細を取得する
	 *
	 * @param int $id 記録ID
	 * @return CakeResponse
	 */
	public function view($id)
	{
		$id = (int)$id;

		// 存在チェック
		if(!$this->Record->exists($id))
			$this->fail(404, 'Record not found');

		$record = $this->Record->findById($id);

		// 一般ユーザは自分のレコードのみ参照可能
		if(!$this->isStaff())
		{
			if((int)$record['Record']['user_id'] !== $this->currentUserId())
				$this->fail(403, 'Forbidden');
		}

		return $this->ok($record['Record']);
	}
}
