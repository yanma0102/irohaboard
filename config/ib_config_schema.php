<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * 設定項目スキーマ定義（24項目・6カテゴリ）
 *
 * GUI描画・検証・キャストの単一定義源。
 * デフォルト値は ib_config.php を参照（ここには記述しない）。
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

// カテゴリ定義（表示順）
$categories = [
    'ldap'     => ['label' => 'LDAP設定',       'order' => 1],
    'security' => ['label' => 'セキュリティ',    'order' => 2],
    'upload'   => ['label' => 'アップロード',    'order' => 3],
    'ui'       => ['label' => 'UI表示',         'order' => 4],
    'api'      => ['label' => 'API',            'order' => 5],
    'import'   => ['label' => '取込',           'order' => 6],
];

// 24項目のフィールド定義
$fields = [
    // ─── 2.1 LDAP設定（9項目）───
    'ldap_enabled' => [
        'type'     => 'bool',
        'category' => 'ldap',
        'label'    => 'LDAP連携の有効化',
        'help'     => '有効時にログイン認証をLDAPサーバーへフォールバックします。',
    ],
    'ldap_host' => [
        'type'         => 'string',
        'category'     => 'ldap',
        'label'        => 'LDAPサーバーホスト名',
        'required_if'  => ['ldap_enabled', true],
    ],
    'ldap_port' => [
        'type'     => 'int',
        'category' => 'ldap',
        'label'    => 'LDAPポート番号',
        'min'      => 1,
        'max'      => 65535,
    ],
    'ldap_base_dn' => [
        'type'         => 'string',
        'category'     => 'ldap',
        'label'        => 'ベースDN',
        'required_if'  => ['ldap_enabled', true],
    ],
    'ldap_bind_dn' => [
        'type'         => 'string',
        'category'     => 'ldap',
        'label'        => 'バインドDN',
        'required_if'  => ['ldap_enabled', true],
    ],
    'ldap_bind_password' => [
        'type'     => 'secret',
        'category' => 'ldap',
        'label'    => 'バインドパスワード',
        'help'     => '空欄の場合は既存値を維持します。',
    ],
    'ldap_uid_attribute' => [
        'type'     => 'string',
        'category' => 'ldap',
        'label'    => 'ユーザID属性',
        'help'     => 'LDAPサーバー上のユーザID属性名（例: uid, sAMAccountName）。',
    ],
    'ldap_user_dn_pattern' => [
        'type'     => 'string',
        'category' => 'ldap',
        'label'    => 'ユーザDNパターン',
        'help'     => '%s にユーザIDが置換されます。例: uid=%s,ou=people,dc=example,dc=com',
    ],
    'ldap_tls' => [
        'type'     => 'bool',
        'category' => 'ldap',
        'label'    => 'StartTLSを使用',
    ],

    // ─── 2.2 セキュリティ（5項目）───
    'remember_token_expired_days' => [
        'type'     => 'int',
        'category' => 'security',
        'label'    => 'ログイン状態保持の有効日数',
        'min'      => 1,
        'max'      => 365,
    ],
    'api_token_expired_days' => [
        'type'     => 'int',
        'category' => 'security',
        'label'    => 'APIトークンの有効日数',
        'min'      => 1,
        'max'      => 365,
    ],
    'deny_install_update_access' => [
        'type'     => 'bool',
        'category' => 'security',
        'label'    => 'インストーラー・アップデータへのアクセス拒否',
    ],
    'demo_mode' => [
        'type'     => 'bool',
        'category' => 'security',
        'label'    => 'デモモード',
    ],
    'demo_login_id' => [
        'type'     => 'string',
        'category' => 'security',
        'label'    => 'デモユーザのログインID',
    ],
    'demo_password' => [
        'type'     => 'secret',
        'category' => 'security',
        'label'    => 'デモユーザのパスワード',
        'help'     => '空欄の場合は既存値を維持します。',
    ],

    // ─── 2.3 アップロード（3項目）───
    'upload_maxsize' => [
        'type'     => 'size',
        'category' => 'upload',
        'label'    => 'アップロード上限（全体）',
        'min'      => 1,
        'max'      => 1024,
        'unit'     => 'MB',
    ],
    'upload_image_maxsize' => [
        'type'     => 'size',
        'category' => 'upload',
        'label'    => '画像アップロード上限',
        'min'      => 1,
        'max'      => 1024,
        'unit'     => 'MB',
    ],
    'upload_movie_maxsize' => [
        'type'     => 'size',
        'category' => 'upload',
        'label'    => '動画アップロード上限',
        'min'      => 1,
        'max'      => 1024,
        'unit'     => 'MB',
    ],

    // ─── 2.4 UI表示（4項目）───
    'show_admin_link' => [
        'type'     => 'bool',
        'category' => 'ui',
        'label'    => 'ログイン画面に管理リンクを表示',
    ],
    'open_link_same_window' => [
        'type'     => 'bool',
        'category' => 'ui',
        'label'    => 'リンクを同一ウィンドウで開く',
    ],
    'close_on_select' => [
        'type'     => 'bool',
        'category' => 'ui',
        'label'    => 'select2選択時に自動クローズ',
    ],
    'use_upload_image' => [
        'type'     => 'bool',
        'category' => 'ui',
        'label'    => 'リッチエディタの画像アップロードを有効',
    ],

    // ─── 2.5 API（1項目）───
    'api_rate_limit_per_minute' => [
        'type'     => 'int',
        'category' => 'api',
        'label'    => 'APIレートリミット（回/分）',
        'min'      => 1,
        'max'      => 100000,
    ],

    // ─── 2.6 取込（2項目）───
    'import_group_count' => [
        'type'     => 'int',
        'category' => 'import',
        'label'    => 'グループ取込の1回上限',
        'min'      => 1,
        'max'      => 10000,
    ],
    'import_course_count' => [
        'type'     => 'int',
        'category' => 'import',
        'label'    => 'コース取込の1回上限',
        'min'      => 1,
        'max'      => 10000,
    ],
];

return [
    'categories' => $categories,
    'fields'     => $fields,
];
