# W2 検出項目 原因解析（Root Cause Analysis）

> 対象: `Docs/verification/evidence/W2/W2-execution-record.md`・`W2-retest-record.md` の D-08〜D-12
> 実施日: 2026-09-26 / 方法: 静的コード点検（file:line）＋ W2実測・再試験記録の参照
> 前提: コード修正は未実施（D-11 のみ修正済み）。原因の特定と影響評価のみ。

---

## 総括

| ID | 事象 | 根本原因（要約） | 分類 | 重要度 |
|----|------|------------------|------|--------|
| D-08 | API/MCP の教材 Write が受講登録済み課程に限定 | `accessibleCourseIds()` が受講関係テーブルの UNION クエリのみでロールによるバイパス分岐がない。Read 側（`index()`）にのみ `isStaff()` 分岐が存在し、Write 側（`add()`/`edit()`/`delete()`）に移植されなかった | 設計欠落 | S2 / P0 |
| D-09 | MCP ツールのエラーが `isError: false` で返る | ツールがエラーを plain array（`['error' => '...']`）で return しており、SDK はこれを成功結果として `CallToolResult(isError: false)` にラップする。`isError: true` を立てる経路（`ToolCallException` / `CallToolResult::error()`）が利用されていない | 実装バグ | S2 / P0 |
| D-10 | ユーザー CSV インポートが機能しない | `getData('csvfile')` は `$_POST` のみ参照。multipart のファイルは `$_FILES` 側にあり、CakePHP 5 では `getUploadedFile()` で取得する必要がある | 実装バグ | S2 / P0 |
| D-11 | CSV の Content-Type charset 宣言が UTF-8 だった（修正済み） | CSV 本文は `mb_convert_variables('SJIS-WIN', ...)` で変換済みだが、`withType('csv')` の CakePHP 既定 charset=UTF-8 がヘッダに残り、明示上書きがなかった | 実装バグ（修正済） | S3 |
| D-12 | `upload` が FormProtection で 302 拒否 | `unlockActions` に `upload` が含まれておらず、multipart で `$_FILES` が `$_POST` に無いことが FormProtection のハッシュ検証と恒常的に不一致 | 設定/実装バグ | S2 / P0 |

---

## D-08: `accessibleCourseIds()` に staff バイパスなし → Write が受講登録済み課程に限定

### 根本原因

**`accessibleCourseIds()` は受講登録テーブルの UNION クエリでユーザーが受講済みのコース ID のみを返し、ロール（admin/manager/editor/teacher）によるバイパス分岐が存在しない。Read 側にのみ `isStaff()` による分岐が実装されており、Write 側への移植が漏れている。**

### 根拠（file:line・grep 実測）

| 場所 | 内容 |
|------|------|
| `src/Controller/Api/BaseController.php:394` | `protected function accessibleCourseIds(int $userId): array` — 受講関係のみで構成。ロール判定なし |
| `src/Controller/Api/BaseController.php:288` | `protected function isStaff(): bool` — `['admin','manager','editor','teacher']` の判定（**Write 側からは呼ばれていない**） |
| `src/Service/AccessControlService.php:47` | 同一ロジックのサービス版 `isStaff(string $role)` |
| `src/Service/AccessControlService.php:61` | サービス版 `accessibleCourseIds()`。`canAccessCourse()` もこれに依存 |
| `src/Controller/Api/ContentsController.php:35` | **Read 側**: `if ($this->isStaff()) {` — staff は全コース参照可 |
| `src/Controller/Api/ContentsController.php:152` | **Write `add()`**: `$accessibleIds = $this->accessibleCourseIds(...)` → staff バイパスなし |
| `src/Controller/Api/ContentsController.php:197` | **Write `edit()`**: 同上 |
| `src/Controller/Api/ContentsController.php:259` | **Write `delete()`**: 同上 |
| `src/Mcp/Tool/CreateContentTool.php:64` | MCP Write: `if (!$this->accessControl->isStaff($role))` の後 `:68` で `canAccessCourse()`（`accessibleCourseIds` 依存） |
| `src/Mcp/Tool/UpdateContentTool.php:64,77` | MCP Write: 同様に `canAccessCourse()` で受講参加を要求 |
| `src/Mcp/Tool/ListCoursesTool.php:51` | **MCP Read**: `if (!$this->accessControl->isStaff($role))` で staff は全コース一覧可（非対称の証拠） |

