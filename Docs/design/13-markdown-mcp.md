# 13 Markdown 対応 & MCP サーバ統合 設計書

本ドキュメントは、iroha Board に対する以下の 3 機能追加の詳細設計をまとめたものである。

1. コンテンツ本文の **Markdown 対応**
2. Markdown コンテンツを操作するための **Contents API の Write 拡張**
3. REST API を基盤とした **MCP (Model Context Protocol) サーバ**の具備

対象構成は `Docs/design/README.md` の共通設計方針に従う（CakePHP 5.4.x / PHP 8.4 / MariaDB 11.4 LTS / Composer 2）。

---

## 1. 概要

### 1.1 背景

現状、学習コンテンツ（`ib_contents`）の本文 `body` は、リッチテキスト（`kind='html'`、Summernote で生成した生 HTML）としてのみ編集・表示できる。テキスト主体の教材を Markdown で作成したいという要望がある一方で、管理画面の GUI エディタ整備を初期リリースの必須要件とはしない。まず **API 経由で Markdown コンテンツを作成・取得できること**を必須とする。

また、既存 REST API v1 を外部ツール（AI エージェント等）から利用可能にするため、**MCP サーバ**を具備する。

### 1.2 スコープ

| 区分 | 対象 | 備考 |
|---|---|---|
| 必須（初期） | Markdown コンテンツの保存・表示 | `kind='markdown'` |
| 必須（初期） | Contents API の Write（作成・更新・削除） | スタッフ権限 |
| 必須（初期） | MCP サーバ（読み取り系ツール） | `/mcp` 単一エンドポイント |
| 任意（後期） | 管理画面の Markdown 専用 GUI エディタ | 必須ではない |
| 任意（後期） | MCP 書き込み系ツール | Phase 3 |

### 1.3 非目標

- 既存 `html` コンテンツの Markdown への自動変換（移行は手動または別途ツール）。
- OAuth 2.1 Authorization Server の実装（既存 Bearer トークンで代替）。
- Markdown 以外の新規フォーマット（reStructuredText 等）。

---

## 2. 現状分析（As-Is）

### 2.1 コンテンツモデル

| 項目 | 現状 |
|---|---|
| テーブル | `ib_contents`（`ContentsTable`、プレフィクスは `setTable('ib_contents')` で明示） |
| 本文カラム | `body` MEDIUMTEXT NULL（最大 16,777,215 バイト） |
| 種別 | `kind` VARCHAR(20) DEFAULT ''（`config/ib_config.php` の `content_kind`） |
| 既存 kind | `label` / `html`（リッチテキスト） / `movie` / `url` / `file` / `test`（`text` はコメントアウト） |
| バリデーション | `title` / `kind` / `status` 等はあり。**`body` のバリデーションは無し** |
| Entity | `src/Model/Entity/Content.php`。`_accessible = ['*' => true, 'id' => false]`、アクセサ無し |

### 2.2 表示フロー

`templates/Contents/view.php`（L41-69）が `kind` で分岐し、L73 で `<?= $body ?>` として出力する。

| kind | 生成方法 | エスケープ |
|---|---|---|
| `url` | `<iframe>`（`h($content['url'])`） | あり |
| `movie` | `<video>`（`h($content['url'])`） | あり |
| `text` | `h($body)` → `autoLinkUrls()` → `nl2br()` | あり |
| **`html`** | **生 HTML をそのまま出力** | **なし（サニタイズ無し）** |

> **留意**: `kind='html'` は管理画面（Summernote）でスタッフが作成した HTML を前提に、意図的に無サニタイズで出力している。Markdown 対応では、Markdown が生 HTML を埋め込める点を踏まえ、**Markdown 側には出力時サニタイズを必須とする**。

### 2.3 API / 認証

| 項目 | 現状 |
|---|---|
| API 基盤 | `src/Controller/Api/BaseController.php`（Bearer 認証 `selector:validator`、`ok()` / `okList()` / `fail()` / `pagination()` / `requireStaff()` 等） |
| 認証 | `Authorization: Bearer <selector:validator>` → `UserTokensTable::authenticateApiToken()` |
| トークン発行 | `POST /api/v1/auth/token`（IP 単位レート制限 10 req/h） |
| エラー | `ApiException` + `ApiErrorMiddleware`（JSON 化） + `ErrorsController::notFound()` |
| Contents API | **GET `/api/v1/contents` と GET `/api/v1/contents/{id}` のみ（Read-only）** |
| ミドルウェア | ErrorHandler → HostHeader → Asset → Routing → **ApiError** → BodyParser → Authentication → CsrfProtection |
| CSRF | `Application.php` L121 で `str_starts_with($uri, '/api/')` をスキップ |

### 2.4 MCP 関連

- PHP 用 MCP 実装は未導入。Markdown ライブラリも未導入（`league/commonmark` / `parsedown` 等は一切無し）。

---

## 3. Markdown 対応設計

### 3.1 表現方式の決定：`kind='markdown'` を新設

本文フォーマットを表現する方法として、(a) `kind` に `'markdown'` を追加、(b) `body_format` カラムを追加、の 2 案を比較した。

| 比較軸 | (a) `kind='markdown'` | (b) `body_format` カラム追加 |
|---|---|---|
| DB スキーマ変更 | **不要**（`kind` は VARCHAR(20)、値追加のみ） | `ALTER TABLE` が必要 |
| 既存 `kind` 分岐 | `switch` に `case` 追加 | `body_format` で分岐（`kind` と 2 次元化） |
| API の `kind` フィルタ | そのまま `kind=markdown` で絞り込み可能 | 複合条件が必要 |
| admin `copy()` / `preview()` | `kind` と `body` をセットでコピー済みで漏れが無い | `body_format` のコピー漏れリスク |
| 管理画面 UI 切替 | `kind` でエディタを切り替える既存方式に自然に乗る | `kind='html'` の中で混在し複雑化 |

**決定: (a) `kind='markdown'` を採用する。** DB スキーマ変更ゼロで、既存の「`kind` で表示・編集を分岐する」パターンにそのまま乗るため、変更範囲と回帰リスクが最小となる。却下案 (b) は柔軟性の利点より、移行コストとコピー漏れ・複合条件化のデメリットが上回る。

> 補足: 既存の `text`（プレーンテキスト）kind は `config/ib_config.php` でコメントアウトされているが、`templates/Contents/view.php` には `case 'text'` が残存する。本設計では `text` を復活させず、Markdown を `text` の上位互換として扱う。

### 3.2 設定変更

`config/ib_config.php` の 2 配列に `markdown` を追加する（`text` と同様の扱い）。

```php
// config/ib_config.php
$config['content_kind'] = [
    'label'     => 'ラベル',
    'html'      => 'リッチテキスト',
    'markdown'  => 'Markdown',   // ← 追加
    'movie'     => '動画',
    'url'       => 'URL',
    'file'      => '配布資料',
    'test'      => 'テスト',
    'enquete'   => 'アンケート',
];

$config['content_kind_comment'] = [
    // ...
    'markdown'  => 'Markdown <span>(Markdown形式で学習項目を作成します。API経由での作成・取得に対応しています。)</span>', // ← 追加
    // ...
];
```

### 3.3 レンダリング & サニタイズ

#### 3.3.1 ライブラリ

`league/commonmark`（^2.x）を採用する。PSR-12 準拠・アクティブメンテ・GitHub Flavored Markdown 拡張対応・PHP 8.4 互換。

```bash
composer require league/commonmark
```

サニタイズには `ezyang/htmlpurifier` を用いる。

```bash
composer require ezyang/htmlpurifier
```

#### 3.3.2 サニタイズ方針（出力時サニタイズ）

Markdown は生 HTML の埋め込みを許容するため、**Markdown → HTML 変換後の HTML に対してサニタイズする**。入力（保存前）にサニタイズすると Markdown 構文（`>` 引用、`<` 等）を破壊する恐れがあるため、**出力時サニタイズ**を正道とする。

#### 3.3.3 View ヘルパー

`src/View/Helper/MarkdownHelper.php` を新設し、変換とサニタイズをカプセル化する。

```php
<?php
// src/View/Helper/MarkdownHelper.php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;

class MarkdownHelper extends Helper
{
    private CommonMarkConverter $converter;
    private \HTMLPurifier $purifier;

    public function initialize(array $config): void
    {
        $environment = new Environment([]);
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $this->converter = new CommonMarkConverter([], $environment);

        // HTMLPurifier は DTD 解析等の初期化コストが高いため、生成は 1 回だけ行いプロパティに保持する。
        // （毎回 new するとコンテンツ表示のたびにオーバーヘッドが発生する。）
        $purifierConfig = \HTMLPurifier_Config::createDefault();
        $purifierConfig->set('HTML.Allowed', implode(',', [
            'p', 'br', 'hr',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'strong', 'em', 'del', 'ins', 'mark', 'code', 'pre', 'sup', 'sub', 'abbr',
            'ul', 'ol', 'li', 'dl', 'dt', 'dd',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
            'blockquote', 'details', 'summary',
            'a[href|title|target]', 'img[src|alt|title|width|height]',
        ]));
        $purifierConfig->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $purifierConfig->set('Attr.EnableID', true);
        $purifierConfig->set('Cache.DefinitionImpl', null); // 開発時はキャッシュ無効。本番では有効化を検討。
        $this->purifier = new \HTMLPurifier($purifierConfig);
    }

    /**
     * Markdown をサニタイズ済み HTML に変換する。
     */
    public function text(?string $markdown): string
    {
        if ($markdown === null || $markdown === '') {
            return '';
        }
        $html = $this->converter->convert($markdown)->getContent();
        return $this->purifier->purify($html);
    }
}
```

`src/View/AppView.php` の `initialize()` に `$this->loadHelper('Markdown');` を追加する。

#### 3.3.4 表示分岐

`templates/Contents/view.php` の `switch` に `case 'markdown'` を追加する。

```php
case 'markdown': // Markdown コンテンツ
    $body = $this->Markdown->text($content['body']);
    break;
```

出力側（L73）は `html` と同様に生 HTML として出力するが、`MarkdownHelper` 内でサニタイズ済みであるため安全である。

### 3.4 管理画面（初期は最小対応）

初期リリースでは Markdown 専用エディタを必須としない。`kind='markdown'` 選択時は Summernote ではなく **プレーン textarea** を表示し、サーバサイドでレンダリングする方式とする。

```php
// templates/Admin/Contents/edit.php — kind に応じた本文入力の切替
// kind=html  : Summernote（既存）
// kind=markdown : textarea + プレビュー
$this->Form->control('body', [
    'type'  => 'textarea',
    'label' => __('内容 (Markdown)'),
    'class' => 'form-control',
    'rows'  => 20,
]);
```

`templates/Admin/Contents/edit.php` 内の `render()` 関数（L57-96、kind に応じた表示切替）に `kind==='markdown'` 分岐を追加し、Summernote を初期化せず textarea を表示する。なお `webroot/js/admin.js` に定義されているのは `setRichTextEditor()`（L4-51）のみで `render()` は存在しないため、編集対象は **edit.php** である。`Admin/ContentsController::preview()` も `MarkdownRenderer`（後述 §3.5）でサーバサイドレンダリングする。

> 専用エディタ（CodeMirror / EasyMDE）とリアルタイムプレビューは Phase 3 で導入する。

### 3.5 共通レンダラの切り出し

View ヘルパーだけでなく、API レスポンス（レンダリング済み HTML を返す用途）や管理画面プレビューでも同一ロジックを使うため、コアロジックを `src/Utility/MarkdownRenderer.php` に切り出す。

```php
// src/Utility/MarkdownRenderer.php
namespace App\Utility;

class MarkdownRenderer implements \Cake\Core\SingletonInterface
{
    /** 生 Markdown をサニタイズ済み HTML に変換 */
    public function toHtml(?string $markdown): string { /* CommonMark + HTMLPurifier */ }

    /** Markdown ソースをそのまま返す（API 用。変換はクライアント側で行う選択肢を残す） */
    public function raw(?string $markdown): ?string { return $markdown; }
}
```

`MarkdownHelper` は本クラスに委譲する。

### 3.6 既存 `html` kind の扱い（今回の判断）

**Phase 1 では既存 `html` kind の出力サニタイズは行わない。** 理由:

1. Summernote 生成 HTML には `<iframe>`（YouTube 埋め込み）等が正当に含まれ、一律サニタイズすると既存教材が壊れる可能性がある。
2. 影響範囲の調査（全 `ib_contents.body` の棚卸し）が別途必要。

**将来方針**: `html` kind にも段階的に出力時サニタイズを導入する。まずログのみ（サニタイズ結果を記録し出力は変えない）で影響範囲を把握し、合意後に適用する。

---

## 4. Contents API 拡張（Write）

### 4.1 エンドポイント

既存の GET 2 本に加え、以下の Write エンドポイントを追加する。

| Method | Path | Action | 権限 |
|---|---|---|---|
| GET | `/api/v1/contents` | `index`（既存） | 認証済み |
| GET | `/api/v1/contents/{id}` | `view`（既存） | 認証済み |
| **POST** | `/api/v1/contents` | `add` | **スタッフ** |
| **PUT** | `/api/v1/contents/{id}` | `edit`（全置換） | **スタッフ + コース権限** |
| **PATCH** | `/api/v1/contents/{id}` | `edit`（部分更新） | **スタッフ + コース権限** |
| **DELETE** | `/api/v1/contents/{id}` | `delete` | **スタッフ + コース権限** |

スタッフ = `admin` / `manager` / `editor` / `teacher`（`BaseController::isStaff()` と同義）。

### 4.2 リクエスト / レスポンス

#### POST `/api/v1/contents`

```json
{
  "course_id": 1,
  "title": "第1章 イントロダクション",
  "kind": "markdown",
  "body": "# イントロ\n\nこれは **Markdown** 教材です。",
  "status": 0,
  "sort_no": 5,
  "comment": "備考"
}
```

| フィールド | 必須 | 備考 |
|---|---|---|
| `course_id` | ○ | アクセス可能コースであること |
| `title` | ○ | 最大 200 文字 |
| `kind` | ○ | `content_kind` の許可値（`markdown` 含む） |
| `body` | ○（`text`/`html`/`markdown` 時） | `kind` により要否判断 |
| `status` | | 既定 `0`（非公開）を推奨。明示指定を許可 |
| `sort_no` | | 未指定時は自動採番 |
| `comment` | | 備考 |

レスポンス（201）:

```json
{ "data": { "id": 42, "course_id": 1, "user_id": 3, "title": "第1章 イントロダクション",
            "kind": "markdown", "body": "# イントロ\n\n...", "status": 0, "sort_no": 5,
            "created": "2026-09-24T10:00:00+09:00", "modified": "2026-09-24T10:00:00+09:00" } }
```

> **201 の返却方法**: 既存 `BaseController::ok()` は 200 固定であるため、201 を返すには `respond(['data' => $content->toArray()], 201)` を用いるか、`BaseController` に `created($data)` ヘルパーを追加する。

#### PUT / PATCH `/api/v1/contents/{id}`

- PUT は全置換、PATCH は部分更新。
- レスポンス（200）は更新後のエンティティ。

#### DELETE `/api/v1/contents/{id}`

- 論理削除（`deleted` に日時）または物理削除。既存の Web 側 `Admin/ContentsController::delete()` の挙動に合わせる。
- **関連データのカスケード削除を必須とする**: 既存 Web 側と同様に、紐づく `ContentsQuestions` を削除する（`src/Controller/Admin/ContentsController.php` L159: `fetchTable('ContentsQuestions')->deleteAll(['content_id' => $content_id])`）。これを欠かすと孤児レコードが蓄積する。
- レスポンス（204 または `{"data":{"id":...}}`）。

### 4.3 認可

```
requireStaff() でスタッフ判定
    ↓
対象 course_id ∈ accessibleCourseIds(currentUserId) を確認（403 if not）
    ↓
user_id は currentUserId() を自動設定（クライアント指定は無視）
```

