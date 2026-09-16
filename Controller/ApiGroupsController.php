<?php
/**
 * iroha Board REST API グループコントローラ
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

App::uses('ApiBaseController', 'Controller');

/**
 * ApiGroups Controller
 * グループの一覧・詳細を提供する
 */
class ApiGroupsController extends ApiBaseController
{
	/**
	 * 使用モデル
	 * @var array
	 */
	public $uses = ['Group'];

	/**
	 * グループ一覧を取得する
	 *
	 * @return CakeResponse
	 */
	public function index()
	{
		$conditions = ['Group.deleted' => null];

		if($this->isStaff())
		{
			// スタッフは全件参照可能
			$title = $this->queryParam('title');
			if($title !== null)
				$conditions['Group.title LIKE'] = '%' . $title . '%';

			$status = $this->queryParam('status');
			if($status !== null)
				$conditions['Group.status'] = (int)$status;
		}
		else
		{
			// 一般ユーザは所属グループのみ
			$groupIds = $this->currentUserGroupIds($this->currentUserId());

			if(empty($groupIds))
				return $this->okList([], ['page' => 1, 'limit' => 0, 'total' => 0, 'count' => 0]);

			$conditions['Group.id IN'] = $groupIds;

			$title = $this->queryParam('title');
			if($title !== null)
				$conditions['Group.title LIKE'] = '%' . $title . '%';

			$status = $this->queryParam('status');
			if($status !== null)
				$conditions['Group.status'] = (int)$status;
		}

		$fields = [
			'Group.id', 'Group.title', 'Group.comment',
			'Group.status', 'Group.logo', 'Group.copyright',
			'Group.module', 'Group.created', 'Group.modified',
		];

		$order = ['Group.id' => 'asc'];

		list($rows, $meta) = $this->paginatedList($this->Group, [
			'conditions' => $conditions,
			'fields' => $fields,
			'order' => $order,
		]);

		return $this->okList($rows, $meta);
	}

	/**
	 * グループ詳細を取得する
	 *
	 * @param int $id グループID
	 * @return CakeResponse
	 */
	public function view($id)
	{
		$id = (int)$id;

		// 存在チェック
		if(!$this->Group->exists($id))
			$this->fail(404, 'Group not found');

		// 一般ユーザは所属グループのみ参照可能
		if(!$this->isStaff())
		{
			$groupIds = $this->currentUserGroupIds($this->currentUserId());

			if(!in_array($id, $groupIds, true))
				$this->fail(404, 'Group not found');
		}

		$group = $this->Group->findById($id);

		return $this->ok($group['Group']);
	}
}
