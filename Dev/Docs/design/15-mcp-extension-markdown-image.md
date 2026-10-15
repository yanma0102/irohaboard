# 15 MCP ツール網羅拡張 & Markdown 画像添付 設計書

- **バージョン**: 1.1（2026-10-15 起案／GUI 操作テスト結果を反映）
- **状態**: 設計（実装前レビュー待ち）
- **関連**:
  - `Dev/Docs/design/13-markdown-mcp.md`（Markdown 対応 & MCP サーバ統合。本設計はその拡張）
  - `Dev/Docs/design/08-rest-api.md` / `Dev/Docs/API.md`（REST API v1。MCP ツールの意味論の正）
  - `Dev/Docs/design/14-config-gui-admin.md`（`upload_image_maxsize` など画像設定の GUI 編集）

---

## 1. 概要

### 1.1 背景

`13-markdown-mcp.md` で Markdown コンテンツと MCP サーバ（`/mcp`）を導入し、
現時点で **11 ツール**（読み取り 7 + 書き込み 4）が稼働している。

| 種別 | 現行ツール |
|---|---|
| 読み取り | `list_courses` `get_course` `list_contents` `get_content` `get_content_html` `list_records` `get_user_profile` |
| 書き込み | `create_content` `update_content` `create_test_question` `update_test_question` |

一方 REST API v1（`/api/v1`）は users / groups / courses / contents / records の
CRUD・割当を含む **28 ルート**を提供しており、MCP は本来の狙い（AI クライアントからの
学習コンテンツ運用）に対してカバレッジが不足している。また Markdown の画像は
管理画面の EasyMDE からの手動アップロードにのみ対応し、REST API / MCP 経由では
添付できず、貼付け導線も実装詳細（`Admin/ContentsController::uploadImage`）に
密結合している。

本設計は以下 2 機能を追加する。

1. **MCP ツール網羅拡張** — REST API / 管理画面にあるが MCP に無い操作を追加
2. **Markdown 画像添付** — 管理画面 EasyMDE の貼付け導線の完成・堅牢化、REST API
   および MCP 経由の画像アップロード・添付

### 1.2 スコープ（ユーザ承認済み）

| 区分 | 含める |
|---|---|
| MCP | ツール網羅拡張、画像アップロード系ツール |
| Markdown 画像 | 管理画面 EasyMDE の完成・堅牢化、REST API 画像アップロード、MCP 経由の画像添付 |

### 1.3 非目標（本設計では扱わない）

- MCP プロトコル機能: Resources / Prompts / ツールアノテーション / `tools/list`
  ページネーション / `resources/templates`（別スコープ）
- MCP の認証・運用強化: OAuth Protected Resource Metadata、監査ログ、レート制限の細分化
- 画像配信の権限制御の見直し、孤児画像の自動削除、保存先（`files/` と
  `webroot/uploads/`）の方針統一（`development-gaps.md` C-5 の残課題。別スコープ）
- テスト採点を伴う学習履歴の登録（`kind='test'` の受験フロー）。本設計の
  `create_record` は非テストコンテンツの進捗記録に限定する。

> **前提（As-Is のまま据え置く）**: `Contents::fileImage` はランダムファイル名による
> 事実上の保護のみで、認証なしに配信する。この挙動は本設計で変更しない。

---

## 2. 現状分析（As-Is）

### 2.1 MCP 実装の構成

| 要素 | 現状 |
|---|---|
| エンドポイント | `config/routes.php` の `/mcp` スコープ（POST/DELETE/OPTIONS/GET） |
| コントローラ | `app/src/Controller/McpController.php`（`endpoint` / `deleteSession` / `options` / `health`） |
| Server 構築 | `app/src/Mcp/McpServerFactory.php`。`addTool([$instance, '__invoke'], name:, description:)` で明示登録 |
| 認証 | `IrohaAuthMiddleware` + `IrohaTokenValidator`（Bearer）。`OAuthRequestMetaMiddleware` が `oauth.*` を `_meta.oauth` へ転記 |
| ツール基底 | `HasOAuthContextTrait`（`getUserId()` / `getRole()`） |
| 権限 | `App\Service\AccessControlService`（`isStaff` / `accessibleCourseIds` / `canAccessCourse` / `currentUserGroupIds`） |
| エラー | `Mcp\Exception\ToolCallException`（SDK が `isError: true` に変換） |
| レート制限 | `McpRateLimitMiddleware`。読み取り 60/分、書き込み 20/分。**書き込み判定は `WRITE_TOOLS` 定数（`create_content`/`update_content` のみ）** |
| セッション | `FileSessionStore`（`TMP . 'mcp-sessions'`, TTL 3600） |
| ボディ上限 | SDK `StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES = 4,194,304`（4 MiB） |

### 2.2 REST API v1 のサーフェス（MCP 拡張の意味論の正）

`config/routes.php` の `/api/v1` スコープより。

| リソース | エンドポイント |
|---|---|
| auth | `POST /auth/token`, `DELETE /auth/token` |
| users | `GET/POST /users`, `GET/PUT/PATCH/DELETE /users/{id}`, `PUT/PATCH /users/{id}/password`, `GET/POST /users/{id}/courses`, `DELETE /users/{id}/courses/{course_id}` |
| courses | `GET/POST /courses`, `GET/PUT/PATCH/DELETE /courses/{id}` |
| contents | `GET/POST /contents`, `GET/PUT/PATCH/DELETE /contents/{id}` |
| records | `GET /records`, `GET /records/{id}` |
| groups | `GET/POST /groups`, `GET/PUT/PATCH/DELETE /groups/{id}`, `GET/POST /groups/{id}/users`, `DELETE /groups/{id}/users/{user_id}`, `GET/POST /groups/{id}/courses`, `DELETE /groups/{id}/courses/{course_id}` |

権限区分（`Api\BaseController`）:
- `requireStaff()` = `admin` / `manager` / `editor` / `teacher`
- `requireManager()` = `admin` / `manager`
- レスポンス封筒 = `{data: ...}` / `{data: [...], meta: {...}}` / `{error: {code, message, errors?}}`

### 2.3 Markdown 画像の現状

