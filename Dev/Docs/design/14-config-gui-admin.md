# 設計書: 設定項目のGUI編集機能（Config GUI Admin）

- **バージョン**: 1.0（2026-09-27）
- **状態**: 承認済み要件に基づく実装設計
- **関連**: `config/ib_config.php`（現行ファイル設定）、LDAP連携機能（tag: `ldap-integration-1.0`）

---

## 1. 概要・背景

`config/ib_config.php` にハードコードされている設定（LDAP認証設定など24項目）は、
変更のたびにファイル編集と再デプロイが必要だった。本機能は管理画面（GUI）から
これらの項目を閲覧・編集可能にし、**保存後次リクエストから即時に反映**する。

### 確定した要件（要件整理フェーズでユーザー承認済み）

| 論点 | 決定 |
|---|---|
| 対象範囲 | スカラー値 **24項目全部**（配列・HTML・ラベル定義は対象外） |
| 永続化方式 | **DB保存 + bootstrap で `Configure::write` 上書き**。`ib_config.php` は Git 管理のデフォルト値 |
| LDAP接続テスト | **必要**（保存前でも実行可能なbind疎通テスト） |

---

## 2. 対象設定項目一覧（24項目・6カテゴリ）

型: `bool` / `int` / `string` / `secret`（マスク表示・空欄で変更なし）/ `size`（バイト保存・MB表示）

### 2.1 LDAP設定（category: `ldap`）— 9項目

| キー | 型 | ラベル | バリデーション |
|---|---|---|---|
| `ldap_enabled` | bool | LDAP連携の有効化 | — |
| `ldap_host` | string | LDAPサーバーホスト名 | `ldap_enabled=1` のとき必須 |
| `ldap_port` | int | LDAPポート番号 | 1〜65535 |
| `ldap_base_dn` | string | ベースDN | `ldap_enabled=1` のとき必須 |
| `ldap_bind_dn` | string | バインドDN | `ldap_enabled=1` のとき必須 |
| `ldap_bind_password` | secret | バインドパスワード | 空欄=変更なし |
| `ldap_uid_attribute` | string | ユーザID属性 | — |
| `ldap_user_dn_pattern` | string | ユーザDNパターン（`%s` 使用可） | — |
| `ldap_tls` | bool | StartTLSを使用 | — |

※ カテゴリ内に**接続テストボタン**を配置（§10）。

### 2.2 セキュリティ（category: `security`）— 5項目

| キー | 型 | ラベル | バリデーション |
|---|---|---|---|
| `remember_token_expired_days` | int | ログイン状態保持の有効日数 | 1〜365 |
| `deny_install_update_access` | bool | インストーラー・アップデータへのアクセス拒否 | — |
| `demo_mode` | bool | デモモード | — |
| `demo_login_id` | string | デモユーザのログインID | — |
| `demo_password` | secret | デモユーザのパスワード | 空欄=変更なし |

### 2.3 アップロード（category: `upload`）— 3項目

| キー | 型 | ラベル | バリデーション |
|---|---|---|---|
| `upload_maxsize` | size | アップロード上限（全体） | 1〜1024 MB |
| `upload_image_maxsize` | size | 画像アップロード上限 | 1〜1024 MB |
| `upload_movie_maxsize` | size | 動画アップロード上限 | 1〜1024 MB |

※ DB/`Configure` には**バイト値**で保存。GUI のみ MB 表示・MB 入力（×1024×1024 換算）。

### 2.4 UI表示（category: `ui`）— 4項目

| キー | 型 | ラベル |
|---|---|---|
| `show_admin_link` | bool | ログイン画面に管理リンクを表示 |
| `open_link_same_window` | bool | リンクを同一ウィンドウで開く |
| `close_on_select` | bool | select2選択時に自動クローズ |
| `use_upload_image` | bool | リッチエディタの画像アップロードを有効 |

### 2.5 API（category: `api`）— 1項目

| キー | 型 | ラベル | バリデーション |
|---|---|---|---|
| `api_rate_limit_per_minute` | int | APIレートリミット（回/分） | 1〜100000 |

### 2.6 取込（category: `import`）— 2項目

| キー | 型 | ラベル | バリデーション |
|---|---|---|---|
| `import_group_count` | int | グループ取込の1回上限 | 1〜10000 |
| `import_course_count` | int | コース取込の1回上限 | 1〜10000 |

### 2.7 対象外項目と理由

