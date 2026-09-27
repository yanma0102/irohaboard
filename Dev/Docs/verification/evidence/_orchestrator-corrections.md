# Orchestrator による検証結果の訂正と再検証記録

| 項目 | 内容 |
|------|------|
| 実施日 | 2026-09-26 |
| 位置づけ | 各ウェーブ記録（レーン実施分）と **Orchestrator が実施した独立再実測**の差分を記録する。原本の記録ファイルは**改変していない**（出所の追跡可能性のため） |
| 対象 | W4 / W6 / W7 の記録、および W1-fix-record.md の主張 |

---

## 1. W7 記録の「修正済み」判定は一部**不正**だった

W7 レコードは `evidence/W1/W1-fix-record.md` の「修正済み」記載をそのまま信頼し、完了条件 §5-2「P0 に製品起因の NG が 0」を **✅ 充足** と判定している。

しかし `commit 5f974df` を対象に Orchestrator が再実測した結果、**D-10 は未修正**であり、コード上も未修正であることが確認された。

### 1.1 コード実体（決定的証拠）

| ファイル:行 | 実体 |
|---|---|
| `src/Controller/Admin/UsersController.php:376` | `$csvfile = $this->request->getData('csvfile');` ← **未修正** |
| `src/Controller/Admin/UsersController.php:379` | `if (!is_array($csvfile) \|\| $csvfile['error'] != 0) {` |
| `src/Controller/Admin/UsersController.php:386` | `Utils::getCsvData($csvfile['tmp_name']);` |
| `getUploadedFile(...)` の使用箇所 | **`Admin/ContentsController.php:267,324` のみ**。`UsersController` には**使用箇所なし** |

`ServerRequest::getData()` は `$_POST`（`withParsedBody`）のみを参照し、multipart の実ファイルは `$_FILES`（`withUploadedFiles`）側にある。よって `is_array($csvfile)` は常に false となり、**CSV インポートは恒久的に失敗する**。

### 1.2 実測（再確認）

multipart POST（ファイル入力名 `csvfile`）→ HTTP 200 だが本文「インポートファイルが指定されていません」、**`ib_users` 反映 0 件**。

### 1.3 訂正後の真相

| ID | W7 の判定 | **訂正後の判定** | 根拠 |
|----|-----------|----------------|------|
| D-01 | 修正済み ✅ | 修正済み ✅（**変更なし**） | 再実測で 121 回目 429 を確認 |
| D-02 | 修正済み ✅ | ⚠️ **部分修正** | 設定キーは解決したが、実アップロードは FormProtection で不可 → **D-12** |
| D-03 | 修正済み ✅ | ⚠️ **部分修正** | Groups / ContentsQuestions / EnquetesQuestions のみ。**`Admin/RecordsController.php` の `demo_mode` は 0 件** |
| D-04 / D-05 / D-06 / D-11 | 修正済み ✅ | 修正済み ✅（**変更なし**） | 再実測で確認 |
| D-08 | 判定要 | ❌ **未修正** | staff 3 ロールの `POST /api/v1/contents` が依然 403 |
| D-09 | 判定要 | ❌ **未修正** | `isError` 設定 0 件、`create_content` が `isError=false` のまま |
| **D-10** | 修正済み ✅ | ❌ **未修正** | 上記 1.1 / 1.2 |
| **D-12** | （未記載） | ❌ **新規・未修正** | FormProtection により `upload` が 302 で拒否 |

**完了条件 §5-2 の判定は「未充足」に訂正**（P0 に製品起因の NG が残存）。

---

## 2. W4 記録の新規不備の独立再検証

### D-27（テスト結果表示ページ 500）: **再現を確認**

```
POST /users/login (user1)            → 302（ログイン成功）
GET  /contents-questions/record/7/6  → 500   ← 再現
GET  /contents-questions/record/7/7  → 200   （アンケート側は正常）
```

W4 の報告どおり。原因は `templates/ContentsQuestions/index.php:164` 付近で、記述式設問の `correct` が空文字のため `explode(',', '')` が `['']` となり、`$correct_no - 1` で `TypeError: string - int` が発生する経路。

**影響**: 記述式設問を含むテストの「結果を見る」画面が 500 になり、受講者が結果を確認できない。死にリンク（`/contents-questions/record/{content}/{record}`）も 2 件検出。

### D-29（学習記録保存が拒否）: **再現を確認（当初報告を一部精密化）**

```
POST /records/add/2（/_csrfToken と _Token[fields] 等を {_POST} 由来で送出）
  → HTTP 302  Location: http://localhost:8082/users/login
```

**`Location` が `/users/login` である点が決定的**で、これは `AppController::blackHole()`（FormProtection 失敗）である。成功時のリダイレクト先は `/contents/index/{course_id}` のはず。

事後の確認: `ib_records` は **2 行（id 6, 7）のみ**で Ventura行も追加されていない（**レコードが保存されていない**）。

### 2.1 D-12 と D-29 の共通根本原因（新規指摘）

両者は**別々の症状だが同じ原因クラス**に属し、FormProtection のトークン binding が「トークンを発行したページ URL」に固定されていることに起因する。

| 事実 | 出典 |
|---|---|
| `unlockActions` は **15 箇所**に存在するが、`RecordsController` には**存在しない** | `grep -rn "unlockActions" src/` |
| `Admin/ContentsController` の `unlockActions(['order','preview','uploadImage','copy'])` に **`upload` が無い** | `src/Controller/Admin/ContentsController.php:31` |
| 対照実験: `uploadImage` は multipart + `_csrfToken` のみで **200 成功** | Orchestrator 実測 |
| `templates/Contents/view.php:34` は JS 用の URL を `['controller'=>'records','action'=>'add',...]` として生成し、**そのページで生成したトークンを別 URL へ POST する** | 同ファイル |
| テスト/アンケート送信は `unlockActions(['index'])` で除外されているため**正常動作する** | `ContentsQuestionsController.php:32`, `EnquetesQuestionsController.php:31` |