```php
// user_id はクライアント指定を無視し、認証済みユーザーで強制上書きする
$content = $contentsTable->newEmptyEntity();
$content = $contentsTable->patchEntity($content, $this->input());
$content->user_id = $this->currentUserId(); // 入力を上書き
```

> `Content` エンティティの `_accessible` は `['*' => true, 'id' => false]` のため、入力 `user_id` をそのまま反映しうる。セキュリティ上、**必ず認証ユーザーで上書き**すること。

### 4.4 バリデーション

`ContentsTable::validationDefault()` に以下を追加する。

- `kind`: `inList` で `array_keys(Configure::read('content_kind'))` に限定。**既存レコードには `kind=''`（空文字）が存在しうるため `allowEmpty: true` を付与**し、既存データの更新を壊さないこと。新規作成時の必須チェックは別途 `requirePresence` / API 側で担保する。
- `body`: `kind` が `text`/`html`/`markdown` の場合に必須（他 kind では `allowEmptyString` で許容）。

既存の `title` / `status` / `sort_no` 等のルールは維持する。

### 4.5 ルーティング

`config/routes.php` の `/api/v1` スコープ内、既存 Contents ルートの直後に追加する。**本リポジトリの既存 API はすべて `$builder->connect()` + `_method` オプションで記述されている**（例: users/courses, L202-292）。`$builder->get()/post()/put()/patch()/delete()` ショートカットの第 3 引数はルート名であり `['pass' => ...]` を渡せないため、既存流儀に合わせて `connect()` を用いる。

```php
// config/routes.php — /api/v1 scope 内（既存 GET の直後に追加）
$builder->connect('/contents', [
    'controller' => 'Contents',
    'action' => 'add',
    '_method' => 'POST',
]);
$builder->connect('/contents/{id}', [
    'controller' => 'Contents',
    'action' => 'edit',
    '_method' => 'PUT',
], ['id' => '\d+', 'pass' => ['id']]);
$builder->connect('/contents/{id}', [
    'controller' => 'Contents',
    'action' => 'edit',
    '_method' => 'PATCH',
], ['id' => '\d+', 'pass' => ['id']]);
$builder->connect('/contents/{id}', [
    'controller' => 'Contents',
    'action' => 'delete',
    '_method' => 'DELETE',
], ['id' => '\d+', 'pass' => ['id']]);
```

> 既存の GET `/contents`（index）と GET `/contents/{id}`（view）（L295-304）は変更しない。

実装は `src/Controller/Api/ContentsController.php` に `add()` / `edit()` / `delete()` を追加する。

---

## 5. MCP サーバ設計

### 5.1 方式選定

| 判断項目 | 決定 | 根拠 |
|---|---|---|
| ライブラリ | **公式 `mcp/sdk`（v0.8.1）** | Apache-2.0、Fabien Potencier 等がメンテ、PSR-7/15 準拠、約 1,600 スター・3.8M DL。`Cake\Http\ServerRequest` が PSR-7 をネイティブ実装するため統合が容易 |
| トランスポート | **Streamable HTTP**（SDK の `StreamableHttpTransport`） | リモート Web アプリの正攻法。stdio はローカル専用、HTTP+SSE は非推奨 |
| 状態管理 | **ステートレス優先**（既定の 2 モード両対応を許容） | PHP-FPM はプロセス間状態を持ちにくい。MCP `2026-07-28`（stateless）中心、必要に応じ `2025-11-25`（ハンドシェイク）も SDK が自動判別 |
| 認証 | **既存 Bearer トークンを再利用** | OAuth 2.1 Authorization Server を別途立てず、Resource Server として既存トークンを検証 |
| エンドポイント | **`/mcp`（単一 URL）** | プロトコルバージョンは MCP がヘッダで交渉するため URL 版管理は不要 |

却下した代替案:

- `crustum/mcp`（CakePHP プラグイン）: 実在するが **GitHub スター 0・総 DL 476 件・メンテナ 1 名**の超新規（v1.0.0 が 2026-09-18 公開）。主要依存としての信頼性が不足。
- `logiscape/mcp-sdk-php`: 実証済みだが、公式 SDK と同等機能。公式が利用不能な場合の**フォールバック**とする。
- `php-mcp/server`: 865 スターだが最終安定版が 2025-07 で旧仕様。採用しない。

### 5.2 アーキテクチャ

```
[MCP クライアント (Claude 等)]
        │  POST /mcp  Authorization: Bearer <selector:validator>
        │  Content-Type: application/json  Accept: application/json, text/event-stream
        ▼
[CakePHP ルーティング]  →  [McpController::endpoint]
        │  （Cake\Http\ServerRequest は PSR-7 ServerRequestInterface を実装）
        ▼
[Mcp\Server\Transport\StreamableHttpTransport] （SDK）
        │  IrohaAuthMiddleware（自作 Bearer トークン検証）
        │  OAuthRequestMetaMiddleware（oauth.* 属性 → JSON-RPC _meta へ転記）
        ▼
[Mcp\Server]  →  [ツール/リソース・ハンドラ]
        ▼
[ContentsTable / CoursesTable / RecordsTable / UsersTable]（ドメインロジック直呼び出し）
```

> **原則**: MCP ツールは内部 REST API を HTTP 経由で呼び出さない。Table / サービス層を直接呼び出す。これにより認証の二重化・レイテンシ・循環依存を避ける。

### 5.3 認証

SDK の `AuthorizationMiddleware` は使わない（`ProtectedResourceMetadata` に AS URL が必須のため）。自作の `IrohaAuthMiddleware` で既存トークン検証をラップする。

```php
// src/Mcp/IrohaTokenValidator.php
namespace App\Mcp;

use App\Model\Table\UserTokensTable;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

class IrohaTokenValidator implements AuthorizationTokenValidatorInterface
{
    public function __construct(private UserTokensTable $tokens) {}

    public function validate(string $accessToken): AuthorizationResult
    {
        // accessToken は "selector:validator" 形式
        $result = $this->tokens->lookupApiToken($accessToken); // 毎リクエスト照合。authenticateApiToken() は失敗時にトークンを失効させる副作用があるため使わない（G-3）
        if ($result === null) {
            return AuthorizationResult::unauthorized('invalid_token', 'Invalid or expired token');
        }
        $user = $result['user'];
        // 属性キーは必ず 'oauth.' プレフィクスを付ける。
        // これにより OAuthRequestMetaMiddleware が JSON-RPC の _meta.oauth へ転記する。
        // v0.8.1 確認済み: allow(array $attributes = []), unauthorized(?string $error, ?string $errorDescription, ?array $scopes)
        return AuthorizationResult::allow([
            'oauth.user_id' => (int)$user['id'],
            'oauth.role'    => (string)$user['role'],
        ]);
    }
}
```

- トークン発行は既存 `POST /api/v1/auth/token` をそのまま利用する。
- **AuthorizationMiddleware は使用しない（v0.8.1 検証確定）**: SDK の `AuthorizationMiddleware` はコンストラクタの第 2 引数に `ProtectedResourceMetadata` を必須とし、そのコンストラクタは **≥1 個の非空 authorizationServers URL 文字列を必須とする**（空配列では `InvalidArgumentException`）。iroha Board には OAuth 認可サーバ（AS）が存在しないため、SDK の `AuthorizationMiddleware` は使わない。AS 無しの自己発行トークンで偽の AS URL を宣言して RFC 9728 メタデータの偽装エンドポイントを公開することは望ましくない。
- **自作 `IrohaAuthMiddleware`（PSR-7 ミドルウェア、約 30 行）**: Bearer トークンを `IrohaTokenValidator::validate()` で検証し、成功時は `AuthorizationResult` の属性を `$request->withAttribute('oauth.user_id', ...)` の形（キーは必ず `oauth.` プレフィクス）で PSR-7 request attributes に設定する。失敗時は 401 + `WWW-Authenticate: Bearer error="invalid_token", error_description="..."` を返す。詳細は付録 E-3 を参照。
- **プリンシパルの伝播（確定）**: `IrohaAuthMiddleware` が設定した `oauth.*` request attributes は、SDK の `Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware` が JSON-RPC の `params._meta.oauth` へ転記する。`OAuthRequestMetaMiddleware` は `AuthorizationMiddleware` に依存せず、PSR-7 request attributes に `oauth.` プレフィクスのキーが存在すれば転記する（v0.8.1 確認済み）。ツールハンドラは SDK が自動注入する `RequestContext` 経由で取得する。

  ```php
  use Mcp\Server\RequestContext;

  public function getContent(int $content_id, RequestContext $context): array
  {
      $oauth  = $context->getRequest()->getMeta()['oauth'] ?? [];
      $userId = (int)($oauth['oauth.user_id'] ?? 0);
      $role   = (string)($oauth['oauth.role'] ?? '');
      // v0.8.1 確認済み: getMeta()['oauth'] の内部キーも 'oauth.' プレフィクス付き
      // 以降、$userId / $role で認可
  }
  ```

  属性キーに `oauth.` プレフィクスが無いと転記されない点に注意。
- 401 応答は MCP 仕様の `WWW-Authenticate: Bearer error="invalid_token"` 形式で返す（`IrohaAuthMiddleware` 内で生成）。

### 5.4 エンドポイント・ルーティング・CSRF

```php
// config/routes.php — トップレベルに追加
$builder->scope('/mcp', function (\Cake\Routing\RouteBuilder $builder): void {
    $builder->post('/',   ['controller' => 'Mcp', 'action' => 'endpoint']);
    $builder->delete('/', ['controller' => 'Mcp', 'action' => 'endpoint']); // セッション終了（ハンドシェイク時代）
    $builder->options('/',['controller' => 'Mcp', 'action' => 'endpoint']); // CORS preflight
});
```

`src/Application.php` の CSRF スキップ条件に `/mcp` を追加する。

```php
// src/Application.php L121 付近
return str_starts_with($uri, '/api/') || str_starts_with($uri, '/mcp');
```

> **補足**: ミドルウェア順では `AuthenticationMiddleware` が Routing より前に実行されるが、`/mcp` はセッション・Cookie を使用しないため、未認証状態では単に識別情報が付かないだけでリダイレクト等は発生しない（既存 `/api/*` と同様）。認証そのものは `IrohaTokenValidator` が担う。CORS ヘッダは SDK の `CorsMiddleware` が設定し、McpController が §5.5 のヘッダ転記で応答に反映する。

### 5.5 コントローラと Server 構築

```php
// src/Controller/McpController.php
namespace App\Controller;

use App\Mcp\McpServerFactory;
use App\Mcp\IrohaAuthMiddleware;
use App\Mcp\IrohaTokenValidator;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware;

class McpController extends Controller
{
    public function endpoint(): \Cake\Http\Response
    {
        $server = (new McpServerFactory())->create($this->request, $this->response);

        $auth = new IrohaAuthMiddleware(new IrohaTokenValidator($this->fetchTable('UserTokens')));

        // Cake\Http\ServerRequest は PSR-7 をネイティブ実装 → 変換不要
        // OAuthRequestMetaMiddleware が IrohaAuthMiddleware が設定した oauth.* 属性を JSON-RPC _meta へ転記する
        // IrohaAuthMiddleware は認証失敗時に 401 + WWW-Authenticate: Bearer error="invalid_token" を返す
        $transport = new StreamableHttpTransport(
            request: $this->request,
            middleware: [
                ...StreamableHttpTransport::defaultMiddleware(),
                $auth,
                new OAuthRequestMetaMiddleware(),
            ],
        );

        $psr7 = $server->run($transport); // PSR-7 ResponseInterface

        // ステータス・本文だけでなく、SDK が設定した全ヘッダを転記する。
        // 特に Content-Type(application/json / text/event-stream) を落とすと
        // MCP クライアントが応答を解釈できずプロトコルが破綻する。
        $response = $this->response->withStatus($psr7->getStatusCode());
        foreach ($psr7->getHeaders() as $name => $values) {
            $response = $response->withHeader($name, $values);
        }
        $response = $response->withBody($psr7->getBody());

        return $response;
    }
}
```

```php
// src/Mcp/McpServerFactory.php
namespace App\Mcp;

use Mcp\Server;

class McpServerFactory
{
    public function create($request, $response): Server
    {
        return Server::builder()
            ->setServerInfo('iroha Board', '1.0.0')
            ->setDiscovery(dirname(__DIR__) . '/Mcp/Tool', ['.'], excludeDirs: ['vendor'])
            // または個別登録:
            // ->addTool([\App\Mcp\Tool\CourseTools::class, 'listCourses'], 'list_courses')
            ->build();
    }
}
```

`Server` はリクエストごとに構築する（`build()` は軽量）。必要に応じて DI コンテナ（CakePHP の PSR-11 コンテナ）を `setContainer()` で渡し、ハンドラクラスの解決に利用する。

> **カプセル化方針**: SDK は pre-1.0 のため、`src/Mcp/` 配下に SDK 依存を閉じ込める。SDK の API 変更時は `McpServerFactory` / `IrohaTokenValidator` / ツールクラスのみを修正する。

### 5.6 ツール / リソース初期セット

ツールは SDK の属性 `#[McpTool]` または `addTool()` で登録する。

#### 読み取り系（Phase 2）

| ツール名 | 説明 | 権限 | 主な引数 |
|---|---|---|---|
| `list_courses` | アクセス可能なコース一覧 | 認証済み | `page`, `limit` |
| `get_course` | コース詳細 | コース権限 | `course_id` |
| `list_contents` | コンテンツ一覧 | コース権限 | `course_id`, `kind?`, `page`, `limit` |
| `get_content` | コンテンツ詳細（`body` は生 Markdown） | コース権限 | `content_id` |
| `get_content_html` | レンダリング済み HTML を返す | コース権限 | `content_id` |
| `list_records` | 学習履歴（自分、スタッフは全件） | 本人 / スタッフ | `course_id?`, `user_id?`, `page`, `limit` |
| `get_user_profile` | プロフィール | 本人 / スタッフ | `user_id?` |

> AI クライアントに渡す本文は原則 **生 Markdown**（`get_content` の `body`）。`get_content_html` はサニタイズ済み HTML が必要な場合の補助。
>
> **セキュリティ注意**: `get_content_html` のうち `kind='markdown'` は `MarkdownRenderer` でサニタイズ済み HTML（§3.3）を返す。一方 `kind='html'` は既存仕様どおり**未サニタイズの生 HTML** を返す（§3.6 の Phase 1 方針による）。既定では `kind='html'` の呼び出しを許可するが、MCP クライアント側でのサニタイズを推奨する旨をドキュメント化する。厳格化する場合は `kind='html'` にも HTMLPurifier を適用する選択肢がある。ただし U-5 の決定により、`html` kind へのサニタイズ適用は Phase 3 でログ評価を経てから行う（§9）。

#### 書き込み系（Phase 3）

| ツール名 | 説明 | 権限 | 主な引数 |
|---|---|---|---|
| `create_content` | コンテンツ作成 | スタッフ + コース権限 | `course_id`, `title`, `kind`, `body`, `status?` |
| `update_content` | コンテンツ更新 | スタッフ + コース権限 | `content_id`, `title?`, `body?`, `status?` |

#### リソース（Phase 2 以降・任意）

| URI | 説明 |
|---|---|
| `ib://courses` | コース一覧 |
| `ib://courses/{id}` | コース詳細 |
| `ib://contents/{id}` | コンテンツ（Markdown ソース） |

### 5.7 権限モデル

```
Bearer トークン検証 → user_id / role 取得
    ↓
ツール内で:
  read  : 対象 course_id ∈ accessibleCourseIds(user_id)
  write : requireStaff 相当（admin/manager/editor/teacher） かつ 対象 course_id ∈ accessibleCourseIds(user_id)
  records: 本人の履歴、またはスタッフ
```

