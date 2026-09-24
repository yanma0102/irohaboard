# CakePHP 5.x 移行 仕様書（検討版）

| 項目 | 内容 |
|---|---|
| 文書名 | CakePHP 5.x 移行 仕様書 |
| 対象システム | iroha Board（eラーニングシステム） |
| 対象リポジトリ | `/root/project/irohaboard` |
| 現行構成 | CakePHP 2.10.24 / PHP 8.1 / MySQL 5.7 / Apache 2.4（Docker） |
| 移行先 | CakePHP 5.4.x / PHP 8.4 / MariaDB 11.4 LTS / Apache 2.4（Docker） |
| ステータス | 検討（未承認・未実行） |
| 作成日 | 2026-09-16 |
| 前提ブランチ | `dev`（本仕様書は `docs/cakephp5-migration-spec` にて管理） |

---

## 1. 目的と範囲

### 1.1 背景

現行スタックはすべてサポート終了（EOL）している。

| コンポーネント | 現行 | EOL |
|---|---|---|
| PHP | 8.1 | 2025-12-31 |
| MySQL | 5.7 | 2023-10-25 |
| CakePHP | 2.10.24 | 2021-06-15（2.x 系全体） |

セキュリティ修正が提供されない状態が続いており、フレームワークを含めた本格的なモダナイゼーションが必要。

### 1.2 本仕様書の目的

CakePHP 2.10 から CakePHP 5.x への移行について、対象範囲・移行先・書き換えポイント・工数・フェーズ計画・リスクを定義し、実行判断と作業計画の基礎とする。

### 1.3 範囲

- **対象**: アプリケーションコード（Controller / Model / View / Config / Plugin / Vendor）、DB スキーマ、Docker 実行環境
- **対象外**: 新機能開発、UI/UX の再設計（移植を原則とする）、外部システム連携仕様の変更

---

## 2. 現状

### 2.1 アプリケーション規模

| ディレクトリ | ファイル数 | 行数 |
|---|---|---|
| Controller | 22 | 6,070 |
| Model | 16 | 2,075 |
| View（テンプレート＋Helper） | 55 | 3,513 |
| Config | 5 | 1,339 |
| Vendor / Lib（自社） | 3 | 470 |
| Plugin（4プラグイン） | 77 | 13,832 |
| **自社コード合計** | **~97** | **~13,500** |
| **全体（Plugin 含む）** | **174** | **~27,300** |

小規模アプリケーションに分類される。

### 2.2 現行スタックの構成

- **Docker**: `php:8.1-apache` + `mysql:5.7`（`docker/Dockerfile`, `docker/docker-compose.yml`）
- **CakePHP 本体**: Docker ビルド時に `git clone --branch 2.10.24` で取得（`docker/Dockerfile:39`）。リポジトリには未含有、フレームワークへのパッチも無し
- **依存管理**: ルートに `composer.json` / `composer.lock` は無し。Plugin / Vendor は git に直接コミット
- **PHP 8.1 対応**: 自社コードへのパッチのみ（commit `0712164`, `66c0089`, `b69b063`, `f27e9d9`）

### 2.3 同梱プラグイン

| プラグイン | 実バージョン | 状態 |
|---|---|---|
| cakephp/debug_kit | 2.2.6 | EOL（CakePHP 2.x 系） |
| alaxos/acl | 不明 | **実質未使用** |
| slywalker/boost_cake | 不明 | 非アクティブ（2014年頃で更新停止） |
| cakedc/search | 不明 | リポジトリはアーカイブ済 |

---

## 3. 移行先ターゲット

| コンポーネント | 現行 | 移行先 | 根拠 |
|---|---|---|---|
| CakePHP | 2.10.24 | **5.4.x**（最新 5.4.2: 2026-09-05） | 最新安定版 |
| PHP | 8.1 | **8.4** | CakePHP 5.x は PHP 8.2 以上必須。5.1 以降 PHP 8.4 対応 |
| MariaDB | 5.7（MySQL 経由） | **11.4 LTS** | CakePHP 5.x は MariaDB 10.1+ を公式サポート。MySQL と同一の `Mysql` ドライバを使用。GA 2024-05-29、Community EOL 2029-05-29 |
| Apache | 2.4（mod_php） | 2.4（変更なし） | — |
| Debian | Bullseye | Bookworm / Trixie | `php:8.4-apache` ベース |
| Composer | 2 | 2 | 変更なし |

