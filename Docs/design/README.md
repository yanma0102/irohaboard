# iroha Board — CakePHP 5.x 移行 詳細設計書

本ディレクトリ（`Docs/design/`）には、CakePHP 2.10 → 5.x 移行の詳細設計書をドキュメント番号別に格納しています。

## ドキュメント一覧

| 番号 | ファイル名 | 内容 |
|---|---|---|
| 01 | `01-system-architecture.md` | システム構成設計（ディレクトリ・Composer・autoload・Docker・環境変数） |
| 02 | `02-domain-model.md` | ドメインモデル設計（16テーブル→Table/Entity、アソシエーション、バリデーション、型） |
| 03 | `03-orm-migration.md` | ORM 移行設計（AppModel チェーン廃止・raw SQL 置換・Search 自作） |
| 04 | `04-authentication.md` | 認証・認可設計（Authentication プラグイン・ロール・RememberMe・API Bearer） |
| 05 | `05-security.md` | セキュリティ設計（FormProtection/Csrf・パスワード・セッション） |
| 06 | `06-routing.md` | ルーティング設計（admin prefix・API v1・scoped routes） |
| 07 | `07-controllers.md` | Controller 設計（全22ファイル・全76アクションの移行マッピング） |
| 08 | `08-rest-api.md` | REST API 設計（24エンドポイントの仕様・リクエスト/レスポンス・認証） |
| 09 | `09-views.md` | View 設計（.ctp→.php・BoostCake→bootstrap-ui・画面別ヘルパー対応） |
| 10 | `10-database-migration.md` | DB 移行設計（utf8mb4変換・MariaDB 11.4・GROUP BY修正・手順・リバース） |
| 11 | `11-config-bootstrap.md` | 設定・起動設計（config/app.php・Application.php・Custom ディレクトリ再実装） |
| 12 | `12-implementation-test-plan.md` | 実装計画・テスト設計（フェーズ別手順・テストケース・トレーサビリティ） |
| 13 | `13-markdown-mcp.md` | Markdown 対応 & MCP サーバ統合設計（コンテンツ Markdown 化・Contents API Write・MCP サーバ） |

## 共通設計方針

以下は全ドキュメントに共通する設計方針です。個別ドキュメントでは「なぜそう決めたか」の根拠を記述してください。

### ターゲット構成

| 項目 | バージョン |
|---|---|
| CakePHP | 5.4.x（最新 5.4.2: 2026-09-05） |
| PHP | 8.4 |
| MariaDB | 11.4 LTS |
| Composer | 2 |
| Debian | Bookworm / Trixie |

### ディレクトリ構成（CakePHP 5 デフォルト）

```
app/                          # ルート
├── config/
│   ├── app.php               # core.php の全設定を移行
│   ├── bootstrap.php          # bootstrap.php の全処理を移行
│   ├── routes.php             # routes.php をスコープ付きルートに変更
│   └── ib_config.php          # アプリ固有設定（そのまま保持）
├── src/
│   ├── Controller/            # Controller 全体
│   │   ├── AppController.php
│   │   ├── Component/         # カスタムコンポーネント
│   │   ├── Api/               # API コントローラ（prefix ではなくサブディレクトリ化）
│   │   └── ...
│   ├── Model/
│   │   ├── Table/             # Table クラス
│   │   ├── Entity/            # Entity クラス
│   │   └── Behavior/          # カスタムビヘイビア
│   ├── View/
│   │   ├── Helper/            # カスタムヘルパー
│   │   └── AppView.php
│   └── Middleware/            # アプリケーションミドルウェア
├── templates/                 # テンプレート（.php）
│   ├── layout/
│   ├── element/
│   ├── ...
├── webroot/                   # ドキュメントルート
├── tests/                     # PHPUnit テスト
├── composer.json
├── .env
└── ...
```

### テーブルプレフィックス（`ib_`）

CakePHP 5 にはグローバルなテーブルプレフィックス設定がない。以下のいずれかで対応する:
- **推奨**: 各 `Table` クラスの `initialize()` で `$this->setTable('ib_xxx')` を明示
- 別案: 基底 `AppTable` クラスで `initialize()` 内にプレフィックスロジックを実装