| 要素 | 現状 | 場所 |
|---|---|---|
| エディタ | EasyMDE。`upload-image` ボタンのみ動作し、貼付け/ドラッグ&ドロップは**未配線**（F-1） | `app/templates/Admin/Contents/edit.php` |
| アップロード先 | `imageUploadFunction` → `contents/uploadImage`（admin prefix で解決）へ `file` を POST、応答 `[url]` を期待 | 同上 |
| サーバ処理 | `Admin/ContentsController::uploadImage()`（Summernote と共用）。拡張子・サイズ検証、ランダム名生成後、`Router::url(..., true)` を返すが **admin prefix が混入**し 404 になる（F-2） | `app/src/Controller/Admin/ContentsController.php` |
| 保存先 | `ROOT/webroot/uploads/`（`.gitignore` 済み、`.htaccess` あり） | 同上 |
| 配信 | `GET /contents/file-image/{file_name}`（`ContentsController::fileImage`） | `app/config/routes.php`, `app/src/Controller/ContentsController.php` |
| サニタイズ | `MarkdownRenderer` が `img[src|alt|title|width|height]` を許可。`URI.AllowedSchemes` は http/https/mailto | `app/src/Utility/MarkdownRenderer.php` |
| 制限値 | `upload_image_extensions` = `.png/.gif/.jpg/.jpeg`、`upload_image_maxsize` = 2 MiB、`use_upload_image` = true | `app/config/ib_config.php` |

#### GUI 操作テストで検出した既存不具合（2026-10-15・実ブラウザ操作）

PHPUnit / HTTP クライアントでは検出できず、`Dev/scripts/gui-image-upload.py`（§9.6）で再現する。

- **F-1 貼付け/ドラッグ&ドロップが動作しない** — `edit.php` は `imageUpload: true` を指定しているが、
  同梱 EasyMDE が参照するオプション名は `uploadImage`（`if (e.uploadImage) { ... }` の内側で
  paste/drop ハンドラを登録）。このためツールバーの `upload-image` ボタンだけが動作する。
- **F-2 アップロード画像 URL が 404** — admin prefix 配下で実行された `Router::url()` が現在の
  prefix を継承し `http://<host>/admin/contents/file-image/<name>` を返す。存在するのは front の
  `/contents/file-image/<name>` のみ（`config/routes.php`）。EasyMDE / Summernote に挿入された
  画像は表示されず、コンソールに 404 が出る。`413dd82`（サブディレクトリ配置対応）で混入。

### 2.4 課題（本設計で解消するもの）

1. **MCP カバレッジ不足** — users / groups / courses / contents の書き込みの大半、
   テスト問題の一覧・削除、学習履歴の記録が MCP から操作できない。
2. **書き込みレート制限の穴** — 新規書き込みツールを `WRITE_TOOLS` に追加しないと
   60 回/分の読み取り枠で実行されてしまう。
3. **画像アップロード処理の重複・密結合** — 検証・保存ロジックが
   `Admin/ContentsController::uploadImage()` に閉じており、REST / MCP から再利用できない。
4. **EasyMDE のエラー処理が弱い** — サーバが `[false]` を返しても
   クライアントが具体的理由（形式・サイズ）を表示できない。
5. **MCP / REST から画像を添付できない** — Markdown に画像を埋め込む手段が
   管理画面の手作業のみ。
6. **EasyMDE の貼付け/ドラッグ&ドロップが機能しない（F-1）** — オプション名の誤りで
   paste/drop ハンドラが未登録（§2.3）。
7. **挿入される画像 URL が admin prefix 混入で 404（F-2）** — Markdown / リッチテキストとも
   画像が表示されない（§2.3）。

---

## 3. MCP ツール網羅拡張

### 3.1 設計方針

- **意味論は REST API v1 を正とする。** 権限・絞り込み・更新可能フィールド・
  エラー条件は `Api\*` コントローラと一致させる（二重実装を避けるため、
  可能な限り `AccessControlService` と各 Table の既存メソッドへ寄せる）。
- **命名規約**: `verb_resource`（既存に合わせる）。一覧は `list_*`、詳細は `get_*`、
  更新は `update_*`、削除は `delete_*`、割当は `assign_*` / `unassign_*`。
- **レスポンス封筒**: 既存 MCP ツールに合わせ `['data' => ..., 'meta' => ...]` を返す。
  エラーは `ToolCallException` を投げる（`Api\BaseController::fail()` 相当）。
- **権限判定**: `AccessControlService` に `isManager(string $role): bool` を追加し、
  各ツールで `isManager` / `isStaff` を判定する。
- **MCP 未対応のユースケース**（パスワード変更など破壊的操作）は引数仕様に
  「明示的な確認主体（呼び出し元エージェント）」を要求し、監査可能にする。
- **既存ツールは変更しない**（後方互換）。`get_user_profile` は残し、
  一覧系は `list_users` として追加する。

### 3.2 追加ツール一覧

凡例: 権限 = `staff`（admin/manager/editor/teacher）/ `manager`（admin/manager）/ `-`（認証済み・対象アクセス権のみ）

#### 3.2.1 読み取り系（Phase C）

| ツール名 | 説明 | 権限 | 主な引数 | 対応 |
|---|---|---|---|---|
| `list_users` | ユーザ一覧。一般ユーザは自分のみ | staff（全件）/ -（自分） | `username?`, `name?`, `role?`, `page`, `limit` | `GET /users` |
| `get_user` | ユーザ詳細（`get_user_profile` の別名・明示用） | staff / 本人 | `user_id` | `GET /users/{id}` |
| `list_groups` | グループ一覧 | staff（全件）/ -（所属） | `title?`, `status?`, `page`, `limit` | `GET /groups` |
| `get_group` | グループ詳細 | staff / 所属 | `group_id` | `GET /groups/{id}` |
| `list_group_users` | グループ所属ユーザ一覧 | staff / 所属 | `group_id` | `GET /groups/{id}/users` |
| `list_group_courses` | グループ割当コース一覧 | staff / 所属 | `group_id` | `GET /groups/{id}/courses` |
| `list_user_courses` | ユーザ割当コース一覧 | staff / 本人 | `user_id` | `GET /users/{id}/courses` |
| `get_record` | 学習記録の詳細 | staff / 本人 | `record_id` | `GET /records/{id}` |
| `list_test_questions` | テスト問題一覧（正解含む） | staff + コース権限 | `content_id`, `page`, `limit` | Web `ContentsQuestions::index` |
| `get_test_question` | テスト問題詳細 | staff + コース権限 | `question_id` | Web `ContentsQuestions::edit` |
| `list_enquete_questions` | アンケート質問一覧 | staff + コース権限 | `content_id`, `page`, `limit` | Web `EnquetesQuestions::index` |

