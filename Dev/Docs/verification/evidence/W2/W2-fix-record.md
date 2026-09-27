# W2 不備 修正記録（Fix Record）

> 対象: `W2-cause-analysis.md` の D-08〜D-12（D-11 は W1 で修正済）
> 実施日: 2026-09-26 / 実施: 2レーン並列（@fixer）+ 統合検証（orchestrator）
> 前提: 本記録は修正内容と検証結果の記録であり、実施記録（W2-execution-record.md）を上書きしない。

---

## 総括

| ID | 事象 | 修正内容 | 分類 | 重要度 | 状態 |
|---|---|---|---|---|---|
| D-08 | API/MCP の教材 Write が受講登録済み課程に限定（staff も 403） | Write 側にも `isStaff()` バイパスを追加 | 設計欠落 | S2 / P0 | 修正済 |
| D-09 | MCP ツールのエラーが `isError: false` で返る | `return ['error'=>…]` を `throw new ToolCallException` に置換 | 実装バグ | S2 / P0 | 修正済 |
| D-10 | ユーザー CSV インポートが機能しない（0件） | `getData()` → `getUploadedFile()` ＋ FormProtection 解除 | 実装バグ | S2 / P0 | 修正済 |
| D-11 | CSV の Content-Type charset 宣言不一致 | W1（commit `5f974df`）で修正済 | 実装バグ | S3 | 修正済 |
| D-12 | `upload` が FormProtection で 302 拒否 | `unlockActions` に `'upload'` を追加 | 設定/実装バグ | S2 / P0 | 修正済 |

**付随して発見・修正した 3 件**

| # | 内容 | 場所 | 重要度 |
|---|---|---|---|
| E-01 | `import` も FormProtection で blackHole され、CSV インポートが到達不能だった（D-10 の原因が二重に存在） | `Admin/UsersController.php:38` | S2 / P0 |
| E-02 | `set_time_limit(120)` が CLI で phpunit プロセス全体に効き、全スイートが 46% で fatal 中断 | `Admin/UsersController.php:374` | S2 |
| E-03 | `define('COL_*')` が import 複数回実行時に再定義警告（8件）と長寿命プロセスでの状態汚染 | `Admin/UsersController.php:358-365` | S3 |

---

## D-08: staff のコース Write バイパス

**根本原因**: Read 側（`index()`/`view()`）にのみ `isStaff()` 分岐があり、Write 側（`add`/`edit`/`delete`）には `accessibleCourseIds()`（受講登録のみ）を使う判定がそのまま残っていた。

**修正**:
- `src/Controller/Api/ContentsController.php` — `add()` / `edit()` / `delete()` に `isStaff()` バイパスを追加（Read 側と統一）
- `src/Service/AccessControlService.php` — `canAccessCourse()` に staff の即 true 早期 return を追加
- MCP 側: `CreateContentTool` / `UpdateContentTool` が `canAccessCourse()` に `$role` を渡すよう修正（他の MCP ツールは既に `!isStaff` ガードの後ろで呼ぶため変更不要）
- `tests/TestCase/Controller/Api/ContentsControllerWriteTest.php` — `testAddInaccessibleCourse` / `testEditInaccessibleCourse` の期待を「admin は 201/200」に更新

**互換性**: non-staff の挙動は不変。Web 管理画面は元来どおり（`Admin\ContentsController` は `accessibleCourseIds` を使わない）。

## D-09: MCP ツールのエラー伝達

**根本原因**: ツールが `return ['error' => '...']` という plain array を返していた。SDK はこれを成功結果として `CallToolResult(isError: false)` にラップするため、LLM クライアントはエラーを認識できない。

**修正**: 7 ツールで `return ['error'=>…]` を `throw new ToolCallException(...)` に置換（SDK が捕捉して `CallToolResult::error()`＝`isError: true` を返す）。

| ツール | 変換したエラー経路数 |
|---|---|
| `CreateContentTool` | 4（権限/アクセス/不正kind/バリデーション） |
| `UpdateContentTool` | 7 |
| `GetContentTool` | 3 |
| `GetContentHtmlTool` | 3 |
| `ListContentsTool` | 1 |
| `GetUserProfileTool` | 2 |
| `GetCourseTool` | 2 |
| `ListRecordsTool` | 0（元々正常） |