`BaseController` の `accessibleCourseIds()` / `isStaff()` ロジックを **再利用可能なサービスへ抽出**し、API と MCP の双方から呼ぶ（重複実装を避ける）。抽出先は `src/Service/AccessControlService.php` を想定。

### 5.8 レート制限

既存の `ib_logs` ベースのレート制限パターンを流用し、MCP エンドポイントに適用する。

| 種別 | 上限（暫定） |
|---|---|
| 読み取りツール | 60 req/min/user |
| 書き込みツール | 20 req/min/user |

### 5.9 PHP-FPM 制約への対応

| 問題 | 対応 |
|---|---|
| SSE（`text/event-stream`）のタイムアウト | 同期 JSON 応答を主とする。長時間処理は作らない |
| ステートレス運用 | `Mcp-Session-Id` に依存しない（`2026-07-28` 互換の設計） |
| CORS | SDK 既定ミドルウェア（CorsMiddleware）で対応 |
| サーバ構築コスト | `build()` は軽量。必要なら将来キャッシュ |

---

## 6. 段階的実装計画

### Phase 1: Markdown 対応 + Contents API Write

| # | タスク | 成果物 | 依存 |
|---|---|---|---|
| 1-1 | `league/commonmark` / `ezyang/htmlpurifier` を追加 | `composer.json` | - |
| 1-2 | `src/Utility/MarkdownRenderer.php` 作成 | ユーティリティ | 1-1 |
| 1-3 | `src/View/Helper/MarkdownHelper.php` 作成 + `AppView` 登録 | ヘルパー | 1-2 |
| 1-4 | `config/ib_config.php` に `markdown` 追加 | 設定 | - |
| 1-5 | `templates/Contents/view.php` に `case 'markdown'` 追加 | テンプレート | 1-3 |
| 1-6 | `ContentsTable` に `kind` の `inList` / `body` 必須を追加 | Table | 1-4 |
| 1-7 | `Api/ContentsController` に `add` / `edit` / `delete` 追加 | Controller | 1-6 |
| 1-8 | `config/routes.php` に Write ルート追加 | ルーティング | 1-7 |
| 1-9 | `Admin/ContentsController::preview()` の Markdown 対応 | Controller | 1-2 |
| 1-10 | 管理画面 `templates/Admin/Contents/edit.php` の `render()` に kind-markdown 分岐（textarea 表示） | テンプレート | 1-4 |
| 1-11 | `Admin/ContentsController::copy()` が kind=markdown で正しく複製されることを確認 | 確認 | 1-4 |
| 1-12 | `Docs/API.md` に Contents Write を追記 | ドキュメント | 1-7 |
| 1-13 | PHPUnit: `MarkdownRenderer` / API Write | テスト | 1-7 |

**完了条件**: `kind='markdown'` を管理画面・API の双方から作成でき、表示時にサニタイズ済み HTML で描画される。既存 kind に回帰がない。API Write の権限・バリデーションがテストで担保される。

### Phase 2: MCP Read-Only

| # | タスク | 成果物 | 依存 |
|---|---|---|---|
| 2-1 | `mcp/sdk` を追加 | `composer.json` | - |
| 2-2 | `src/Service/AccessControlService.php` 抽出 | サービス | - |
| 2-3 | `src/Mcp/IrohaTokenValidator.php` 作成 | 認証 | 2-1 |
| 2-4 | `src/Mcp/McpServerFactory.php` 作成 | ファクトリ | 2-1 |
| 2-5 | `src/Controller/McpController.php` 作成 | Controller | 2-4 |
| 2-6 | `config/routes.php` に `/mcp` 追加 | ルーティング | 2-5 |
| 2-7 | `Application.php` CSRF スキップに `/mcp` 追加 | 設定 | 2-6 |
| 2-8 | 読み取り系ツール 7 個を実装 | ツール | 2-4, 2-2 |
| 2-9 | MCP レート制限 | ユーティリティ | 2-5 |
| 2-10 | PHPUnit: MCP ツール / 認証 | テスト | 2-8 |

**完了条件**: Claude Desktop 等の MCP クライアントから `/mcp` に接続でき、`list_courses` / `get_content` 等が既存 Bearer トークンで動作する。コース権限が正しく適用される。

### Phase 3: MCP Write + GUI Markdown エディタ

| # | タスク | 成果物 | 依存 |
|---|---|---|---|
| 3-1 | 書き込み系ツール `create_content` / `update_content` | ツール | 2-8 |
| 3-2 | PHPUnit: MCP Write | テスト | 3-1 |
| 3-3 | Markdown 専用エディタ（CodeMirror / EasyMDE）+ プレビュー | JS/テンプレート | 1-10 |
| 3-4 | Markdown 内画像アップロード | Controller/JS | 3-3 |
| 3-5 | 既存 `html` kind のサニタイズ段階導入（ログ→適用） | 方針/実装 | - |

**完了条件**: MCP 経由でコンテンツ作成が可能。管理画面で Markdown 専用エディタとプレビューが動作する。

---

## 7. テスト計画

| 種別 | 対象 | ファイル（予定） |
|---|---|---|
| Unit | `MarkdownRenderer::toHtml()`（XSS サニタイズ含む） | `tests/TestCase/Utility/MarkdownRendererTest.php` |
| Unit | MCP ツール単体（Table スタブ） | `tests/TestCase/Mcp/Tool/*Test.php` |
| Integration | Contents API Write（権限・バリデーション・正常系） | `tests/TestCase/Controller/Api/ContentsControllerWriteTest.php` |
| Integration | MCP エンドポイント（認証 401/403、`tools/list`、`tools/call`） | `tests/TestCase/Controller/McpControllerTest.php` |
| E2E（手動） | Claude Desktop 経由の接続・ツール実行 | `Docs/test/03-api.md` に手順追記 |

セキュリティ重点ケース: Markdown 内 `<script>` / `on*` 属性 / `javascript:` URL が除去されること。MCP で権限外コースのコンテンツが取得できないこと。

---

## 8. リスクと対策

| リスク | 重大度 | 対策 |
|---|---|---|
| `mcp/sdk` が pre-1.0 で API 変更 | 中 | `src/Mcp/` にカプセル化。変更は 1 箇所に集約 |
| Markdown 経由の XSS | 高 | 出力時サニタイズを必須化（HTMLPurifier）。テストで担保 |
| HTMLPurifier が正当な HTML を誤除去 | 低 | 許可リストを段階調整。Phase 3 で `html` kind もログ評価 |
| PHP-FPM での SSE 不安定 | 低 | 同期 JSON 応答のみ使用。SSE 非依存 |
| API Write によるコンテンツ大量生成 | 中 | レート制限 + スタッフ限定 |
| MCP の権限漏れ（コース越境） | 高 | `AccessControlService` に集約し API と共通化。全ツールで強制 |
| 既存 `html` コンテンツへの回帰 | 中 | `html` kind の出力は変更しない。回帰テストで確認 |
| CakePHP の PSR-7 実装差異 | 低 | SDK 検証済み（`Cake\Http\ServerRequest` は PSR-7 実装）。結合テストで確認 |

---

## 9. 確定事項

当初の未確定事項（U-1〜U-6）はすべて確定した（U-3 はレビューによる技術確認で解決）。

| # | 項目 | 決定内容 | 状態 |
|---|---|---|---|
| U-1 | Contents API Write の公開範囲 | **スタッフ限定**（`admin` / `manager` / `editor` / `teacher`）。一般ユーザ開放は将来検討 | **確定** |
| U-2 | Markdown の画像の扱い | **初期は外部 URL のみ**（`![alt](url)`）。アップロードは Phase 3 | **確定** |
| U-3 | MCP 認証済みプリンシパルのツールへの伝播方式 | **解決済み**: `OAuthRequestMetaMiddleware` + `oauth.*` 属性 + `RequestContext::getRequest()->getMeta()['oauth']`（§5.3） | **解決済み** |
| U-4 | API Write のレスポンス（DELETE の 204 か 200 か、論理/物理削除） | **Web 側の既存挙動に合わせる**（`Admin/ContentsController::delete()` と同一の削除方式・ステータス。実装時に確定） | **確定** |
| U-5 | `html` kind のサニタイズ導入時期 | **Phase 3 でログ評価から開始**（影響範囲把握後に適用） | **確定** |
| U-6 | Markdown 許可タグの最終リスト | **本ドキュメント §3.3.3 の推奨リストで開始**し、運用で調整 | **確定** |

> **補足（U-4）**: 具体的な削除方式（論理削除 / 物理削除）と HTTP ステータスは、実装時に `Admin/ContentsController::delete()` の現行実装を正として API 側を一致させる。`ContentsQuestions` のカスケード削除も同様に踏襲する。

---

## 10. ドキュメント更新

| ドキュメント | 変更内容 |
|---|---|
| `Docs/design/13-markdown-mcp.md` | 本ドキュメント（新規） |
| `Docs/design/README.md` | 索引に 13 を追記 |
| `Docs/design/05-security.md` | Markdown XSS 対策・MCP 認証を追記 |
| `Docs/design/08-rest-api.md` | Contents Write エンドポイントを追記 |
| `Docs/API.md` | Contents の POST/PUT/PATCH/DELETE 仕様・MCP エンドポイントを追記（v1.1→v1.2） |
| `Docs/test/03-api.md` | API Write / MCP のテストケース追記 |
| `Docs/test/traceability.md` | 新機能のトレーサビリティ追記 |
| `Docs/design/12-implementation-test-plan.md` | Phase 1-3 を追記 |

---

## 付録 A: 変更・追加ファイル一覧（予定）

| 種別 | パス |
|---|---|
| 新規 | `src/Utility/MarkdownRenderer.php` |
| 新規 | `src/View/Helper/MarkdownHelper.php` |
| 新規 | `src/Service/AccessControlService.php` |
| 新規 | `src/Mcp/McpServerFactory.php` |
| 新規 | `src/Mcp/IrohaTokenValidator.php` |
| 新規 | `src/Mcp/IrohaAuthMiddleware.php` |
| 新規 | `src/Mcp/Tool/*.php` |
| 新規 | `src/Controller/McpController.php` |
| 変更 | `config/ib_config.php`（`markdown` 追加） |
| 変更 | `config/routes.php`（Contents Write / `/mcp`） |
| 変更 | `src/Application.php`（CSRF スキップ） |
| 変更 | `src/Controller/Api/ContentsController.php`（Write 追加） |
| 変更 | `src/Model/Table/ContentsTable.php`（バリデーション） |
| 変更 | `templates/Contents/view.php`（`case 'markdown'`） |
| 変更 | `templates/Admin/Contents/edit.php` / `webroot/js/admin.js` |
| 変更 | `src/View/AppView.php`（ヘルパー登録） |
| 変更 | `composer.json`（`league/commonmark` / `ezyang/htmlpurifier` / `mcp/sdk`） |

---

## 付録 B: 参照

- MCP 仕様 2025-11-25 / 2026-07-28（Streamable HTTP）
- 公式 PHP SDK: `github.com/modelcontextprotocol/php-sdk`（`mcp/sdk` v0.8.1）
- `Docs/design/04-authentication.md`, `Docs/design/05-security.md`, `Docs/design/08-rest-api.md`
- `Docs/API.md`

---

## 付録C Phase 1 実装疑似コード

### C-1. `src/Utility/MarkdownRenderer.php`（全量）

```php
<?php
declare(strict_types=1);

namespace App\Utility;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;

/**
 * Markdown → サニタイズ済み HTML 変換ユーティリティ
 *
 * View ヘルパー / API レスポンス / 管理画面プレビューから共通利用される。
 * HTMLPurifier は DTD 初期化コストが高いため、プロパティに 1 回だけ保持する。
 *
 * @note Static メソッド群。Utils.php と同一の static pattern を踏襲する。
 */
class MarkdownRenderer
{
    private static ?CommonMarkConverter $converter = null;
    private static ?\HTMLPurifier $purifier = null;

    /**
     * Converter を遅延初期化して返す（シングルトン）
     */
    private static function getConverter(): CommonMarkConverter
    {
        if (self::$converter === null) {
            $environment = new Environment([]);
            $environment->addExtension(new GithubFlavoredMarkdownExtension());
            self::$converter = new CommonMarkConverter([], $environment);
        }
        return self::$converter;
    }

    /**
     * HTMLPurifier を遅延初期化して返す（シングルトン）
     *
     * 許可タグリストは design doc §3.3.3 (U-6 確定) に基づく。
     * 運用で追加調整が必要な場合は本メソッドを変更する。
     */
    private static function getPurifier(): \HTMLPurifier
    {
        if (self::$purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', implode(',', [
                'p', 'br', 'hr',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'strong', 'em', 'del', 'ins', 'mark', 'code', 'pre', 'sup', 'sub', 'abbr',
                'ul', 'ol', 'li', 'dl', 'dt', 'dd',
                'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
                'blockquote', 'details', 'summary',
                'a[href|title|target]', 'img[src|alt|title|width|height]',
            ]));
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('Attr.EnableID', true);
            // 開発時はキャッシュ無効。本番環境では null を外して定数パスを設定する。
            $config->set('Cache.DefinitionImpl', null);
            self::$purifier = new \HTMLPurifier($config);
        }
        return self::$purifier;
    }

    /**
     * Markdown をサニタイズ済み HTML に変換する
     *
     * @param string|null $markdown Markdown ソース
     * @return string サニタイズ済み HTML（空文字列も許容）
     */
    public static function toHtml(?string $markdown): string
    {
        if ($markdown === null || $markdown === '') {
            return '';
        }

        $html = self::getConverter()->convert($markdown)->getContent();
        return self::getPurifier()->purify($html);
    }
}
```

**設計判断メモ**:
- `Utils.php` は static メソッド群の既存パターン。`SingletonInterface` はCakePHP のインスタンス管理と衝突する可能性があるため static プロパティで十分。
- `getConverter()` / `getPurifier()` は `null` チェックで遅延初期化。PHPUnit で `MarkdownRenderer::$converter = null;` とリセットできる（`ReflectionProperty` 経由）。

### C-2. `src/View/Helper/MarkdownHelper.php`（全量）+ `AppView.php` 差分

```php
<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Utility\MarkdownRenderer;
use Cake\View\Helper;

/**
 * MarkdownHelper - View から Markdown 変換を呼ぶヘルパー
 *
 * MarkdownRenderer に委譲する薄いラッパー。
 */
class MarkdownHelper extends Helper
{
    /**
     * Markdown をサニタイズ済み HTML に変換する
     *
     * @param string|null $markdown Markdown ソース
     * @return string HTML
     */
    public function text(?string $markdown): string
    {
        return MarkdownRenderer::toHtml($markdown);
    }
}
```

`src/View/AppView.php` に以下を追加（L38 の `AppView` ヘルパー登録の直後）:

```php
// src/View/AppView.php — initialize() 内の既存コード L38 の後に追加
$this->loadHelper('Markdown');
```

> **要確認**: `MarkdownHelper` は Helpers 依存なし（`protected array $helpers = [];` を明示しない場合は CakePHP の既定で空）。`AppViewHelper` が `['Html', 'Form', 'Flash']` を持つのとは独立。

### C-3. `config/ib_config.php` 差分

```php
// config/ib_config.php — $config['content_kind'] 内（L17-26）
// 既存状態:
//   'label'     => 'ラベル',
//   'html'      => 'リッチテキスト',
//   'movie'     => '動画',
//   'url'       => 'URL',
//   'file'      => '配布資料',
//   'test'      => 'テスト',
//   'enquete'   => 'アンケート',
// （'text' はコメントアウト済み）

// 変更後: 'html' の直後に 'markdown' を挿入
$config['content_kind'] = [
    'label'     => 'ラベル',
    'html'      => 'リッチテキスト',
    'markdown'  => 'Markdown',    // ← 追加
    'movie'     => '動画',
    'url'       => 'URL',
    'file'      => '配布資料',
    'test'      => 'テスト',
    'enquete'   => 'アンケート',
];
```