### 発生メカニズム

1. admin ユーザーが `POST /api/v1/contents`（course_id=1、未受講）を送信
2. `requireStaff()` を通過（admin は staff）
3. `accessibleCourseIds()`（`:152`）が admin の受講済みコースを返す（未受講なら `[]`）
4. `in_array($courseId, $accessibleIds, true)` → `false`
5. 403 `'You do not have access to this course'`

**Read 側では `isStaff()` で全コース参照可、Write 側では受講登録必須**という非対称。

### 影響

- admin/manager/editor/teacher が担当外コースのコンテンツを API/MCP 経由で作成・編集・削除できない
- Web 管理画面（`Admin/ContentsController`）は `accessibleCourseIds` を使わないため正常 → **Web と API の権限不整合**
- W2 再試験実測: admin / editor1 / teacher1 の `POST /api/v1/contents` が 3 ロールとも 403

### 分類: **設計欠落** — Read の `isStaff()` 分岐が Write に移植されていない

---

## D-09: MCP ツールのエラーが `isError: false` のまま JSON テキストで返る

### 根本原因

**MCP ツールがエラー時 `['error' => '...']` の plain array を return しており、MCP SDK はこれを成功結果として `CallToolResult(isError: false)` にラップする。`isError: true` を立てる経路（`ToolCallException` の throw、または `CallToolResult::error()` の return）が利用されていない。**

### 根拠（file:line・grep 実測）

- **ツール側の plain array return（15 箇所以上）**:
  - `src/Mcp/Tool/CreateContentTool.php:64` 付近 — `return ['error' => 'Only staff members can create content.']` / `Access denied to this course.` / `Invalid kind...` / `Validation failed.`
  - `src/Mcp/Tool/UpdateContentTool.php:65,74,78,85,104,124`
  - `src/Mcp/Tool/GetUserProfileTool.php:48,57`、`GetContentHtmlTool.php:58,64,67`、`ListContentsTool.php:58`、`GetContentTool.php:54,60,64` ほか
- **SDK 側**:
  - `vendor/mcp/sdk/src/Server/Handler/Request/CallToolHandler.php:116` — 戻り値が `CallToolResult` でない plain array を成功扱いで `new CallToolResult(...)`（既定 `isError: false`）にラップ
  - 同 `:154-163` 付近 — `ToolCallException` catch 時のみ `CallToolResult::error(...)` を返す
  - `vendor/mcp/sdk/src/Exception/ToolCallException.php:17` — `final class ToolCallException extends \RuntimeException`
  - `vendor/mcp/sdk/src/Schema/Result/CallToolResult.php:79` — `public static function error(array $content, ?array $meta = null): self`（`isError: true` を渡す静的ファクトリ）
  - `CallToolResult.php` の仕様コメント — エラーは結果オブジェクト内で `isError: true` として報告すべきで、そうしなければ **LLM がエラーを認識できず自己修正できない**

### 発生メカニズム

1. MCP クライアントが `tools/call`（例: `create_content`、未受講 course_id）
2. ツールが `['error' => 'Access denied to this course.']` を return
3. `CallToolHandler` が plain array を `TextContent`（`{"error": "..."}` の JSON 文字列）に変換し `isError: false` でラップ
4. クライアント（LLM）は「成功」と解釈し、JSON テキストを結果として処理

### 影響

- 権限なし・バリデーション失敗等のエラーが「成功」として返り、クライアントが誤判断する
- W2 再試験実測: `create_content`（未受講）で `isError = false` / `text = {"error": "Access denied to this course."}`

### 分類: **実装バグ** — エラー伝達パスの設計ミス（plain return vs exception / `CallToolResult::error()`）

---

## D-10: ユーザー CSV インポートが機能しない

### 根本原因

**`UsersController::import()` が `ServerRequest::getData('csvfile')` でファイルを取得しているが、CakePHP 5 の `getData()` は `$_POST` のみ参照し、`$_FILES`（`withUploadedFiles`）側にある multipart ファイルは返さないため常に `null` となり、「インポートファイルが指定されていません」で終了する。**