### 3.1 MariaDB 11.4 LTS 採用の根拠

| 項目 | 内容 |
|---|---|
| CakePHP 5 対応 | CakePHP 5.x は MariaDB 10.1+ を公式サポート。MySQL と同一の `Mysql` ドライバを使用し、接続設定・ORM 動作は MySQL と同一 |
| `caching_sha2_password` の問題が無い | MySQL 8 固有の `caching_sha2_password` は MariaDB では使われないため、接続認証エラーのリスクが解消される |
| ライセンス | GPLv2（恒久）＋クライアント LGPL ＋ FLOSS 例外。商用利用に問題なし |
| セキュリティサポート | Community EOL 2029-05-29。長期にわたりセキュリティ修正が提供される |
| コードベース互換性 | アプリコードの修正は不要。MySQL 固有機能（JSON 型/ウィンドウ関数/CTE/パーティション/FULLTEXT）は未使用。予約語 `groups` は ORM がバッククォートするため実害なし |
| 環境変数互換性 | MariaDB Docker イメージでも `MYSQL_*` 環境変数はそのまま動作 |

### 3.2 CakePHP 6.x について

CakePHP 6.0 は開発中（未 GA、PHP 8.4 以上必須）。GA 時期は未定のため、本移行では 5.4 を採用する。

---

## 4. 移行方針

### 4.1 公式制約

CakePHP 2.x から 5.x への**直接移行は公式サポート外**。公式ドキュメントは「最新の 4.x にアップグレードしてから 5.0 へ」という経路を前提としている。

| 移行ステップ | 公式ガイド | 自動化ツール |
|---|---|---|
| 2.x → 3.x | あり | **無し（全手動）** |
| 3.x → 4.x | あり | 一部対応 |
| 4.x → 5.x | あり | 対応（Rector ルール） |

公式 Upgrade Tool（cakephp/upgrade）は「CakePHP 4.x 間、4.x→5.x、5.x 間」の移行を対象とし、**2.x→3.x には対応していない**。

### 4.2 採用方針

**カスタム検索ロジックの方針**: 「新規 CakePHP 5.x アプリを構築し、既存ロジックを手動移植」を採用する。

理由:

1. 2.x→3.x が全手動である以上、段階移行（2→3→4→5）は大規模書き換えを繰り返すだけで効率が悪い
2. コミュニティ（CakePHP フォーラム、開発チーム）も小規模コードベースでは「新規 5.x アプリへ手動移植」を推奨
3. 移行先の骨格を最新化できるため、結果的に保守性が高まる

### 4.3 認証・認可の方針

- 現行の `AuthComponent`（Form 認証のみ、authorize 未設定）→ `cakephp/authentication` に置換
- ACL は**未使用のため削除**
- `AppSecurityComponent` / `Lib/FormToken.php`（自前実装）→ CakePHP 5 組み込みの `FormProtectionMiddleware` に置換

---

## 5. 移行対象の全体像

### 5.1 レイヤー別規模

| レイヤー | ファイル数 | 現行行数 | 難易度 | 移行行数目安 |
|---|---|---|---|---|
| Controller（管理画面） | 10 | 4,196 | 中 | 3,000-3,500 |
| Controller（API） | 8 | 1,652 | 易-中 | 1,000-1,200 |
| Controller（共通: AppController） | 1 | 449 | 中 | 300-400 |
| Model | 16 | 2,075 | 中-難 | 800-1,200 |
| View（.ctp） | 50 | 3,513 | 中 | 500-700 |
| View Helper | 3 | 285 | 中 | 150-250 |
| Config | 5 | 862 | 難 | 500-700 |
| Vendor / Lib（自社） | 3 | 470 | 易 | 20-30 |
| Plugin | 77 | 13,832 | 難（置換） | — |
| Docker / デプロイ | 3 | ~130 | 易-中 | 80-120 |

### 5.2 Controller の分類