```php
// config/ib_config.php — $config['content_kind_comment'] 内
// 既存状態: 各 kind に HTML span を含む配列
// 変更後: 'markdown' エントリを追加
$config['content_kind_comment'] = [
    'label'     => 'ラベル <span>...</span>',
    'html'      => 'リッチテキスト <span>...</span>',
    'markdown'  => 'Markdown <span>(Markdown形式で学習項目を作成します。API経由での作成・取得に対応しています。)</span>', // ← 追加
    'movie'     => '動画 <span>...</span>',
    'url'       => 'URL <span>...</span>',
    'file'      => '配布資料 <span>...</span>',
    'test'      => 'テスト <span>...</span>',
];
```

> 既存の `text` と `enquete` のコメントアウト状態は変更しない。

### C-4. `src/Model/Table/ContentsTable.php` バリデーション追加

`validationDefault(Validator $validator)` メソッド内に以下を追加。既存ルール（title/kind/status/timelimit 等）の**後**に配置:

```php
// ContentsTable::validationDefault() — 既存ルールの後に追加

// kind: 既存ルールは 'kind' => 'maxLength' 20 のみ。
// inList ルールを追加。既存 kind='' が存在するため allowEmpty: true を付与。
$validator
    ->inList('kind', array_keys(Configure::read('content_kind')), [
        'allowEmpty' => true,  // 既存 kind='' レコード保護
        'message' => 'Invalid content kind',
    ]);

// body: kind が text/html/markdown の場合に必須。
// 既存 kind='movie'/'url'/'file'/'test'/'label'/'' では body が空でも可。
$validator
    ->requirePresence('body', 'create', function (\Cake\Validation\ValidationContext $context): bool {
        $kind = $context->data['kind'] ?? '';
        return in_array($kind, ['text', 'html', 'markdown'], true);
    })
    ->allowEmptyString('body', null, function (\Cake\Validation\ValidationContext $context): bool {
        $kind = $context->data['kind'] ?? '';
        return !in_array($kind, ['text', 'html', 'markdown'], true);
    });
```

> **配置位置**: `validationDefault()` メソッドの末尾（既存ルールの後）。`Configure::read('content_kind')` を使用するため `use Cake\Core\Configure;` の `import` がファイル冒頭に既にあるか確認すること（既存ファイル確認済み：`use Cake\Core\Configure;` は**無い**。C-4 実装時にファイル冒頭へ追加すること — **確定**）。

### C-5. `src/Controller/Api/ContentsController.php` add/edit/delete 全量

既存の `index()` / `view()` を変更しない。末尾に以下を追加:

```php
    /**
     * コンテンツを追加する
     *
     * POST /api/v1/contents
     *
     * @return \Cake\Http\Response
     */
    public function add(): \Cake\Http\Response
    {
        $this->requireStaff();

        $input = $this->input();
        $contentsTable = $this->fetchTable('Contents');

        $allowedFields = ['course_id', 'title', 'kind', 'body', 'url', 'file_name', 'status', 'sort_no', 'comment'];
        $fields = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $fields[$field] = $input[$field];
            }
        }

        // course_id 必須チェック
        if (empty($fields['course_id'])) {
            $this->fail(400, 'course_id is required');
        }

        // 対象コースがアクセス可能か確認
        $courseId = (int)$fields['course_id'];
        $accessibleIds = $this->accessibleCourseIds($this->currentUserId());
        if (!in_array($courseId, $accessibleIds, true)) {
            $this->fail(403, 'You do not have access to this course');
        }

        // user_id はクライアント指定を無視し、認証ユーザーで強制上書き
        $fields['user_id'] = $this->currentUserId();

        // sort_no 未指定なら自動採番
        if (!isset($fields['sort_no']) || $fields['sort_no'] === '' || $fields['sort_no'] === null) {
            $fields['sort_no'] = $contentsTable->getNextSortNo($courseId);
        }

        // status 未指定なら 0（非公開）
        if (!isset($fields['status'])) {
            $fields['status'] = 0;
        }

        $entity = $contentsTable->newEntity($fields);

        if ($contentsTable->save($entity)) {
            return $this->ok($entity->toArray(), 201);
        }

        $this->fail(400, 'Validation failed', $entity->getErrors());
    }

    /**
     * コンテンツを更新する
     *
     * PUT/PATCH /api/v1/contents/{id}
     *
     * @param int $id コンテンツID
     * @return \Cake\Http\Response
     */
    public function edit(int $id): \Cake\Http\Response
    {
        $this->requireStaff();

        $contentsTable = $this->fetchTable('Contents');

        if (!$contentsTable->exists(['id' => $id])) {
            $this->fail(404, 'Content not found');
        }

        $target = $contentsTable->get($id);

        // 対象コースがアクセス可能か確認
        $courseId = (int)$target->course_id;
        $accessibleIds = $this->accessibleCourseIds($this->currentUserId());
        if (!in_array($courseId, $accessibleIds, true)) {
            $this->fail(403, 'You do not have access to this course');
        }

        $input = $this->input();
        $allowedFields = ['course_id', 'title', 'kind', 'body', 'url', 'file_name', 'status', 'sort_no', 'comment'];
        $fields = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $fields[$field] = $input[$field];
            }
        }

        if (empty($fields)) {
            $this->fail(400, 'No updatable fields were provided');
        }

        // course_id 変更時は変更先コースへのアクセス権も確認
        if (isset($fields['course_id'])) {
            $newCourseId = (int)$fields['course_id'];
            if (!in_array($newCourseId, $accessibleIds, true)) {
                $this->fail(403, 'You do not have access to the target course');
            }
        }

        // user_id は上書き不可（クライアント指定を無視）
        unset($fields['user_id']);

        $entity = $contentsTable->patchEntity($target, $fields);

        if ($contentsTable->save($entity)) {
            return $this->ok($entity->toArray());
        }

        $this->fail(400, 'Validation failed', $entity->getErrors());
    }

    /**
     * コンテンツを削除する
     *
     * DELETE /api/v1/contents/{id}
     *
     * Web 側 Admin/ContentsController::delete() の挙動に合わせる。
     * - ContentsQuestions のカスケード削除
     * - 物理削除
     *
     * @param int $id コンテンツID
     * @return \Cake\Http\Response
     */
    public function delete(int $id): \Cake\Http\Response
    {
        $this->requireStaff();

        $contentsTable = $this->fetchTable('Contents');

        if (!$contentsTable->exists(['id' => $id])) {
            $this->fail(404, 'Content not found');
        }

        $content = $contentsTable->get($id);

        // 対象コースがアクセス可能か確認
        $courseId = (int)$content->course_id;
        $accessibleIds = $this->accessibleCourseIds($this->currentUserId());
        if (!in_array($courseId, $accessibleIds, true)) {
            $this->fail(403, 'You do not have access to this course');
        }

        if ($contentsTable->delete($content)) {
            // Admin/ContentsController::delete() L159 と同一のカスケード削除
            $this->fetchTable('ContentsQuestions')->deleteAll(['content_id' => $id]);
            return $this->ok(['id' => $id, 'deleted' => true]);
        }

        $this->fail(500, 'Failed to delete content');
    }
```

> **201 応答について**: 既存 `BaseController::ok()` は `respond($data, $status)` であり、`AuthController::issueToken()` L142 で `ok([...], 201)` を使用している（第2引数 = HTTP status）。CoursesController::add() L150 も `ok($data, 201)` を使用。**`created()` ヘルパーは不要**。`$this->ok($entity->toArray(), 201)` で統一する。
>
> **メソッドシグネチャについて**: `add()` は引数なし。`edit(int $id)` / `delete(int $id)` は `$id` を受け取る。これは `['pass' => ['id']]` ルート定義によりルーターがパラメータを渡す既存パターン（CoursesController::edit/delete と同様）。

### C-6. `config/routes.php` Write ルート追加

既存の GET ルート（L295-304）の**直後**に以下を追加:

```php
// config/routes.php — /api/v1 scope 内、既存 GET contents の直後（L304 の後に追加）

// Contents Write (POST/PUT/PATCH/DELETE)
$builder->connect('/contents', [
    'controller' => 'Contents',
    'action' => 'add',
    '_method' => 'POST',
]);
$builder->connect('/contents/{id}', [
    'controller' => 'Contents',
    'action' => 'edit',
    '_method' => 'PUT',
], ['id' => '\d+', 'pass' => ['id']]);
$builder->connect('/contents/{id}', [
    'controller' => 'Contents',
    'action' => 'edit',
    '_method' => 'PATCH',
], ['id' => '\d+', 'pass' => ['id']]);
$builder->connect('/contents/{id}', [
    'controller' => 'Contents',
    'action' => 'delete',
    '_method' => 'DELETE',
], ['id' => '\d+', 'pass' => ['id']]);
```

> 既存 GET ルート（L295-304）は変更しない。`connect()` + `_method` は Users/Courses の既存記法と一致。`['pass' => ['id']]` で `$id` がコントローラに渡る。`'id' => '\d+'` はプレースホルダを数字のみに制約する（G-5）。

### C-7. テンプレート & JS 変更

#### `templates/Contents/view.php` — `case 'markdown'` 追加

L62（`case 'html'` の**前**）に挿入:

```php
        case 'markdown': // Markdown コンテンツ
            $body = $this->Markdown->text($content['body']);
            break;
```

> `html` と同様に L73 `<?= $body ?>` で出力。`MarkdownHelper::text()` 内でサニタイズ済みのため安全。

#### `templates/Admin/Contents/edit.php` — render() 分岐追加

`render()` 関数（L57-96）の `switch(content_kind)` に `case 'markdown'` を追加。L78（`case 'html'` の `break`）の直後に挿入:

```javascript
            case 'markdown': // Markdown
                // Summernote を無効化し、プレーン textarea を表示
                if ($('#body').data('summernote')) {
                    $('#body').summernote('destroy');
                }
                $('#btnPreview').show();
                break;
```

`kind` に応じた本文入力フィールドの表示切替（L194 の div クラス）を更新:

```php
// templates/Admin/Contents/edit.php — L194
// 変更前:
//   echo '<div class="kind kind-text kind-html">';
// 変更後:
echo '<div class="kind kind-text kind-html kind-markdown">';
```

> `kind='markdown'` 選択時は Summernote が初期化されないため、プレーン textarea が表示される。初期リリースでは OK。Phase 3 で CodeMirror/EasyMDE を導入。

#### `webroot/js/admin.js` — 変更なし

`admin.js` に `render()` は存在しない（`render()` は `edit.php` 内の `<script>` ブロックに定義）。`setRichTextEditor()` は変更不要。

### C-8. `src/Controller/Admin/ContentsController::preview()` Markdown 対応差分

L173-189 の `preview()` メソッドに Markdown レンダリングを追加:

```php
    public function preview(): void
    {
        $this->autoRender = false;

        if ($this->request->is('ajax')) {
            $body = $this->getData('content_body');
            $kind = $this->getData('content_kind');

            // kind=markdown の場合、プレビュー用にサニタイズ済み HTML に変換
            if ($kind === 'markdown') {
                $body = \App\Utility\MarkdownRenderer::toHtml($body);
            }

            $data = [
                'id' => 0,
                'title' => $this->getData('content_title'),
                'kind' => $kind,
                'url' => $this->getData('content_url'),
                'body' => $body,
                'course_id' => 0,
            ];

            $this->writeSession('Iroha.preview_content', $data);
        }
    }
```

> プレビュー画面 `templates/Contents/preview.php` がセッションから body を読み取って `<?= $body ?>` として出力するため、preview 階段で HTML 変換しておく必要がある。preview テンプレートは `kind='html'` として扱われるため（preview の kind を `html` に変更するか、body を事前変換するか）、body を事前変換する方式を採用。

### C-9. composer.json への依存追加

```bash
composer require league/commonmark ezyang/htmlpurifier
```

> `mcp/sdk` は Phase 2 で追加。Phase 1 では不要。


---

## 付録D Phase 1 テストケース

### D-1. `tests/TestCase/Utility/MarkdownRendererTest.php`

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Utility;

use App\Utility\MarkdownRenderer;
use Cake\TestSuite\TestCase;

/**
 * MarkdownRenderer のテスト
 */
class MarkdownRendererTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    // ----------------------------------------------------------------
    // 正常変換
    // ----------------------------------------------------------------

    /**
     * null 入力 → 空文字列
     */
    public function testToHtmlNullReturnsEmpty(): void
    {
        $this->assertSame('', MarkdownRenderer::toHtml(null));
    }

    /**
     * 空文字列 → 空文字列
     */
    public function testToHtmlEmptyReturnsEmpty(): void
    {
        $this->assertSame('', MarkdownRenderer::toHtml(''));
    }

    /**
     * 基本的な Markdown 変換: 見出し
     */
    public function testToHtmlHeading(): void
    {
        $result = MarkdownRenderer::toHtml('# Hello');
        $this->assertStringContainsString('<h1>Hello</h1>', $result);
    }

    /**
     * 太字・斜体
     */
    public function testToHtmlInlineFormatting(): void
    {
        $result = MarkdownRenderer::toHtml('**bold** and *italic*');
        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
    }

    /**
     * リスト
     */
    public function testToHtmlList(): void
    {
        $md = "- item1\n- item2\n- item3";
        $result = MarkdownRenderer::toHtml($md);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li>item1</li>', $result);
        $this->assertStringContainsString('<li>item3</li>', $result);
    }

    /**
     * コードブロック
     */
    public function testToHtmlCodeBlock(): void
    {
        $md = "```\nfoo()\n```";
        $result = MarkdownRenderer::toHtml($md);
        $this->assertStringContainsString('<code>', $result);
    }

    // ----------------------------------------------------------------
    // GFM 拡張
    // ----------------------------------------------------------------

    /**
     * GFM: テーブル
     */
    public function testToHtmlTable(): void
    {
        $md = "| A | B |\n|---|---|\n| 1 | 2 |";
        $result = MarkdownRenderer::toHtml($md);
        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<td>1</td>', $result);
    }

    /**
     * GFM: 打消し線
     */
    public function testToHtmlStrikethrough(): void
    {
        $result = MarkdownRenderer::toHtml('~~deleted~~');
        $this->assertStringContainsString('<del>deleted</del>', $result);
    }

    /**
     * GFM: リンク
     */
    public function testToHtmlLink(): void
    {
        $result = MarkdownRenderer::toHtml('[link](https://example.com)');
        $this->assertStringContainsString('<a href="https://example.com"', $result);
    }

    /**
     * GFM: 画像（外部 URL）
     */
    public function testToHtmlImage(): void
    {
        $result = MarkdownRenderer::toHtml('![alt](https://example.com/img.png)');
        $this->assertStringContainsString('<img src="https://example.com/img.png"', $result);
        $this->assertStringContainsString('alt="alt"', $result);
    }

    // ----------------------------------------------------------------
    // XSS サニタイズ
    // ----------------------------------------------------------------

    /**
     * XSS: script タグが除去されること
     */
    public function testToHtmlXssScriptTagRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('<script>alert("xss")</script>');
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert', $result);
    }

    /**
     * XSS: onerror イベントハンドラが除去されること
     */
    public function testToHtmlXssOnErrorRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('<img src=x onerror=alert(1)>');
        $this->assertStringNotContainsString('onerror', $result);
    }

    /**
     * XSS: javascript: URL スキームが除去されること
     */
    public function testToHtmlXssJavascriptUrlRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('[click](javascript:alert(1))');
        $this->assertStringNotContainsString('javascript:', $result);
    }

    /**
     * XSS: 生 iframe が除去されること
     */
    public function testToHtmlXssIframeRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('<iframe src="https://evil.com"></iframe>');
        $this->assertStringNotContainsString('<iframe', $result);
    }

    /**
     * XSS: style タグが除去されること
     */
    public function testToHtmlXssStyleTagRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('<style>body{background:red}</style>');
        $this->assertStringNotContainsString('<style', $result);
    }

    /**
     * XSS: event handler 属性（on*）がすべて除去されること
     */
    public function testToHtmlXssEventHandlerRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('<div onclick="alert(1)">text</div>');
        $this->assertStringNotContainsString('onclick', $result);
    }

    /**
     * 正当な HTML は保持されること（許可タグ）
     */
    public function testToHtmlAllowedTagsPreserved(): void
    {
        $md = "**bold** and [link](https://example.com)";
        $result = MarkdownRenderer::toHtml($md);
        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('href="https://example.com"', $result);
    }

    /**
     * data: URI スキームが除去されること
     */
    public function testToHtmlXssDataUriRemoved(): void
    {
        $result = MarkdownRenderer::toHtml('![x](data:text/html,<script>alert(1)</script>)');
        $this->assertStringNotContainsString('data:', $result);
    }
}
```

### D-2. `tests/TestCase/Controller/Api/ContentsControllerWriteTest.php`

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ContentsController API Write のテスト
 *
 * POST / PUT / PATCH / DELETE の統合テスト
 */
class ContentsControllerWriteTest extends TestCase
{
    use IntegrationTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->getTableLocator()->get('UserTokens')->deleteAll('1 = 1');
        $this->getTableLocator()->get('ContentsQuestions')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Records')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Contents')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Courses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Logs')->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    // ----------------------------------------------------------------
    // ヘルパーメソッド
    // ----------------------------------------------------------------

    private function createUser(string $username = 'testuser', array $overrides = []): \Cake\Datasource\EntityInterface
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $data = array_merge([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'テスト太郎',
            'role' => 'user',
            'email' => $username . '@example.com',
        ], $overrides);

        $entity = $usersTable->newEntity($data);
        $result = $usersTable->save($entity);
        $this->assertNotFalse($result, "ユーザ {$username} の作成に失敗");

        return $result;
    }

    private function issueToken(string $username, string $password): array
    {
        $this->post('/api/v1/auth/token', [
            'username' => $username,
            'password' => $password,
        ]);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body, 'レスポンスに data キーが存在する');

        return $body['data'];
    }

    private function createCourse(string $title = 'テストコース', int $userId = 0, array $overrides = []): \Cake\Datasource\EntityInterface
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $data = array_merge([
            'title' => $title,
            'sort_no' => 1,
            'user_id' => $userId,
        ], $overrides);

        $entity = $coursesTable->newEntity($data);
        $result = $coursesTable->save($entity);
        $this->assertNotFalse($result, "コース {$title} の作成に失敗");

        return $result;
    }

    private function createContent(int $courseId, int $userId, array $overrides = []): \Cake\Datasource\EntityInterface
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $data = array_merge([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'テストコンテンツ',
            'kind' => 'html',
            'body' => '<p>テスト</p>',
            'status' => 1,
            'sort_no' => 1,
        ], $overrides);

        $entity = $contentsTable->newEntity($data);
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, "コンテンツの作成に失敗");

        return $result;
    }

    // ----------------------------------------------------------------
    // POST /api/v1/contents (add)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin で Markdown コンテンツ作成 → 201
     */
    public function testAddMarkdownContent(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'Markdown テスト',
            'kind' => 'markdown',
            'body' => '# イントロ\n\nこれは **Markdown** です。',
            'status' => 0,
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame('Markdown テスト', $body['data']['title']);
        $this->assertSame('markdown', $body['data']['kind']);
        $this->assertSame((int)$course->id, $body['data']['course_id']);
        $this->assertSame((int)$admin->id, $body['data']['user_id']);

        // DB 確認
        $contentsTable = $this->getTableLocator()->get('Contents');
        $this->assertTrue(
            $contentsTable->exists(['title' => 'Markdown テスト', 'kind' => 'markdown']),
            'ib_contents に Markdown コンテンツが作成されている'
        );
    }

    /**
     * 正常系: admin で html コンテンツ作成 → 201
     */
    public function testAddHtmlContent(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'HTML テスト',
            'kind' => 'html',
            'body' => '<p>テスト</p>',
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('html', $body['data']['kind']);
    }

    /**
     * 正常系: kind=movie で body 省略 → 201
     */
    public function testAddMovieContentWithoutBody(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => '動画テスト',
            'kind' => 'movie',
            'url' => 'https://example.com/video.mp4',
        ]);

        $this->assertResponseCode(201);
    }

    /**
     * 正常系: sort_no 未指定時は自動採番
     */
    public function testAddAutoSortNo(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $this->createContent((int)$course->id, (int)$admin->id, ['sort_no' => 5]);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => '自動採番',
            'kind' => 'markdown',
            'body' => 'test',
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(6, $body['data']['sort_no']);
    }

    /**
     * 正常系: status 未指定時は 0（非公開）
     */
    public function testAddDefaultStatusZero(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'ステータス既定',
            'kind' => 'markdown',
            'body' => 'test',
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(0, $body['data']['status']);
    }

    /**
     * 正常系: user_id はクライアント指定を無視されること
     */
    public function testAddUserIdOverridden(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $other = $this->createUser('other01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'user_id 無視テスト',
            'kind' => 'markdown',
            'body' => 'test',
            'user_id' => $other->id, // クライアント指定
        ]);

        $this->assertResponseCode(201);

        $body = json_decode((string)$this->_response->getBody(), true);
        // admin01 の ID が設定されること（other01 ではない）
        $this->assertSame((int)$admin->id, $body['data']['user_id']);
    }

    // ----------------------------------------------------------------
    // POST /api/v1/contents — 異常系
    // ----------------------------------------------------------------

    /**
     * 異常系: 未認証 → 401
     */
    public function testAddUnauthenticated(): void
    {
        $this->post('/api/v1/contents', [
            'course_id' => 1,
            'title' => 'テスト',
            'kind' => 'markdown',
            'body' => 'test',
        ]);
        $this->assertResponseCode(401);
    }

    /**
     * 異常系: 一般ユーザ → 403
     */
    public function testAddForbiddenForUser(): void
    {
        $this->createUser('user01', ['role' => 'user']);
        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => 1,
            'title' => 'テスト',
            'kind' => 'markdown',
            'body' => 'test',
        ]);
        $this->assertResponseCode(403);
    }

    /**
     * 異常系: course_id 未指定 → 400
     */
    public function testAddMissingCourseId(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'title' => 'テスト',
            'kind' => 'markdown',
            'body' => 'test',
        ]);
        $this->assertResponseCode(400);
    }

    /**
     * 異常系: アクセス権外コース → 403
     */
    public function testAddInaccessibleCourse(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $otherAdmin = $this->createUser('admin02', ['role' => 'admin']);
        $course = $this->createCourse('他人のコース', (int)$otherAdmin->id);
        // admin01 にはコース権限なし

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'テスト',
            'kind' => 'markdown',
            'body' => 'test',
        ]);
        $this->assertResponseCode(403);
    }

    /**
     * 異常系: kind=markdown で body 未指定 → 400 バリデーションエラー
     */
    public function testAddMarkdownMissingBody(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'テスト',
            'kind' => 'markdown',
            // body なし
        ]);
        $this->assertResponseCode(400);

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Validation failed', $body['error']['message']);
    }

    /**
     * 異常系: kind=html で body 未指定 → 400
     */
    public function testAddHtmlMissingBody(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'テスト',
            'kind' => 'html',
            // body なし
        ]);
        $this->assertResponseCode(400);
    }

    /**
     * 異常系: 無効な kind → 400
     */
    public function testAddInvalidKind(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'テスト',
            'kind' => 'invalid_kind',
            'body' => 'test',
        ]);
        $this->assertResponseCode(400);
    }

    /**
     * 異常系: title 未指定 → 400
     */
    public function testAddMissingTitle(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'kind' => 'markdown',
            'body' => 'test',
        ]);
        $this->assertResponseCode(400);
    }

    // ----------------------------------------------------------------
    // PUT/PATCH /api/v1/contents/{id} (edit)
    // ----------------------------------------------------------------

    /**
     * 正常系: PUT で Markdown コンテンツ更新 → 200
     */
    public function testEditSuccess(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, [
            'kind' => 'markdown', 'body' => '# old',
        ]);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/' . $content->id, [
            'title' => '更新後タイトル',
            'body' => '# new',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('更新後タイトル', $body['data']['title']);
        $this->assertSame('# new', $body['data']['body']);
    }

    /**
     * 正常系: PATCH で部分更新 → 200
     */
    public function testEditPatchSuccess(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->patch('/api/v1/contents/' . $content->id, [
            'body' => '# patched',
        ]);

        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('# patched', $body['data']['body']);
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testEditNotFound(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/99999', ['title' => 'Not Found']);
        $this->assertResponseCode(404);
    }

    /**
     * 異常系: アクセス権外コース → 403
     */
    public function testEditInaccessibleCourse(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $otherAdmin = $this->createUser('admin02', ['role' => 'admin']);
        $course = $this->createCourse('他人のコース', (int)$otherAdmin->id);
        $content = $this->createContent((int)$course->id, (int)$otherAdmin->id);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/' . $content->id, ['title' => 'No Access']);
        $this->assertResponseCode(403);
    }

    /**
     * 異常系: 一般ユーザ → 403
     */
    public function testEditForbiddenForUser(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/' . $content->id, ['title' => 'Forbidden']);
        $this->assertResponseCode(403);
    }

    /**
     * 異常系: 更新フィールドなし → 400
     */
    public function testEditNoFields(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->put('/api/v1/contents/' . $content->id, []);
        $this->assertResponseCode(400);
    }

    // ----------------------------------------------------------------
    // DELETE /api/v1/contents/{id} (delete)
    // ----------------------------------------------------------------

    /**
     * 正常系: admin でコンテンツ削除 → 200 + DB 削除
     */
    public function testDeleteSuccess(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/contents/' . $content->id);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame((int)$content->id, $body['data']['id']);
        $this->assertTrue($body['data']['deleted']);

        // DB 確認
        $contentsTable = $this->getTableLocator()->get('Contents');
        $this->assertFalse(
            $contentsTable->exists(['id' => $content->id]),
            'ib_contents の該当行が削除されている'
        );
    }

    /**
     * 正常系: ContentsQuestions のカスケード削除
     */
    public function testDeleteCascadesContentsQuestions(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id, ['kind' => 'test']);

        // テスト問題を追加
        $cqTable = $this->getTableLocator()->get('ContentsQuestions');
        $cqEntity = $cqTable->newEntity([
            'content_id' => $content->id,
            'question' => 'テスト問題',
            'answer' => 1,
            'sort_no' => 1,
        ]);
        $cqTable->save($cqEntity);
        $this->assertTrue($cqTable->exists(['content_id' => $content->id]), '問題が作成されている');

        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/contents/' . $content->id);
        $this->assertResponseOk();

        // カスケード削除確認
        $this->assertFalse(
            $cqTable->exists(['content_id' => $content->id]),
            'ContentsQuestions がカスケード削除されている'
        );
    }

    /**
     * 異常系: 存在しない ID → 404
     */
    public function testDeleteNotFound(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $tokenData = $this->issueToken('admin01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/contents/99999');
        $this->assertResponseCode(404);
    }

    /**
     * 異常系: 一般ユーザ → 403
     */
    public function testDeleteForbiddenForUser(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $user = $this->createUser('user01', ['role' => 'user']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $content = $this->createContent((int)$course->id, (int)$admin->id);

        $tokenData = $this->issueToken('user01', 'testpass');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);

        $this->delete('/api/v1/contents/' . $content->id);
        $this->assertResponseCode(403);
    }

    // ----------------------------------------------------------------
    // 回帰: 既存 kind='' レコードが壊れないこと
    // ----------------------------------------------------------------

    /**
     * 既存 kind='' レコードの更新がバリデーションで失敗しないこと
     */
    public function testExistingEmptyKindNotBroken(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);

        // kind='' のレコードを直接作成（Entity を bypass して DB に直接投入）
        $conn = $this->getTableLocator()->get('Contents')->getConnection();
        $conn->execute(
            'INSERT INTO ib_contents (course_id, user_id, title, kind, status, sort_no, created) VALUES (:cid, :uid, :title, :kind, 1, 1, NOW())',
            ['cid' => $course->id, 'uid' => $admin->id, 'title' => '旧データ', 'kind' => '']
        );
        $insertedId = (int)$conn->execute('SELECT LAST_INSERT_ID() AS id')->fetch('assoc')['id'];

        $contentsTable = $this->getTableLocator()->get('Contents');
        $this->assertTrue($contentsTable->exists(['id' => $insertedId]), '旧レコードが存在する');

        // kind='' のまま body を更新 → バリデーションエラーにならないこと
        $entity = $contentsTable->get($insertedId);
        $entity->body = 'updated body';
        $result = $contentsTable->save($entity);
        $this->assertNotFalse($result, 'kind="" レコードの body 更新が成功する');
    }

    // ----------------------------------------------------------------
    // POST → PUT → DELETE の一連フロー
    // ----------------------------------------------------------------

    /**
     * 作成→更新→削除の一連が通ること
     */
    public function testAddEditDeleteFlow(): void
    {
        $admin = $this->createUser('admin01', ['role' => 'admin']);
        $course = $this->createCourse('テストコース', (int)$admin->id);
        $tokenData = $this->issueToken('admin01', 'testpass');

        // POST: 作成
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->post('/api/v1/contents', [
            'course_id' => $course->id,
            'title' => 'フローテスト',
            'kind' => 'markdown',
            'body' => '# original',
        ]);
        $this->assertResponseCode(201);
        $body = json_decode((string)$this->_response->getBody(), true);
        $contentId = $body['data']['id'];
        $this->assertSame('フローテスト', $body['data']['title']);

        // PUT: 更新
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->put('/api/v1/contents/' . $contentId, [
            'title' => '更新済み',
            'body' => '# updated',
        ]);
        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('更新済み', $body['data']['title']);
        $this->assertSame('# updated', $body['data']['body']);

        // DELETE: 削除
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->delete('/api/v1/contents/' . $contentId);
        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($body['data']['deleted']);

        // 削除後に GET → 404
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $tokenData['token']]]);
        $this->get('/api/v1/contents/' . $contentId);
        $this->assertResponseCode(404);
    }
}
```

> **要確認点**: D-2 の `testDeleteCascadesContentsQuestions` は `ContentsQuestions` テーブルに `question`, `answer` カラムが存在することを前提とする。`ContentsQuestionsTable` のスキーマを事前に確認すること。カラム名が異なる場合は `newEntity()` のフィールドを調整する。


---

## 付録E MCP 実装疑似コード

### 1. `src/Service/AccessControlService.php`

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionInterface;

/**
 * コースアクセス制御・ロール判定の共通サービス。
 * BaseController の生 SQL ロジックを移植し、MCP ツールからも利用可能にする。
 */
