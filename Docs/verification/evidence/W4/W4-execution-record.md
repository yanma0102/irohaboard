# W4 実施記録（核心ジャーニー探索）

| 項目 | 内容 |
|------|------|
| 実施日 | 2026-09-26 |
| 実施範囲 | W4（核心ジャーニー探索）: VR-E2E-001〜008 + CH-01〜15 の HTTP 測定可能部分 |
| 実施方法 | curl による実 HTTP 観測（ブラウザ-less）。アプリケーションの修整は行っていない |
| 対象環境 | `http://localhost:8082`（`irohaboard5-web-1` php:8.4-apache）／ MariaDB 11.4.13（port 13307, db `irohaboard`） |
| 前提 | `debug=true`。HTTPS 環境なし。CSRF / FormProtection は有効。セッションは curl cookie jar で維持 |
| テストデータ | user1（id=5, pw=password）、既存学習記録（record id=6/7 の 2 件）。テスト作成物は `W4-` プレフィックスで作成・全削除済み |

---

## 1. 結果サマリ

| ID | 検証項目 | 判定 | 根拠 |
|----|----------|------|------|
| VR-E2E-001 | 初回学習完了ジャーニー | ⚠️ 一部可 | ログイン→トップ→コース一覧→コンテンツ表示→テスト/アンケート送信→ログアウトは通る。ただし**テスト結果表示が 500**（D-27）、**学習記録保存が FormProtection で弾かれる**（D-29） |
| VR-E2E-002 | コース開設〜受講生受講 | N/A（人手・ブラウザ必須） | 管理画面でのコース/コンテンツ作成・CSV インポートは FormProtection のため curl で実施不可。管理画面ログインと一覧閲覧は確認済み |
| VR-E2E-003 | Markdown 制作〜配信〜API/MCP | ⚠️ 一部可 | 既存 Markdown コンテンツ（id=11）の表示は正常。サニタイズ済（XSS プローブ → エスケープ）。作成は管理画面 UI 必須 |
| VR-E2E-004 | グループベース運用 | N/A（人手・ブラウザ必須） | グループ作成/割当は管理画面 UI 必須。権限の即時反映は HTTP では観測困難 |
| VR-E2E-005 | 受験〜再受験〜分析 | ⚠️ 一部可 | テスト送信→記録保存→合否判定は正常。**結果表示ページが 500**（D-27）。再受験フォームは再表示可能（200）。管理画面での履歴 CSV は W2 で確認済み |
| VR-E2E-006 | API 連携ジャーニー | N/A（W2 で実施済み） | API 連携は W2 で検証済み（ApiContractTest 191 tests OK） |
| VR-E2E-007 | MCP 学習支援ジャーニー | N/A（W2 で実施済み） | MCP 連携は W2 で検証済み（McpControllerTest 25 tests OK） |
| VR-E2E-008 | インストール〜運用〜更新〜復旧 | N/A（W3/W7 で実施済み） | W3 でインストール検証、W7 で復元/回帰テスト実施済み |

---

## 2. ジャーニー実測ログ

### 2.1 未認証アクセス → ログイン誘導

```
GET /users-courses
→ 302 → /users/login?redirect=%2Fusers-courses
```
✅ 未認証時、ログイン画面にリダイレクト。リダイレクト先に元の URL が含まれる。

### 2.2 ログイン → ホーム

```
GET /users/login → 200 (CSRF トークン + _Token[fields/unlocked/debug] 取得)
POST /users/login (username=user1, password=password + CSRF + _Token)
→ 302 → / (セッション再生成: AppSession 値が変化)
GET / → 200
  - 「ようこそ ユーザー1 さん」表示
  - お知らせ: 全体公開のお知らせ, グループ限定のお知らせ（2 件表示）
  - コース一覧: 受講可能コース 1 件（学習開始日/前回学習日表示）
  - セキュリティヘッダ: CSP / Referrer-Policy / Permissions-Policy / X-Frame-Options / X-Content-Type-Options すべて付与済み
```
✅ ログイン成功。セッション再生成確認。セキュリティヘッダ確認（W1 から改善）。

### 2.3 コース一覧 → コンテンツ一覧