### 根拠（file:line・grep 実測）

| 場所 | 内容 |
|------|------|
| `src/Controller/Admin/UsersController.php:376` | `$csvfile = $this->request->getData('csvfile');` ← **誤りの API** |
| 同 `:379-380` 付近 | `!is_array($csvfile)` → `Flash->error('インポートファイルが指定されていません')` で終了 |
| `src/Controller/Admin/ContentsController.php:267` | **正しい使用例**: `$this->request->getUploadedFile('file')` |
| `src/Controller/Admin/ContentsController.php:324` | 同（`uploadImage`） |

### メカニズム

CakePHP 5 では `$_FILES` が `$_POST` にマージされない（CakePHP 4 からの BREAK 変更）。`getData()` は `withParsedBody` 側のみを見るため `null`。本来は `getUploadedFile('csvfile')` を使う。

### 影響

- 管理画面からの一括ユーザーインポートが完全に機能しない（DB 反映 0 件・W2 再試験実測）
- CSV インポートに依存する運用（学期初の一括登録）が不可能
- テスト不在のため開発段階で検出されなかった

### 分類: **実装バグ** — CakePHP 4→5 移行時の API 変更への未対応

---

## D-11: CSV の Content-Type charset 宣言が UTF-8 だった（✅ 修正済み）

### 現状

**現状コードは修正済み**（commit `5f974df`）。3 箇所の CSV 出力に
`->withHeader('Content-Type', 'text/csv; charset=SJIS-WIN')` が追加され、宣言と実体が一致している（grep 実測で確認済み）。

### 修正前のずれの原因

**根本原因: CSV 本文は `mb_convert_variables('SJIS-WIN', 'UTF-8', ...)` で CP932 に変換済みだが、`Response::withType('csv')` の CakePHP 既定が `charset=UTF-8` のままヘッダに残り、明示的に上書きする処理がなかった。**

### 根拠（file:line・grep 実測）

| 場所 | 内容 |
|------|------|
| `src/Controller/Admin/RecordsController.php:158,175` | `mb_convert_variables('SJIS-WIN', 'UTF-8', ...)` — ヘッダ行と本体（**実体は SJIS-WIN**） |
| `src/Controller/Admin/RecordsController.php:230,282` | 同（詳細 CSV） |
| `src/Controller/Admin/UsersController.php:577,634` | 同（ユーザー CSV） |
| `src/Controller/Admin/RecordsController.php:181,288` | 修正後: `withHeader('Content-Type', 'text/csv; charset=SJIS-WIN')` |
| `src/Controller/Admin/UsersController.php:643` | 修正後: 同上 |
| `vendor/cakephp/cakephp/src/Http/Response.php` | `$_charset = 'UTF-8'` が既定。`withType()` は charset を上書きしない |

### 修正前後の対比

| 項目 | 修正前 | 修正後 |
|------|--------|--------|
| CSV 本文 | SJIS-WIN | SJIS-WIN（変更なし） |
| Content-Type | `text/csv; charset=UTF-8`（既定） | `text/csv; charset=SJIS-WIN`（明示） |
| 宣言と実体 | **不一致** | **一致** |

### 影響（修正前）

Excel 等が `charset=UTF-8` を信じて開こうとし SJIS-WIN のバイナリが文字化けする。CP932 手動指定なら正常（W2 実測で確認）。

### 分類: **実装バグ（修正済）** — charset ヘッダ未更新

---

## D-12: `upload` が FormProtection で 302 拒否 → file/movie アップロードが利用不可

### 根本原因

**`Admin/ContentsController::initialize()` の `unlockActions` に `upload` が含まれておらず、FormProtection が multipart POST をトークン検証する。multipart ではファイル入力が `$_FILES` 側にあり `$_POST`（`extractFields()` の対象）に含まれないため、描画時と送信時のフィールド集合が恒常的に不一致となり、`FormProtector::validate()` が失敗して `blackHole()` → 302 が返される。**

### 根拠（file:line・grep 実測）

