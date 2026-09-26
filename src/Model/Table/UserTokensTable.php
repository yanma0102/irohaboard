<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Model\Table;

use Cake\Core\Configure;
use Cake\Datasource\FactoryLocator;
use Exception;

/**
 * UserTokens Model
 *
 * Remember Me 用トークン（selector:validator）と API Bearer トークンの管理
 *
 * @method \App\Model\Entity\UserToken newEmptyEntity()
 * @method \App\Model\Entity\UserToken newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\UserToken[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\UserToken get($primaryKey, $options = [])
 * @method \App\Model\Entity\UserToken findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\UserToken patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\UserToken[] patchEntities($entities, array $data, array $options = [])
 * @method \App\Model\Entity\UserToken|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\UserToken saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\UserToken[]|\Cake\Datasource\ResultSetInterface|false saveMany($entities, $options = [])
 * @method \App\Model\Entity\UserToken[]|\Cake\Datasource\ResultSetInterface saveManyOrFail($entities, $options = [])
 */
class UserTokensTable extends AppTable
{
    /**
     * ib_user_tokens が利用可能か（リクエスト内キャッシュ）
     *
     * @var bool|null
     */
    protected ?bool $_tableReady = null;

    /**
     * Initialize method
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ib_user_tokens');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
    }

    /**
     * トークンテーブルが存在するか
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        if ($this->_tableReady !== null) {
            return $this->_tableReady;
        }

        try {
            $this->getSchema();
            $this->_tableReady = true;
        } catch (Exception $e) {
            $this->_tableReady = false;
        }

        return $this->_tableReady;
    }

    /**
     * Cookie 文字列を分解する
     *
     * @param string $cookieValue selector:validator
     * @return array|null {selector, validator}
     */
    public function parseCookie(string $cookieValue): ?array
    {
        if ($cookieValue === '') {
            return null;
        }

        $parts = explode(':', $cookieValue, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$selector, $validator] = $parts;

        if (!preg_match('/^[a-f0-9]{32}$/i', $selector)) {
            return null;
        }

        if (!preg_match('/^[a-f0-9]{64}$/i', $validator)) {
            return null;
        }

        return [
            'selector' => $selector,
            'validator' => $validator,
        ];
    }

    /**
     * Remember Me トークンを発行し、Cookie 用文字列を返す
     *
     * @param int $userId
     * @return string|null selector:validator（保存失敗・テーブル未作成時は null）
     */
    public function issueRememberToken(int $userId): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        if ($userId <= 0) {
            return null;
        }