```
GET /contents/index/1 → 200
  - ラベルコンテンツ (id=1) → /contents/view/1（表示のみ、テスト/アンケート以外）
  - HTMLコンテンツ (id=2) → /contents/view/2
  - Markdownコンテンツ (id=3) → /contents/view/3
  - 動画コンテンツ (id=4) → /contents/view/4
  - URLコンテンツ (id=5) → /contents/view/5
  - ファイルコンテンツ (id=6) → /contents/file-download/6（リンク表示）
  - テストコンテンツ (id=7) → /contents-questions/index/7
  - アンケートコンテンツ (id=8) → /enquetes-questions/index/8
  - Markdown全方位テスト (id=11) → /contents/view/11
  - 非公開コンテンツ (id=9, 10) → 一覧に表示されず
  - 学習時間/学習回数/理解度/完了カラム表示
```
✅ コンテンツ一覧正常。種別に応じたリンク先。

### 2.4 各種コンテンツ表示（8 種類）

| Content ID | 種別 | URL | HTTP | 表示内容 |
|-----------|------|-----|------|---------|
| 1 | label | /contents/view/1 | 200 | 「ラベルコンテンツ」+ 理解度選択ボタン + 中断/戻る |
| 2 | html | /contents/view/2 | 200 | 「HTMLタイトル」「HTML本文」 |
| 3 | markdown | /contents/view/3 | 200 | 「マークダウン 本文です。」 |
| 4 | movie | /contents/view/4 | 200 | 「動画コンテンツ」+ 理解度選択 |
| 5 | url | /contents/view/5 | 200 | 「URLコンテンツ」+ 理解度選択 |
| 6 | file | /contents/view/6 | 200 | 「ファイルコンテンツ」+ 理解度選択 |
| 7 | test | /contents-questions/index/7 | 200 | テストフォーム（3 問: 単一/記述/複数） |
| 8 | enquete | /enquetes-questions/index/8 | 200 | アンケートフォーム（2 問: 満足度/コメント） |
| 11 | markdown | /contents/view/11 | 200 | 見出し/表/コード/リンク/画像/生HTML/XSSプローブ/タスクリスト 正常表示 |

✅ 全 8 種類 + Markdown 全方位テストが表示可能。XSS プローブ (`<script>alert('XSS')</script>`) は `&lt;script&gt;` にサニタイズ済み。

### 2.5 テスト受験（正解/不正解）

```
GET /contents-questions/index/7 → 200
  - 問1: 単一正解 (answer_1, radio, values 1-3)
  - 問2: 記述式 (answer_3, text)
  - 問3: 複数正解 (answer_2[], checkboxes, values 1-4)
  - CSRF + _Token[fields/unlocked/debug] 付き

POST /contents-questions/index/7 (answer_1=1, answer_2[]=1,3, answer_3=empty)
→ 302 → /contents-questions/record/7/8

GET /contents-questions/record/7/8 → 500 ← D-27
  Error: "Unsupported operand types: string - int"
  at templates/ContentsQuestions/index.php:164

DB: ib_records id=8 created (score=10, is_passed=0, is_complete=1)
    ib_records_questions id=18-20 created (3 件)
```
⚠️ テスト送信と記録保存は成功。**結果表示ページで 500 エラー**（D-27）。

### 2.6 アンケート回答

```
GET /enquetes-questions/index/8 → 200
  - 問4: アンケート満足度 (answer_4, radio)
  - 問5: アンケートコメント (answer_5, textarea)

POST /enquetes-questions/index/8 (answer_4=1, answer_5=テスト回答)
→ 302 → /enquetes-questions/record/8/9

GET /enquetes-questions/record/8/9 → 200
  - 「回答内容を送信しました」

DB: ib_records id=9 created (is_passed=2, is_complete=1)
    ib_records_questions: 0 件 ← D-30（回答詳細が未保存）
```
⚠️ アンケート送信は成功。**回答詳細が ib_records_questions に未保存**（D-30）。

### 2.7 非テストコンテンツの学習記録

```
GET /contents/view/1 → 200（CSRF + _Token 取得）

JS finish() が生成する POST:
  POST /records/add/1
  Body: _csrfToken + is_complete=1 + study_sec=15 + understanding=3
  ※ _Token[fields/unlocked/debug] は送信されない

POST /records/add/1 → 302 → /users/login ← D-29
  （FormProtection blackHole: _Token フィールド不足）
```
❌ **学習記録保存が FormProtection により拒否される**（D-29）。`RecordsController::add` が `FormProtection->unlockActions` を呼んでいない。

### 2.8 ファイルダウンロード