| 場所 | 内容 |
|------|------|
| `src/Controller/Admin/ContentsController.php:31` | `$this->FormProtection->unlockActions(['order', 'preview', 'uploadImage', 'copy']);` — **`upload` なし** |
| `src/Controller/Admin/ContentsController.php:243` | `public function upload($file_type): void` — 対象アクション |
| `src/Controller/Admin/ContentsController.php:319` | `public function uploadImage()` — **unlock 対象（= 成功する）** |
| `src/Controller/Admin/ContentsController.php:267` | `getUploadedFile('file')` — multipart で `$_FILES` 側 |
| `templates/Admin/Contents/upload.php` の `Form->create(null, ['type'=>'file', ...])` | フォーム描画時に `_Token` を発行 |

### 発生メカニズム

1. フォーム描画時: FormHelper が `_Token[fields]` 等を生成（フォーム宣言入力を基準にハッシュ）
2. 送信時: `$_POST` に `_Token`、`$_FILES` に `file`
3. `FormProtectionComponent` は `unlockActions` に `upload` がないため検証を実行
4. `FormProtector::extractFields()` は **`$_POST` のみ**からフィールド一覧を作る → ファイル入力が欠落
5. ハッシュ不一致 → `validate()` 失敗 → `blackHole` → 302

### 対照（W2 再試験で確認済み）

| アクション | `unlockActions` | 結果 |
|---|---|---|
| `uploadImage` | **あり** | **HTTP 200 成功** |
| `upload` | **なし** | **302（blackHole）** |

### D-29（W4）との共通根本原因

`_orchestrator-corrections.md` の指摘どおり D-12 と D-29 は**同一原因クラス**:

| 事実 | D-12 | D-29 |
|------|------|------|
| `unlockActions` の状態 | `upload` が不在（`:31`） | `unlockActions([])`（空配列＝全アクション検証対象） |
| 送信側 | multipart で `$_FILES` が `extractFields()` 対象外 | JS が `_csrfToken` のみ送信（`_Token` 不在） |
| 結果 | 302 blackHole | 302 blackHole → login へ |

**構造的問題**: FormProtection のトークン binding が「発行元ページ URL」に固定されており、AJAX/iframe 経由で URL が変わると恒久的に失敗する。

### 影響

- 「配布資料」（`kind=file`）と「動画」（`kind=movie`）のアップロードがブラウザ・API 双方で利用不可
- `uploadImage`（Summernote 画像挿入）のみ機能

### 分類: **設定/実装バグ** — `unlockActions` への `upload` 追加漏れ + multipart と FormProtection の不整合

---

## 付録: 共通する構造的問題

| 問題 | 該当 D-ID | 構造 |
|------|-----------|------|
| Read/Write の権限モデル非対称 | D-08 | Read は `isStaff()` で全コース可、Write は受講登録必須。staff バイパスが Read にのみ存在 |
| MCP ツールのエラー伝達設計の欠如 | D-09 | plain array の return → SDK 成功扱い。`ToolCallException` / `CallToolResult::error()` が未使用 |
| CakePHP 4→5 移行時の API 変更への未対応 | D-10, D-11 | `getData()` が `$_FILES` を見なくなった / charset が `withType()` で上書きされなくなった。互換レイヤー不在 |
| FormProtection と JS/multipart の不整合 | D-12（＋D-29） | `unlockActions` 欠落 + 送信側のフィールド集合が `extractFields()` 対象と不一致 |

---

## 付録2: 修正優先度の提案（参考・未実施）

| ID | 対応案 | 理由 |
|---|---|---|
| D-08 | `accessibleCourseIds()` 冒頭に `if (isStaff()) return 全コース;`、または `add()`/`edit()`/`delete()` に `isStaff()` 分岐を追加 | Read と Write の権限モデルを統一 |
| D-09 | ツールの `return ['error' => ...]` を `throw new ToolCallException(...)` に変更（または `CallToolResult::error()` を返す） | MCP 仕様適合・LLM にエラーを認識させる |
| D-10 | `getData('csvfile')` → `getUploadedFile('csvfile')`、`is_array` チェック → `!== null` チェック | CakePHP 5 の正しい API |
| D-11 | ✅ 修正済み | — |
| D-12 | `unlockActions` に `'upload'` を追加（D-29 は `RecordsController` の対象アクションを unlock） | CSRF を弱めずに FormProtection を回避 |

> 本解析は検査記録であり、コード修正は行っていない。
