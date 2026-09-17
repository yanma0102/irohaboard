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
 * ユーザの一覧・詳細・追加・更新・削除・コース割当を提供する
 */
class ApiUsersController extends ApiBaseController
{
	/**
	 * 使用モデル
	 * @var array
	 */
	public $uses = ['User', 'UserToken', 'UsersCourse'];

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
			{
				if($this->wantsExact())
					$conditions['User.username'] = $username;
				else
					$conditions['User.username LIKE'] = '%' . $username . '%';
			}

			$name = $this->queryParam('name');
			if($name !== null)
			{
				if($this->wantsExact())
					$conditions['User.name'] = $name;
				else
					$conditions['User.name LIKE'] = '%' . $name . '%';
			}

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
			$saved['User']['id'] = (int)$saved['User']['id'];
			unset($saved['User']['password']);
			return $this->ok($saved['User'], 201);
		}

		$this->fail(400, 'Validation failed', $this->User->validationErrors);
	}

	/**
	 * ユーザを更新する
	 *
	 * @param int $id ユーザID
	 * @return CakeResponse
	 */
	public function edit($id)
	{
		$this->requireManager();
		$id = (int)$id;

		if(!$this->User->exists($id))
			$this->fail(404, 'User not found');

		$target = $this->User->findById($id);
		$isAdmin = ($this->currentRole() === 'admin');

		// 管理者以外は管理者アカウントを変更不可
		if($target['User']['role'] === 'admin' && !$isAdmin)
			$this->fail(403, 'Only administrators can modify an administrator account');

		$input = $this->input();
		$allowed = ['role', 'name', 'email', 'comment', 'started', 'ended'];
		$fields = [];

		foreach($allowed as $field)
		{
			if(array_key_exists($field, $input))
				$fields[$field] = $input[$field];
		}

		if(empty($fields))
			$this->fail(400, 'No updatable fields were provided');

		if(array_key_exists('role', $fields))
		{
			// 自分のロール変更は禁止（ロックアウト防止）
			if($id === $this->currentUserId())
				$this->fail(403, 'Cannot change your own role');

			// admin への昇格は admin のみ
			if($fields['role'] === 'admin' && !$isAdmin)
				$this->fail(403, 'Only administrators can grant the admin role');
		}

		$fields['id'] = $id;

		$saved = $this->User->save(['User' => $fields]);

		if(!$saved)
			$this->fail(400, 'Validation failed', $this->User->validationErrors);

		$saved['User']['id'] = (int)$saved['User']['id'];
		unset($saved['User']['password']);

		return $this->ok($saved['User']);
	}

	/**
	 * パスワードを変更する
	 *
	 * @param int $id ユーザID
	 * @return CakeResponse
	 */
	public function changePassword($id)
	{
		$this->requireManager();
		$id = (int)$id;

		if(!$this->User->exists($id))
			$this->fail(404, 'User not found');

		$target = $this->User->findById($id);

		if($target['User']['role'] === 'admin' && $this->currentRole() !== 'admin')
			$this->fail(403, 'Only administrators can modify an administrator account');

		$input = $this->input();
		$password = null;

		// ポータル互換のため new_password も受け付ける
		if(isset($input['password']) && $input['password'] !== '')
			$password = (string)$input['password'];
		elseif(isset($input['new_password']) && $input['new_password'] !== '')
			$password = (string)$input['new_password'];

		if($password === null)
			$this->fail(400, 'password is required');

		$saved = $this->User->save(['User' => ['id' => $id, 'password' => $password]]);

		if(!$saved)
			$this->fail(400, 'Validation failed', $this->User->validationErrors);

		// 変更対象ユーザのトークンを失効（Remember Me / API）
		$this->UserToken->revokeAllForUser($id);
		$this->UserToken->revokeAllApiForUser($id);

		return $this->ok(['id' => $id, 'password_changed' => true]);
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

	/**
	 * ユーザに割当済みのコース一覧を取得する
	 *
	 * @param int $id ユーザID
	 * @return CakeResponse
	 */
	public function courses($id)
	{
		$id = (int)$id;

		// スタッフは誰でも参照可能、一般ユーザは自分のみ
		if(!$this->isStaff() && $id !== $this->currentUserId())
			$this->fail(403, 'Forbidden');

		// ユーザ存在チェック
		if(!$this->User->exists($id))
			$this->fail(404, 'User not found');

		$Course = ClassRegistry::init('Course');

		$sql = 'SELECT Course.id, Course.title, Course.introduction, Course.opened,'
			. ' Course.sort_no, Course.user_id, Course.created, Course.modified'
			. ' FROM ib_courses Course'
			. ' INNER JOIN ib_users_courses uc ON uc.course_id = Course.id'
			. ' WHERE uc.user_id = :user_id'
			. ' AND Course.deleted IS NULL'
			. ' ORDER BY Course.sort_no asc';

		$result = $Course->query($sql, ['user_id' => $id]);

		$courses = [];

		if(is_array($result))
		{
			foreach($result as $row)
			{
				// query() の結果は $row['Course'] または $row[0] の可能性がある
				$course = isset($row['Course']) ? $row['Course'] : (isset($row[0]) ? $row[0] : $row);

				$courses[] = [
					'id' => isset($course['id']) ? (int)$course['id'] : 0,
					'title' => isset($course['title']) ? $course['title'] : '',
					'introduction' => isset($course['introduction']) ? $course['introduction'] : null,
					'opened' => isset($course['opened']) ? $course['opened'] : null,
					'sort_no' => isset($course['sort_no']) ? (int)$course['sort_no'] : 0,
					'user_id' => isset($course['user_id']) ? (int)$course['user_id'] : 0,
					'created' => isset($course['created']) ? $course['created'] : null,
					'modified' => isset($course['modified']) ? $course['modified'] : null,
				];
			}
		}

		return $this->okList($courses, ['count' => count($courses)]);
	}

	/**
	 * ユーザにコースを割り当てる
	 *
	 * @param int $id ユーザID
	 * @return CakeResponse
	 */
	public function assignCourse($id)
	{
		$this->requireManager();
		$id = (int)$id;

		// ユーザ存在チェック
		if(!$this->User->exists($id))
			$this->fail(404, 'User not found');

		$input = $this->input();

		if(!isset($input['course_id']) || $input['course_id'] === '' || $input['course_id'] === null)
			$this->fail(400, 'course_id is required');

		$courseId = (int)$input['course_id'];

		// コース存在チェック
		$Course = ClassRegistry::init('Course');

		if(!$Course->exists($courseId))
			$this->fail(404, 'Course not found');

		// 既に割当済みかチェック
		$existing = $this->UsersCourse->find('first', [
			'conditions' => [
				'UsersCourse.user_id' => $id,
				'UsersCourse.course_id' => $courseId,
			],
			'recursive' => -1,
		]);

		if($existing)
			return $this->ok(['user_id' => $id, 'course_id' => $courseId, 'assigned' => true, 'created' => false]);

		$this->UsersCourse->create();
		$saved = $this->UsersCourse->save([
			'UsersCourse' => [
				'user_id' => $id,
				'course_id' => $courseId,
			],
		]);

		if(!$saved)
			$this->fail(500, 'Failed to assign course');

		return $this->ok(['user_id' => $id, 'course_id' => $courseId, 'assigned' => true, 'created' => true], 201);
	}

	/**
	 * ユーザのコース割当を解除する
	 *
	 * @param int $id ユーザID
	 * @param int $course_id コースID
	 * @return CakeResponse
	 */
	public function unassignCourse($id, $course_id)
	{
		$this->requireManager();
		$id = (int)$id;
		$course_id = (int)$course_id;

		// ユーザ存在チェック
		if(!$this->User->exists($id))
			$this->fail(404, 'User not found');

		$deleted = false;

		$existing = $this->UsersCourse->find('first', [
			'conditions' => [
				'UsersCourse.user_id' => $id,
				'UsersCourse.course_id' => $course_id,
			],
			'recursive' => -1,
		]);

		if($existing)
			$deleted = (bool)$this->UsersCourse->delete($existing['UsersCourse']['id']);

		return $this->ok(['user_id' => $id, 'course_id' => $course_id, 'deleted' => $deleted]);
	}
}