```
GET /contents/file-download/6 → 404
  （ファイル `sample.pdf` がディスクに存在しない。Seed データのため）
  路由ロジック: `ContentsController::file_download:165-173`
  → `ROOT/files/{url}` → `ROOT/webroot/uploads/{url}` → 両方不在で 404
```
⚠️ ファイルダウンロードのルーティングは正常。Seed データに実ファイルが不在のため404。

### 2.9 お知らせ（全体公開/グループ限定）

```
Home page (GET /):
  - お知らせセクションに 2 件表示: 全体公開のお知らせ, グループ限定のお知らせ
  - 非公開のお知らせは Home に表示されず ✅

GET /infos → 200
  - 一覧に 3 件表示: 全体公開, グループ限定, 非公開 ← D-28

GET /infos/view/1 → 200（全体公開: 閲覧可）
GET /infos/view/2 → 200（グループ限定: user1 はグループ 1 所属のため閲覧可）
GET /infos/view/3 → 200（非公開: 閲覧不可のはずだが 200）← D-28
```
⚠️ **非公開のお知らせ（opened=NULL）が一覧・詳細画面で閲覧可能**（D-28）。

### 2.10 ログアウト → セッション破棄

```
GET /users/logout → 302 → /users/login
  Set-Cookie: AppSession=deleted; Max-Age=0
  Set-Cookie: AppSession=<new>; path=/; HttpOnly; SameSite=Lax

GET /users-courses (same cookies) → 302 → /users/login
  → セッション破棄済み。未認証状態に戻る。
```
✅ ログアウト後、セッションが正しく破棄される。

### 2.11 バック/リロードの安全性

```
GET /contents/view/1 → 200（1 回目）
GET /contents/view/1 → 200（2 回目、同一セッション）
  → 冪等。二重送信リスクなし（GET なので）。
```
✅ コンテンツ表示は GET のため冪等。

---

## 3. 不備一覧

| ID | 重大度 | 内容 | 根拠 | 要判定 |
|----|--------|------|------|--------|
| D-27 | **S2** | テスト結果表示ページが 500 エラー（`TypeError: Unsupported operand types: string - int`） | `templates/ContentsQuestions/index.php:164` — 記述式問題（text）の `correct` フィールドが空の場合、`explode(',', '')` が `['']` を返し、`$correct_no - 1`（空文字列 - 1）で TypeError 発生。**テスト結果が一切閲覧できない** | S2 |
| D-28 | **S2** | 非公開のお知らせ（`opened=NULL`）が受講者画面で閲覧可能 | `src/Model/Table/InfosTable.php:getInfoIdList()` — クエリが `IbInfosGroups.group_id IS NULL` のみで `opened` フィルタなし。一覧（`/infos`）・詳細（`/infos/view/3`）で非公開コンテンツが表示される。**情報漏洩の可能性** | S2 |
| D-29 | **S2** | 受講者の学習記録保存（`/records/add/{id}`）が FormProtection により拒否される | `src/Controller/RecordsController.php:30` — `add()` アクションが `FormProtection->unlockActions()` を呼んでいない。JS `finish()` 関数（`webroot/js/contents_view.js:50-86`）が `_csrfToken` のみ送信する設計のため、`_Token[fields/unlocked/debug]` が欠如し FormProtection blackHole（302→ログイン）となる。**label/html/markdown/movie/url/file 種別の学習記録が保存できない** | S2 |
| D-30 | **S3** | アンケート回答の詳細が `ib_records_questions` に保存されない | `src/Controller/EnquetesQuestionsController.php:126-129` — `RecordsQuestions` の `save()` は呼ばれているが、DB 上に `record_id=9` のレコードが 0 件。`is_correct=-1`（`smallint(1)` への格納）または `getData('answer_N')` の問題の可能性。レコード自体は作成されるが回答内容が欠落 | S3 |

---

## 4. 未実施（人手・ブラウザ必須）とリスク受容