| キー群 | 理由 |
|---|---|
| `legacy_security_salt` | 旧SHA1パスワード検証に使用。変更すると旧パスワードのログインが全滅し復旧困難なため**ファイル編集のみで管理**（非対象を明示） |
| `mcp_cors_allowed_origins` ほか配列値 | 型が複雑（Phase 2検討） |
| `content_kind` / `form_*` / `theme_colors` / `user_role` 等のラベル・定義マップ（18項目） | コード定義。GUI化の恩恵が小さくリスク大 |

---

## 3. 全体構成

```
config/ib_config.php ──(デフォルト値, Git管理)──┐
                                               ▼
                              Application::bootstrap()
                                Configure::load('ib_config')   … 既存
                                ↓
                                デフォルト値スナップショット（§7）
                                ↓
                              ib_config_overrides (DB) ──上書き──▶ Configure::read(<key>)
                                                                   ▲
管理画面 /admin/configs ──保存──▶ ib_config_overrides (DB) ────────┘（次リクエストから反映）
        │
        └─接続テスト ──POST /admin/configs/test-ldap──▶ LdapAuthService::testConnection()
```

### 構成要素

| 要素 | 役割 |
|---|---|
| `config/ib_config_schema.php` | 24項目の定義（型・ラベル・カテゴリ・バリデーション・secretフラグ）。GUI描画・検証・キャストの**単一定義源** |
| `src/Service/AppConfigService.php` | スキーマ取得、型検証、正規化（キャスト）、保存、デフォルト復元、bootstrap用オーバーライド適用 |
| `src/Model/Table/ConfigOverridesTable.php` | `ib_config_overrides` のCRUD |
| `src/Controller/Admin/ConfigsController.php` | 画面表示・保存・リセット・接続テスト（JSON） |
| `templates/Admin/Configs/index.php` | カテゴリ別パネルのフォーム |
| `src/Application.php` | bootstrap にオーバーライド反映を追加 |

---

## 4. DB設計: `ib_config_overrides`

既存 `ib_settings` と**分離した新規テーブル**とする。理由:
- `ib_settings` は `AppController::beforeFilter()` が全行をセッション `Setting.*` へ格納しており、`ldap_bind_password` 等の機微値・大量キーの流入を避ける
- `SettingsController` の既存画面（4固定項目）に干渉させない

```sql
-- config/schema/app.sql / update.sql / tests/schema.sql に追記（3ファイル同順で追加）
-- + config/Migrations/ に CakePHP migration も追加（InitialSchema と同様の整合運用）
CREATE TABLE IF NOT EXISTS `ib_config_overrides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL COMMENT 'ib_config.phpのキー名',
  `config_value` text NOT NULL COMMENT '正規化規化済み値（型はスキーマ定義に従う）',
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- **保存対象は変更したキーのみ**（デフォルト値と同値でも保存可＝明示的オーバーライド。行が無いキーは `ib_config.php` の値が使われる）
- `config_value` は正規化済み文字列: bool=`'1'`/`'0'`、int/size=10進バイト値、string/secret=原文

### スキーマ適用キーマ適用箇所（4箇所で同期）

| ファイル | 用途 |
|---|---|
| `config/schema/app.sql` | 新規インストール（`InstallController::_executeSQLScript`） |
| `config/schema/update.sql` | 既存環境のアップデート（`UpdateController`、`CREATE TABLE IF NOT EXISTS` で再実行安全） |
| `tests/schema.sql` | PHPUnit テストDB |
| `config/Migrations/`（新規） | migrations ベースの環境（`cakephp/migrations` ^5.0 導入済み） |

---

## 5. 設定定義: `config/ib_config_schema.php`

```php
<?php
declare(strict_types=1);
// 戻り値: キー => 定義
return [
    'ldap_enabled' => [
        'type' => 'bool',                 // bool | int | string | secret | size
        'category' => 'ldap',             // カテゴリID
        'label' => 'LDAP連携の有効化',
        'help' => '有効時にログイン認証をLDAPサーバーへフォールバックします。',
    ],
    'ldap_host' => [
        'type' => 'string',
        'category' => 'ldap',
        'label' => 'LDAPサーバーホスト名',
        'required_if' => ['ldap_enabled', true],   // 条件付き必須
    ],
    'ldap_port' => [
        'type' => 'int', 'category' => 'ldap', 'label' => 'LDAPポート番号',
        'min' => 1, 'max' => 65535,
    ],
    'ldap_bind_password' => [
        'type' => 'secret', 'category' => 'ldap', 'label' => 'バインドパスワード',
    ],
    'upload_maxsize' => [
        'type' => 'size', 'category' => 'upload', 'label' => 'アップロードサイズ上限（全体）',
        'min' => 1, 'max' => 1024,        // MB単位で検証・表示
    ],
    // ... 残り20項目も同形式（§2の表をそのまま機械可読化）
];
```