class AccessControlService
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * スタッフロール判定（BaseController::isStaff() と同一ロジック）
     */
    public function isStaff(string $role): bool
    {
        return in_array($role, ['admin', 'manager', 'editor', 'teacher'], true);
    }

    /**
     * ユーザーがアクセス可能なコース ID 一覧を返す。
     * ib_users_courses と ib_groups_courses/ib_users_groups の UNION。
     * BaseController::accessibleCourseIds() L394-421 と同一。
     */
    public function accessibleCourseIds(int $userId): array
    {
        $sql = '
            SELECT DISTINCT uc.course_id AS id
            FROM ib_users_courses AS uc
            WHERE uc.user_id = :userId
            UNION
            SELECT DISTINCT gc.course_id AS id
            FROM ib_groups_courses AS gc
            INNER JOIN ib_users_groups AS ug
                ON ug.group_id = gc.group_id
            WHERE ug.user_id = :userId
        ';
        $rows = $this->connection->execute($sql, ['userId' => $userId], ['userId' => 'integer'])->fetchAll('assoc');
        return array_column($rows, 'id');
    }

    /**
     * ユーザーが指定コースにアクセスできるか。
     */
    public function canAccessCourse(int $userId, int $courseId): bool
    {
        $ids = $this->accessibleCourseIds($userId);
        return in_array($courseId, $ids, true);
    }

    /**
     * ユーザーが所属するグループ ID 一覧。
     * BaseController::currentUserGroupIds() L429-446 と同一。
     */
    public function currentUserGroupIds(int $userId): array
    {
        $sql = '
            SELECT DISTINCT ug.group_id AS id
            FROM ib_users_groups AS ug
            WHERE ug.user_id = :userId
        ';
        $rows = $this->connection->execute($sql, ['userId' => $userId], ['userId' => 'integer'])->fetchAll('assoc');
        return array_column($rows, 'id');
    }
}
```

### 2. `UserTokensTable` — 失効しない照合メソッド追加 + `src/Mcp/IrohaTokenValidator.php`

**UserTokensTable 追加分（`src/Model/Table/UserTokensTable.php` に追記）:**

```php
    /**
     * API トークンを照合するが、失効しない（MCP ミドルウェア用）。
     * authenticateApiToken() と異なり、password_verify 失敗時にトークンを失効しない。
     *
     * @return array{user: array<string, mixed>, token_id: int}|null
     */
    public function lookupApiToken(string $tokenString): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        [$selector, $validator] = $this->parseCookie($tokenString);
        if ($selector === null || $validator === null) {
            return null;
        }

        $token = $this->find()
            ->where([
                'token_type' => 'api',
                'token_selector' => $selector,
            ])
            ->first();

        if ($token === null) {
            return null;
        }

        // 失効・有効期限チェック
        if ($token->revoked !== null || $token->expired < new \Cake\I18n\DateTime()) {
            return null;
        }

        // password_verify で照合。失敗してもトークンを失効しない。
        if (!password_verify($validator, $token->token_hash)) {
            return null; // 失効せず null を返すのみ
        }

        // ユーザー取得（FactoryLocator パターンを踏襲）
        $userTable = \Cake\ORM\TableRegistry::getTableLocator()->get('Users');
        $user = $userTable->find()
            ->where(['Users.id' => $token->user_id, 'Users.deleted IS NULL'])
            ->first();

        if ($user === null) {
            return null;
        }

        return [
            'user' => $user->toArray(),
            'token_id' => $token->id,
        ];
    }
```

**`src/Mcp/IrohaTokenValidator.php`:**

```php
<?php
declare(strict_types=1);

namespace App\Mcp;

use App\Model\Table\UserTokensTable;
// v0.8.1 確認済み FQCN（Mcp\Server\Transport\Http\OAuth\）
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;

/**
 * 既存 Bearer トークンで MCP 認証するバリデータ。
 * authenticateApiToken() ではなく lookupApiToken() を使い、
 * 毎リクエストの照合でトークンを失効しない。
 */
class IrohaTokenValidator implements AuthorizationTokenValidatorInterface
{
    public function __construct(
        private UserTokensTable $userTokensTable,
    ) {}

    /**
     * Bearer トークンの検証。IrohaAuthMiddleware から呼ぶ。
     *
     * @param string $token Bearer トークン文字列（selector:validator）
     * @return AuthorizationResult
     */
    public function validate(string $token): AuthorizationResult
    {
        $result = $this->userTokensTable->lookupApiToken($token);

        if ($result === null) {
            // v0.8.1 確認済み: unauthorized(?string $error, ?string $errorDescription, ?array $scopes)
            return AuthorizationResult::unauthorized(
                'invalid_token',
                'Token is invalid or expired',
            );
        }

        // v0.8.1 確認済み: allow(array $attributes = [])
        return AuthorizationResult::allow([
            'oauth.user_id' => $result['user']['id'],
            'oauth.role'    => $result['user']['role'],
            'oauth.name'    => $result['user']['name'],
            'oauth.token_id' => $result['token_id'],
        ]);
    }
}
```

### 3. `src/Mcp/IrohaAuthMiddleware.php`

```php
<?php
declare(strict_types=1);

namespace App\Mcp;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Bearer トークン検証ミドルウェア（v0.8.1 検証に基づく自作）。
 * SDK の AuthorizationMiddleware は使わない — ProtectedResourceMetadata に
 * authorizationServers が必須（AS 無しの自己発行トークンでは不適）。
 * 成功時は oauth.* 属性を PSR-7 request attributes に設定し、
 * OAuthRequestMetaMiddleware が params._meta.oauth へ転記する。
 */
class IrohaAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private IrohaTokenValidator $validator,
        private ?ResponseFactoryInterface $responseFactory = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $this->unauthorized('invalid_token', 'Missing bearer token');
        }

        $result = $this->validator->validate($m[1]);
        if (!$result->isAllowed()) {
            return $this->unauthorized(
                $result->getError() ?? 'invalid_token',
                $result->getErrorDescription() ?? 'Token is invalid or expired',
            );
        }

        foreach ($result->getAttributes() as $key => $value) {
            // キーは必ず 'oauth.' プレフィクス（OAuthRequestMetaMiddleware の抽出条件）
            $request = $request->withAttribute((string)$key, $value);
        }

        return $handler->handle($request);
    }

    private function unauthorized(string $error, string $description): ResponseInterface
    {
        $header = sprintf('Bearer error="%s", error_description="%s"', $error, $description);
        // CakePHP 5: Cake\Http\ResponseFactory をコンストラクタ注入で使用
        if ($this->responseFactory !== null) {
            return $this->responseFactory->createResponse(401)
                ->withHeader('WWW-Authenticate', $header);
        }
        // フォールバック: シンプルな PSR-7 レスポンス生成（Guzzle/Mezzio 等の ResponseFactory を推奨）
        return new \Nyholm\Psr7\Response(
            401,
            ['WWW-Authenticate' => $header],
            json_encode(['error' => $error, 'error_description' => $description]),
        );
    }
}
```

> **設計判断**: `ResponseFactoryInterface` をコンストラクタで optional 注入。CakePHP 5 の場合は `new Cake\Http\ResponseFactory()` を渡す。`$responseFactory === null` のフォールバックは `Nyholm\Psr7\Response`（PSR-7 標準テスト用ライブラリ。mcp/sdk の依存にも含まれる可能性がある）で生成するが、本番では `ResponseFactory` 注入を推奨する。

### 4. `src/Mcp/McpServerFactory.php`

```php
<?php
declare(strict_types=1);

namespace App\Mcp;

use App\Service\AccessControlService;
use App\Mcp\Tool\ListContentsTool;
use App\Mcp\Tool\GetContentTool;
use App\Mcp\Tool\GetContentHtmlTool;
use App\Mcp\Tool\CreateContentTool;
use App\Mcp\Tool\UpdateContentTool;
use App\Mcp\Tool\ListCoursesTool;
use App\Mcp\Tool\GetCourseTool;
use App\Mcp\Tool\ListRecordsTool;
use App\Mcp\Tool\GetUserProfileTool;
use Cake\Datasource\ConnectionInterface;
use Mcp\Server; // v0.8.1 確認済み: Server クラスの FQCN は Mcp\Server（クラス本体。namespace Mcp\Server とは別）

/**
 * MCP Server の構築・ツール登録を担当するファクトリ。
 *
 * pre-1.0 の mcp/sdk への依存は src/Mcp/ に閉じ込め、
 * アプリケーション層（Controller/Service）からの直接依存を避ける。
 */
class McpServerFactory
{
    /**
     * ツール登録済みの Server インスタンスを生成する。
     */
    public function create(
        AccessControlService $accessControl,
        ConnectionInterface $connection,
    ): Server {
        $server = Server::builder() // v0.8.1 確認済み: Server::builder()
            ->setServerInfo('iroha Board MCP', '1.0.0')
            ->setDiscovery(__DIR__ . '/Tool', ['.'], excludeDirs: ['vendor']) // v0.8.1 確認済み: setDiscovery と addTool は併用可（ローダー順: explicit → addTool → discovery、同名衝突は手動登録が優先）。discovery は #[McpTool] 属性をスキャンする
            // --- Read-Only ツール ---
            // addTool(instance) の name/description 省略時は #[McpTool] 属性から解決。明示引数を渡した場合は明示が優先（v0.8.1 確認済み）。inputSchema を明示する場合は addTool(..., inputSchema: [...]) で渡す
            ->addTool(new ListCoursesTool($accessControl))
            ->addTool(new GetCourseTool($accessControl))
            ->addTool(new ListContentsTool($accessControl))
            ->addTool(new GetContentTool($accessControl))
            ->addTool(new GetContentHtmlTool($accessControl))
            ->addTool(new ListRecordsTool($accessControl))
            ->addTool(new GetUserProfileTool())
            // --- Write ツール（Phase 3） ---
            ->addTool(new CreateContentTool($accessControl))
            ->addTool(new UpdateContentTool($accessControl))
            ->build();

        return $server;
    }
}
```

### 5. `src/Controller/McpController.php`

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Mcp\IrohaTokenValidator;
use App\Mcp\IrohaAuthMiddleware;
use App\Mcp\McpServerFactory;
use App\Service\AccessControlService;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Controller\Controller;
use Cake\ORM\TableRegistry;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware;
use Psr\Http\Message\ResponseInterface as PsrResponse;

/**
 * MCP エンドポイントコントローラ。
 *
 * BaseController を継承しない（CSRF/Authentication ミドルウェアの干渉を避ける）。
 * Bearer 認証は自作 IrohaAuthMiddleware で完結させる。
 */
class McpController extends Controller
{
    /**
     * CSRF を無効化（Application.php で /mcp をスキップ済みだが念のため）
     */
    public function beforeFilter(\Cake\Event\EventInterface $event): void
    {
        // CSRF チェックスキップ（Application.php の skipCheckCallback が /mcp を弾くが、
        // Controller レベルでも安全に無効化）
        if ($this->components()->has('CsrfProtection')) {
            $this->components()->get('CsrfProtection')->skipCheck();
        }

        parent::beforeFilter($event);
    }

    /**
     * POST /mcp — MCP エンドポイント
     *
     * 認証: Bearer トークン → IrohaTokenValidator → IrohaAuthMiddleware
     * ツールハンドラへの user_id 伝播: OAuthRequestMetaMiddleware 経由
     */
    public function endpoint(): Response
    {
        // --- 1. PSR-7 リクエストの取得（CakePHP 5 は PSR-7 ネイティブ実装） ---
        /** @var ServerRequest $request CakePHP ServerRequest（PSR-7 実装） */
        $request = $this->request;

        // --- 2. トークンバリデータの生成 ---
        $userTokensTable = TableRegistry::getTableLocator()->get('UserTokens');
        $validator = new IrohaTokenValidator($userTokensTable);

        // --- 3. MCP ミドルウェアスタック構築 ---
        $transportMiddleware = [
            // v0.8.1 確認済み: defaultMiddleware() は CorsMiddleware + DnsRebindingProtectionMiddleware。middleware: null の場合も同様に遅延適用される
            ...StreamableHttpTransport::defaultMiddleware(),
            // Bearer 認証（自作ミドルウェア — SDK の AuthorizationMiddleware は使わない）
            new IrohaAuthMiddleware($validator),
            // oauth.* → JSON-RPC _meta.oauth への橋渡し
            new OAuthRequestMetaMiddleware(),
        ];

        // --- 4. Transport 生成 ---
        $transport = new StreamableHttpTransport(
            request: $request,
            middleware: $transportMiddleware,
        );

        // --- 5. Server 生成 & 実行 ---
        $accessControl = new AccessControlService(
            TableRegistry::getTableLocator()->get('Contents')->getConnection(),
        );
        $factory = new McpServerFactory();
        $server = $factory->create($accessControl, $accessControl->getConnection()); // 要確認: getConnection は AccessControlService に追加するか直接渡す

        /** @var PsrResponse $psrResponse */
        $psrResponse = $server->run($transport);

        // --- 6. PSR-7 → Cake Response 変換（全ヘッダ転記） ---
        return $this->convertToCakeResponse($psrResponse);
    }

    /**
     * PSR-7 Response → CakePHP Response への変換。
     * ヘッダは array<string, string[]> 形式で、値ごとに withHeader/withAddedHeader で転記。
     *
     * PHP-FPM 対策: text/event-stream (SSE) の場合は出力をバッファリングして
     * まとめて返す。fastcgi_finish_request() は CakePHP のレスポンスフローと
     * 矛盾するため使用せず、SSE はストリーミング非対応でバッファリング返却にする。
     */
    private function convertToCakeResponse(PsrResponse $psrResponse): Response
    {
        $body = (string) $psrResponse->getBody();
        $statusCode = $psrResponse->getStatusCode();

        // CakePHP レスポンス構築
        $response = new Response();

        // ヘッダ転記: getHeaders() は ['Header-Name' => ['value1', 'value2']]
        foreach ($psrResponse->getHeaders() as $name => $values) {
            // 最初の値は withHeader で設定（既存ヘッダを上書き）
            $response = $response->withHeader($name, array_shift($values));
            // 残りの値は withAddedHeader で追加
            foreach ($values as $value) {
                $response = $response->withAddedHeader($name, $value);
            }
        }

        $response = $response->withStatus($statusCode)
            ->withStringBody($body);

        // PHP-FPM: SSE レスポンスの場合のガード
        // StreamableHttpTransport が text/event-stream を返す場合があるが、
        // PHP-FPM では出力バッファリングが効くため、CakePHP の
        // レスポンスフローでバッファリング返却となる。問題なし。
        // fastcgi_finish_request() は CakePHP のミドルウェアチェーンと
        // 矛盾するため使用しない。

        return $response;
    }
}
```

### 6. `config/routes.php` 差分 + `src/Application.php` 差分

**`config/routes.php` — `/mcp` スコープ追加（既存の API v1 スコープの後に追記）:**

```php
    // --- MCP エンドポイント（Phase 2） ---
    // CSRF は Application.php で /mcp をスキップ済み
    $routes->scope('/mcp', function (\Cake\Routing\RouteBuilder $builder) {
        // MCP は単一 POST エンドポイント。DELETE はセッション切断用（Phase 3）。
        $builder->post('/', ['controller' => 'Mcp', 'action' => 'endpoint'], 'mcp:post');
        $builder->delete('/', ['controller' => 'Mcp', 'action' => 'deleteSession'], 'mcp:delete');
        $builder->options('/', ['controller' => 'Mcp', 'action' => 'options'], 'mcp:options');
    });
```

**`src/Application.php` — CSRF スキップ変更:**

```php
    // L121 の str_starts_with チェックを修正
    // 変更前:
    // if (str_starts_with($uri, '/api/')) {
    // 変更後:
    if (str_starts_with($uri, '/api/') || str_starts_with($uri, '/mcp')) {
        return true;
    }
```

### 7. 代表ツール 3 つ（`list_contents` / `get_content` / `create_content`）

全ツール共通前提:
- `#[McpTool]` 属性の正確な FQCN（`Mcp\Capability\Attribute\McpTool`）
- `#[Schema]` 属性（`Mcp\Capability\Attribute\Schema`、TARGET_METHOD|TARGET_PARAMETER）で引数説明・enum を指定可能
- ハンドラは `Mcp\Server\RequestContext` を型宣言すると自動注入
- `oauth.user_id` は `$context->getRequest()->getMeta()['oauth']['oauth.user_id']` で取得

**`src/Mcp/Tool/ListContentsTool.php`:**