**管理画面系（`admin_` プレフィクス）— 10ファイル / 4,196行**

| ファイル | 行数 | 役割 |
|---|---|---|
| UsersController.php | 873 | ユーザ管理、ログイン、パスワード変更、インポート/エクスポート |
| ContentsController.php | 749 | コンテンツ管理、ファイル入出力、プレビュー |
| ContentsQuestionsController.php | 436 | テスト問題管理、採点 |
| EnquetesQuestionsController.php | 337 | アンケート管理、回答集計 |
| RecordsController.php | 372 | 学習履歴、CSV 出力 |
| InstallController.php | 315 | 初期インストール |
| UpdateController.php | 153 | DB スキーマ更新 |
| InfosController.php | 161 | お知らせ管理 |
| CoursesController.php | 124 | コース管理 |
| GroupsController.php | 119 | グループ管理 |
| SettingsController.php | 51 | システム設定 |
| UsersCoursesController.php | 52 | 受講コース一覧 |

**API 系 — 8ファイル / 1,652行**

| ファイル | 行数 | 役割 |
|---|---|---|
| ApiBaseController.php | 478 | API 基底（Bearer 認証、JSON 応答、ページング） |
| ApiUsersController.php | 410 | ユーザ CRUD、コース割当 |
| ApiGroupsController.php | 260 | グループ、ユーザ割当 |
| ApiAuthController.php | 200 | トークン発行/失効 |
| ApiCoursesController.php | 180 | コース |
| ApiContentsController.php | 118 | コンテンツ（読み取り） |
| ApiRecordsController.php | 108 | 学習履歴（読み取り） |
| ApiErrorsController.php | 38 | API 404 フォールバック |

### 5.3 認証・認可・セキュリティの現状

| 項目 | 現状 | 場所 |
|---|---|---|
| Auth 認証 | `Form` 認証のみ（authorize 未設定） | `Controller/AppController.php:24-40` |
| ロール判定 | 手動実装（admin/manager/editor/teacher 判定） | `Controller/AppController.php:113-117` |
| ACL | **未使用**（プラグイン未ロード） | — |
| Security | `AppSecurityComponent`（`SecurityComponent` 継承） | `Controller/Component/AppSecurityComponent.php` |
| フォームトークン | `Lib/FormToken.php`（CakePHP 4 方式の HMAC を自前実装） | `Lib/FormToken.php` |
| DebugKit | 本番でも常時ロード | `Controller/AppController.php:25` |

### 5.4 ACL 未使用の根拠

- `Config/bootstrap.php:93-95` に `CakePlugin::load('Acl')` が無い
- `AppController` の `$components` に Acl が無い
- 全 Controller で `AclComponent` 未使用
- `Config/acl.php`, `Config/Schema/db_acl.sql` は CakePHP 2 初期セットアップ時の残留

→ `Plugin/Acl`、`Config/acl.php`、`Config/Schema/db_acl.sql` は削除可能。

---

## 6. 主要な書き換えポイント

### 6.1 機械的変換パターン

| 変更前（CakePHP 2） | 変更後（CakePHP 5） | 該当数 |
|---|---|---|
| `App::uses('Foo', 'Bar')` | `use Cake\Foo\Foo;` | 46 |
| `App::import()` | `use` 文 ＋ autoload | 4 |
| `$this->request->data['Key']` | `$this->request->getData('Key')` | 55 |
| `$this->data['Key']` | `$this->request->getData('Key')` | 7 |
| `$this->Session->read('key')` | `$this->request->getSession()->read('key')` | 11 |
| `$this->Auth->user()` | `$this->Authentication->getIdentity()` | 20 |
| `$this->Cookie->` | Cookie Middleware / CookieAuthenticator | 9 |
| `$this->request->params['admin']` | `$this->request->getParam('admin')` | 6 |
| `$this->response->type('json')` | `$this->response->withType('json')` | 13 |
| `$this->_stop()` | `return $this->response`（Middleware で送信） | 1 |
| `ClassRegistry::init()` | `$this->fetchTable()` / `TableRegistry::get()` | 3+ |
| `$this->Form->input()` | `$this->Form->control()` | ~100 |
| `String::insert()` | `Text::insert()` | 2 |
| `$this->Session->flash()` | `$this->Flash->render()` | 2 |
| `.ctp` → `.php` | リネーム | 50ファイル |
| `Flash` / `redirect` / `set` / `render` / `Configure::read` | 概ねそのまま | — |