**テスト追随**: 既存 MCP テスト 21 件を新契約へ移行（`expectException(ToolCallException::class)` ＋メッセージ断定）。`testStaffWithoutEnrollmentDenied` 2 件は D-08 変更に伴い「staff は未受講コースに書込可（成功）」期待へ反転・改名。HTTP レベルの `McpControllerTest` にも `assertToolError()` ヘルパを追加し、`isError: true` を直接検証するよう更新。

## D-10: ユーザー CSV インポート

**根本原因**（二重）:
1. `getData('csvfile')` は `$_POST` のみ参照し、multipart のファイルは `$_FILES` にあるため常に `null`（CakePHP 4→5 移行未対応）
2. `import` アクションが `unlockActions` に無く、FormProtection が multipart POST を blackHole（`AppController::blackHole()` → `/admin/users/login` へ 302）

**修正**:
- `getData('csvfile')` → `getUploadedFile('csvfile')`、エラーチェックを `=== null || getError() !== UPLOAD_ERR_OK`、パス取得を `$csvfile->getStream()->getMetadata('uri')`
- `unlockActions` に `'import'` を追加（E-01）。理由: `FormProtector::extractFields()` は `getParsedBody()`（= `$_POST`）のみを材料にするため、ファイル入力は描画時ハッシュに含まれ送信時に存在せず、multipart では構造的に不一致。`upload` と同じ対処。

## E-02: `set_time_limit` の CLI ガード

**根本原因**: `import()` 内の `set_time_limit(120)` は CLI では `max_execution_time` が本来 0（無制限）だが、この呼び出しが phpunit プロセス全体に適用され、 suite 後半で fatal。

**修正**: `if (PHP_SAPI !== 'cli') { set_time_limit(120); }` にガード。

## E-03: `define()` のローカル変数化

**根本原因**: `COL_*` 列番号を `define()` でグローバル定数にしていたため、`import()` が同一プロセス内で複数回呼ばれると再定義警告（8件）が発生し、長寿命プロセスでは他リクエストへ状態を持ち込む。

**修正**: メソッドローカル変数 `$COL_LOGINID` 等に変更（使用 10 箇所）。

---

## 検証結果

### 自動テスト

```
bash scripts/test-fresh.sh
Tests: 675, Assertions: 3324, Failures: 0, Errors: 0, Warnings: 0, Deprecations: 2
```

- 前回基準 645 tests / 3173 assertions から **+30 tests / +151 assertions**
- Warnings 8 件（`define()` 再定義）は解消済み
- Deprecations 2 件は既存の `Query::order()` と `AppController::beforeFilter` 戻り値（本次修正対象外）

**追加テスト**:
- `tests/TestCase/Controller/Api/ContentsWriteAuthorizationTest.php`（新規 17件）— staff 4 ロールが未受講コースに書込可 / non-staff は 403
- `tests/TestCase/Mcp/ToolErrorPropagationTest.php`（新規 8件）— エラーが `ToolCallException` / staff は未受講でも成功
- `Admin/UsersControllerTest` — D-10 の 2件
- `Admin/ContentsControllerTest` — D-12 の 2件

**テスト整備上の修正**（製品コードの不備ではない）:
- `ContentsWriteAuthorizationTest` / `ToolErrorPropagationTest` のユーザ名にアンダースコアが含まれ、`UsersTable` の英数字検証（`/^[a-zA-Z0-9]+$/`）に違反して `save()` が失敗していた → 英数字のみに修正
- `ToolErrorPropagationTest` に `Configure::load('ib_config')` を追加（`content_kind` が空で `Invalid kind` になっていた）
- `testUploadMultipartPostIsNotBlockedByFormProtection` は `.txt`（`upload_extensions` に含まれる拡張子）を使っていたため「許可外」の前提が成立せず → `.php` に変更
- `testImportAcceptsUploadedCsvFile` の期待リダイレクトを `/admin/users` → `/admin` に修正（`routes.php:34-37` で `/admin` が `Admin\Users::index` に接続された正規URL。`redirect(['action'=>'index'])` は Admin 全体の既定書き方）