> `list_users` の一般ユーザは `get_user_profile` と重複するが、REST の絞り込みに
> 合わせて `list_users` を `staff` 専用（一般ユーザは自レコード1件）として実装する。

#### 3.2.2 書き込み系（Phase D）

| ツール名 | 説明 | 権限 | 主な引数 | 対応 |
|---|---|---|---|---|
| `delete_content` | コンテンツ削除（問題もカスケード削除） | staff + コース権限 | `content_id` | `DELETE /contents/{id}` |
| `reorder_contents` | コース内コンテンツの並び替え | staff + コース権限 | `course_id`, `content_ids[]` | Web `Admin/Contents::order` |
| `copy_content` | コンテンツ＋テスト問題の複製 | staff + コース権限 | `content_id`, `target_course_id?` | Web `Admin/Contents::copy` |
| `create_record` | 学習履歴の記録（非テスト） | -（受講者本人） | `content_id`, `study_sec?`, `understanding?`, `is_complete?` | Web `Records::add` |
| `delete_test_question` | テスト問題削除 | staff + コース権限 | `question_id` | Web `Admin/ContentsQuestions::delete` |
| `create_course` | コース作成 | manager | `title`, `introduction?`, `opened?`, `comment?`, `sort_no?` | `POST /courses` |
| `update_course` | コース更新 | manager | `course_id`, `title?`, `introduction?`, `opened?`, `comment?`, `sort_no?` | `PUT /courses/{id}` |
| `delete_course` | コース削除 | manager | `course_id` | `DELETE /courses/{id}` |
| `create_user` | ユーザ作成 | manager | `username`, `password`, `name`, `role?`, `email?`, `comment?`, `started?`, `ended?`, `is_active?` | `POST /users` |
| `update_user` | ユーザ更新（管理者保護ルール準拠） | manager | `user_id`, `role?`, `name?`, `email?`, `comment?`, `started?`, `ended?`, `is_active?` | `PUT /users/{id}` |
| `change_user_password` | パスワード変更（トークン失効） | manager | `user_id`, `password` | `PUT /users/{id}/password` |
| `delete_user` | ユーザ削除 | manager | `user_id` | `DELETE /users/{id}` |
| `assign_user_course` | ユーザにコース割当 | manager | `user_id`, `course_id` | `POST /users/{id}/courses` |
| `unassign_user_course` | ユーザのコース割当解除 | manager | `user_id`, `course_id` | `DELETE /users/{id}/courses/{course_id}` |
| `create_group` | グループ作成 | manager | `title`, `comment?`, `status?`, `logo?`, `copyright?`, `module?` | `POST /groups` |
| `update_group` | グループ更新 | manager | `group_id`, 同上 | `PUT /groups/{id}` |
| `delete_group` | グループ削除（中間テーブル cascade） | manager | `group_id` | `DELETE /groups/{id}` |
| `assign_group_user` | グループにユーザ割当 | manager | `group_id`, `user_id` | `POST /groups/{id}/users` |
| `unassign_group_user` | グループのユーザ割当解除 | manager | `group_id`, `user_id` | `DELETE /groups/{id}/users/{user_id}` |
| `assign_group_course` | グループにコース割当 | manager | `group_id`, `course_id` | `POST /groups/{id}/courses` |
| `unassign_group_course` | グループのコース割当解除 | manager | `group_id`, `course_id` | `DELETE /groups/{id}/courses/{course_id}` |

> **`update_user` の保護ルール**（`Api\UsersController::edit()` と同一）:
> 管理者以外は admin アカウント変更不可 / 自分のロール変更不可 / admin 付与は admin のみ /
> 自分自身の無効化不可 / 最後の有効な管理者の無効化不可。無効化時はトークン全失効。

#### 3.2.3 画像系（Phase E）

| ツール名 | 説明 | 権限 | 主な引数 |
|---|---|---|---|
| `upload_image` | 画像を保存し URL を返す | staff + コース権限 | `course_id`, `file_name`, `data`（base64 or data URL）, `alt?` |

`create_content` / `update_content` に任意引数 `images[]` を追加する（§7.2）。
詳細は §7 を参照。

### 3.3 実装方針

#### 3.3.1 `AccessControlService` の拡張

```php
// app/src/Service/AccessControlService.php

/**
 * 管理者・マネージャ判定（Api\BaseController::requireManager() と同一）
 */
public function isManager(string $role): bool
{
    return in_array($role, ['admin', 'manager'], true);
}
```

#### 3.3.2 ツールの共通実装パターン

既存ツール（`CreateContentTool`）に倣う。

```php
class DeleteContentTool
{
    use HasOAuthContextTrait;

    public function __construct(private AccessControlService $accessControl) {}

    #[McpTool(
        name: 'delete_content',
        description: 'Delete a content (staff only). Related test questions are also deleted.',
    )]
    public function __invoke(RequestContext $context, int $content_id): array
    {
        $userId = $this->getUserId($context);
        $role   = $this->getRole($context);

        if (!$this->accessControl->isStaff($role)) {
            throw new ToolCallException('Only staff members can delete content.');
        }

        $contentsTable = TableRegistry::getTableLocator()->get('Contents');
        $content = $contentsTable->find()
            ->where(['id' => $content_id, 'deleted IS NULL'])
            ->first();

        if ($content === null) {
            throw new ToolCallException('Content not found.');
        }
        if (!$this->accessControl->canAccessCourse($userId, (int)$content->course_id, $role)) {
            throw new ToolCallException('Access denied to this course.');
        }

        if (!$contentsTable->delete($content)) {
            throw new ToolCallException('Failed to delete content.');
        }
        TableRegistry::getTableLocator()->get('ContentsQuestions')->deleteAll(['content_id' => $content_id]);

        return ['data' => ['id' => $content_id, 'deleted' => true]];
    }
}
```

#### 3.3.3 `McpServerFactory` への登録

`addTool([$instance, '__invoke'], name:, description:)` を既存ブロックの後に追記する。
`name` は必ず明示する（属性名は明示登録時に読まれない。`13-markdown-mcp.md` 付録G-10）。

#### 3.3.4 書き込みレート制限の更新（必須）