### 6.2 設計やり直しが必要な領域

| 領域 | 内容 |
|---|---|
| **ORM（Model → Table/Entity）** | `AppModel` の `find()` メソッドチェーン（`Model/AppModel.php:51-140`）は CakePHP 5 で不可。全呼び出し元の修正が必要。`$belongsTo`/`$hasMany`/`$validate`/`$actsAs` をメソッド呼び出しへ変更 |
| **Auth / セキュリティ** | `AuthComponent` → `Authentication` プラグイン。ログインフロー全体の再設計。`SecurityComponent` → `FormProtectionMiddleware` |
| **Config / Bootstrap / Routes** | `core.php`(413行) / `bootstrap.php`(148行) / `routes.php`(103行) を `config/app.php` + `Application.php` + Middleware へ全面移行 |
| **Custom ディレクトリ** | `App::build()` によるオーバーライド機構（17パス、`Config/bootstrap.php:55-75`）を autoload + classmap で再実装 |
| **admin プレフィクス** | `Routing.prefixes` → `$routes->prefix('Admin', ...)` スコープ。URL 構造が変化 |
| **コントローラ名** | snake_case（`api_users` 等）→ CamelCase（`ApiUsers`）。URL は kebab-case |
| **raw SQL（16箇所）** | QueryBuilder へ移行、または `->execute()` で残置 |
| **GROUP BY（6箇所）** | MySQL 8 の `ONLY_FULL_GROUP_BY` 対応が必要 |

### 6.3 ORM 移行の詳細

| CakePHP 2 | CakePHP 5 |
|---|---|
| `AppModel` | `Table` / `Entity` |
| `$belongsTo = [...]`（プロパティ） | `$this->belongsTo(...)`（メソッド） |
| `$this->Model->find('all', ['conditions'=>...])` | `$this->Model->find()->where([...])` |
| `contain` | `->contain()` |
| `$virtualFields` | バーチャルプロパティ |
| `Search.Searchable` + `$filterArgs` | 自作検索ロジック（QueryBuilder） |
| `beforeSave`（`Model/User.php:114-129`） | Table のコールバック |

- `Search.Searchable` の利用は **User / Record の2モデルのみ**（`Model/User.php:134-151`, `Model/Record.php:85-110`）。Controller 側も `UsersController` / `RecordsController` の2箇所のみのため、自作検索で置換可能
- raw SQL は 16箇所。特に `Model/UsersCourse.php:61-105`、`Model/Content.php:114-164`、`Model/Info.php:110-130` が複雑

### 6.4 API 層の移行

`ApiBaseController`（478行）は `Controller` を直接継承し、`AppController` を経由しない。手動 Bearer 認証 + `ClassRegistry::init()` でモデル取得。

| 現行 | CakePHP 5 |
|---|---|
| `Controller` 継承 | `Cake\Http\Controller` 継承 |
| 手動 Bearer 認証 | `Authentication` プラグイン + custom Authenticator |
| `ClassRegistry::init()` | `$this->fetchTable()` |
| `$this->_stop()` | `return $this->response` + Middleware |
| `$this->response->type('json')` | `$this->response->withType('json')` |

REST API v1 の入出力仕様（`Docs/API.md`、799行）は維持する。

### 6.5 View 層の移行

| 現行 | CakePHP 5 |
|---|---|
| `.ctp` テンプレート | `.php` テンプレート |
| `BoostCake.BoostCakeHtml` / `AppBoostCakeForm` / `BoostCake.BoostCakePaginator` | `friendsofcake/bootstrap-ui` |
| `FormHelper::input()` | `FormHelper::control()` |
| `$this->Session->flash()` | `$this->Flash->render()` |
| `String::insert()` | `Text::insert()` |
| `$this->Html->scriptStart/End` | `$this->fetch('script')` |

### 6.6 Config / 起動の移行

