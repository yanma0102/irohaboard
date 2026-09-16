<?php
/**
 * iroha Board REST API ユーザコントローラ
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

App::uses('ApiBaseController', 'Controller');

/**
 * ApiUsers Controller
 * ユーザの一覧・詳細・追加・削除を提供する
 */
class ApiUsersController extends ApiBaseController
{
	/**
	 * 使用モデル
	 * @var array
	 */
	public $uses = ['User'];

	/**
	 * ユーザ一覧を取得する
	 *
	 * @return CakeResponse
	 */
	public function index()
	{
		$conditions = ['User.deleted' => null];
		$currentUser = $this->currentUserId();

		// スタッフは全件参照可能、一般ユーザは自分のみ
		if($this->isStaff())
		{
			$username = $this->queryParam('username');
			if($username !== null)
				$conditions['User.username LIKE'] = '%' . $username . '%';

			$name = $this->queryParam('name');
			if($name !== null)
				$conditions['User.name LIKE'] = '%' . $name . '%';

			$role = $this->queryParam('role');
			if($role !== null)
				$conditions['User.role'] = $role;
		}
		else
		{
			$conditions['User.id'] = $currentUser;
		}

		$fields = [
			'User.id', 'User.username', 'User.name', 'User.role',
			'User.email', 'User.comment', 'User.last_logined',
			'User.started', 'User.ended', 'User.created', 'User.modified',
		];

		$order = ['User.id' => 'asc'];

		list($rows, $meta) = $this->paginatedList($this->User, [
			'conditions' => $conditions,
			'fields' => $fields,
			'order' => $order,
		]);

		// password を念のため除去
		foreach($rows as &$row)
			unset($row['password']);

		return $this->okList($rows, $meta);
	}

	/**
	 * ユーザ詳細を取得する
	 *
	 * @param int $id ユーザID
	 * @return CakeResponse
	 */
	public function view($id)
	{
		$id = (int)$id;

		// スタッフは誰でも参照可能、一般ユーザは自分のみ
		if(!$this->isStaff() && $id !== $this->currentUserId())
			$this->fail(403, 'Forbidden');

		// 存在チェック
		if(!$this->User->exists($id))
			$this->fail(404, 'User not found');

		$user = $this->User->findById($id);

		unset($user['User']['password']);

		return $this->ok($user['User']);
	}

	/**
	 * ユーザを追加する
	 *
	 * @return CakeResponse
	 */
	public function add()
	{
		$this->requireManager();

		$input = $this->input();
		$fields = [];

		$allowedFields = ['username', 'password', 'name', 'role', 'email', 'comment', 'started', 'ended'];

		foreach($allowedFields as $field)
		{
			if(array_key_exists($field, $input))
				$fields[$field] = $input[$field];
		}

		$this->User->create();
		$saved = $this->User->save(['User' => $fields]);

		if($saved)
		{
			unset($saved['User']['password']);
			return $this->ok($saved['User'], 201);
		}

		$this->fail(400, 'Validation failed', $this->User->validationErrors);
	}

	/**
	 * ユーザを削除する
	 *
	 * @param int $id ユーザID
	 * @return CakeResponse
	 */
	public function delete($id)
	{
		$this->requireManager();

		$id = (int)$id;

		if(!$this->User->exists($id))
			$this->fail(404, 'User not found');

		// 自分自身は削除できない
		if($id === $this->currentUserId())
			$this->fail(400, 'Cannot delete your own account');

		$this->User->delete($id);

		return $this->ok(['id' => $id, 'deleted' => true]);
	}
}
