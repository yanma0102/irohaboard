<?php
declare(strict_types=1);

/**
 * iroha Board REST API 認証コントローラ
 *
 * POST   /api/v1/auth/token  … ログインID/パスワードから API トークンを発行
 * DELETE /api/v1/auth/token  … 使用中の API トークンを無効化
 *
 * CakePHP 5 版
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Controller\Api;

use Cake\Core\Configure;
use Cake\Http\Response;

/**
 * ApiAuth Controller
 */
class AuthController extends BaseController
{
    /**
     * 認証不要でアクセス可能なアクション名
     *
     * @var array<string>
     */
    protected array $allowUnauthenticated = ['issueToken'];

    /**
     * ログイン失敗の許容回数（1時間あたり）
     *
     * @var int
     */
    public const MAX_LOGIN_ATTEMPTS = 10;

    /**
     * 永続トークンの期限
     *
     * @var string
     */
    public const PERMANENT_EXPIRED = '9999-12-31 23:59:59';

    /**
     * API トークンを発行する
     *
     * POST /api/v1/auth/token
     * body: {"username": "...", "password": "...", "permanent": false}
     *   permanent を true にすると無期限トークンを発行（admin のみ）
     *
     * @return \Cake\Http\Response
     */
    public function issueToken(): Response
    {
        $data = $this->input();

        $username = isset($data['username']) ? (string)$data['username'] : '';
        $password = isset($data['password']) ? (string)$data['password'] : '';

        if ($username === '' || $password === '') {
            $this->fail(400, 'username and password are required');
        }

        // ブルートフォース対策（同一ユーザ名で1時間に規定回数失敗したらブロック）
        if ($this->isRateLimited($username)) {
            $this->fail(429, 'Too many failed attempts. Please try again later.');
        }

        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->find()
            ->where(['username' => $username])
            ->first();

        if (!$user || !empty($user->deleted)) {
            $this->logFailedAttempt($username);
            $this->fail(401, 'Invalid credentials');
        }

        $hash = $user->password;
        $verified = false;

        // 先頭が $ の場合は bcrypt、それ以外は旧 SHA1 方式
        if (substr($hash, 0, 1) === '$') {
            $verified = password_verify($password, $hash);
        } else {
            $legacySalt = (string)Configure::read('legacy_security_salt');
            $verified = ($hash === sha1($legacySalt . $password) || $hash === sha1($password));
        }

        if (!$verified) {
            $this->logFailedAttempt($username);
            $this->fail(401, 'Invalid credentials');
        }

        // 永続トークンは管理者のみ発行可能
        $permanent = false;
        if (isset($data['permanent']) && !is_bool($data['permanent'])) {
            $permanent = filter_var($data['permanent'], FILTER_VALIDATE_BOOLEAN);
        } elseif (isset($data['permanent'])) {
            $permanent = (bool)$data['permanent'];
        }

        if ($permanent && $user->role !== 'admin') {
            $this->fail(403, 'Only administrators can issue a permanent token');
        }

        $userTokensTable = $this->fetchTable('UserTokens');

        if ($permanent) {
            $expires = self::PERMANENT_EXPIRED;
            $token = $userTokensTable->issueApiToken((int)$user->id, null, true);
        } else {
            $days = (int)Configure::read('api_token_expired_days');
            if ($days <= 0) {
                $days = 30;
            }

            $expires = date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
            $token = $userTokensTable->issueApiToken((int)$user->id, $days);
        }

        if (!$token) {
            $this->fail(500, 'Failed to issue API token');
        }

        return $this->ok([
            'token' => $token,
            'token_type' => 'Bearer',
            'permanent' => (bool)$permanent,
            'expires' => $expires,
            'user' => [
                'id' => (int)$user->id,
                'username' => $user->username,
                'name' => $user->name,
                'role' => $user->role,
            ],
        ], 201);
    }

    /**
     * 使用中の API トークンを無効化する
     *
     * DELETE /api/v1/auth/token
     *
     * @return \Cake\Http\Response
     */
    public function revokeToken(): Response
    {
        if (!empty($this->apiToken)) {
            $userTokensTable = $this->fetchTable('UserTokens');
            $userTokensTable->revokeApiToken($this->apiToken);
        }

        return $this->ok(['revoked' => true]);
    }

    /**
     * ログイン試行がブロック対象か判定する
     *
     * 直近1時間以内に同一ユーザ名＋同一IPで規定回数以上失敗していたらブロックする。
     * IPを鍵に含めることで、攻撃者が他人のユーザ名でロックアウトする DoS を防止する。
     *
     * @param string $username ログインID
     * @return bool
     */
    private function isRateLimited(string $username): bool
    {
        if ($username === '') {
            return false;
        }

        $ip = $this->_getApiClientIp();

        $threshold = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $logsTable = $this->fetchTable('Logs');
        $count = $logsTable->find()
            ->where([
                'log_type' => 'api_login_error',
                'log_content' => $username,
                'user_ip' => $ip,
                'created >=' => $threshold,
            ])
            ->count();

        return $count >= self::MAX_LOGIN_ATTEMPTS;
    }

    /**
     * ログイン失敗を記録する
     *
     * @param string $username ログインID
     * @return void
     */
    private function logFailedAttempt(string $username): void
    {
        $ip = $this->_getApiClientIp();

        $logsTable = $this->fetchTable('Logs');
        $log = $logsTable->newEntity([
            'log_type' => 'api_login_error',
            'log_content' => $username,
            'user_id' => null,
            'user_ip' => $ip,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
        $logsTable->save($log);
    }

    /**
     * APIクライアントIPアドレスを取得する
     *
     * X-Forwarded-For ヘッダーが存在する場合は先頭のIPを使用し、
     * それ以外は REMOTE_ADDR を返す
     *
     * @return string IPアドレス文字列
     */
    private function _getApiClientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);

            return trim($ips[0]);
        }

        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