| 現行 | 移行先 |
|---|---|
| `Config/core.php` | `config/app.php` |
| `Config/bootstrap.php` | `src/Application.php` |
| `Config/routes.php` | `config/routes.php`（scoped routes） |
| `Config/ib_config.php` | `Application::bootstrap()` でロード or `config/app.php` 統合 |
| `index.php`（`require 'webroot/index.php'`） | CakePHP 5 のエントリポイント構成へ |
| `Config/Schema/app.sql` 等 | CakePHP Migrations へ移行（任意） |

---

## 7. プラグイン代替

| 現行 | CakePHP 5 代替 | 最新版 | 対応 PHP | 状態 | 移行方法 |
|---|---|---|---|---|---|
| cakephp/debug_kit 2.2.6 | `cakephp/debug_kit` | 5.2.4（2026-06） | ≥8.1 | 公式・アクティブ | `composer require` |
| slywalker/boost_cake | `friendsofcake/bootstrap-ui` | 5.2.0（2026-07） | ≥8.2 | アクティブ | `composer require` |
| cakedc/search | 自作検索ロジック | — | — | — | QueryBuilder で2モデル×2コントローラを置換 |
| alaxos/acl | 削除 | — | — | — | 未使用のため削除 |

参考（採用候補）:

| パッケージ | 最新版 | 用途 |
|---|---|---|
| `cakephp/authentication` | 4.2.1（2026-07） | 認証 |
| `cakephp/authorization` | 3.5.3（2026-07） | 認可（必要時） |

---

## 8. 工数見積

| レイヤー | 工数 | 内訳 |
|---|---|---|
| Backend Controller（管理画面10 + API8 + 共通1） | 10-15日 | 機械的変換 + Auth/Security 再設計 |
| Data（Model 16 + AppModel + raw SQL） | 5-7日 | ORM 全面書き換え + SQL 修正 |
| View（.ctp 50 + Helper 3） | 5-7日 | リネーム + ヘルパー修正 + BoostCake 置換 |
| Config / Routing / Bootstrap | 3-5日 | 全面書き換え |
| DB 移行（utf8mb4 + MariaDB 11.4） | 2-3日 | GROUP BY 修正 + 文字セット変換 |
| テスト / 検証 | 5-7日 | 全画面回帰 + API + CSRF 動作確認 |
| Docker / デプロイ | 1-2日 | Dockerfile / compose / Apache 設定 |
| **合計** | **32-48人日** | 1名: 6-9週間 / 2名: 4-5週間 |

> 注: 既存テストコードが無い場合、回帰テストの新規作成分を別途見込むこと。

---

## 9. フェーズ計画

### Phase 0: 準備・検証（1-2週間）

- CakePHP 5.x 新規アプリの雛形作成（`composer create-project cakephp/app`）
- 既存コード・DB・アップロードファイルのバックアップとリストア訓練
- `Config/Schema/update.sql:37` の `ib_records.group_id` 不整合の修正
- 手動テストチェックリストの作成（ログイン → コース → コンテンツ → テスト → 管理画面）
- API エンドポイント一覧と期待応答の文書化（`Docs/API.md` 準拠）
- ロールバック手順の文書化

**完了条件**: 影響範囲リスト・手順書・テストチェックリストが揃う

### Phase 1: Config / Bootstrap / Routes 移行（1-2週間）

- `config/app.php` 作成（`core.php` の設定を移行）
- `src/Application.php` 作成（`bootstrap.php` の処理を移行）
- `config/routes.php` 作成（scoped routes 化）
- `ib_config.php` の読み込み機構移行
- Custom ディレクトリのオーバーライド機構を autoload + classmap で再実装

**完了条件**: 設定・ルーティング・プラグインロードが動作

### Phase 2: Model / ORM 移行（2-3週間）

- AppModel 廃止、各モデルを Table / Entity 化
- アソシエーション宣言をメソッド呼び出しへ変更
- `find()` チェーン廃止、QueryBuilder 移行
- raw SQL 16箇所の移行・修正
- バリデーションルールの移行
- Search 検索ロジックの自作化（User / Record）

**完了条件**: 全クエリ・保存・削除・検索が正常動作