- カテゴリ定義も同ファイルに `categories` キーで保持（`ldap`/`security`/`upload`/`ui`/`api`/`import`、各ラベル・表示順）。
- **デフォルト値はここに書かない**。`ib_config.php` を単一のデフォルト源とし、bootstrap でスナップショットする（§7）。

---

## 6. 保存・反映の処理フロー

### 6.1 保存（POST `/admin/configs`、`op=save`）

1. 権限確認: `RoleComponent::isAdmin()` が false なら `ForbiddenException`（§11）
2. demo_mode 判定: `demo_mode=true` の環境では、**`demo_mode` を `false` にする変更のみ許可**（他の保存は拒否しFlashで通知）。デモ環境で自分自身を締め出さない
3. カテゴリに属する受信キーを `AppConfigService::validate()` へ
   - 型チェック（int は数値範囲、bool は `1`/`0`、size は MB 範囲）
   - `required_if`（`ldap_enabled=1` 時の host/base_dn/bind_dn）
   - エラー時: 入力値を保持して再表示 + Flash error
4. 正規化: bool→`'1'`/`'0'`、size→バイト（`MB * 1024 * 1024`）、int→10進文字列、string/secret→原文
5. **secret かつ受信値が空文字 → スキップ**（既存オーバーライド/デフォルトを維持）
6. upsert: `UPDATE ... WHERE config_key=?`、影響0行なら `INSERT`
7. PRG: 画面へ redirect + Flash success「設定を保存しました（次リクエストから反映されます）」

### 6.2 デフォルトに戻す（POST、`op=reset`）

- 対象カテゴリの全キーの `ib_config_overrides` 行を `DELETE` → 次リクエストから `ib_config.php` の値が有効化

### 6.3 反映（`Application::bootstrap()`）

```php
// src/Application::bootstrap() 内、既存の Configure::load('ib_config') の直後
Configure::load('ib_config');
$this->_applyConfigOverrides();   // 追加
```

`_applyConfigOverrides()` の処理:

1. `ib_config.php` ロード直後・オーバーライド適用**前**に、スキーマ24キーの現値を
   `Configure::write('ib_config_defaults', [$key => $value, ...])` としてスナップショット
   （GUI の「デフォルト値」表示・復元確認に使用）
2. `ib_config_overrides` を全件 SELECT（最大24行の単一クエリ。既存の `ib_settings`
   セッション読出と同程度のコストで問題なし。必要時 `Cache` 化は将来の最適化）
3. キーごとにスキーマ型でキャストして `Configure::write($key, $typedValue)`
4. **失敗耐性**: DB未接続・テーブル未作成（未インストール環境）等の例外は握り潰し、
   ファイル値のみで起動を継続（`try/catch (\Throwable)`）
5. **実装時確認事項**: `Datasources` 設定が bootstrap 時点で利用可能かを実装時に検証。
   不可の場合の代替: 最早段のミドルウェア（`ApiRateLimitMiddleware` より前）で適用。
   いずれの場合も `Configure::read('api_rate_limit_per_minute')` を読む前に適用が完了する構造とする

---

## 7. 画面設計

- **ルート**: Admin プレフィックスの `fallbacks()` により追加ルート登録不要
  - 一覧・保存: `GET/POST /admin/configs`
  - 接続テスト: `POST /admin/configs/test-ldap`
- **メニュー**: `templates/element/admin_menu.php` の「システム設定」直後、
  `if($loginedUser['role'] == 'admin')` 内に「アプリ設定」を追加（`ConfigsController` で `active` 判定）
- **レイアウト**: 既存 `Admin/Settings/index.php` と同じく `admin_menu` element + `panel panel-default` を
  カテゴリ数だけ縦積み（各パネル = 各カテゴリ）
  - ヘッダ: カテゴリラベル + 「デフォルトに戻す」ボタン（`op=reset`、別フォームまたは button name）
  - 本文: `Configure::read('form_defaults')` を用いた `Form->create(null, ...)` +
    `Form->control()` をスキーマ定義から動的生成
    - bool → checkbox（`hidden` で `0` を先に送信）
    - int → `type=number` + `min`/`max`
    - size → `type=number` + `min`/`max`、単位ラベル「MB」（表示・受信はMB、保存時にバイトへ変換）
    - string → text、`ldap_user_dn_pattern` は text（`%s` の案内 help を併記）
    - secret → `type=password`、`value` は**出力しない**、placeholder に「設定済み（変更する場合のみ入力）」
  - 各フィールドに「既定: <default>」の小文字表示（`ib_config_defaults` から）
  - 送信: `保存`（`op=save`、`$this->Form->submit()` 既存スタイル流用）
