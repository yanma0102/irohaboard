<?php
/**
 * iroha Board REST API 認証コントローラ
 *
 * POST   /api/v1/auth/token  … ログインID/パスワードから API トークンを発行
 * DELETE /api/v1/auth/token  … 使用中の API トークンを無効化
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

App::uses('ApiBaseController', 'Controller');
App::uses('Security', 'Utility');

class ApiAuthController extends ApiBaseController
{
	/**
	 * @var array
	 */
	public $uses = ['User', 'UserToken'];

	/**
	 * トークン発行は認証不要
	 * @var array
	 */
	protected $allowUnauthenticated = ['issueToken'];

	/**
	 * API トークンを発行する
	 *
	 * POST /api/v1/auth/token
	 * body: {"username": "...", "password": "..."}
	 *
	 * @return CakeResponse
	 */
	public function issueToken()
	{
		$data = $this->input();

		$username = isset($data['username']) ? (string)$data['username'] : '';
		$password = isset($data['password']) ? (string)$data['password'] : '';

		if($username === '' || $password === '')
			$this->fail(400, 'username and password are required');

		$user = $this->User->findByUsername($username);

		if(!$user || !empty($user['User']['deleted']))
			$this->fail(401, 'Invalid credentials');

		$hash = $user['User']['password'];
		$verified = false;

		// 先頭が $ の場合は bcrypt、それ以外は旧 SHA1 方式
		if(substr($hash, 0, 1) === '$')
			$verified = password_verify($password, $hash);
		else
			$verified = ($hash === Security::hash($password, null, true));

		if(!$verified)
			$this->fail(401, 'Invalid credentials');

		$days = (int)Configure::read('api_token_expired_days');
		if($days <= 0)
			$days = 30;

		$token = $this->UserToken->issueApiToken($user['User']['id'], $days);

		if(!$token)
			$this->fail(500, 'Failed to issue API token');

		return $this->ok([
			'token' => $token,
			'token_type' => 'Bearer',
			'expires' => date('Y-m-d H:i:s', strtotime('+' . $days . ' days')),
			'user' => [
				'id' => (int)$user['User']['id'],
				'username' => $user['User']['username'],
				'name' => $user['User']['name'],
				'role' => $user['User']['role'],
			],
		], 201);
	}

	/**
	 * 使用中の API トークンを無効化する
	 *
	 * DELETE /api/v1/auth/token
	 *
	 * @return CakeResponse
	 */
	public function revokeToken()
	{
		if(!empty($this->apiToken))
			$this->UserToken->revokeApiToken($this->apiToken);

		return $this->ok(['revoked' => true]);
	}
}