```php
// app/src/Mcp/McpRateLimitMiddleware.php
private const WRITE_TOOLS = [
    'create_content', 'update_content',
    'create_test_question', 'update_test_question', 'delete_test_question',
    'delete_content', 'reorder_contents', 'copy_content', 'create_record',
    'create_course', 'update_course', 'delete_course',
    'create_user', 'update_user', 'delete_user', 'change_user_password',
    'assign_user_course', 'unassign_user_course',
    'create_group', 'update_group', 'delete_group',
    'assign_group_user', 'unassign_group_user',
    'assign_group_course', 'unassign_group_course',
    'upload_image',
];
```

> 現行 `WRITE_TOOLS` は `create_content` / `update_content` のみで、既存の
> `create_test_question` / `update_test_question` も漏れている。本対応で併せて是正する。

### 3.4 ページネーションと引数スキーマ

- 一覧系は既存に合わせ `page` / `limit` を `int` で受け、`limit` は 1〜200 にクランプする。
- `enum` が必要な引数は `#[Schema(enum: [...])]` を付与する（v0.8.1 の制約）。
- `course_id` 等の必須引数は PHP の型宣言で表現し、MCP の inputSchema に反映させる。
- `content_ids[]` は `array` で受け、要素を `intval` へ正規化してから
  `ContentsTable::setOrder()` に渡す（所有者・コース所属の検証を先に行う）。

### 3.5 段階的実装計画

| Phase | 内容 | 完了条件 |
|---|---|---|
| C | 読み取り系 11 ツール追加 | `tools/list` に反映、権限・ページネーションの単体テストが通る |
| D | 書き込み・管理系 24 ツール追加 + `WRITE_TOOLS` 更新 | 権限（staff/manager）・保護ルール・レート制限のテストが通る |
| E | `upload_image` + `create_content`/`update_content` の `images` 拡張 | §7 のテストが通る |

> C と D は独立して並行実装可能。E は §4 の画像基盤に依存する。

---

## 4. 画像アップロード基盤（共通部品）

REST / admin / MCP の 3 経路で同じ検証・保存を再利用するため、サービスへ抽出する。

### 4.1 保存仕様

| 項目 | 仕様 |
|---|---|
| 保存先 | `ROOT . DS . 'webroot' . DS . 'uploads'`（無ければ 0755 で作成） |
| ファイル名 | `date('YmdHis') . <ランダム英小文字4文字> . '.' . <拡張子>`（既存踏襲） |
| 拡張子 | `Configure::read('upload_image_extensions')` に含まれること（既定 `.png/.gif/.jpg/.jpeg`） |
| サイズ | `Configure::read('upload_image_maxsize')` 以下であること |
| 実体検証 | `finfo` / `getimagesize()` で実画像であることを確認（拡張子偽装を拒否） |
| 配信 URL | `Router::url(['controller' => 'Contents', 'action' => 'fileImage', $fileName, 'prefix' => false], true)`（絶対 URL。`prefix => false` の明示が F-2 の修正） |
| 実行抑止 | PHP 実行は `webroot/.htaccess`（`\.php$` を `[F,L]`）と `uploads/.htaccess` で抑止済み |

### 4.2 `ImageStorageService`

```php
namespace App\Service;

use Cake\Core\Configure;
use Laminas\Diactoros\UploadedFile;   // CakePHP が返す PSR-7 UploadedFile
use RuntimeException;

/**
 * 画像アップロードの検証・保存を一元化する。
 * admin / REST API / MCP から共用する。
 */
class ImageStorageService
{
    public const REASON_NO_FILE            = 'no_file';
    public const REASON_EXTENSION          = 'extension_not_allowed';
    public const REASON_TOO_LARGE          = 'too_large';
    public const REASON_NOT_IMAGE          = 'not_an_image';
    public const REASON_SAVE_FAILED        = 'save_failed';

    public function __construct(private string $uploadDir) {}

    /**
     * @return array{file_name: string, size: int, mime: string}
     * @throws ImageUploadException 検証失敗・保存失敗
     */
    public function storeFromUploadedFile(UploadedFile $file): array;
    //  1. getError() === UPLOAD_ERR_OK を確認
    //  2. 拡張子（strtolower(pathinfo(getClientFilename())))
    //  3. getSize() <= upload_image_maxsize
    //  4. getimagesize($file->getStream()->getMetadata('uri') もしくは一時パス) で実体確認
    //  5. ランダム名で moveTo($dest)（例外捕捉 + is_file で成否判定）
    //  6. ['file_name'=>..., 'size'=>..., 'mime'=>...] を返す

    /**
     * base64 文字列 / data URL から保存する（MCP 用）。
     * @throws ImageUploadException
     */
    public function storeFromBase64(string $data, string $originalFileName): array;
    //  1. data URL（data:image/png;base64,...）ならプレフィックスを除去
    //  2. base64_decode(strict: true)。失敗なら NOT_IMAGE
    //  3. 一時ファイルへ書き出し、storeFromUploadedFile と同一の検証（サイズ・実体）を通す
    //  4. 保存して ['file_name'=>..., 'size'=>..., 'mime'=>...] を返す

    /**
     * 保存済みファイルの配信用絶対 URL を返す。
     * admin prefix 配下のリクエストから呼ばれても front の
     * /contents/file-image/{fileName} を生成する（`prefix => false`。F-2 の修正）。
     */
    public function url(string $fileName): string;
}
```

`ImageUploadException` は `reason`（上記定数）とメッセージを持ち、
admin は Flash、REST は HTTP ステータス、MCP は `ToolCallException` へマッピングする。

| reason | admin 表示 | REST | MCP |
|---|---|---|---|
| `no_file` | ファイルが指定されていません | 400 | ToolCallException |
| `extension_not_allowed` | 許可されていない形式です（対応: .png/.gif/.jpg/.jpeg） | 415 | ToolCallException |
| `too_large` | サイズ上限（2 MB）を超えています | 413 | ToolCallException |
| `not_an_image` | 画像ファイルではありません | 415 | ToolCallException |
| `save_failed` | アップロードに失敗しました | 500 | ToolCallException |

### 4.3 設定

- 既存の `upload_image_extensions` / `upload_image_maxsize` をそのまま使う。
- MCP 経由の base64 は JSON ボディに載るため、SDK の 4 MiB 上限も考慮し、
  **エンコード文字列長の上限**を `Configure::read('mcp_image_maxbytes', 3_500_000)` で
  追加する（デコード後は `upload_image_maxsize` でも検証）。上限超過は
  `ToolCallException`。`config/ib_config.php` に既定値を追記する。