### DB エンコーディング

- `utf8mb4` / `utf8mb4_unicode_ci` に統一
- MariaDB 11.x は新規データベースの既定が `utf8mb4`。既存データベースは `utf8mb3` の場合があるため `utf8mb4` への変換が必要
- CakePHP 5 の `database.php` → `config/app.php` の `Datasources` 設定で `'encoding' => 'utf8mb4'`

### 認証

| 機能 | プラグイン/方式 |
|---|---|
| ログイン/セッション | `cakephp/authentication` 4.x |
| RememberMe | カスタム Authenticator（`UserToken` テーブル + `CookieAuthenticator` 代替） |
| API Bearer | カスタム Authenticator（`UserToken` テーブル + `BearerTokenIdentifier`） |
| ロール判定 | 手動実装（`Authentication` プラグイン後に `$this->Authentication->getIdentity()` でロール取得） |
| ACL | **削除**（未使用） |

### セキュリティ

| 機能 | CakePHP 5 での対応 |
|---|---|
| CSRF | `CsrfProtectionMiddleware` |
| フォーム改ざん防止 | `FormProtectionComponent` |
| HTTPS | `HttpsEnforcerMiddleware`（必要に応じて） |
| パスワード | `password_hash(PASSWORD_BCRYPT)` — 既存 bcrypt 対応済み |

### ルーティング

- **API v1**: `$routes->scope('/api/v1', ...)` で明示定義。URL は `/api/v1/users` などを維持
- **admin プレフィクス**: `$routes->prefix('Admin', ...)` でスコープ定義。URL は `/admin/users` 等（CakePHP 5 では admin/users/admin_index → /admin/users/index）
- **コントローラ名**: CakePHP 2 の `api_users` → CakePHP 5 の `ApiUsers`（URL は `api-users`）。ルーティングで明示的に `controller => 'ApiUsers'` を指定して URL 互換性を維持

### ヘルパー

| 現行 (CakePHP 2) | 移行先 (CakePHP 5) |
|---|---|
| `BoostCake.BoostCakeHtml` | `friendsofcake/bootstrap-ui` の `HtmlHelper` |
| `AppBoostCakeForm` | `friendsofcake/bootstrap-ui` の `FormHelper`（AppFormHelper の入力ヘルパー統合） |
| `BoostCake.BoostCakePaginator` | `friendsofcake/bootstrap-ui` の `PaginatorHelper` |

### View 内ロジック

テンプレート内の `switch` 分岐や `getExplain()` 関数定義は、Controller の Helper メソッドや View ブロックに分離する。

### Raw SQL

- 複雑なレポート系クエリ（`UsersCourse::getCourseRecord()`、`Content::getContentRecord()` 等）は **そのまま保持**（`Connection::execute()` または `Table::query()` で実行）
- GROUP BY 6 箇所は MariaDB 11.4 の `ONLY_FULL_GROUP_BY` に合わせて修正
- setOrder 系の更新クエリは `Query` オブジェクトに置換

### Custom ディレクトリ

現状 Custom/ は全空。CakePHP 5 移行後は以下のいずれかで実現:
- **推奨**: PSR-4 autoload で `App\Custom\` 名前空間を `config/composer.json` に定義
- 設定上書き: `ib_config.php` の配列は CakePHP 5 の Configure で継続ロード

### API 仕様

- REST API v1 の入出力形式（`Docs/API.md` 準拠）は **完全維持**
- レスポンス形式: `{ data: ... }` / `{ data: [...], meta: {page,limit,total,count} }` / `{ error: {code,message,errors?} }`
- ステータスコード: 200/201/400/401/403/404/429/500

### テスト

- PHPUnit（CakePHP 5 対応版）
- 移行回帰テスト: 主要フロー（ログイン→コース→コンテンツ→テスト→管理画面）と API 全エンドポイント

## 関連ドキュメント

| ドキュメント | 場所 |
|---|---|
| 移行仕様書（全体計画） | `Docs/cakephp5-migration-spec.md` |
| REST API 仕様 | `Docs/API.md` |
| ドメイン定義 | `Config/Schema/app.sql` |
| アプリ設定 | `Config/ib_config.php` |