- **LDAPパネルのみ**: `接続テスト` ボタン（`type=button` + JS fetch、`op` を送らない）と
  結果表示エリア（`#ldap-test-result`、成功=緑/失敗=赤）
- **POST時の再表示**: バリデーションエラー時は受信値をフォームへ再出力（`value` にリクエスト値を優先）

### JavaScript（接続テスト）

- フォーム内の `_csrfToken` を読み、`X-CSRF-Token` ヘッダとして fetch に付与
- ボディに LDAP フィールド9項目（フォームの name 群を `FormData` から採取、チェックボックスは hidden+checkbox の両値を解決）を JSON で送信
- 返却 `{success: bool, message: string}` を結果エリアに表示。**保存は行わない**

---

## 8. コントローラ設計: `Admin/ConfigsController`

```php
class ConfigsController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->FormProtection->unlockActions(['testLdap']); // AJAXはToken fields検証を免除
    }

    public function beforeFilter(EventInterface $event): void  // 追加
    {
        parent::beforeFilter($event);
        if (!$this->Role->isAdmin()) {
            throw new ForbiddenException();   // adminロール限定（menu表示条件と一致）
        }
    }

    public function index(): ?Response    // GET表示 / POST save / POST reset（op分岐）
    public function testLdap(): ?Response // JSON: {success, message}
}
```

- 既存 `SettingsController` 同様 `demo_mode` 保存ガードは action 内（§6.1-2 のルールで拡張）
- `testLdap` は FormProtection 解除 + CSRF はヘッダで検証（`CsrfProtectionMiddleware` は `X-CSRF-Token` 対応）
- レスポンス: `->withType('json')->withStringBody(json_encode(...))`（`Admin/ContentsController.php:368-372` と同型）

---

## 9. 検証・正規化ルール（`AppConfigService`）

| 型 | 受信 | 正規化（DB保存） | Configure へ書き込む値 |
|---|---|---|---|
| bool | `'1'`/`'0'` | `'1'`/`'0'` | `true`/`false` |
| int | 10進文字列 | 10進文字列 | `(int)` |
| size | MB文字列 | バイト（`×1048576`） | `(int)` バイト |
| string | 原文 | 原文（trim） | 文字列 |
| secret | 原文 or 空 | 空なら**保存スキップ** | 文字列 |

- 検証エラーは `['キー' => 'エラーメッセージ']` 形式で返し、コントローラが Flash/フォームに反映
- 不正キー（スキーマ外）は受信時点で無視（スキーマ24キーのみ処理）

---

## 10. LDAP接続テスト設計

### `LdapAuthService::testConnection(array $params): array`

- 引数: フォーム受信値（`ldap_host`, `ldap_port`, `ldap_bind_dn`, `ldap_bind_password`, `ldap_base_dn`, `ldap_tls`）。**未保存値でもテスト可能**（`Configure` を参照しない）
- 戻り値: `['success' => bool, 'message' => string]`（message は日本語）
- 手順:
  1. `extension_loaded('ldap')` 未満 → `success=false`, 「PHPのLDAP拡張が有効ではありません」
  2. host 空 → `success=false` に必須項目エラー
  3. `ldap_connect(host, port)`（`ldap_tls=true` なら `ldap_start_tls`）
  4. `ldap_bind(bind_dn, bind_password)` を実行
  5. 成功時: 可能なら `ldap_read(base_dn)` でベースDN到達性も確認（失敗しても bind 成功なら success=true、警告を message に付記）
  6. 失敗時: `ldap_error` / `ldap_errno` を日本語文脈で整形（認証失敗49=資格情報、接続不能=サーバー/ポート、権限50=bind DN等）
- `testLdap` アクションはこれを呼ぶだけ（実ログイン処理 `_login()` とは分離、設定保存を伴わない）

---

## 11. セキュリティ

| 項対策 | 内容 |
|---|---|
| 権限 | `beforeFilter` で `Role::isAdmin()` 必須（`/admin/*` の staff 判定より強い admin-only）。非対象ロールは 403 |
| CSRF | 通常POSTは `FormProtection`（`unlockActions([])`）+ `CsrfProtectionMiddleware`。テストAJAXは `X-CSRF-Token` ヘッダ |
| 機微値 | `secret` 型は画面に出力しない（placeholder のみ）。保存は上書き時のみ。ログ出力・Flashへの値含め禁止 |
| SQL | ORM（`ConfigOverridesTable`）のみ使用、動的SQL禁止 |
| demo_mode | 上記 §6.1-2 の通り、demo 環境では無効化変更以外の保存を拒否 |
| 対象外の明示 | `legacy_security_salt` は GUI 対象外（§2.7） |