---

## 5. 管理画面 EasyMDE 画像貼付けの完成・堅牢化

### 5.1 現状の課題

1. **貼付け/ドラッグ&ドロップが動作しない（F-1）** — `imageUpload: true` は EasyMDE の
   オプション名ではない（正: `uploadImage`）。このため paste/drop ハンドラが登録されず、
   ツールバーの `upload-image` ボタン経由でしかアップロードできない。
2. **挿入される画像 URL が 404（F-2）** — `uploadImage` が返す URL に admin prefix が混入する。
   プレビュー・学習者画面とも画像が表示されず、コンソールに 404 が出る。
3. サーバが `[false]` を返してもクライアントは「画像のアップロードに失敗しました」としか
   表示できず、形式・サイズの理由が伝わらない。
4. `uploadImage` の検証・保存ロジックがコントローラに閉じている（§2.4-3）。
5. `use_upload_image = false` の設定が EasyMDE 側で考慮されない。
6. 貼付け/ドロップの受け入れ拡張子が EasyMDE 既定（`image/png,image/jpeg,image/gif,image/avif`）で、
   サーバ許可拡張子（`avif` を含まない）と不一致。

> F-1 / F-2 は GUI 操作テスト（§9.6）でのみ検出できた。修正の検証も同テストで行う。

### 5.2 仕様

| 項目 | 仕様 |
|---|---|
| 対象 kind | `markdown` のみ（`kind='markdown'` 選択時に EasyMDE を初期化） |
| 起動条件 | `Configure::read('use_upload_image') === true` のときのみ `uploadImage: true`（F-1 の修正） |
| `imageAccept` | サーバ許可拡張子から組み立て（例 `image/png,image/jpeg,image/gif`） |
| トリガ | ① ツールバーの `upload-image` ボタン ② クリップボード貼付け ③ ドラッグ&ドロップ |
| 送信 | `POST`（admin prefix 解決）`multipart/form-data`、フィールド `file`、`_csrfToken` 付与 |
| CSRF | `CsrfProtectionMiddleware` のトークン検証を維持。`FormProtection` は `uploadImage` を `unlockActions` 済み |
| サイズ超過 | クライアント側で事前に `onError`（`imageMaxSize` を `upload_image_maxsize` と一致させる） |
| 挿入 | 成功時 `![alt](<絶対URL>)` をカーソル位置へ挿入 |
| 画像 URL | `prefix => false` で front の `GET /contents/file-image/{file_name}` を絶対 URL 生成（F-2 の修正）。サブディレクトリ配置でも `Router::fullBaseUrl()` からベースパスを解決 |
| サーバ応答 | **後方互換のため `[url]` / `[false]` を維持**（Summernote 共通のため） |

> 応答形式を `{data:{filePath}}` に変えると Summernote 側の JS が壊れるため変更しない。
> EasyMDE は `imageUploadFunction` 内で `[url]` を解釈し続ける。

### 5.3 実装差分

**サーバ（`Admin/ContentsController::uploadImage`）**: 内部処理を
`ImageStorageService` に置換し、検証理由をログに残す。

```php
public function uploadImage(): Response
{
    $this->autoRender = false;

    if (!$this->request->is('ajax')) {
        return $this->response;
    }

    if (Configure::read('use_upload_image') !== true) {
        return $this->json([false]);
    }

    $file = $this->request->getUploadedFile('file')
        ?? $this->request->getUploadedFile('image');

    try {
        if ($file === null) {
            throw new ImageUploadException(ImageUploadException::REASON_NO_FILE);
        }
        $stored = (new ImageStorageService($this->uploadDir()))->storeFromUploadedFile($file);
        $response = [$this->imageStorage->url($stored['file_name'])];
    } catch (ImageUploadException $e) {
        $this->log('image upload rejected: ' . $e->getReason(), 'debug');
        $response = [false];
    }

    return $this->json($response);
}
```

> F-2（URL の admin prefix 混入）の修正は `ImageStorageService::url()` に集約する（§4.1 の
> `prefix => false`）。コントローラは生成済み URL を返すだけにする。

**クライアント（`app/templates/Admin/Contents/edit.php`）**: `initMarkdownEditor()` を調整。

```js
markdownEditor = new EasyMDE({
    element: $('#body')[0],
    spellChecker: false,
    autosave: { enabled: false },
    autoDownloadFontAwesome: false,
    toolbar: [ 'bold', 'italic', 'heading', '|', 'quote',
        'unordered-list', 'ordered-list', '|', 'link',
        <?= Configure::read('use_upload_image') ? "'upload-image'," : '' ?>
        '|', 'preview', 'side-by-side', 'fullscreen', 'guide' ],
    uploadImage: <?= Configure::read('use_upload_image') ? 'true' : 'false' ?>,
    imageAccept: '<?= implode(', ', array_map(
        fn($e) => 'image/' . ltrim($e, '.'), (array)Configure::read('upload_image_extensions'))) ?>',
    imageMaxSize: <?= (int)Configure::read('upload_image_maxsize') ?>,
    imageUploadFunction: function (file, onSuccess, onError) {
        var data = new FormData();
        data.append('file', file);
        data.append('_csrfToken', $('input[name="_csrfToken"]').val());
        $.ajax({
            data: data, type: 'POST', cache: false,
            url: '<?= $this->Url->build(['controller' => 'contents', 'action' => 'uploadImage']) ?>',
            contentType: false, processData: false,
            success: function (response) {
                var payload = (typeof response === 'string') ? JSON.parse(response) : response;
                var url = payload ? payload[0] : false;
                if (url) { onSuccess(url); }
                else { onError('画像をアップロードできません。形式（png/gif/jpg/jpeg）とサイズ上限を確認してください'); }
            },
            error: function () { onError('通信中にエラーが発生しました'); }
        });
    }
});
```

> F-1 の修正点: オプション名は `uploadImage`（`imageUpload` は EasyMDE が無視するため
> paste/drop が無効になる）。`upload-image` ボタンは `uploadImage` が true の場合のみ
> 機能するため、`use_upload_image = false` のときはツールバーからも外す。

---

## 6. REST API 画像アップロード

### 6.1 エンドポイント

