<?php
/**
 * iroha Board REST API コンテンツコントローラ
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

App::uses('ApiBaseController', 'Controller');

/**
 * ApiContents Controller
 * コンテンツの一覧・詳細を提供する
 */
class ApiContentsController extends ApiBaseController
{
	/**
	 * 使用モデル
	 * @var array
	 */
	public $uses = ['Content'];

	/**
	 * コンテンツ一覧を取得する
	 *
	 * @return CakeResponse
	 */
	public function index()
	{
		$conditions = ['Content.deleted' => null];

		if($this->isStaff())
		{
			// スタッフは全件参照可能
			$courseId = $this->queryParam('course_id');
			if($courseId !== null)
				$conditions['Content.course_id'] = (int)$courseId;

			$kind = $this->queryParam('kind');
			if($kind !== null)
				$conditions['Content.kind'] = $kind;

			$status = $this->queryParam('status');
			if($status !== null)
				$conditions['Content.status'] = (int)$status;
		}
		else
		{
			// 一般ユーザは受講可能なコースの公開コンテンツのみ
			$courseIds = $this->accessibleCourseIds($this->currentUserId());

			if(empty($courseIds))
				return $this->okList([], ['page' => 1, 'limit' => 0, 'total' => 0, 'count' => 0]);

			$conditions['Content.course_id IN'] = $courseIds;
			// 一般ユーザは公開コンテンツのみ（status クエリパラメータは無視）
			$conditions['Content.status'] = 1;

			$courseId = $this->queryParam('course_id');
			if($courseId !== null)
				$conditions['Content.course_id'] = (int)$courseId;

			$kind = $this->queryParam('kind');
			if($kind !== null)
				$conditions['Content.kind'] = $kind;
		}

		$fields = [
			'Content.id', 'Content.course_id', 'Content.user_id',
			'Content.title', 'Content.url', 'Content.file_name',
			'Content.kind', 'Content.body', 'Content.timelimit',
			'Content.pass_rate', 'Content.question_count',
			'Content.wrong_mode', 'Content.status', 'Content.opened',
			'Content.sort_no', 'Content.created', 'Content.modified',
		];

		$order = ['Content.sort_no' => 'asc', 'Content.id' => 'asc'];

		list($rows, $meta) = $this->paginatedList($this->Content, [
			'conditions' => $conditions,
			'fields' => $fields,
			'order' => $order,
		]);

		return $this->okList($rows, $meta);
	}

	/**
	 * コンテンツ詳細を取得する
	 *
	 * @param int $id コンテンツID
	 * @return CakeResponse
	 */
	public function view($id)
	{
		$id = (int)$id;

		// 存在チェック
		if(!$this->Content->exists($id))
			$this->fail(404, 'Content not found');

		$content = $this->Content->findById($id);

		// 一般ユーザは受講可能なコースの公開コンテンツのみ参照可能
		if(!$this->isStaff())
		{
			$courseIds = $this->accessibleCourseIds($this->currentUserId());
			$courseId = (int)$content['Content']['course_id'];

			if(!in_array($courseId, $courseIds, true) || (int)$content['Content']['status'] !== 1)
				$this->fail(404, 'Content not found');
		}

		return $this->ok($content['Content']);
	}
}
