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
 * グループの一覧・詳細・ユーザ割当を提供する
 */
class ApiGroupsController extends ApiBaseController
{
	/**
	 * 使用モデル
	 * @var array
	 */
	public $uses = ['Group', 'UsersGroup'];

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
			{
				if($this->wantsExact())
					$conditions['Group.title'] = $title;
				else
					$conditions['Group.title LIKE'] = '%' . $title . '%';
			}

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
			{
				if($this->wantsExact())
					$conditions['Group.title'] = $title;
				else
					$conditions['Group.title LIKE'] = '%' . $title . '%';
			}

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

	/**
	 * グループに所属するユーザ一覧を取得する
	 *
	 * @param int $id グループID
	 * @return CakeResponse
	 */
	public function users($id)
	{
		$id = (int)$id;

		// グループ存在チェック
		if(!$this->Group->exists($id))
			$this->fail(404, 'Group not found');

		// 一般ユーザは所属グループのみ参照可能
		if(!$this->isStaff())
		{
			$groupIds = $this->currentUserGroupIds($this->currentUserId());

			if(!in_array($id, $groupIds, true))
				$this->fail(404, 'Group not found');
		}

		$User = ClassRegistry::init('User');

		$sql = 'SELECT User.id, User.username, User.name, User.role, User.email'
			. ' FROM ib_users User'
			. ' INNER JOIN ib_users_groups ug ON ug.user_id = User.id'
			. ' WHERE ug.group_id = :group_id'
			. ' AND User.deleted IS NULL'
			. ' ORDER BY User.id asc';

		$result = $User->query($sql, ['group_id' => $id]);

		$users = [];

		if(is_array($result))
		{
			foreach($result as $row)
			{
				$user = isset($row['User']) ? $row['User'] : (isset($row[0]) ? $row[0] : $row);

				$users[] = [
					'id' => isset($user['id']) ? (int)$user['id'] : 0,
					'username' => isset($user['username']) ? $user['username'] : '',
					'name' => isset($user['name']) ? $user['name'] : '',
					'role' => isset($user['role']) ? $user['role'] : '',
					'email' => isset($user['email']) ? $user['email'] : '',
				];
			}
		}

		return $this->okList($users, ['count' => count($users)]);
	}

	/**
	 * グループにユーザを割り当てる
	 *
	 * @param int $id グループID
	 * @return CakeResponse
	 */
	public function assignUser($id)
	{
		$this->requireManager();
		$id = (int)$id;

		// グループ存在チェック
		if(!$this->Group->exists($id))
			$this->fail(404, 'Group not found');

		$input = $this->input();

		if(!isset($input['user_id']) || $input['user_id'] === '' || $input['user_id'] === null)
			$this->fail(400, 'user_id is required');

		$userId = (int)$input['user_id'];

		// ユーザ存在チェック
		$User = ClassRegistry::init('User');

		if(!$User->exists($userId))
			$this->fail(404, 'User not found');

		// 既に所属しているかチェック
		$existing = $this->UsersGroup->find('first', [
			'conditions' => [
				'UsersGroup.user_id' => $userId,
				'UsersGroup.group_id' => $id,
			],
			'recursive' => -1,
		]);

		if($existing)
			return $this->ok(['group_id' => $id, 'user_id' => $userId, 'assigned' => true, 'created' => false]);

		$this->UsersGroup->create();
		$saved = $this->UsersGroup->save([
			'UsersGroup' => [
				'user_id' => $userId,
				'group_id' => $id,
			],
		]);

		if(!$saved)
			$this->fail(500, 'Failed to assign user');

		return $this->ok(['group_id' => $id, 'user_id' => $userId, 'assigned' => true, 'created' => true], 201);
	}

	/**
	 * グループからユーザを解除する
	 *
	 * @param int $id グループID
	 * @param int $user_id ユーザID
	 * @return CakeResponse
	 */
	public function unassignUser($id, $user_id)
	{
		$this->requireManager();
		$id = (int)$id;
		$user_id = (int)$user_id;

		// グループ存在チェック
		if(!$this->Group->exists($id))
			$this->fail(404, 'Group not found');

		$deleted = false;

		$existing = $this->UsersGroup->find('first', [
			'conditions' => [
				'UsersGroup.user_id' => $user_id,
				'UsersGroup.group_id' => $id,
			],
			'recursive' => -1,
		]);

		if($existing)
			$deleted = (bool)$this->UsersGroup->delete($existing['UsersGroup']['id']);

		return $this->ok(['group_id' => $id, 'user_id' => $user_id, 'deleted' => $deleted]);
	}
}
