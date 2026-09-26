# W1／W2 不備の再試験記録（commit `5f974df` 対応分）

| 項目 | 内容 |
|------|------|
| 実施日 | 2026-09-26 |
| 実施対象 | commit `5f974df fix: W1/W2検出の不具合を修正（APIレート制限/セキュリティヘッダ/アップロード/demo_mode/ロックアウトDoS/CSV charset）` |
| 実施方法 | 自動テスト（`scripts/test-fresh.sh`）＋ 静的コード点検 ＋ 実 HTTP 外部観測（curl）。**アプリケーションの修整は行っていない（検査係）** |
| 対象環境 | `http://localhost:8082`（`irohaboard5-web-1`）／ MariaDB 11.4.13（port 13307, db `irohaboard`） |
| 前回記録 | [W1-execution-record.md](../W1/W1-execution-record.md) / [W1-cause-analysis.md](../W1/W1-cause-analysis.md) / [W2-execution-record.md](W2-execution-record.md) |
| 作業ツリー | 実施時点で `git status` クリーン（未追跡は `.slim/` のみ） |

---

## 1. 再試験サマリ

| ID | 不備 | 判定 | 根拠 |
|----|------|------|------|
| D-01 | API レート制限なし | ✅ **修正確認** | 正しい Bearer で `/api/v1/courses` を 130 回連続 GET → **200 が 120 件・429 が 10 件、初発 429 は 121 回目**。`Retry-After: 9` / `X-RateLimit-Limit: 120` / `X-RateLimit-Remaining: 0`、本文は API エラー契約どおり |
| D-02 | `file` 種別のアップロードが常時拒否 | ⚠️ **部分修正（実操作は不可）** | `upload()` の設定キー解決は分岐された（`file` → `upload_extensions`/`upload_maxsize`）。しかし **FormProtection により POST が 302 で弾かれ、実アップロードは成立しない**。→ **D-12 として新規記録** |
| D-03 | demo_mode 判定漏れ（4 画面） | ⚠️ **部分修正** | Groups(2) / ContentsQuestions(3) / EnquetesQuestions(3) に追加。**`Admin/RecordsController.php` の `demo_mode` 参照は 0 件＝未修正** |
| D-04 | CSP/HSTS/Referrer-Policy/Permissions-Policy 未設定 | ✅ **修正確認** | `Referrer-Policy: strict-origin-when-cross-origin`、`Permissions-Policy: geolocation=(), microphone=(), camera=()`、`Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'` が付与。**HSTS は HTTPS 限定のため HTTP 環境では不在（仕様どおり）** |
| D-05 | セキュリティヘッダが Apache 層のみ | ✅ **修正確認** | アプリ層 `SecurityHeadersMiddleware`（`src/Application.php:86`）で付与。`X-Frame-Options`/`X-Content-Type-Options` はアプリ層と Apache vhost の**二重出力**（値は同一） |
| D-06 | Prelock がユーザー名単位（ロックアウト DoS） | ✅ **修正確認** | 同一ユーザーで 11 回失敗後 block 表示。**別ユーザー `user1` への悪影響なし**（302=ログイン成功） |
| D-07 | Cookie `Secure` 不在 | ⚠️ **要判定のまま** | `config/app.php` に `'secure' => filter_var(env('SESSION_SECURE', false)...)` と `cookie_samesite => 'Lax'` を追加。HTTP 環境では `Secure` の実効を確認できない |
| D-08 | API/MCP の教材 Write が受講登録済み課程に限定 | ❌ **未修正** | `accessibleCourseIds()` に staff バイパスなし（静的）。実測でも admin / editor1 / teacher1 の `POST /api/v1/contents`（course_id=1、未受講）は **3 ロールすべて 403**。対照に `GET /api/v1/courses`（admin）= 200 |
| D-09 | MCP ツールエラーが `isError: false` のまま | ❌ **未修正** | `isError` の設定は `src/` 内に **0 件**。実測でも `create_content`（未受講 course_id=2）が `isError = False` / `text = {"error": "Access denied to this course."}`、JSON-RPC `error` メンバなし |
| D-10 | ユーザー CSV インポートが機能しない | ❌ **未修正** | `getUploadedFiles` の使用箇所 **0 件**、`getData('csvfile')` **1 件**のまま。実測でも multipart POST が「インポートファイルが指定されていません」で終了し **DB 反映 0 件** |
| D-11 | CSV の charset 宣言と実体の不一致 | ✅ **修正確認** | 3 つの CSV 出力（`/admin/users?cmd=export`・`/admin/records?cmd=csv`・`?cmd=csv_detail`）すべて `Content-Type: text/csv; charset=SJIS-WIN`。実体 CP932 と一致 |
| **D-12** | **新規: `file`／`movie` 種別のアップロードがブラウザから利用不可** | ❌ **新規不備** | 正しい手順（iframe ページ取得→トークン付き multipart POST）でも **302（`AppController::blackHole()`）**。`uploadImage` のみ成功。→ [D-12 の詳細](#3-d-12-の詳述) |

---

## 2. 自動テストのベースライン

```
bash scripts/test-fresh.sh
→ Tests: 645, Assertions: 3173, Deprecations: 2, Errors: 0, Failures: 0
```

- 前回 609 件から **+36 件**（`ApiRateLimitMiddlewareTest` 219 行 / `SecurityHeadersMiddlewareTest` 186 行 / `BruteForceIpLockoutTest` 302 行、各 Admin コントローラ、ApplicationTest 改訂）。
- Deprecation 2 件は既知（`Admin/ContentsControllerTest` の `Query::order`、`AppController::beforeFilter` のイベント戻り値）。

---

## 3. D-12 の詳述

### 影響

**「配布資料」（`kind=file`）と「動画」（`kind=movie`）のアップロードが、ブラウザからも API 的にも利用不可。**
`uploadImage`（Summernote の画像挿入）だけが機能する。

### 再現手順（正しい手順でも失敗する）

1. `GET /admin/contents/upload/file`（iframe ページ）を取得し、ページ内のフォームとトークンを読む
2. そのトークンを付して同一 URL へ multipart POST

**結果: 全 POST が HTTP 302**（`AppController::blackHole()` の「トークンの有効期限が切れました」→ login へリダイレクト）。
`_Token[unlocked]=file` を明示的に送っても同じ。

### 対照実験（原因の切り分け）

| アクション | `unlockActions` の有無 | multipart POST の結果 |
|---|---|---|
| `uploadImage` | **あり**（`Admin/ContentsController.php:31`） | **HTTP 200 成功** |
| `upload` | **なし** | 302（blackHole） |

### 原因（コード読解）

1. `FormProtectionComponent::startup()` は `unlockActions` に無いアクションで POST を受けると `FormProtector::validate($data, $url, $sessionId)` を実行する。
2. `FormProtector::extractFields()` は `$formData`（= `$request->getParsedBody()`、multipart では `$_POST` のみ）からフィールド一覧を作る。
3. 一方 `FormHelper` は **form の宣言入力**を基準にハッシュを生成する → 検証時と描画時でフィールド集合が食い違う。
4. 結果として、**ファイル入力 `file`（`$_FILES` 側）が解析対象の POST ボディに含まれないため**ハッシュが恒常的に不一致になる。
5. `upload` 側には `unlockField('file')` による回避も、`unlockActions` への追加もされていない。

### 補足

- `ib_config.php` に `upload_file_extensions` / `upload_file_maxsize` は**依旧不在**（`upload_extensions` / `upload_maxsize` が実在）。D-02 はコントローラ側で解決する方針。
- したがって **D-02 の設定キー問題と D-12 の FormProtection 問題は独立した 2 つの原因**であり、D-02 を直しても D-12 は残る。

---

## 4. 判定

- 修正確認: **D-01 / D-04 / D-05 / D-06 / D-11（5 件）**
- 部分修正: **D-02（実操作は不可→D-12 へ）/ D-03（Records 未）/ D-07（要判定のまま）**
- 未修正: **D-08 / D-09 / D-10（3 件とも P0）**
- 新規: **D-12（S2/P0 相当）**
- **再試験は不合格**。P0 4 件（D-08 / D-09 / D-10 / D-12）が未解決。
- 自動テスト 645 件が緑である一方、`Api/`・`Mcp/`・アップロード経路の**契約テストが不足している**（緑 = 適合ではない）。

---

## 5. 後片付け

- 再試験で生成した `ib_contents` の `retest-probe` / `retest-mcp` 行を削除（残 0）
- `ib_logs` の `retestlock` 12 件を削除
- `ib_users` / `ib_courses` / `ib_records` は元の状態を維持
