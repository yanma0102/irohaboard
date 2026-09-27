<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Service;

use Cake\Core\Configure;

/**
 * LDAP 外部認証サービス
 *
 * ib_config の ldap_* 設定を利用し、LDAP サーバにバインドして認証を行う。
 * ローカルDBにユーザが存在しない場合でも、LDAP 認証に成功したユーザを
 * アプリ側で自動登録（プロビジョニング）するために利用される。
 */
class LdapAuthService
{
    /**
     * LDAP 連携が有効かどうか
     *
     * ldap_enabled が true、ホストが設定済み、かつ PHP の ldap 拡張が有効な場合のみ true。
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool)Configure::read('ldap_enabled')
            && (string)Configure::read('ldap_host') !== ''
            && extension_loaded('ldap');
    }

    /**
     * LDAP 認証を実行する
     *
     * @param string $username ログインID（uid / sAMAccountName）
     * @param string $password 平文パスワード
     * @return array<string, string>|null 成功時はユーザ属性（少なくとも username）、失敗時は null
     */
    public function authenticate(string $username, string $password): ?array
    {
        if (!$this->isEnabled() || $username === '' || $password === '') {
            return null;
        }

        $host = (string)Configure::read('ldap_host');
        $port = (int)Configure::read('ldap_port');
        if ($port <= 0) {
            $port = 389;
        }

        $connection = @ldap_connect($host, $port);
        if ($connection === false) {
            return null;
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);

        if ((bool)Configure::read('ldap_tls')) {
            if (!@ldap_start_tls($connection)) {
                @ldap_unbind($connection);

                return null;
            }
        }

        try {
            $userDn = $this->resolveUserDn($connection, $username);
            if ($userDn === null) {
                return null;
            }

            // ユーザ自身のDNでパスワードバインド（認証）
            if (!@ldap_bind($connection, $userDn, $password)) {
                return null;
            }

            $attributes = $this->readAttributes($connection, $userDn);

            return ['username' => $username] + $attributes;
        } finally {
            @ldap_unbind($connection);
        }
    }

    /**
     * ユーザの DN を解決する
     *
     * ldap_user_dn_pattern が設定されている場合はそれを利用し、
     * 未設定の場合はサービスアカウントで ldap_search により検索する。
     *
     * @param mixed $connection LDAP 接続
     * @param string $username ログインID
     * @return string|null
     */
    protected function resolveUserDn($connection, string $username): ?string
    {
        $pattern = (string)Configure::read('ldap_user_dn_pattern');
        if ($pattern !== '' && str_contains($pattern, '%s')) {
            return sprintf($pattern, ldap_escape($username, '', LDAP_ESCAPE_DN));
        }

        $serviceDn = (string)Configure::read('ldap_bind_dn');
        $servicePassword = (string)Configure::read('ldap_bind_password');
        if ($serviceDn === '' || !@ldap_bind($connection, $serviceDn, $servicePassword)) {
            return null;
        }

        $baseDn = (string)Configure::read('ldap_base_dn');
        $uidAttribute = (string)Configure::read('ldap_uid_attribute');
        if ($baseDn === '' || $uidAttribute === '') {
            return null;
        }

        $filter = sprintf('(%s=%s)', $uidAttribute, ldap_escape($username, '', LDAP_ESCAPE_FILTER));
        $search = @ldap_search($connection, $baseDn, $filter, ['dn']);
        if ($search === false) {
            return null;
        }

        $entries = ldap_get_entries($connection, $search);
        if (empty($entries['count']) || empty($entries[0]['dn'])) {
            return null;
        }

        return (string)$entries[0]['dn'];
    }

    /**
     * ユーザエントリから表示用属性を取得する
     *
     * @param mixed $connection LDAP 接続
     * @param string $userDn ユーザ DN
     * @return array<string, string>
     */
    protected function readAttributes($connection, string $userDn): array
    {
        $attributes = [];

        $search = @ldap_read($connection, $userDn, '(objectClass=*)', ['cn', 'displayName', 'mail']);
        if ($search !== false) {
            $entries = ldap_get_entries($connection, $search);
            if (!empty($entries['count'])) {
                $entry = $entries[0];

                $name = $entry['displayname'][0] ?? $entry['cn'][0] ?? '';
                if ($name !== '') {
                    $attributes['name'] = (string)$name;
                }

                $mail = $entry['mail'][0] ?? '';
                if ($mail !== '') {
                    $attributes['email'] = (string)$mail;
                }
            }
        }

        return $attributes;
    }
}