| メソッド | パス | 説明 |
|---|---|---|
| POST | `/api/v1/contents/images` | 画像をアップロードし、配信 URL を返す（staff のみ） |

### 6.2 リクエスト / レスポンス

**リクエスト** `Content-Type: multipart/form-data`

| フィールド | 必須 | 説明 |
|---|---|---|
| `file`（または `image`） | ○ | 画像ファイル（`.png/.gif/.jpg/.jpeg`、2 MiB 以下） |
| `course_id` | − | 指定時はコースアクセス権を検証（staff は全コース可） |

**成功** `201 Created`

```json
{
  "data": {
    "file_name": "20261015123456abcd.png",
    "url": "https://example.com/contents/file-image/20261015123456abcd.png",
    "size": 12345,
    "mime": "image/png"
  }
}
```

**エラー**（`ApiErrorMiddleware` の封筒に従う）

| 状況 | ステータス | message |
|---|---|---|
| ファイル無し | 400 | `image file is required` |
| 形式不正 | 415 | `Unsupported image type` |
| サイズ超過 | 413 | `Image is too large` |
| 実体が画像でない | 415 | `Unsupported image type` |
| 保存失敗 | 500 | `Failed to store image` |
| 権限なし | 403 | （`requireStaff`） |

### 6.3 認可

- `requireStaff()` を要求（admin の `uploadImage` と同等）。
- `course_id` 指定時は `AccessControlService::canAccessCourse($userId, $courseId, $role)`
  を検証。staff は全コース可。

### 6.4 実装

```php
// app/src/Controller/Api/ContentsController.php
public function uploadImage(): Response
{
    $this->requireStaff();

    $file = $this->request->getUploadedFile('file')
        ?? $this->request->getUploadedFile('image');

    $courseId = $this->input()['course_id'] ?? $this->request->getData('course_id');
    if ($courseId !== null && $courseId !== '') {
        $access = new AccessControlService($this->fetchTable('Users')->getConnection());
        if (!$access->canAccessCourse($this->currentUserId(), (int)$courseId, $this->currentRole())) {
            $this->fail(403, 'You do not have access to this course');
        }
    }

    try {
        if ($file === null) {
            throw new ImageUploadException(ImageUploadException::REASON_NO_FILE);
        }
        $stored = $this->imageStorage()->storeFromUploadedFile($file);
    } catch (ImageUploadException $e) {
        $status = match ($e->getReason()) {
            ImageUploadException::REASON_NO_FILE     => 400,
            ImageUploadException::REASON_TOO_LARGE  => 413,
            ImageUploadException::REASON_EXTENSION,
            ImageUploadException::REASON_NOT_IMAGE  => 415,
            default                                  => 500,
        };
        $this->fail($status, $e->getPublicMessage());
    }

    return $this->ok([
        'file_name' => $stored['file_name'],
        'url'       => $this->imageStorage()->url($stored['file_name']),
        'size'      => $stored['size'],
        'mime'      => $stored['mime'],
    ], 201);
}
```

### 6.5 ルーティング

`/api/v1/contents` スコープ内、`/contents/{id}` 群より前に追加する。

```php
$builder->connect('/contents/images', [
    'controller' => 'Contents',
    'action' => 'uploadImage',
    '_method' => 'POST',
]);
```

> `{id}` ルートは `'id' => '\d+'` 制約付きのため `images` とは衝突しないが、
> 可読性のため先に定義する。

---

## 7. MCP 経由の画像添付

### 7.1 `upload_image` ツール

```php
#[McpTool(
    name: 'upload_image',
    description: 'Upload an image and return its public URL. Staff only. '
        . 'Use the returned url in Markdown: ![alt](url). '
        . 'Allowed: png, gif, jpg, jpeg; max upload_image_maxsize.',
)]
public function __invoke(
    RequestContext $context,
    int $course_id,
    string $file_name,
    string $data,          // base64 or data URL (data:image/png;base64,...)
    ?string $alt = null,
): array;
```

処理:
1. `isStaff($role)` と `canAccessCourse($userId, $course_id, $role)` を検証。
2. `strlen($data) <= Configure::read('mcp_image_maxbytes')` を検証。
3. `ImageStorageService::storeFromBase64($data, $file_name)` を呼ぶ。
4. `['data' => ['file_name'=>..., 'url'=>..., 'size'=>..., 'mime'=>..., 'alt'=>$alt]]` を返す。

**レスポンス例**

```json
{ "data": { "file_name": "20261015123456abcd.png",
            "url": "https://example.com/contents/file-image/20261015123456abcd.png",
            "size": 12345, "mime": "image/png", "alt": null } }
```

### 7.2 `create_content` / `update_content` の `images[]` 拡張

Markdown コンテンツ作成・更新時に画像を同時登録できるようにする。

```php
/**
 * @param array<int, array{file_name: string, data: string, alt?: string}> $images
 */
public function __invoke(
    RequestContext $context,
    int $course_id,       // create_content
    string $title,
    #[Schema(enum: ['label','html','markdown','movie','url','file','test','enquete'])]
    string $kind,
    string $body = '',
    int $status = 0,
    array $images = [],
): array;
```

仕様:
- `images` は `kind = 'markdown'` のときのみ許可（それ以外で指定された場合は
  `ToolCallException('images can only be used with kind=markdown.')`）。
- 本文先頭を基準に、保存後に `body` の末尾へ `"\n\n![alt](url)"` を追記する。
- `update_content` は「更新後の kind が markdown」かつ「対象 kind が markdown」の
  両方を満たす場合のみ許可（既存 Markdown の partial update を壊さない）。
- 返却 `data` に `images: [{file_name, url, alt}]` を追加する。
- 画像保存に失敗した場合、コンテンツ保存はロールバックできないため、
  画像を先に保存し、いずれかが失敗したら処理全体を `ToolCallException` で中断する。
  （コンテンツは保存しない）

> 代替運用: `upload_image` で URL を得てから、呼び出し側が `body` に
> `![](url)` を書いて `create_content`/`update_content` を呼ぶことも可能。
> この場合 `images` 引数は不要。

### 7.3 制限と留意

- MCP の args は JSON のため、画像は base64 で渡す。base64 は元サイズの約 1.33 倍。
  既定で `upload_image_maxsize` = 2 MiB / `mcp_image_maxbytes` = 3.5 MB、
  トランスポート上限 4 MiB の範囲で運用する。大きい画像が必要な場合は
  `maxBodyBytes` の引き上げ（`McpController`）と `mcp_image_maxbytes` を見直す。