### Phase 3: Controller / API 移行（2-3週間）

- AppController の認証移行（Auth → Authentication）
- 管理画面 Controller の `request->data` → `getData()` 等の置換
- API Controller の移行（ClassRegistry → TableRegistry、Response API）
- FormToken / AppSecurityComponent 削除 + FormProtectionMiddleware 導入

**完了条件**: 全 CRUD・認証フロー・API が動作

### Phase 4: View / テンプレート移行（1-2週間）

- `.ctp` → `.php` リネーム
- BoostCake → bootstrap-ui ヘルパーへ変更
- `Form->input()` → `Form->control()` 変更
- Session ヘルパー → Flash 移行
- admin プレフィクス URL 変更への対応

**完了条件**: 全画面の描画・フォーム送信が動作

### Phase 5: DB 移行・テスト（1-2週間）

- MariaDB 11.4 へのデータ移行（utf8mb3 → utf8mb4 変換含む、`mysqldump` による論理移行）
- GROUP BY 6箇所の `ONLY_FULL_GROUP_BY` 対応（MariaDB 10.2+ でも既定有効）
- `caching_sha2_password` 対応は不要（MariaDB では使用しない）
- MariaDB ヘルスチェック検証（`healthcheck.sh --connect --innodb_initialized`）
- 全画面回帰テスト、API 回帰テスト

**完了条件**: テストチェックリスト全項目パス、移行前後でデータ整合

### Phase 6: 安定化・デプロイ（1週間）

- Dockerfile / compose 変更（`php:8.4-apache` + `mariadb:11.4`）
- DebugKit の環境別ロード確認（本番無効）
- セキュリティヘッダー・ログ・監視の確認
- 本番デプロイ

**完了条件**: 本番環境で全機能動作

---

## 10. リスク登録簿

| # | リスク | 影響 | 確率 | 緩和策 |
|---|---|---|---|---|
| R1 | ORM 全面書き換えで集計・サブクエリ結果が変わる | 高 | 中 | 移行前後の SQL 結果を MariaDB 11.4 で照合 |
| R2 | Auth 移行でログインフローが壊れる | 高 | 中 | Phase 1 で認証フローを先行移植・検証 |
| R3 | Custom ディレクトリのオーバーライド機構が動作しない | 中 | 中 | Phase 1 で autoload + classmap を検証 |
| R4 | DB 接続時の認証方式による接続失敗 | 高 | 低 | MariaDB では `caching_sha2_password` を使用しないため、MySQL 8.4 で検討されていた接続失敗リスクは低減。ただし接続設定（`MYSQL_*` 環境変数）の動作確認は Phase 5 で実施 |
| R5 | GROUP BY 修正で集計結果が変わる | 高 | 低 | 修正前後で結果を突合 |
| R6 | REST API v1 の互換性が壊れる | 高 | 低 | `Docs/API.md` 準拠の回帰テスト |
| R7 | admin プレフィクス URL 変更で既存リンクが壊れる | 中 | 高 | 旧 URL からのリダイレクト |
| R8 | FormToken の動作が変わる | 中 | 低 | フォーム送信の全画面テスト |
| R9 | `ib_records.group_id` の不整合が顕在化 | 中 | 高 | Phase 0 でスキーマ修正 |
| R10 | BoostCake → bootstrap-ui の CSS クラス不整合 | 中 | 中 | テンプレート毎のビジュアル確認 |

---

## 11. プロジェクト固有の注意点

1. **Dockerfile がフレームワークを clone する構成**（`docker/Dockerfile:39`）: 移行後は Composer 管理へ変更し、ブランチ名ハードコードを排除する。
2. **composer 未使用 / Plugin の git 直コミット**: 移行後は `composer.json` で依存管理。自社 Vendor は PSR-4 autoload へ。セキュリティパッチ適用の自動化が可能になる。
3. **`update.sql:37` の `ib_records.group_id` 不整合**: `ib_records` に `group_id` カラムが無いのに INDEX を張ろうとする。新規インストールが失敗するため、移行前に app.sql / update.sql を整合させる。
4. **DebugKit が本番でも稼働**（`Controller/AppController.php:25`）: 情報漏洩・性能リスク。環境別ロードに変更する。
5. **REST API v1 互換性**: `ApiBaseController` は Controller 直継承。移行後も入出力形式を維持する必要がある。
6. **FormToken 自前実装**: CakePHP 5 の `FormProtectionMiddleware` で置換し、`SecurityComponent` 依存を全廃する。
7. **AppModel のメソッドチェーン**: `Model/AppModel.php:51-140` の仕組みが CakePHP 5 に無い。全呼び出し元の修正が必要。
8. **README のメンテナンスモード記載**: 本格移行を実行する場合、README の動作環境・方針の更新も必要。