```php
<?php
declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Service\AccessControlService;
use App\Model\Table\ContentsTable;
use App\Model\Table\CoursesTable;
use Cake\ORM\TableRegistry;
use Mcp\Capability\Attribute\McpTool; // v0.8.1 確認済み
use Mcp\Server\RequestContext;

class ListContentsTool
{
    public function __construct(
        private AccessControlService $accessControl,
    ) {}

    #[McpTool(
        name: 'list_contents',
        description: '指定コースのコンテンツ一覧を返す。page でページネーション可能。',
        // v0.8.1: #[McpTool] に inputSchema パラメータ無し。引数説明・enum はパラメータ属性 #[Schema(description: ..., enum: [...])] または addTool(..., inputSchema: [...]) で明示（自動生成は plain string に enum を付けない）
    )]
    public function __invoke(RequestContext $context, int $course_id, int $page = 1, int $limit = 20): array
    {
        // 1. 認証ユーザー取得
        $userId = $this->getUserId($context);

        // 2. コースアクセス権チェック
        if (!$this->accessControl->canAccessCourse($userId, $course_id)) {
            return ['error' => 'Access denied to this course.'];
        }

        // 3. クエリ
        /** @var ContentsTable $contentsTable */
        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $offset = max(0, ($page - 1) * $limit);
        $total = $contentsTable->find()
            ->where(['course_id' => $course_id, 'deleted IS NULL'])
            ->count();

        $rows = $contentsTable->find()
            ->select(['id', 'course_id', 'title', 'kind', 'status', 'sort_no', 'created'])
            ->where(['course_id' => $course_id, 'deleted IS NULL'])
            ->order(['sort_no' => 'ASC'])
            ->limit($limit)
            ->offset($offset)
            ->toArray();

        return [
            'data' => $rows,
            'meta' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
            ],
        ];
    }

    private function getUserId(RequestContext $context): int
    {
        $meta = $context->getRequest()->getMeta();
        return (int)($meta['oauth']['oauth.user_id'] ?? 0);
    }
}
```

**`src/Mcp/Tool/GetContentTool.php`:**

```php
<?php
declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Service\AccessControlService;
use App\Model\Table\ContentsTable;
use Cake\ORM\TableRegistry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

class GetContentTool
{
    public function __construct(
        private AccessControlService $accessControl,
    ) {}

    #[McpTool(
        name: 'get_content',
        description: 'コンテンツのメタデータ＋本文を返す。kind=html は未サニタイズの生 HTML を含む（Phase 3 で対応）。',
    )]
    public function __invoke(RequestContext $context, int $content_id): array
    {
        $userId = $this->getUserId($context);

        /** @var ContentsTable $contentsTable */
        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $content = $contentsTable->get($content_id, contain: ['Courses']);

        if ($content === null) {
            return ['error' => 'Content not found.'];
        }

        // コースアクセス権チェック
        if (!$this->accessControl->canAccessCourse($userId, $content->course_id)) {
            return ['error' => 'Access denied to this content.'];
        }

        return [
            'data' => [
                'id'         => $content->id,
                'course_id'  => $content->course_id,
                'title'      => $content->title,
                'kind'       => $content->kind,
                'body'       => $content->body,
                'status'     => $content->status,
                'sort_no'    => $content->sort_no,
                'created'    => $content->created,
                // kind=html の body は未サニタイズ。
                // Phase 3 で HTMLPurifier を適用する。
                // kind=markdown の場合はここでは Markdown 原文を返す。
                // HTML 変換は get_content_html を使うこと。
            ],
        ];
    }

    private function getUserId(RequestContext $context): int
    {
        $meta = $context->getRequest()->getMeta();
        return (int)($meta['oauth']['oauth.user_id'] ?? 0);
    }
}
```

**`src/Mcp/Tool/GetContentHtmlTool.php`:**

```php
<?php
declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Service\AccessControlService;
use App\Model\Table\ContentsTable;
use App\View\Helper\MarkdownHelper;
use Cake\ORM\TableRegistry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

class GetContentHtmlTool
{
    public function __construct(
        private AccessControlService $accessControl,
    ) {}

    #[McpTool(
        name: 'get_content_html',
        description: 'コンテンツをレンダリングした HTML を返す。kind=markdown は Markdown→HTML 変換＋サニタイズ。kind=html は生 HTML（未サニタイズ）。',
    )]
    public function __invoke(RequestContext $context, int $content_id): array
    {
        $userId = $this->getUserId($context);

        /** @var ContentsTable $contentsTable */
        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $content = $contentsTable->get($content_id, contain: ['Courses']);

        if ($content === null) {
            return ['error' => 'Content not found.'];
        }

        if (!$this->accessControl->canAccessCourse($userId, $content->course_id)) {
            return ['error' => 'Access denied to this content.'];
        }

        $body = $content->body ?? '';
        $html = match ($content->kind) {
            'markdown' => (new MarkdownHelper())->text($body),
            'html'     => $body, // ⚠️ 未サニタイズ。Phase 3 で HTMLPurifier を適用する。
            'text'     => nl2br(h($body)),
            default    => h($body),
        };

        return [
            'data' => [
                'id'   => $content->id,
                'html' => $html,
            ],
        ];
    }

    private function getUserId(RequestContext $context): int
    {
        $meta = $context->getRequest()->getMeta();
        return (int)($meta['oauth']['oauth.user_id'] ?? 0);
    }
}
```

**`src/Mcp/Tool/CreateContentTool.php`:**

```php
<?php
declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Service\AccessControlService;
use App\Model\Table\ContentsTable;
use Cake\ORM\TableRegistry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

class CreateContentTool
{
    public function __construct(
        private AccessControlService $accessControl,
    ) {}

    #[McpTool(
        name: 'create_content',
        description: '新規コンテンツを作成する（スタッフのみ）。kind=markdown の場合、body は Markdown 原文。',
    )]
    public function __invoke(
        RequestContext $context,
        int $course_id,
        string $title,
        // enum は #[Schema] で明示（plain string からは自動生成されない — v0.8.1）
        #[Schema(enum: ['label', 'html', 'movie', 'url', 'file', 'text', 'markdown'])]
        string $kind,
        string $body = '',
        int $status = 0,
    ): array {
        $userId = $this->getUserId($context);
        $role   = $this->getRole($context);

        // スタッフのみ
        if (!$this->accessControl->isStaff($role)) {
            return ['error' => 'Only staff members can create content.'];
        }

        // コースアクセス権チェック
        if (!$this->accessControl->canAccessCourse($userId, $course_id)) {
            return ['error' => 'Access denied to this course.'];
        }

        /** @var ContentsTable $contentsTable */
        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $nextSortNo = $contentsTable->getNextSortNo($course_id);

        $entity = $contentsTable->newEntity([
            'course_id' => $course_id,
            'user_id'   => $userId,
            'title'     => $title,
            'kind'      => $kind,
            'body'      => $body,
            'status'    => $status,
            'sort_no'   => $nextSortNo,
        ]);

        if ($entity->hasErrors()) {
            return ['error' => 'Validation failed.', 'details' => $entity->getErrors()];
        }

        $contentsTable->save($entity);

        return [
            'data' => [
                'id'        => $entity->id,
                'course_id' => $entity->course_id,
                'title'     => $entity->title,
                'kind'      => $entity->kind,
                'status'    => $entity->status,
                'sort_no'   => $entity->sort_no,
            ],
        ];
    }

    private function getUserId(RequestContext $context): int
    {
        $meta = $context->getRequest()->getMeta();
        return (int)($meta['oauth']['oauth.user_id'] ?? 0);
    }

    private function getRole(RequestContext $context): string
    {
        $meta = $context->getRequest()->getMeta();
        return (string)($meta['oauth']['oauth.role'] ?? '');
    }
}
```

**`src/Mcp/Tool/UpdateContentTool.php`:**

```php
<?php
declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Service\AccessControlService;
use App\Model\Table\ContentsTable;
use Cake\ORM\TableRegistry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

class UpdateContentTool
{
    public function __construct(
        private AccessControlService $accessControl,
    ) {}

    #[McpTool(
        name: 'update_content',
        description: '既存コンテンツを更新する（スタッフのみ）。指定されたフィールドのみ部分更新。',
    )]
    public function __invoke(
        RequestContext $context,
        int $content_id,
        ?string $title = null,
        // enum は #[Schema] で明示（plain string からは自動生成されない — v0.8.1）
        #[Schema(enum: ['label', 'html', 'movie', 'url', 'file', 'text', 'markdown'])]
        ?string $kind = null,
        ?string $body = null,
        ?int $status = null,
    ): array {
        $userId = $this->getUserId($context);
        $role   = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            return ['error' => 'Only staff members can update content.'];
        }

        /** @var ContentsTable $contentsTable */
        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $content = $contentsTable->get($content_id);

        if ($content === null) {
            return ['error' => 'Content not found.'];
        }

        if (!$this->accessControl->canAccessCourse($userId, $content->course_id)) {
            return ['error' => 'Access denied to this content.'];
        }

        // 部分更新: null でないフィールドのみセット
        $patchData = [];
        if ($title !== null)   $patchData['title']  = $title;
        if ($kind !== null)    $patchData['kind']   = $kind;
        if ($body !== null)    $patchData['body']   = $body;
        if ($status !== null)  $patchData['status'] = $status;

        if (empty($patchData)) {
            return ['error' => 'No fields to update.'];
        }

        $entity = $contentsTable->patchEntity($content, $patchData);

        if ($entity->hasErrors()) {
            return ['error' => 'Validation failed.', 'details' => $entity->getErrors()];
        }

        $contentsTable->save($entity);

        return [
            'data' => [
                'id'        => $entity->id,
                'course_id' => $entity->course_id,
                'title'     => $entity->title,
                'kind'      => $entity->kind,
                'status'    => $entity->status,
                'sort_no'   => $entity->sort_no,
            ],
        ];
    }

    private function getUserId(RequestContext $context): int
    {
        $meta = $context->getRequest()->getMeta();
        return (int)($meta['oauth']['oauth.user_id'] ?? 0);
    }

    private function getRole(RequestContext $context): string
    {
        $meta = $context->getRequest()->getMeta();
        return (string)($meta['oauth']['oauth.role'] ?? '');
    }
}
```

### 8. `composer.json` 依存追加

```bash
composer require league/commonmark ezyang/htmlpurifier mcp/sdk
```


---

## 付録F MCP テストケース

### `tests/TestCase/Service/AccessControlServiceTest.php`

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\AccessControlService;
use Cake\TestSuite\TestCase;

class AccessControlServiceTest extends TestCase
{
    protected AccessControlService $service;

    public function setUp(): void
    {
        parent::setUp();
        $connection = $this->getTableLocator()->get('Contents')->getConnection();
        $this->service = new AccessControlService($connection);

        // テストデータクリーンアップ
        $this->getTableLocator()->get('UsersGroups')->deleteAll('1 = 1');
        $this->getTableLocator()->get('GroupsCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Groups')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Courses')->deleteAll('1 = 1');
    }

    public function testIsStaffAdmin(): void
    {
        $this->assertTrue($this->service->isStaff('admin'));
    }

    public function testIsStaffUser(): void
    {
        $this->assertFalse($this->service->isStaff('user'));
    }

    public function testAccessibleCourseIdsDirectEnrollment(): void
    {
        // Arrange: ユーザー1にコース1を直接紐付け
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->newEntity([
            'username' => 'testuser1', 'name' => 'Test', 'role' => 'user',
            'password' => 'password123',
        ]);
        $usersTable->save($user);

        $coursesTable = $this->getTableLocator()->get('Courses');
        $course = $coursesTable->newEntity([
            'title' => 'Course A', 'user_id' => $user->id, 'sort_no' => 1,
        ]);
        $coursesTable->save($course);

        $ucTable = $this->getTableLocator()->get('UsersCourses');
        $uc = $ucTable->newEntity(['user_id' => $user->id, 'course_id' => $course->id]);
        $ucTable->save($uc);

        // Act
        $ids = $this->service->accessibleCourseIds($user->id);

        // Assert
        $this->assertContains($course->id, $ids);
    }

    public function testAccessibleCourseIdsViaGroup(): void
    {
        // Arrange: ユーザー→グループ→コース経由
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->newEntity([
            'username' => 'testuser2', 'name' => 'Test2', 'role' => 'user',
            'password' => 'password123',
        ]);
        $usersTable->save($user);

        $groupsTable = $this->getTableLocator()->get('Groups');
        $group = $groupsTable->newEntity(['name' => 'Group A', 'user_id' => $user->id]);
        $groupsTable->save($group);

        $coursesTable = $this->getTableLocator()->get('Courses');
        $course = $coursesTable->newEntity([
            'title' => 'Course B', 'user_id' => $user->id, 'sort_no' => 1,
        ]);
        $coursesTable->save($course);

        $ugTable = $this->getTableLocator()->get('UsersGroups');
        $ugTable->save($ugTable->newEntity(['user_id' => $user->id, 'group_id' => $group->id]));

        $gcTable = $this->getTableLocator()->get('GroupsCourses');
        $gcTable->save($gcTable->newEntity(['group_id' => $group->id, 'course_id' => $course->id]));

        // Act
        $ids = $this->service->accessibleCourseIds($user->id);

        // Assert
        $this->assertContains($course->id, $ids);
    }

    public function testCanAccessCourseGranted(): void
    {
        // (上記と同様の Arrange)
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->newEntity([
            'username' => 'testuser3', 'name' => 'Test3', 'role' => 'user',
            'password' => 'password123',
        ]);
        $usersTable->save($user);

        $coursesTable = $this->getTableLocator()->get('Courses');
        $course = $coursesTable->newEntity([
            'title' => 'Course C', 'user_id' => $user->id, 'sort_no' => 1,
        ]);
        $coursesTable->save($course);

        $ucTable = $this->getTableLocator()->get('UsersCourses');
        $ucTable->save($ucTable->newEntity(['user_id' => $user->id, 'course_id' => $course->id]));

        // Act & Assert
        $this->assertTrue($this->service->canAccessCourse($user->id, $course->id));
    }

    public function testCanAccessCourseDenied(): void
    {
        // Arrange: ユーザーとコースは存在するが紐付けなし
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->newEntity([
            'username' => 'testuser4', 'name' => 'Test4', 'role' => 'user',
            'password' => 'password123',
        ]);
        $usersTable->save($user);

        $coursesTable = $this->getTableLocator()->get('Courses');
        $course = $coursesTable->newEntity([
            'title' => 'Course D', 'user_id' => 999, 'sort_no' => 1,
        ]);
        $coursesTable->save($course);

        // Act & Assert
        $this->assertFalse($this->service->canAccessCourse($user->id, $course->id));
    }
}
```

### `tests/TestCase/Mcp/IrohaTokenValidatorTest.php`

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Mcp;

use App\Mcp\IrohaTokenValidator;
use App\Model\Table\UserTokensTable;
use Cake\TestSuite\TestCase;
use Cake\ORM\TableRegistry;

class IrohaTokenValidatorTest extends TestCase
{
    protected UserTokensTable $userTokensTable;
    protected IrohaTokenValidator $validator;

    public function setUp(): void
    {
        parent::setUp();
        $this->userTokensTable = TableRegistry::getTableLocator()->get('UserTokens');
        $this->validator = new IrohaTokenValidator($this->userTokensTable);

        // テストデータクリーンアップ
        $this->userTokensTable->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
    }

    public function testValidTokenReturnsAllow(): void
    {
        // Arrange: ユーザーとトークンを作成
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->newEntity([
            'username' => 'mcpuser1', 'name' => 'MCP User', 'role' => 'admin',
            'password' => 'securepass123',
        ]);
        $usersTable->save($user);

        // issueApiToken でトークン生成（selector:validator 形式）
        $tokenString = $this->userTokensTable->issueApiToken($user->id, 30, false);

        // Act
        $result = $this->validator->validate($tokenString);

        // Assert
        // 要確認: AuthorizationResult の型。allow の場合は内部に attributes が入る
        $this->assertNotNull($result);
        // token の失効副作用がないことを確認（再照合しても有効）
        $result2 = $this->validator->validate($tokenString);
        $this->assertNotNull($result2);
    }

    public function testInvalidTokenReturnsUnauthorized(): void
    {
        // Act
        $result = $this->validator->validate('invalid-token-string');

        // Assert
        $this->assertNotNull($result);
        // 要確認: AuthorizationResult::unauthorized の場合の判定方法
    }

    public function testRevokedTokenReturnsUnauthorized(): void
    {
        // Arrange
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->newEntity([
            'username' => 'mcpuser2', 'name' => 'MCP User 2', 'role' => 'user',
            'password' => 'securepass123',
        ]);
        $usersTable->save($user);

        $tokenString = $this->userTokensTable->issueApiToken($user->id, 30, false);

        // トークンを失効
        $parsed = $this->userTokensTable->parseCookie($tokenString);
        $tokenEntity = $this->userTokensTable->find()
            ->where(['token_selector' => $parsed['selector']])
            ->first();
        $tokenEntity->revoked = new \Cake\I18n\DateTime();
        $this->userTokensTable->save($tokenEntity);

        // Act
        $result = $this->validator->validate($tokenString);

        // Assert
        $this->assertNotNull($result);
    }

    public function testLookupApiTokenDoesNotRevokeOnFailure(): void
    {
        // Arrange
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->newEntity([
            'username' => 'mcpuser3', 'name' => 'MCP User 3', 'role' => 'user',
            'password' => 'securepass123',
        ]);
        $usersTable->save($user);

        $tokenString = $this->userTokensTable->issueApiToken($user->id, 30, false);

        // 不正な validator で照合（selector は正しい、validator はダミー）
        $parsed = $this->userTokensTable->parseCookie($tokenString);
        $badToken = $parsed['selector'] . ':' . str_repeat('x', 64);

        // Act
        $result = $this->userTokensTable->lookupApiToken($badToken);

        // Assert: null が返る
        $this->assertNull($result);

        // トークンが失効されていないことを確認
        $tokenEntity = $this->userTokensTable->find()
            ->where(['token_selector' => $parsed['selector']])
            ->first();
        $this->assertNotNull($tokenEntity);
        $this->assertNull($tokenEntity->revoked, 'Token should NOT be revoked by lookupApiToken');
    }
}
```

