<?php
/**
 * iroha Board REST API コースコントローラ
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

App::uses('ApiBaseController', 'Controller');

/**
 * ApiCourses Controller
 * コースの一覧・詳細・追加・削除を提供する
 */
class ApiCoursesController extends ApiBaseController
{
	/**
	 * 使用モデル
	 * @var array
	 */
	public $uses = ['Course'];

	/**
	 * コース一覧を取得する
	 *
	 * @return CakeResponse
	 */
	public function index()
	{
		$conditions = ['Course.deleted' => null];

		if($this->isStaff())
		{
			// スタッフは全件参照可能
			$title = $this->queryParam('title');
			if($title !== null)
			{
				if($this->wantsExact())
					$conditions['Course.title'] = $title;
				else
					$conditions['Course.title LIKE'] = '%' . $title . '%';
			}
		}
		else
		{
			// 一般ユーザは受講可能なコースのみ
			$courseIds = $this->accessibleCourseIds($this->currentUserId());

			if(empty($courseIds))
				return $this->okList([], ['page' => 1, 'limit' => 0, 'total' => 0, 'count' => 0]);

			$conditions['Course.id IN'] = $courseIds;

			$title = $this->queryParam('title');
			if($title !== null)
			{
				if($this->wantsExact())
					$conditions['Course.title'] = $title;
				else
					$conditions['Course.title LIKE'] = '%' . $title . '%';
			}
		}

		$fields = [
			'Course.id', 'Course.title', 'Course.introduction',
			'Course.opened', 'Course.sort_no', 'Course.comment',
			'Course.user_id', 'Course.created', 'Course.modified',
		];

		$order = ['Course.sort_no' => 'asc', 'Course.id' => 'asc'];

		list($rows, $meta) = $this->paginatedList($this->Course, [
			'conditions' => $conditions,
			'fields' => $fields,
			'order' => $order,
		]);

		return $this->okList($rows, $meta);
	}

	/**
	 * コース詳細を取得する
	 *
	 * @param int $id コースID
	 * @return CakeResponse
	 */
	public function view($id)
	{
		$id = (int)$id;

		// 存在チェック
		if(!$this->Course->exists($id))
			$this->fail(404, 'Course not found');

		// 一般ユーザは受講可能なコースのみ参照可能
		if(!$this->isStaff())
		{
			$courseIds = $this->accessibleCourseIds($this->currentUserId());

			if(!in_array($id, $courseIds, true))
				$this->fail(404, 'Course not found');
		}

		$course = $this->Course->findById($id);

		return $this->ok($course['Course']);
	}

	/**
	 * コースを追加する
	 *
	 * @return CakeResponse
	 */
	public function add()
	{
		$this->requireManager();

		$input = $this->input();
		$fields = [];

		$allowedFields = ['title', 'introduction', 'opened', 'comment', 'sort_no'];

		foreach($allowedFields as $field)
		{
			if(array_key_exists($field, $input))
				$fields[$field] = $input[$field];
		}

		// sort_no 未指定なら最大値+1 を設定
		if(!isset($fields['sort_no']) || $fields['sort_no'] === '' || $fields['sort_no'] === null)
		{
			$maxResult = $this->Course->find('first', [
				'conditions' => ['Course.deleted' => null],
				'fields' => ['MAX(Course.sort_no) AS max_sort_no'],
				'recursive' => -1,
			]);

			$maxSortNo = isset($maxResult['max_sort_no']) ? (int)$maxResult['max_sort_no'] : 0;
			$fields['sort_no'] = $maxSortNo + 1;
		}

		// 作成者を設定
		$fields['user_id'] = $this->currentUserId();

		$this->Course->create();
		$saved = $this->Course->save(['Course' => $fields]);

		if($saved)
		{
			$saved['Course']['id'] = (int)$saved['Course']['id'];
			$saved['Course']['user_id'] = (int)$saved['Course']['user_id'];
			$saved['Course']['sort_no'] = (int)$saved['Course']['sort_no'];
			return $this->ok($saved['Course'], 201);
		}

		$this->fail(400, 'Validation failed', $this->Course->validationErrors);
	}

	/**
	 * コースを削除する
	 *
	 * @param int $id コースID
	 * @return CakeResponse
	 */
	public function delete($id)
	{
		$this->requireManager();

		$id = (int)$id;

		if(!$this->Course->exists($id))
			$this->fail(404, 'Course not found');

		$this->Course->deleteCourse($id);

		return $this->ok(['id' => $id, 'deleted' => true]);
	}
}