- `file_name` は表示・記録用。保存名はサーバ側でランダム生成し、パストラバーサルを
  防ぐ（`basename` 相当。`ImageStorageService` 内で実施）。
- SVG は `upload_image_extensions` に含めない（スクリプト混入対策）。

---

## 8. セキュリティ・データ考慮

| 論点 | 対応 |
|---|---|
| 拡張子偽装 | `getimagesize()` / `finfo` による実体検証（拡張子だけで判定しない） |
| 実行ファイル混入 | 許可拡張子のみ、ランダム名、`uploads/.htaccess` + `webroot/.htaccess` で PHP 実行不可 |
| ボディサイズ | 画像は `upload_image_maxsize`、MCP は `mcp_image_maxbytes`、SDK は 4 MiB で多段制限 |
| 権限 | admin/REST/MCP とも staff 必須。MCP/REST は `course_id` のアクセス権を追加検証 |
| CSRF | admin 経路は `_csrfToken` を維持（既存どおり） |
| 監査 | 画像アップロード拒否理由を debug ログに記録（`$this->log(...)`） |
| 孤児画像 | **本設計の対象外**（アップロードしたが本文参照されない画像は残存する） |
| 配信の権限制御 | **現状維持**（`fileImage` は名前の推測困難性のみ）。別スコープ |

---

## 9. テスト計画

テストは `Dev/tests/TestCase/` に配置し、`cd app && vendor/bin/phpunit` で実行する。
既存の MCP ツールテスト（`Dev/tests/TestCase/Mcp/Tool/CreateContentToolTest.php`）の
`makeContext()` / データ生成ヘルパを踏襲する。

### 9.1 画像基盤（`ImageStorageServiceTest`）

| # | ケース | 期待 |
|---|---|---|
| 1 | png を保存 | ランダム名で `webroot/uploads` に生成、`size`/`mime` を返す |
| 2 | 拡張子 `.php` を拒否 | `REASON_EXTENSION` |
| 3 | 拡張子 png だが中身が PHP | `REASON_NOT_IMAGE` |
| 4 | `upload_image_maxsize` 超過 | `REASON_TOO_LARGE` |
| 5 | base64 / data URL から保存 | 正常保存、URL を返す |
| 6 | 不正 base64 | `REASON_NOT_IMAGE` |

### 9.2 管理画面（`Admin/ContentsControllerTest`）

| # | ケース | 期待 |
|---|---|---|
| 1 | AJAX + png アップロード | `[url]`（絶対 URL）を返す |
| 2 | AJAX + 非画像 | `[false]` |
| 3 | `use_upload_image=false` | `[false]` を返す |
| 4 | CSRF トークン無し | 403 |

### 9.3 REST API（`Api/ContentsControllerTest`）

| # | ケース | 期待 |
|---|---|---|
| 1 | staff + png | 201 `{data:{file_name,url,size,mime}}` |
| 2 | 非 staff | 403 |
| 3 | 形式不正 | 415 |
| 4 | サイズ超過 | 413 |
| 5 | ファイル無し | 400 |
| 6 | `course_id` 権限なし（非 staff） | 403 |

### 9.4 MCP ツール（`Mcp/Tool/*Test`）

| # | ケース | 期待 |
|---|---|---|
| 1 | `upload_image` 正常 | `data.url` を返す |
| 2 | `upload_image` 非 staff | `ToolCallException` |
| 3 | `create_content(kind=markdown, images=[...])` | 本文末尾に `![alt](url)` 追記、`data.images` を返す |
| 4 | `create_content(kind=html, images=[...])` | `ToolCallException('images can only be used...')` |
| 5 | `update_content` images で kind が html のまま | エラー |
| 6 | 各新規ツールの権限（staff/manager） | 403 相当の `ToolCallException` |
| 7 | `update_user` の自己ロール変更・最後の管理者無効化 | `ToolCallException` |
| 8 | `reorder_contents` | `sort_no` が 1..N に更新 |
| 9 | `WRITE_TOOLS` に追加したツールの 21 回目呼び出し | 429（レート制限） |

### 9.5 回帰

- **現状の赤**: `McpControllerTest::testToolsListContainsAllTools` が失敗中（期待 9 / 実 11。
  `c7e51b9` のテスト問題ツール追加時に未更新）。本拡張の実装時に実装後のツール数へ更新して緑に戻す。
- `tools/list` 件数の増加に伴う既存アサーションの見直し。
- `Dev/scripts/smoke-mcp.sh` の「9 tools」コメントを実態（拡張後は拡張後の数）に合わせる
  （アサーションは最小集合のため現状でも緑）。
- PHPUnit notice 8 件（`Mcp/ToolErrorPropagationTest` の 8 テスト。`SessionInterface` /
  `Request` の mock に期待値を設定していない）。失敗ではないが、任意で stub 化または
  `#[AllowMockObjectsWithoutExpectations]` により解消する。
- `phpcs` / `phpstan`（`app/phpcs.xml`, `app/phpstan.neon`）を実行。

### 9.6 GUI 操作テスト（Playwright・必須）

PHPUnit / HTTP クライアントでは JS・DOM の不良（F-1 / F-2）を検出できないため、
実ブラウザ操作テストを必須とする。

- スクリプト: `Dev/scripts/gui-image-upload.py`（コンテンツは保存せず、画像のみアップロードする）
- 前提: Docker アプリ起動（既定 `http://localhost:8082`）、Python 3.10+、Playwright + Chromium
- 実行: `python3 Dev/scripts/gui-image-upload.py`
  （`BASE_URL` / `API_USER` / `API_PASS` / `COURSE_ID` で上書き可）
- 内容（16 チェック）: 管理者ログイン → markdown の新規作成画面 → EasyMDE 初期化 →
  `upload-image` ボタンでアップロード → 応答 URL が `/contents/file-image/`（admin prefix なし）→
  本文への画像記法挿入 → 画像 URL の取得（200 / `image/*`）→ プレビュー描画 →
  実クリップボード Ctrl+V 貼付け → 非画像の拒否と通知 → コンソールエラー 0 件

**実施結果（2026-10-15・Docker 実機）**:

| 状態 | 結果 | 備考 |
|---|---|---|
| 現行実装（修正前） | 11/16 PASS | #6 貼付けハンドラ / #9 URL prefix / #11 画像取得 / #13 Ctrl+V / #16 コンソールエラー が FAIL |
| F-1 / F-2 を一時適用して検証 | 16/16 PASS | URL は `http://localhost:8082/contents/file-image/...` を返し、Ctrl+V 貼付けも動作 |

- ドラッグ&ドロップは合成 DragEvent では再現できず自動化できないため手動確認項目とする
  （paste と同一ブロックで有効化されるため、F-1 の修正で有効になる）。
- 学習者画面での画像表示（front のコンテンツ閲覧）もリリース前の手動確認項目に含める。
- 実装完了時の完了条件は本スクリプト 16/16 PASS とする。

---

## 10. リスクと対策

| リスク | 影響 | 対策 |
|---|---|---|
| ツール数増加で `tools/list` が肥大 | クライアントのコンテキスト消費 | 説明文を簡潔化。Phase 単位で投入 |
| 書き込みツールの権限漏れ | 不正更新 | `Api\*` と同一条件を単体テストで網羅 |
| MCP base64 のボディ肥大 | 413 / タイムアウト | `mcp_image_maxbytes` + 早期検証。大きな画像は REST/admin を推奨 |
| 画像保存成功後にコンテンツ保存失敗 | 孤児画像 | `create_content` は画像を先に保存し、失敗時はコンテンツ未保存で中断 |
| EasyMDE 応答形式変更による Summernote 破壊 | リッチテキスト画像が壊れる | 応答を `[url]`/`[false]` のまま維持 |
| admin の `uploadImage` リファクタで挙動差分 | 既存機能の退行 | `Admin/ContentsControllerTest` の回帰 + 新規テスト |
| staff が全コース操作可（既存仕様） | 意図しない広権限 | 既存 API と同一。本設計では変更しない（明記） |

---

## 11. 確定事項・要確認

| # | 項目 | 内容 |
|---|---|---|
| K-1 | `create_record` の範囲 | 非テストコンテンツの手動進捗記録のみ。テスト採点は対象外 |
| K-2 | `get_user` の要否 | `get_user_profile` と重複。MCP クライアントの可読性優先で別名を追加 |
| K-3 | `copy_content` の `target_course_id` | 省略時は同一コース。指定時は staff の全コース可（Web と同様） |
| K-4 | `images[]` の挿入位置 | 本文末尾に追記。位置制御が必要なら `upload_image` 併用を案内 |
| K-5 | `mcp_image_maxbytes` | 既定 3,500,000 文字。運用で調整 |
| K-6 | `list_users` の一般ユーザ挙動 | 自レコード 1 件のみ返す（`GET /users` と同一） |
| K-7 | 画像配信・孤児削除 | 本設計の非目標。別設計で扱う |
| K-8 | MCP プロトコル機能（Resources 等） | 本設計の非目標。別設計で扱う |

---

## 12. 変更・追加ファイル一覧（予定）

### 追加

| ファイル | 内容 |
|---|---|
| `app/src/Service/ImageStorageService.php` | 画像検証・保存の共通サービス |
| `app/src/Service/ImageUploadException.php` | 検証理由を持つ例外 |
| `app/src/Mcp/Tool/UploadImageTool.php` | `upload_image` |
| `app/src/Mcp/Tool/ListUsersTool.php` ほか（§3.2.1） | 読み取り系ツール |
| `app/src/Mcp/Tool/DeleteContentTool.php` ほか（§3.2.2） | 書き込み・管理系ツール |
| `Dev/tests/TestCase/Service/ImageStorageServiceTest.php` | §9.1 |
| `Dev/tests/TestCase/Mcp/Tool/UploadImageToolTest.php` ほか | §9.4 |
| `Dev/tests/TestCase/Controller/Api/ContentsImageUploadTest.php` | §9.3 |
| `Dev/scripts/gui-image-upload.py` | §9.6 の GUI 操作テスト（Playwright） |

### 変更

| ファイル | 内容 |
|---|---|
| `app/src/Mcp/McpServerFactory.php` | 新規ツールの登録 |
| `app/src/Mcp/McpRateLimitMiddleware.php` | `WRITE_TOOLS` 更新 |
| `app/src/Service/AccessControlService.php` | `isManager()` 追加 |
| `app/src/Controller/Admin/ContentsController.php` | `uploadImage` をサービス化、返却 URL の F-2 修正 |
| `app/src/Controller/Api/ContentsController.php` | `uploadImage` 追加 |
| `app/src/Mcp/Tool/CreateContentTool.php` / `UpdateContentTool.php` | `images[]` 対応 |
| `app/config/ib_config.php` | `mcp_image_maxbytes` 追加 |
| `app/config/routes.php` | `POST /api/v1/contents/images` 追加 |
| `app/templates/Admin/Contents/edit.php` | EasyMDE の `uploadImage`（F-1）/`imageAccept`/`imageMaxSize`/`use_upload_image` 対応 |
| `Dev/tests/TestCase/Controller/McpControllerTest.php` | `tools/list` 期待値更新（現状の赤も解消） |
| `Dev/scripts/README.md` | `gui-image-upload.py` の実行方法を追記 |
| `Dev/scripts/smoke-mcp.sh` | ツール数のコメント更新 |

---

## 付録 A: `/api/v1/contents/images` の動作例

```bash
curl -sS -X POST 'https://example.com/api/v1/contents/images' \
  -H 'Authorization: Bearer <selector:validator>' \
  -F 'file=@diagram.png' \
  -F 'course_id=3'
```

```json
{
  "data": {
    "file_name": "20261015123456abcd.png",
    "url": "https://example.com/contents/file-image/20261015123456abcd.png",
    "size": 20480,
    "mime": "image/png"
  }
}
```

## 付録 B: MCP `create_content` with images の呼び出し例

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "create_content",
    "arguments": {
      "course_id": 3,
      "title": "図解つきレッスン",
      "kind": "markdown",
      "body": "# 図解\n\n以下の図を参照。",
      "status": 1,
      "images": [
        { "file_name": "diagram.png", "alt": "構成図",
          "data": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUg..." }
      ]
    }
  }
}
```

保存後の `body` 末尾に `\n\n![構成図](https://example.com/contents/file-image/<name>.png)` が追記され、
応答 `data.images` に配信 URL が返る。