---

## 12. 実装ファイル一覧

### 新規

| ファイル | 内容 |
|---|---|
| `config/ib_config_schema.php` | 24項目 + カテゴリ定義 |
| `src/Service/AppConfigService.php` | 検証・正規化・保存・リセット・bootstrap適用（静的ローダ含む） |
| `src/Model/Table/ConfigOverridesTable.php` | `ib_config_overrides` CRUD |
| `src/Model/Entity/ConfigOverride.php` | エンティティ |
| `src/Controller/Admin/ConfigsController.php` | index / testLdap |
| `templates/Admin/Configs/index.php` | カテゴリ別パネル画面 |
| `config/Migrations/2026xxxx_CreateConfigOverrides.php` | migration |
| `tests/TestCase/Service/AppConfigServiceTest.php` | サービス単体 |
| `tests/TestCase/Controller/Admin/ConfigsControllerTest.php` | 権限・保存・リセット・テスト連携 |

### 変更

| ファイル | 変更 |
|---|---|
| `src/Application.php` | bootstrap に `_applyConfigOverrides()` 追加（§6.3） |
| `templates/element/admin_menu.php` | 「アプリ設定」メニュー追加（admin限定ブロック内） |
| `config/schema/app.sql` | `ib_config_overrides` DDL 追記 |
| `config/schema/update.sql` | 同上（再実行安全） |
| `tests/schema.sql` | 同上 |
| `src/Service/LdapAuthService.php` | `testConnection()` 追加（既存メソッド非変更） |

---

## 13. テスト計画

### 単体（PHPUnit、実行は `--filter` で対象限定。全体実行はタイムアウトのため不可）

1. **AppConfigServiceTest**
   - スキーマ読出（24キー・カテゴリ分類）
   - 型検証（int 範囲外、bool 不正値、`required_if` 発動/不発動）
   - 正規化（MB→バイト、bool→`'1'`/`'0'`）
   - upsert ラウンドトリップ（保存→読出→リセット→デフォルト復帰）
   - bootstrap 適用: オーバーライド行を書き込むと `Configure::read` が型付きで返ること
   - DB例外時に例外が握り潰されること
2. **ConfigsControllerTest**
   - admin → GET 200、フォームに24項目が表示
   - 非admin（`manager1` 等）→ 403
   - POST保存 → レコード生成 + Flash、secret空欄 → 既存値維持
   - POST reset → レコード削除
   - 検証エラー → 200再表示 + エラー
   - demo_mode=1 → 保存拒否（demo_mode解除のみ許可）
3. **LdapAuthService::testConnection**（拡張なし環境では失敗系のみ実行、成功系は skip）
   - 拡張未ロード → `success=false`
   - host空 → 必須エラー
   - 到達不能 host → `success=false` + 理由メッセージ

### E2E（8082・手動/Curl）

1. `/admin/configs` 表示（admin）→ 24項目 + デフォルト値表示
2. `show_admin_link` を ON 保存 → 次リクエストでログイン画面に管理リンク表示（**LDAP不要で反映を確認できる観測点**）
3. 「デフォルトに戻す」→ リンク消える
4. LDAP接続テスト: `ldap` host（8082ネットワークでは不在）→ **失敗メッセージが正しく表示**されることを確認（エラー系のE2E）
5. 既存回帰: `--filter UsersTableTest` 12/12、ログイン（admin/adminpass）302、`logs/error.log` に新規例外なし

---

## 14. リスク・既知の制約

| リスク | 対応 |
|---|---|
| bootstrap 時点の DB 可用性 | try/catch で安全遅延。実装時に Datasources 利用可否を検証し、不可なら早期ミドルウェアへ（§6.3-5） |
| 毎リクエスト1クエリ追加 | 24行以下のPK/UNIQUEテーブルの全件読出。既存 `ib_settings` セッション読出と同規模。計測で問題あれば `Cache` 化（Phase 2） |
| 4箇所のスキーマ同期（SQL×3 + migration） | 同一DDLをコピペ。`CREATE TABLE IF NOT EXISTS` で再実行安全 |
| GUI 保存値とファイル値の二重源 | 「既定:」表示 + リセット機能で可視化。行が無いキーは常にファイル値 |
| `ldap_host` 等の環境依存値 | GUI 化により環境ごとの上書きが可能に（本ドキュメントの利点） |
| 全体PHPUnitはタイムアウト | `--filter` 指定実行のみ（プロジェクト既知事項） |
