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
App::uses('ClassRegistry', 'Utility');

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
	 * ログイン失敗の許容回数（1時間あたり）
	 * @var int
	 */
	const MAX_LOGIN_ATTEMPTS = 10;

	/**
	 * 永続トークンの期限
	 * @var string
	 */
	const PERMANENT_EXPIRED = '9999-12-31 23:59:59';

	/**
	 * API トークンを発行する
	 *
	 * POST /api/v1/auth/token
	 * body: {"username": "...", "password": "...", "permanent": false}
	 *   permanent を true にすると無期限トークンを発行（admin のみ）
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

		// ブルートフォース対策（同一ユーザ名で1時間に規定回数失敗したらブロック）
		if($this->_isRateLimited($username))
			$this->fail(429, 'Too many failed attempts. Please try again later.');

		$user = $this->User->findByUsername($username);

		if(!$user || !empty($user['User']['deleted']))
		{
			$this->_logFailedAttempt($username);
			$this->fail(401, 'Invalid credentials');
		}

		$hash = $user['User']['password'];
		$verified = false;

		// 先頭が $ の場合は bcrypt、それ以外は旧 SHA1 方式
		if(substr($hash, 0, 1) === '$')
			$verified = password_verify($password, $hash);
		else
			$verified = ($hash === Security::hash($password, null, true));

		if(!$verified)
		{
			$this->_logFailedAttempt($username);
			$this->fail(401, 'Invalid credentials');
		}

		// 永続トークンは管理者のみ発行可能
		$permanent = false;
		if(isset($data['permanent']) && !is_bool($data['permanent']))
			$permanent = filter_var($data['permanent'], FILTER_VALIDATE_BOOLEAN);
		elseif(isset($data['permanent']))
			$permanent = (bool)$data['permanent'];

		if($permanent && $user['User']['role'] !== 'admin')
			$this->fail(403, 'Only administrators can issue a permanent token');

		if($permanent)
		{
			$expires = self::PERMANENT_EXPIRED;
			$token = $this->UserToken->issueApiToken($user['User']['id'], null, true);
		}
		else
		{
			$days = (int)Configure::read('api_token_expired_days');
			if($days <= 0)
				$days = 30;

			$expires = date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
			$token = $this->UserToken->issueApiToken($user['User']['id'], $days);
		}

		if(!$token)
			$this->fail(500, 'Failed to issue API token');

		return $this->ok([
			'token' => $token,
			'token_type' => 'Bearer',
			'permanent' => (bool)$permanent,
			'expires' => $expires,
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

	/**
	 * ログイン試行がブロック対象か判定する
	 *
	 * @param string $username ログインID
	 * @return bool
	 */
	private function _isRateLimited($username)
	{
		if($username === '')
			return false;

		$threshold = date('Y-m-d H:i:s', strtotime('-1 hour'));

		$Log = ClassRegistry::init('Log');
		$count = $Log->find('count', [
			'conditions' => [
				'Log.log_type' => 'api_login_error',
				'Log.log_content' => $username,
				'Log.created >=' => $threshold,
			]
		]);

		return ($count >= self::MAX_LOGIN_ATTEMPTS);
	}

	/**
	 * ログイン失敗を記録する
	 *
	 * @param string $username ログインID
	 * @return void
	 */
	private function _logFailedAttempt($username)
	{
		if(!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
		{
			$ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			$ip = trim($ips[0]);
		}
		else
		{
			$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
		}

		$Log = ClassRegistry::init('Log');
		$Log->create();
		$Log->save([
			'log_type' => 'api_login_error',
			'log_content' => $username,
			'user_id' => null,
			'user_ip' => $ip,
			'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
			'created' => date('Y-m-d H:i:s'),
		]);
	}
}