### ライブ実測（http://localhost:8082）

| ID | 実測内容 | 結果 |
|---|---|---|
| D-08 | admin トークンで未受講コース（course_id=1, admin の受講登録 0 件）へ `POST /api/v1/contents` | **201 Created**（`id=21`）。修正前は 403 |
| D-09 | 非staff（user1）トークンで MCP `tools/call create_content` | **`isError: true`**、本文 `Only staff members can create content.`。修正前は `isError: false` |
| D-10 | 管理画面ログイン後、multipart でヘッダ行のみ CSV を `POST /admin/users/import` | **302 → `/admin`**、Flash「インポートが完了しました」。修正前は 302 → `/admin/users/login` |
| D-12 | 管理画面ログイン後、multipart で許可外拡張子 `.php` を `POST /admin/contents/upload/file` | **200 OK**、Login へのリダイレクトなし、「アップロードされたファイルの形式は許可されていません」を表示 |
| スモーク | `bash scripts/smoke-api.sh` / `bash scripts/smoke-mcp.sh` | **3/3 PASS** / **4/4 PASS** |

検証で作成したコンテンツ（`title='D-08 ライブ検証'`）は削除済み。一時ファイルも削除済み。

---

## 変更ファイル一覧

### 製品コード
- `src/Controller/Api/ContentsController.php`（D-08 Write 側 staff バイパス）
- `src/Service/AccessControlService.php`（D-08 `canAccessCourse()` の staff 早期 return）
- `src/Mcp/Tool/{Create,Update,Get,List}*Tool.php` 計7ファイル（D-08 `$role` 伝播 + D-09 例外化）
- `src/Controller/Admin/UsersController.php`（D-10 `getUploadedFile`、E-01 `unlockActions`、E-02 CLI ガード、E-03 ローカル変数化）

### テスト
- `tests/TestCase/Controller/Api/ContentsControllerWriteTest.php`（D-08 期待値更新）
- `tests/TestCase/Controller/Api/ContentsWriteAuthorizationTest.php`（新規 17件）
- `tests/TestCase/Mcp/ToolErrorPropagationTest.php`（新規 8件）
- `tests/TestCase/Mcp/Tool/` 6ファイル（D-09 新契約へ 21件移行）
- `tests/TestCase/Controller/McpControllerTest.php`（`assertToolError()` 追加、2件更新）
- `tests/TestCase/Controller/Admin/{Users,Contents}ControllerTest.php`（D-10/D-12 回帰 4件）

---

## 残課題・次フェーズ

- **D-10 の真の完全性**: `import` はFormProtection を解除したが、これは「multipart では構造的に検証できない」ための対処。CSV 取込は大量データ・rikeDrawing 文字列を含むため、別途 ファイル内容バリデーション + 1行あたりのトランザクション方針の確認を推奨（W3 以降）。
- **D-12 も同じ方針**: `upload` の解除により CSRF（`CsrfProtectionMiddleware`）のみが一道orkshop。ファイル自体の種類・サイズ検証はコントローラ側で行っている（既存）。
- **`_orchestrator-corrections.md` の指摘**（D-12 と D-29 ＝ `RecordsController` の同種問題）は未着手。`RecordsController` の `unlockActions` 欠落は W4 范围的 D-29 として残る。
- 本記録時点ではコミット未実施。

---

## 独立レビュー結果（@oracle 2レーン・読み取り専用）

W2 P0 修正（D-08〜D-12 + E-01〜E-03）の製品コードとテスト変更を、Orchestrator 以外の独立レビューに付した。

### 製品コード（`src/`）: **承認。S1〜S3 の問題なし**