つまり **「描画したページと異なる URL へ JS が POST する」AJAX/ドロップダウン経路は、FormProtection が機能する限り恒久的に失敗する**。現在それは `upload`（D-12）と `records/add`（D-29）で顕在化している。

**要記録の提案**: 今後 FormProtection を素朴に `unlockActions([])` で無効化する修正は、CSRF 防御を弱めるため**非推奨**。URL 全体で有効になるトークンを発行する設計（例: `Form->create()` の URL と JS の送信先を一致させる、または `unlockField` でファイル入力を除外する）が必要。

---

## 3. W6 記録の主張 2 件の反転（Orchestrator 再実測）

| ID | W6 の当初記録 | **再実測** | 対応 |
|----|--------------|-----------|------|
| D-31 | `GET /api/v1/contents/abc` → **400** + DebugKit の完全なデバッグダンプ（情報漏洩・S3） | **404** + アプリケーションの HTML 404 ページ（**DebugKit ダンプではない**） | W6 記録を訂正済み。S3 → **S4**（形式不整合のみ。認証認可の迂回なし） |
| D-32 | `GET /api/v1/contents/1%00` → Apache 404 HTML | **404** + Apache HTML ページ | **一致**（変更なし） |
| D-33 | `OPTIONS /mcp` → **403**（Origin チェック過厳） | **401** `{"error":"invalid_token","error_description":"Missing bearer token"}` | W6 記録を訂正済み。真の不備は「プリフライトに CORS ヘッダを伴わない」こと（S4） |

**教訓**: 「400」「403」のような**具体的なステータスは必ず一次実測で裏取りする**こと。レーンの報告は方向付けとしては有用だが、詳細値は orchestrator が確認すべきである。

---

## 4. 全体索引の不備 ID 一覧（Orchestrator 調整後）

| ID | 重大度 | 状態 | 摘要 |
|----|--------|------|------|
| D-01 | S2 | ✅ 修正済 | API レート制限（120/min） |
| D-02 | S2 | ⚠️ 部分 | file 種別の設定キー解決。実操作は D-12 により不可 |
| D-03 | S2 | ⚠️ 部分 | demo_mode 4 画面中 3 画面のみ。**Records 未** |
| D-04 | S3 | ✅ 修正済 | CSP / Referrer-Policy / Permissions-Policy |
| D-05 | S3 | ✅ 修正済 | セキュリティヘッダのアプリ層付与 |
| D-06 | S3 | ✅ 修正済 | Prelock の IP 併用 |
| D-07 | 要判定 | 判定不能 | Cookie `Secure` は HTTPS 環境が必要 |
| D-08 | S2 | ❌ **未修正** | API/MCP 教材 Write の staff バイパス欠落 |
| D-09 | S2 | ❌ **未修正** | MCP `isError` 契約逸脱 |
| D-10 | S2 | ❌ **未修正** | CSV インポートが `getData()` で `$_FILES` を読めない |
| D-11 | S3 | ✅ 修正済 | CSV charset を `SJIS-WIN` に |
| D-12 | S2 | ❌ **新規・未修正** | `upload` が FormProtection で 302。file/movie アップロード不可 |
| D-13 | S3 | ❌ | `<html lang>` 不在（6 テンプレート） |
| D-14 | S2 | ❌ | テスト/アンケート画面のラジオ・チェックボックスに `<label>` なし |
| D-15 | S2 | ❌ | モーダルに `role="dialog"`/`aria-modal`/フォーカストラップなし |
| D-16 | S3 | ❌ | install 画面の `<label for>` と input id 不一致 |
| D-17 | S3 | ❌ | `error400.php` で URL がエスケープなし |
| D-18 | S3 | ❌ | `date()` 直接使用 31 箇所 |
| D-19 | S4 | ❌ | flash の `<div onclick>` がキーボード操作不可 |
| D-20 | S4 | ❌ | 13 テーブル中 12 に `<caption>` なし |
| D-21 | S4 | ❌ | 見出しレベルの飛び |
| D-22 | S4 | ❌ | phpcs 違反 997 件 |
| D-23 | S2 | ❌ | `InstallController` の DB 名検出が `Configure::consume` で常に既定へフォールバック |
| D-24 | S3 | ❌ | `_executeSQLScript()` が 23000 (Duplicate entry) を握り潰さない |
| D-25 | S4 | ❌ | `bootstrap.php` が `App.fullBaseUrl` を未設定 |
| D-26 | S4 | ❌ | D-23 により install バリデーションを動的検証できない |
| D-27 | S2 | ❌ | テスト結果表示ページ 500（`TypeError: string - int`） |
| D-28 | S2 | ❌ | 非公開お知らせが受講者画面で閲覧可能 |
| D-29 | S2 | ❌ | 学習記録保存が FormProtection で拒否（302→login、レコード未生成） |
| D-30 | S3 | ❌ | アンケート回答詳細が `ib_records_questions` に未保存 |
| D-31 | S4 | ❌ | API の一部が HTML 404 を返す（形式不整合。認証・認可の迂回には至らない） |
| D-32 | S4 | ❌ | NULL byte パスで Apache 404 HTML |
| D-33 | S4 | ❌ | MCP preflight が 401 を返し CORS ヘッダを伴わない |