---

## 12. 決定事項（要判断）

| # | 決定事項 | 選択肢 |
|---|---|---|
| 1 | 移行の実行可否 | ① 実行 ② 保留 ③ フォーク方式（PHP 8.4 のみ）へ変更 |
| 2 | 体制 | ① 自社 ② 外部委託 ③ ハイブリッド |
| 3 | 期間・スケジュール | ① 1名 6-9週間 ② 2名 4-5週間 ③ フェーズ単位の段階判断 |
| 4 | 移行中の稼働維持 | ① 新旧並行 ② 一時停止 ③ 段階的移行 |
| 5 | テスト方針 | ① 手動のみ ② PHPUnit 整備 ③ 主要フローのみ自動化 |

---

## 付録 A. 主要な根拠箇所

| 内容 | 根拠 |
|---|---|
| CakePHP clone | `docker/Dockerfile:39` |
| PHP / MySQL バージョン | `docker/Dockerfile:1`, `docker/docker-compose.yml:27` |
| AppController 認証構成 | `Controller/AppController.php:24-40`, `:113-117` |
| DebugKit 常時ロード | `Controller/AppController.php:25` |
| ACL 未使用 | `Config/bootstrap.php:93-95`（Acl ロード無し）, `Controller/AppController.php:31-35`（authorize 無し） |
| 自前フォームトークン | `Lib/FormToken.php`, `Controller/Component/AppSecurityComponent.php` |
| AppModel チェーン | `Model/AppModel.php:51-140` |
| Search 利用 | `Model/User.php:134-151`, `Model/Record.php:85-110`, `Controller/UsersController.php:260-263`, `Controller/RecordsController.php:41-45` |
| raw SQL / GROUP BY | `Model/UsersCourse.php:61-105`, `Model/Content.php:114-164`, `Model/Info.php:110-130` |
| スキーマ不整合 | `Config/Schema/update.sql:37` |
| Custom 機構 | `Config/bootstrap.php:55-75` |
| REST API 仕様 | `Docs/API.md` |

## 付録 B. 外部情報の出典

| 内容 | 出典 |
|---|---|
| CakePHP 5.x 要求 PHP / サポート | https://github.com/cakephp/cakephp/wiki |
| CakePHP 5.0 移行ガイド | https://book.cakephp.org/5.x/appendices/5-0-migration-guide.html |
| CakePHP 3.0 移行ガイド | https://book.cakephp.org/3.x/appendices/3-0-migration-guide.html |
| Upgrade Tool 対応範囲 | https://github.com/cakephp/upgrade |
| PHP サポート状況 | https://www.php.net/supported-versions.php |
| MySQL EOL | https://www.mysql.com/support/eol-notice.html |
| MariaDB 11.4 LTS リリースノート | https://mariadb.com/kb/en/mariadb-11-4-release-notes/ |
| MariaDB サポートポリシー | https://mariadb.org/about/support-policy/ |
| MariaDB ライセンス | https://mariadb.com/kb/en/mariadb-licensing-faq/ |
| CakePHP MariaDB サポート | https://book.cakephp.org/5.x/en/deployment/database-configuration.html |
| MariaDB Docker イメージ | https://hub.docker.com/_/mariadb |
| 代替プラグイン | https://packagist.org/packages/friendsofcake/bootstrap-ui, https://packagist.org/packages/cakephp/debug_kit |

> 本仕様書は 2026-09-16 時点の調査に基づく。外部情報は変更され得るため、実行前に最新情報の再確認を要する。