| 検証項目 | 結論 |
|---|---|
| D-08 権限昇格の有無 | 問題なし。`isStaff()` は `Api\BaseController` / `AccessControlService` / `RoleComponent` の3実装で同一ロール集合（`admin, manager, editor, teacher`）。Web 管理画面と一致 |
| D-08 適用漏れ | なし。`MoveContentTool` は不存在、`CoursesController` 書込は `requireManager()` で対象外、`ListRecordsTool` は読取のみ |
| D-09 完全性 | 完全。`src/Mcp/Tool/` に `return ['error' => ...]` は 0 件。SDK の `CallToolHandler` が `ToolCallException` → `CallToolResult::error()`（`isError: true`）へ変換する経路を確認。`details` も `json_encode` で文字列化され保持 |
| CSRF/FormProtection | 穴なし。`unlockActions` は FormProtection のみ解除。`CsrfProtectionMiddleware` は `/admin/*` で依然有効。`upload`/`import` は `AppController::beforeFilter()` の staff ガードで未認証到達不可 |
| D-10 | 正しい。`getError() !== UPLOAD_ERR_OK` は strict 比較で適切。`getStream()->getMetadata('uri')` は `moveTo()` 前の一時パスとして妥当で `Utils::getCsvData()` の `file_get_contents()` と整合 |
| E-02 | 正しい。`set_time_limit` は `UsersController` の1件のみ |
| E-03 | 正しい。`COL_` のグローバル参照は 0 件、ローカル変数は同一ブロック内で定義・参照 |
| 新規混入バグ | なし（`edit()` の `$accessibleIds` 初期化、`delete()` のスコープ、例外の未 catch すべて安全） |

### テスト変更: 概ね妥当。軽微 4 件を反映済み

| ID | 内容 | 対応 |
|---|---|---|
| R-3 | `testImportAcceptsUploadedCsvFile` の `assertRedirect('/admin')` が誤りの可能性 | **非該当と確定**。`config/routes.php:31-33` で `/admin` → `Admin\Users::index` に接続済み。全スイート green で実挙動とも一致 |
| R-4 | `testAddInaccessibleCourse` / `testEditInaccessibleCourse` が名指しする「inaccessible」に対し実 assert は成功（201/200） | **反映済**。`testStaffCanAddToUnenrolledCourse` / `testStaffCanEditToUnenrolledCourse` へ改名（docblock も更新） |
| R-6 | `testUserCanAddContentToEnrolledCourse` は名が「追加できる」だが実 assert は 403 | **反映済**。`testUserCannotAddContentEvenIfEnrolled` へ改名 |
| R-2 | `testUploadActionIsInUnlockActions` のソース文字列検査が `uploadImage` に誤マッチしうる | **反映済**。`assertMatchesRegularExpression("/unlockActions\(\[[^\]]*'upload'[^\]]*\]\)/")` へ厳密化 |
| R-5 | `issueToken()` のシグネチャが既存テストと不一致（パスワードハードコード） | **反映済**。`issueToken(string $username, string $password = 'testpass')` に変更 |

### S4 改善（将来のリファクタで権限昇格に化ける风险的除去）

読み取り系 MCP 4ツールで `canAccessCourse()` に `$role` を明示的に渡すようにした。現状は `!isStaff` ガード内で動作は正しいが、ガード解除時に `$role=''`（= non-staff 扱い）が意図せず適用されるリスク在未来リファクタで排除する。

- `src/Mcp/Tool/GetContentTool.php:60`
- `src/Mcp/Tool/GetContentHtmlTool.php:64`
- `src/Mcp/Tool/GetCourseTool.php:54`
- `src/Mcp/Tool/ListContentsTool.php:58`

### カバレッジ穴（未検証として記録。挙動不具合ではない）

- D-08: MCP 経由の delete、`GetCourseTool`/`GetContentTool`/`ListContentsTool` の staff 成功パス、受講済みコースでの回帰
- D-10: 不正 CSV（カラム不足・英数字外ユーザー名）、デモモード時挙動
- D-12: 許可拡張子でのアップロード成功パス、サイズ上限超過

### 反映後の再検証

`bash scripts/test-fresh.sh` → **675 tests / 3324 assertions / Errors 0 / Failures 0 / Deprecations 2**（green 維持）。