### `tests/TestCase/Controller/McpControllerTest.php`

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Table\UserTokensTable;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class McpControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected UserTokensTable $userTokensTable;

    public function setUp(): void
    {
        parent::setUp();
        $this->userTokensTable = $this->getTableLocator()->get('UserTokens');

        // 全テーブルクリーンアップ
        $this->userTokensTable->deleteAll('1 = 1');
        $this->getTableLocator()->get('Records')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Contents')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Courses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
    }

    // --- ヘルパーメソッド ---

    private function createUser(string $username = 'testuser', string $role = 'user'): array
    {
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->newEntity([
            'username' => $username,
            'name' => 'Test User',
            'role' => $role,
            'password' => 'password123',
        ]);
        $usersTable->save($user);
        return $user->toArray();
    }

    private function issueToken(int $userId): string
    {
        return $this->userTokensTable->issueApiToken($userId, 30, false);
    }

    private function createCourse(int $userId, string $title = 'Test Course'): array
    {
        $coursesTable = $this->getTableLocator()->get('Courses');
        $course = $coursesTable->newEntity([
            'title' => $title, 'user_id' => $userId, 'sort_no' => 1,
        ]);
        $coursesTable->save($course);
        return $course->toArray();
    }

    private function createContent(int $courseId, int $userId, array $overrides = []): array
    {
        $contentsTable = $this->getTableLocator()->get('Contents');
        $entity = $contentsTable->newEntity(array_merge([
            'course_id' => $courseId,
            'user_id' => $userId,
            'title' => 'Test Content',
            'kind' => 'text',
            'body' => 'Hello world',
            'status' => 1,
            'sort_no' => 1,
        ], $overrides));
        $contentsTable->save($entity);
        return $entity->toArray();
    }

    // --- テストケース ---

    public function testUnauthenticatedReturns401(): void
    {
        // Arrange: Bearer トークンなし
        $this->configRequest([
            'headers' => ['Accept' => 'application/json'],
        ]);

        // Act: JSON-RPC initialize リクエスト
        $this->post('/mcp', json_encode([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
            'id' => 1,
        ]));

        // Assert: 401 Unauthorized
        $this->assertResponseCode(401);
    }

    public function testInvalidTokenReturns401(): void
    {
        // Arrange: 不正なトークン
        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer invalid-token',
                'Accept' => 'application/json',
            ],
        ]);

        // Act
        $this->post('/mcp', json_encode([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
            'id' => 1,
        ]));

        // Assert
        $this->assertResponseCode(401);
    }

    public function testInitializeReturnsCapabilities(): void
    {
        // Arrange
        $user = $this->createUser('mcpuser', 'admin');
        $token = $this->issueToken($user['id']);

        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);

        // Act
        $this->post('/mcp', json_encode([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
            'id' => 1,
        ]));

        // Assert: 200 OK + JSON-RPC レスポンス
        $this->assertResponseOk();
        $result = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('serverInfo', $result['result']);
        $this->assertSame('iroha Board MCP', $result['result']['serverInfo']['name']);
    }

    public function testToolsListReturnsToolDefinitions(): void
    {
        // Arrange
        $user = $this->createUser('mcpuser2', 'admin');
        $token = $this->issueToken($user['id']);

        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);

        // Act
        $this->post('/mcp', json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'params' => (object) [],
            'id' => 2,
        ]));

        // Assert
        $this->assertResponseOk();
        $result = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('tools', $result['result']);
        $toolNames = array_column($result['result']['tools'], 'name');
        $this->assertContains('list_contents', $toolNames);
        $this->assertContains('get_content', $toolNames);
        $this->assertContains('create_content', $toolNames);
    }

    public function testListContentsReturnsDataForAccessibleCourse(): void
    {
        // Arrange
        $user = $this->createUser('mcpuser3', 'user');
        $token = $this->issueToken($user['id']);
        $course = $this->createCourse($user['id']);
        $this->createContent($course['id'], $user['id']);

        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);

        // Act
        $this->post('/mcp', json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_contents',
                'arguments' => ['course_id' => $course['id']],
            ],
            'id' => 3,
        ]));

        // Assert
        $this->assertResponseOk();
        $result = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('content', $result['result']);
    }

    public function testListContentsDeniedForInaccessibleCourse(): void
    {
        // Arrange: ユーザーはコースに紐付けなし
        $user = $this->createUser('mcpuser4', 'user');
        $token = $this->issueToken($user['id']);

        // 別ユーザーのコース
        $otherUser = $this->createUser('otheruser', 'admin');
        $course = $this->createCourse($otherUser['id']);

        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);

        // Act
        $this->post('/mcp', json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_contents',
                'arguments' => ['course_id' => $course['id']],
            ],
            'id' => 4,
        ]));

        // Assert: エラーメッセージを含む
        $this->assertResponseOk();
        $result = json_decode((string)$this->_response->getBody(), true);
        $text = $result['result']['content'][0]['text'] ?? '';
        $this->assertStringContainsString('Access denied', $text);
    }

    public function testCreateContentDeniedForNonStaff(): void
    {
        // Arrange
        $user = $this->createUser('mcpuser5', 'user');
        $token = $this->issueToken($user['id']);
        $course = $this->createCourse($user['id']);

        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);

        // Act
        $this->post('/mcp', json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_content',
                'arguments' => [
                    'course_id' => $course['id'],
                    'title' => 'New Content',
                    'kind' => 'markdown',
                    'body' => '# Hello',
                ],
            ],
            'id' => 5,
        ]));

        // Assert
        $this->assertResponseOk();
        $result = json_decode((string)$this->_response->getBody(), true);
        $text = $result['result']['content'][0]['text'] ?? '';
        $this->assertStringContainsString('Only staff', $text);
    }

    public function testCreateContentSucceedsForStaff(): void
    {
        // Arrange
        $user = $this->createUser('mcpuser6', 'admin');
        $token = $this->issueToken($user['id']);
        $course = $this->createCourse($user['id']);

        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);

        // Act
        $this->post('/mcp', json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_content',
                'arguments' => [
                    'course_id' => $course['id'],
                    'title' => 'Staff Content',
                    'kind' => 'markdown',
                    'body' => '# Markdown Content',
                    'status' => 1,
                ],
            ],
            'id' => 6,
        ]));

        // Assert
        $this->assertResponseOk();
        $result = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('result', $result);
        // 作成されたコンテンツの ID が返されることを確認
        $text = $result['result']['content'][0]['text'] ?? '';
        $this->assertJson($text);
        $data = json_decode($text, true);
        $this->assertArrayHasKey('id', $data['data'] ?? []);
    }

    public function testResponseContentTypeHeaderPreserved(): void
    {
        // Arrange
        $user = $this->createUser('mcpuser7', 'admin');
        $token = $this->issueToken($user['id']);

        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);

        // Act
        $this->post('/mcp', json_encode([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
            'id' => 7,
        ]));

        // Assert: Content-Type ヘッダが保持されている
        $this->assertResponseOk();
        $contentType = $this->_response->getHeaderLine('Content-Type');
        $this->assertNotEmpty($contentType, 'Content-Type header should be preserved');
    }
}
```

### `tests/TestCase/Mcp/Tool/ListContentsToolTest.php`

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Mcp\Tool;

use App\Mcp\Tool\ListContentsTool;
use App\Service\AccessControlService;
use App\Model\Table\ContentsTable;
use Cake\TestSuite\TestCase;
use Cake\ORM\TableRegistry;

class ListContentsToolTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->getTableLocator()->get('Contents')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersCourses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Courses')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
    }

    public function testListContentsReturnsPaginatedResults(): void
    {
        // Arrange
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->newEntity([
            'username' => 'tooluser1', 'name' => 'Tool User', 'role' => 'user',
            'password' => 'password123',
        ]);
        $usersTable->save($user);

        $coursesTable = $this->getTableLocator()->get('Courses');
        $course = $coursesTable->newEntity([
            'title' => 'Tool Course', 'user_id' => $user->id, 'sort_no' => 1,
        ]);
        $coursesTable->save($course);

        $ucTable = $this->getTableLocator()->get('UsersCourses');
        $ucTable->save($ucTable->newEntity(['user_id' => $user->id, 'course_id' => $course->id]));

        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        for ($i = 0; $i < 5; $i++) {
            $contentsTable->save($contentsTable->newEntity([
                'course_id' => $course->id, 'user_id' => $user->id,
                'title' => "Content $i", 'kind' => 'text', 'body' => "Body $i",
                'status' => 1, 'sort_no' => $i,
            ]));
        }

        $connection = $contentsTable->getConnection();
        $accessControl = new AccessControlService($connection);
        $tool = new ListContentsTool($accessControl);

        // RequestContext モック（要確認: MCP SDK の RequestContext の構造）
        $context = $this->createMock(\Mcp\Server\RequestContext::class);
        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getMeta')->willReturn([
            'oauth' => ['oauth.user_id' => $user->id],
        ]);
        $context->method('getRequest')->willReturn($request);

        // Act
        $result = $tool($context, course_id: $course->id);

        // Assert
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(5, $result['data']);
        $this->assertArrayHasKey('meta', $result);
        $this->assertSame(1, $result['meta']['page']);
        $this->assertSame(5, $result['meta']['total']);
    }

    public function testListContentsDeniedForInaccessibleCourse(): void
    {
        // Arrange: ユーザーはコースに紐付けなし
        $usersTable = $this->getTableLocator()->get('Users');
        $user = $usersTable->newEntity([
            'username' => 'tooluser2', 'name' => 'Tool User 2', 'role' => 'user',
            'password' => 'password123',
        ]);
        $usersTable->save($user);

        $coursesTable = $this->getTableLocator()->get('Courses');
        $course = $coursesTable->newEntity([
            'title' => 'Other Course', 'user_id' => 999, 'sort_no' => 1,
        ]);
        $coursesTable->save($course);

        $connection = $this->getTableLocator()->get('Contents')->getConnection();
        $accessControl = new AccessControlService($connection);
        $tool = new ListContentsTool($accessControl);

        $context = $this->createMock(\Mcp\Server\RequestContext::class);
        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getMeta')->willReturn([
            'oauth' => ['oauth.user_id' => $user->id],
        ]);
        $context->method('getRequest')->willReturn($request);

        // Act
        $result = $tool($context, course_id: $course->id);

        // Assert
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Access denied', $result['error']);
    }
}
```

### `composer.json` 依存追加

```bash
# Phase 1: Markdown
composer require league/commonmark ezyang/htmlpurifier

# Phase 2: MCP
composer require mcp/sdk
```

---

## 付録G 要確認・実装時確定事項

本ドキュメント本文（§1〜§10）が設計の正であり、付録C〜F は実装レベルの詳細（疑似コード・テスト）である。実装着手時に以下を確定・反映すること（G-1〜G-5, G-10 は解決済み、G-6〜G-9 は実装時の確認事項）。

| # | 項目 | 内容 |
|---|---|---|
| G-1 | `ContentsTable` の import | **解決済み**。ContentsTable に `use Cake\Core\Configure;` が無いことを確認。C-4 に追加指示を反映済み |
| G-2 | `MarkdownHelper` の API 名 | **解決済み**。E-6 を C-2 定義の `MarkdownHelper` API に統一済み |
| G-3 | MCP のトークン照合 | **解決済み**。§5.3 サンプルを `lookupApiToken()` に修正済み |
| G-4 | `mcp/sdk` の FQCN | **大部分解決（v0.8.1 確認済み）**: `Mcp\Server` / `Server::builder` / `setServerInfo` / `addTool` シグネチャ / `build` / `run` / `#[McpTool]`=`Mcp\Capability\Attribute\McpTool`（inputSchema パラメータ無し） / `setDiscovery(string $basePath, ...)` / `AuthorizationTokenValidatorInterface::validate()` / `AuthorizationMiddleware`・`ProtectedResourceMetadata` シグネチャ。**残件は G-10 で解決済み** |
| G-5 | Write ルートの `{id}` 制約 | **解決済み**。§4.5・C-6 の `{id}` ルートに `'id' => '\d+'` を反映済み |
| G-6 | `kind` の `text` | `content_kind` に `text` は存在しない（`templates/Contents/view.php` に `case 'text'` が残るのみ）。C-4 の body 必須判定に `text` を含めているが、新規作成では `markdown`/`html` のみ該当する |
| G-7 | `kind` inList | C-4 のとおり `allowEmpty: true` を付与し、既存 `kind=''` レコードの更新を壊さないこと |
| G-8 | DELETE 応答 | U-4 のとおり Web 側 `Admin/ContentsController::delete()` の既存挙動（方式・ステータス）に一致させる |
| G-9 | `html` kind のサニタイズ | U-5 のとおり Phase 3 でログ評価から開始。Phase 1/2 では `get_content_html` を含め未サニタイズのまま返す点をドキュメント化する |
| G-10 | mcp/sdk 残確認 | **解決済み（v0.8.1 ソース検証）** ① allow(array $attributes = []) / unauthorized(?string $error, ?string $errorDescription, ?array $scopes) ② AuthorizationMiddleware 不使用と決定（AS 無し）。ProtectedResourceMetadata は authorizationServers 非空必須のため、自作 IrohaAuthMiddleware が oauth.* request attributes を設定し OAuthRequestMetaMiddleware が転記（E-3/E-5 に反映） ③ RequestContext = Mcp\Server\RequestContext、getMeta()['oauth']（内部キーも oauth. 付き） ④ StreamableHttpTransport ctor（request, responseFactory, streamFactory, logger, middleware, maxBodyBytes）named arg 安全、defaultMiddleware()=Cors+DnsRebinding ⑤ addTool 省略時は #[McpTool] 属性、明示が優先。enum/description は #[Schema] 属性または addTool inputSchema ⑥ setDiscovery と addTool は併用可・手動優先 |