        $days = (int)Configure::read('remember_token_expired_days');
        if ($days <= 0) {
            $days = 14;
        }

        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));

        $entity = $this->newEntity([
            'user_id' => $userId,
            'token_type' => 'remember',
            'token_selector' => $selector,
            'token_hash' => password_hash($validator, PASSWORD_DEFAULT),
            'expired' => date('Y-m-d H:i:s', strtotime('+' . $days . ' days')),
            'last_used' => date('Y-m-d H:i:s'),
            'revoked' => null,
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);

        try {
            if (!$this->save($entity)) {
                return null;
            }
        } catch (Exception $e) {
            $this->_tableReady = false;

            return null;
        }

        return $selector . ':' . $validator;
    }

    /**
     * Cookie からユーザを認証する
     *
     * @param string $cookieValue
     * @return array|null User 配列（Auth->login 用）。失敗時 null
     */
    public function authenticateRememberCookie(string $cookieValue): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $parsed = $this->parseCookie($cookieValue);
        if ($parsed === null) {
            return null;
        }

        try {
            $token = $this->find()
                ->where([
                    'token_type' => 'remember',
                    'token_selector' => $parsed['selector'],
                    'revoked IS NULL',
                    'expired >=' => date('Y-m-d H:i:s'),
                ])
                ->first();

            if (!$token) {
                return null;
            }

            if (!password_verify($parsed['validator'], $token->token_hash)) {
                // 不正な Cookie の可能性 → トークン無効化
                $token->revoked = date('Y-m-d H:i:s');
                $this->save($token);

                return null;
            }

            $usersTable = FactoryLocator::get('Table')->get('Users');
            $user = $usersTable->find()
                ->where(['id' => $token->user_id, 'deleted IS NULL'])
                ->first();

            if (!$user) {
                return null;
            }

            $token->last_used = date('Y-m-d H:i:s');
            $this->save($token);

            return $user->toArray();
        } catch (Exception $e) {
            $this->_tableReady = false;

            return null;
        }
    }

    /**
     * Cookie に対応するトークンを無効化する
     *
     * @param string $cookieValue
     * @return void
     */
    public function revokeByCookie(string $cookieValue): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $parsed = $this->parseCookie($cookieValue);
        if ($parsed === null) {
            return;
        }

        try {
            $token = $this->find()
                ->where([
                    'token_type' => 'remember',
                    'token_selector' => $parsed['selector'],
                    'revoked IS NULL',
                ])
                ->first();

            if ($token) {
                $token->revoked = date('Y-m-d H:i:s');
                $this->save($token);
            }
        } catch (Exception $e) {
            $this->_tableReady = false;
        }
    }

    /**
     * ユーザの Remember Me トークンをすべて無効化する
     *
     * @param int $userId
     * @return void
     */
    public function revokeAllForUser(int $userId): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        if ($userId <= 0) {
            return;
        }

        try {
            $this->updateQuery()
                ->set(['revoked' => date('Y-m-d H:i:s')])
                ->where([
                    'user_id' => $userId,
                    'token_type' => 'remember',
                    'revoked IS NULL',
                ])
                ->execute();
        } catch (Exception $e) {
            $this->_tableReady = false;
        }
    }

    /**
     * API トークンを発行し、Bearer 用文字列を返す
     *
     * @param int $userId ユーザID
     * @param int|null $days 有効日数（未指定時は Security.api_token_expired_days、既定30日）
     * @param bool $permanent true の場合、無期限トークン（期限 9999-12-31）を発行
     * @return string|null selector:validator（保存失敗・テーブル未作成時は null）
     */
    public function issueApiToken(int $userId, ?int $days = null, bool $permanent = false): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        if ($userId <= 0) {
            return null;
        }

        if ($permanent) {
            $expired = '9999-12-31 23:59:59';
        } else {
            if ($days === null) {
                $days = (int)Configure::read('api_token_expired_days');
                if ($days <= 0) {
                    $days = 30;
                }
            }

            if ($days <= 0) {
                $days = 30;
            }

            $expired = date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
        }

        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));

        $entity = $this->newEntity([
            'user_id' => $userId,
            'token_type' => 'api',
            'token_selector' => $selector,
            'token_hash' => password_hash($validator, PASSWORD_DEFAULT),
            'expired' => $expired,
            'last_used' => date('Y-m-d H:i:s'),
            'revoked' => null,
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);

        try {
            if (!$this->save($entity)) {
                return null;
            }
        } catch (Exception $e) {
            $this->_tableReady = false;

            return null;
        }

        return $selector . ':' . $validator;
    }

    /**
     * Bearer トークン文字列からユーザを認証する
     *
     * @param string $tokenString selector:validator
     * @return array|null ['user' => User配列(password無し), 'token_id' => int]。失敗時 null
     */
    public function authenticateApiToken(string $tokenString): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $parsed = $this->parseCookie($tokenString);
        if ($parsed === null) {
            return null;
        }

        try {
            $token = $this->find()
                ->where([
                    'token_type' => 'api',
                    'token_selector' => $parsed['selector'],
                    'revoked IS NULL',
                    'expired >=' => date('Y-m-d H:i:s'),
                ])
                ->first();

            if (!$token) {
                return null;
            }

            if (!password_verify($parsed['validator'], $token->token_hash)) {
                // 不正なトークンの可能性 → 無効化
                $token->revoked = date('Y-m-d H:i:s');
                $this->save($token);

                return null;
            }

            $usersTable = FactoryLocator::get('Table')->get('Users');
            $user = $usersTable->find()
                ->where(['id' => $token->user_id, 'deleted IS NULL'])
                ->first();

            if (!$user) {
                return null;
            }

            $token->last_used = date('Y-m-d H:i:s');
            $this->save($token);

            $userArray = $user->toArray();
            unset($userArray['password']);

            return [
                'user' => $userArray,
                'token_id' => (int)$token->id,
            ];
        } catch (Exception $e) {
            $this->_tableReady = false;

            return null;
        }
    }

    /**
     * API トークンを照合する（MCP ミドルウェア用・失効副作用なし）
     *
     * authenticateApiToken() と異なり、password_verify 失敗時に
     * トークンを失効せず、last_used も更新しない（毎リクエスト照合）。
     *
     * @param string $tokenString selector:validator
     * @return array{user: array<string, mixed>, token_id: int}|null 失敗時 null
     */
    public function lookupApiToken(string $tokenString): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $parsed = $this->parseCookie($tokenString);
        if ($parsed === null) {
            return null;
        }

        try {
            $token = $this->find()
                ->where([
                    'token_type' => 'api',
                    'token_selector' => $parsed['selector'],
                    'revoked IS NULL',
                    'expired >=' => date('Y-m-d H:i:s'),
                ])
                ->first();

            if (!$token) {
                return null;
            }

            // password_verify で照合。失敗してもトークンを失効しない。
            if (!password_verify($parsed['validator'], $token->token_hash)) {
                return null;
            }

            $usersTable = FactoryLocator::get('Table')->get('Users');
            $user = $usersTable->find()
                ->where(['id' => $token->user_id, 'deleted IS NULL'])
                ->first();

            if (!$user) {
                return null;
            }

            $userArray = $user->toArray();
            unset($userArray['password']);

            return [
                'user' => $userArray,
                'token_id' => (int)$token->id,
            ];
        } catch (Exception $e) {
            $this->_tableReady = false;

            return null;
        }
    }

    /**
     * API トークンを無効化する
     *
     * @param string $tokenString selector:validator
     * @return bool 無効化できた場合 true
     */
    public function revokeApiToken(string $tokenString): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $parsed = $this->parseCookie($tokenString);
        if ($parsed === null) {
            return false;
        }

        try {
            $token = $this->find()
                ->where([
                    'token_type' => 'api',
                    'token_selector' => $parsed['selector'],
                    'revoked IS NULL',
                ])
                ->first();

            if (!$token) {
                return false;
            }

            $token->revoked = date('Y-m-d H:i:s');

            return (bool)$this->save($token);
        } catch (Exception $e) {
            $this->_tableReady = false;

            return false;
        }
    }

    /**
     * ユーザの API トークンをすべて無効化する
     *
     * @param int $userId
     * @return void
     */
    public function revokeAllApiForUser(int $userId): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        if ($userId <= 0) {
            return;
        }

        try {
            $this->updateQuery()
                ->set(['revoked' => date('Y-m-d H:i:s')])
                ->where([
                    'user_id' => $userId,
                    'token_type' => 'api',
                    'revoked IS NULL',
                ])
                ->execute();
        } catch (Exception $e) {
            $this->_tableReady = false;
        }
    }
}