| # | 未実施範囲 | 理由 | 影響 | リスク評価 |
|---|-----------|------|------|----------|
| U-1 | VR-E2E-002（コース開設〜受講） | 管理画面のコース/コンテンツ/ユーザー作成は FormProtection 付きフォーム送信が必要。curl ではセッション + CSRF + ファイルアップロードの同期が困難 | コース開設フローの統合検証が不足 | **中**: 個別 API/モデル テストで大部分カバー |
| U-2 | VR-E2E-004（グループベース運用） | グループ作成→コース割当→ユーザー割当の一連フローはブラウザ操作が必要 | グループ権限の即時反映検証が不足 | **低**: 権限ロジックは自動テストで検証済み |
| U-3 | CH-01（初回体回体験の霧） | UI/UX の主観的評価（用語の不統一、導線の分かりやすさ、空状態の不安）は視覚確認が必要 | 用語/導線の問題が未発見の可能性 | **低**: curl で構造は確認済み |
| U-4 | CH-04（Markdown の深淵） | GFM の全要素（表・コード・リンク・画像・XSS）は curl で確認。ただし CSS 表示品質は視覚確認が必要 | CSS/レイアウトの崩れが未発見の可能性 | **低**: 機能面は検証済み |
| U-5 | CH-08（CSV の迷宮） | CSV インポート/エクスポートの文字化け・大量データは W2 で確認。ただし実ブラウザでの操作は未実施 | 操作性の問題が未発見の可能性 | **低**: W2 で CSV 出力確認済み |
| U-6 | CH-09（ファイルの砦） | 拡張子偽装・巨大ファイル・トラバーサルは人手テスト必須 | ファイル関連の脆弱性が未発見の可能性 | **中**: D-02（file 種別拒否）が未修正のため影響大 |
| U-7 | CH-10b（権限の壁 API/MCP/CSV） | API/MCP の権限テストは W2 で実施済み。CSV 経由の昇格は D-10（CSV インポート不具合）のため実質不可 | CSV 経由の昇格リスク | **低**: CSV インポート自体が機能不全 |
| U-8 | CH-11（管理画面の重圧） | 大量データ時の性能/使い勝手はブラウザ操作が必要 | パフォーマンス問題が未発見の可能性 | **低**: 現環境は小規模データ |
| U-9 | CH-13（MCP の境界） | MCP テストは W2 で実施済み | — | **低** |
| U-10 | CH-15（グループとコースの網） | M2M の組合せ爆発テストはブラウザ操作が必要 | 組合せ漏れの可能性 | **低**: DB レベルの整合性は確認済み |
| U-11 | VR-E2E-009（モバイル受講） | モバイル実機での操作確認は不可 | レスポンシブ表示の問題が未発見の可能性 | **中**: Bootstrap 3 ベースのため影響限定的 |
| U-12 | VR-E2E-012（アクセシビリティ受講） | キーボード/スクリーンリーダー操作は不可。W5 で静的 a11y チェック済み | 操作性の問題が未発見の可能性 | **中**: W5 で D-13〜D-22 発見済み |

---

## 5. 判定

### 5.1 総合判定

**⚠️ 一部可** — 主要ジャーニー（ログイン→閲覧→テスト/アンケート送信→ログアウト）は通るが、S2 級の不備 3 件が存在。

### 5.2 判定根拠

| 基準 | 状態 |
|------|------|
| ログイン→トップ→コース一覧→コンテンツ表示 | ✅ 全ステップ 200 |
| テスト送信→記録保存 | ✅ 302 + DB 確認 |
| テスト結果表示 | ❌ 500 エラー（D-27） |
| 学習記録保存（非テスト系） | ❌ FormProtection で拒否（D-29） |
| アンケート送信→記録 | ⚠️ レコードは保存されるが回答詳細が未保存（D-30） |
| お知らせの可視性制御 | ❌ 非公開情報が漏洩（D-28） |
| ログアウト→セッション破棄 | ✅ 正常 |
| 権限 Wall（管理画面） | ✅ 全て 302 |
| 死にリンク | ⚠️ テスト結果ページ（D-27）以外は正常（404/403 は適切） |
| レスポンスヘッダ | ✅ CSP/Referrer-Policy/Permissions-Policy/X-Frame-Options/X-Content-Type-Options 付与済み |

### 5.3 重要な教訓

1. **D-27/D-29 はユーザー体験を損なう**: テスト結果が見れない＋学習記録が保存されない意味着で、受講者画面の核心機能が動作しない。
2. **D-28 は情報漏洩**: 非公開のお知らせが全ユーザーに可见。管理画面での設定変更が反映されていない。
3. **セキュリティヘッダは改善された**: W1 の D-04（CSP/Referrer/Permissions 未設定）と D-05（Apache 層のみ）は `SecurityHeadersMiddleware` により修正済み。
4. **FormProtection の一貫性が課題**: `ContentsQuestionsController` と `EnquetesQuestionsController` は `unlockActions` を呼ぶが、`RecordsController` は呼ばない。JS 側の設計（`finish()` 関数が `_Token` フィールドを送信しない）と不整合。
