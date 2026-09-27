# W5 検出項目 原因解析（Root Cause Analysis）

> 対象: `Docs/verification/evidence/W5/W5-a11y-static.md` の D-13〜D-22
> 実施日: 2026-09-26 / 方法: 静的コード点検（file:line）＋ W5静的解析記録の参照
> 前提: コード修正は未実施。原因の特定と影響評価のみ。

---

## 総括

| ID | 事象 | 根本原因（要約） | 分類 | 重要度 |
|----|------|------------------|------|--------|
| D-13 | `<html lang>` 不在（6件） | `<html>` タグが共通レイアウトに集約されず各テンプレートに独立手書き。`lang` を一箇所に書く設計がない | 設計欠落 | S3 |
| D-14 | ラジオ/チェックボックスに `<label>` なし | 選択肢タグを `sprintf` で組み立てる際、`<input>` とテキストを直接出力し `<label for>` を付与するロジックがない | 実装漏れ | S2 |
| D-15 | モーダルに `role="dialog"` / `aria-modal` / フォーカストラップなし | Bootstrap 3 の `.modal` クラスの見た目にのみ依存し、ARIA 属性と JS フォーカストラップが未実装 | 実装漏れ | S2 |
| D-16 | インストール画面の `for`/`id` 不一致（4件） | 手動 HTML 作成で label の `for` をコピペして統一（`for="Password"`）し、実際の `id` と同期させないまま放置 | 実装バグ | S3 |
| D-17 | `error400.php` で URL 未エスケープ | 2つの error400.php で `$url` を `h()` なしで文字列結合・`printf` 出力 | 実装漏れ | S3 |
| D-18 | `date()` 直接使用 31 箇所 | CakePHP 5 の `FrozenTime` への移行が未完了。開発当初の PHP 直書きパターンが残存 | 未移行（推定） | S3 |
| D-19 | flash `<div onclick>` がキーボード操作不可（5件） | flash element を `<div onclick>` で閉じる設計。`<button>` / `role="button"` / `tabindex` 未付与 | 実装漏れ | S4 |
| D-20 | 13テーブル中12に `<caption>` なし | テーブル作成時に `<caption>` を含めるコーディング規約が不在 | 規約なし | S4 |
| D-21 | 見出しレベルの飛び | ページ固有の `<h4>` のみ配置。CakePHP デフォルトテンプレート由来の h1 とアプリ側の h4 が混在 | 設計欠落 | S4 |
| D-22 | phpcs 違反 997 件 | `phpcs.xml` は CakePHP 標準を参照するが CS-Fixer 未導入・初回一括修正なし | ツール未導入 | S4 |

---

## D-13: `<html lang>` 属性が全テンプレート（6件）で不在

**根本原因: `<html>` タグが共通レイアウトに集約されず、6ファイルがそれぞれ独立に `<html>` を手書きしており、`lang` 属性の追加が全ファイルに波及しなかった。**

- grep 実測（`<html>` の出現、6件）:
  - `templates/layout/default.php:13` — レイアウト基盤
  - `templates/layout/error.php:2` — エラー用レイアウト
  - `templates/layout/flash.php:2` — フラッシュリダイレクト用
  - `templates/layout/email/html/default.php:18` — メールテンプレート
  - `templates/Contents/view.php:2` — レイアウト不使用
  - `templates/Pages/home.php:61` — CakePHP デフォルト由来・`disableAutoLayout` でレイアウト不使用
- メカニズム: 各ファイルが独立に `<!DOCTYPE html><html>` を記述しているため、「1箇所で `lang` を追加すれば全ページに反映」という構造がない。
- 影響: WCAG 3.1.1 違反。スクリーンリーダーがページ言語を判別できず、日本語テキストを英語として読み上げる可能性がある。
- 分類: **設計欠落**（レイアウト継承の外にある手書き `<html>` が多数存在）

---

## D-14: テスト/アンケート画面のラジオ・チェックボックスに `<label>` なし

**根本原因: 選択肢出力ロジックで `<input>` と選択肢テキストを同一の `sprintf` 文字列に結合して出力する設計で、`<label for>` で関連付けるコーディングがなされていない。**

- grep 実測（3箇所、いずれも `id` 属性なし）:
  - `templates/ContentsQuestions/index.php:124` — `sprintf('<input type="checkbox" ...> %s<br>', ...)`
  - `templates/ContentsQuestions/index.php:134` — `sprintf('<input type="radio" ...> %s<br>', ...)`
  - `templates/EnquetesQuestions/index.php:105` — `sprintf('<input type="radio" ...> %s<br>', ...)`
- メカニズム: `<input>` に `id` 属性を付与し `<label for>` で囲む基本パターンが意識されず、テキストを隣に配置する方式で実装された。
- 影響: WCAG 1.3.1/4.1.2 違反。スクリーンリーダーでラジオ/チェックボックスの操作が困難。テスト/アンケートは本アプリの主要操作フロー。
- 分類: **実装漏れ**

---

## D-15: モーダルに `role="dialog"` / `aria-modal` / フォーカストラップなし

**根本原因: Bootstrap 3 の `.modal` クラスの見た目・表示切替にのみ依存し、ARIA 属性（`role="dialog"` / `aria-modal` / `aria-labelledby`）と JS フォーカストラップの追加実装が未実施。**

- 該当 3 モーダル:
  - `templates/ContentsQuestions/index.php:252` — `<div class="modal fade" id="confirmModal">`（採点確認）
  - `templates/EnquetesQuestions/index.php:151` — `<div class="modal fade" id="confirmModal">`（送信確認）
  - `templates/Admin/Contents/edit.php:332` — `<div class="modal fade" id="uploadDialog">`（ファイルアップロード）
- メカニズム: Bootstrap のモーダルは CSS/JS で表示切替するが `role="dialog"` 等の ARIA 属性は自動付与されない。アクセシビリティ属性とフォーカス管理（Tab キーをモーダル内に閉じ込めること）が未実装。
- 影響: WCAG 2.4.3/4.1.2 違反。キーボードユーザーがモーダル外へフォーカスが逸脱する。
- 分類: **実装漏れ**

---

## D-16: インストール画面の `<label for>` と input id が不一致（4件）

**根本原因: 手動 HTML 作成時に `<label for="Password">` をコピペで共通化し、実際の `<input id>` と同期させなかった。**

- grep 実測（`templates/Install/index.php`）:
  - `:15` — `<label for="Password">管理者ログインID` → 対応 input に `id` なし（`:17` の username 欄）
  - `:21` — `<label for="Password">パスワード` → `<input id="UserPassword">`（`:23`）
  - `:27` — `<label for="Password">パスワード(確認用)` → `<input id="UserPassword2">`（`:29`）
  - `:33` — `<label for="UserRegistNo"></label>` → 対応 `id` の input なし（`:35` は submit、`id` なし）
- メカニズム: インストール画面は FormHelper を使わず raw HTML。label の `for` を 1 行目の値でコピーし、各行の input id が異なる状態で更新されなかった。
- 影響: WCAG 1.3.1 違反。ID/パスワード入力欄とラベルの対応関係が不明。
- 分類: **実装バグ**

---

## D-17: `error400.php` で URL がエスケープなしで出力

**根本原因: 2つの error400.php（小文字 `error/` と大文字 `Error/`）の両方で、`$url` を `h()` なしで文字列結合・出力している。**

- grep 実測:
  - `templates/error/error400.php:13` — `printf(__d('cake', '指定されたアドレス %s へのリクエストは無効です。'), "<strong>'{$url}'</strong>");`
  - `templates/Error/error400.php:25` — `<?= __d('cake', 'The requested address {0} was not found on this server.', "<strong>'{$url}'</strong>") ?>`
- メカニズム: `$url` は `ErrorHandler` が渡すリクエスト URL（GET パラメータ由来の余地あり）。テンプレート側の防御（`h()`）が欠如。2ファイルが存在するが、小文字版が実際のエラー描画で使われる。
- 影響: XSS の余地あり（S3）。ただし CakePHP の ErrorController が一定程度フィルタリングする場合があり、実際の影響は限定的（推定）。
- 分類: **実装漏れ**

---

## D-18: `src/` 内 `date()` 直接使用 31 箇所

**根本原因: CakePHP 5 の `FrozenTime` / `Time` への移行が未完了で、開発当初の PHP 標準 `date()` が残存している。特に `UserTokensTable.php` に集中。**

- 代表箇所（grep 実測の主な例）:

| ファイル | 用途 |
|------------|------|
| `src/Model/Table/UserTokensTable.php` | トークン有効期限・`last_used`・期限チェック |
| `src/Controller/Trait/UserLoginTrait.php` | 最終ログイン日時・ログインブロック閾値 |
| `src/Controller/Api/AuthController.php` | API トークン有効期限 |
| `src/Controller/Admin/ContentsController.php` | ファイルリネーム用 `YmdHis` |
| `src/Middleware/ApiRateLimitMiddleware.php` | レート制限ウィンドウキー `YmHi` |
| `src/View/Helper/AppFormHelper.php` | 現在年 `(int)date('Y')` |

- メカニズム: PHP 標準の `date()` はサーバーのタイムゾーン設定に依存する。CakePHP の `defaultTimezone`（`Asia/Tokyo`）と `date()` のタイムゾーンが不一致になる可能性がある。（「移行未完了」の経緯は**推定**）
- 影響: 大部分は DB 格納用の日付文字列であり実害は軽微（S3）。ただし `date()` のタイムゾーンは `date_default_timezone_get()` 依存で `defaultTimezone` と整合しない余地がある。
- 分類: **未移行（推定）**

---

## D-19: flash の `<div onclick>` がキーボード操作不可（5件）

**根本原因: flash メッセージの閉じる操作を `<div onclick>` で実装し、`<button>` / `role="button"` / `tabindex` / キーボードイベント（Enter/Space）の対応がない。**

- grep 実測（5ファイルすべて同一パターン）:
  - `templates/element/flash/default.php:15`
  - `templates/element/flash/error.php:11`
  - `templates/element/flash/success.php:11`
  - `templates/element/flash/warning.php:11`
  - `templates/element/flash/info.php:11`
- メカニズム: `<div>` は既定ではキーボードフォーカスを受け取らない（`tabindex` なし）。`onclick` のみで `onkeydown` がないためキーボードユーザーが閉じられない。`role="button"` / `aria-label` も不在。
- 影響: WCAG 2.1.1 違反（S4）。メッセージ自体は表示されるため操作不能にはならないが、キーボード操作性が低下。
- 分類: **実装漏れ**

---

## D-20: 13テーブル中12に `<caption>` なし

**根本原因: テーブル作成時に `<caption>` を含めるコーディング規約が不在で、`<th>` のみで十分と考えられてきた。**

- grep 実測: `<caption>` 出現 **1 件** / `<table>` 出現 **13 件**（W5記録の「13 中 12 なし」と一致）。
  - あり: `templates/ContentsQuestions/index.php` — `<caption>テスト結果</caption>`
  - なし: コンテンツ一覧、お知らせ一覧、コース一覧、各管理画面（Courses/Contents/EnquetesQuestions/Groups/Users/Infos/ContentsQuestions/Records）、CSV説明表 等。
- メカニズム: `<caption>` が必須要件として認識されておらず、`<th>` でヘッダ行を定義すれば十分と考えられた。テスト結果テーブルのみあるのは後から追加された際に ARIA 意識があったためと**推定**。
- 影響: WCAG 1.3.1 違反（S4）。直前の見出しが文脈として機能するため実害は軽微。
- 分類: **規約なし**

---

## D-21: 見出しレベルの飛び

**根本原因: ページごとに独立に見出しレベルを選択しており、`<h1>`〜`<h6>` の階層設計がない。CakePHP デフォルトテンプレート由来の h1 とアプリケーション側の h4 が混在。**

- W5記録より:
  - `Pages/home.php` — h1 と h4 の混在（h2 欠落）
  - `ContentsQuestions/index.php` / `EnquetesQuestions/index.php` / `UsersCourses/index.php` — `h4` のみ（h1〜h3 不在）
  - `Admin/Contents/upload.php` — アップロードダイアログ内の `h4`
- メカニズム: `Pages/home.php` は CakePHP デフォルトテンプレート（bake 生成）由来で h1 が CakePHP ロゴに割り当てられている。他ページは Bootstrap の `.panel-heading` 内に `<h4>` を置くパターンが標準化され、ページに `h1` を置く設計思想がない。
- 影響: WCAG 1.3.1 違反（S4）。各ページが単一の見出しレベルで構成されているためナビゲーションへの影響は限定的。
- 分類: **設計欠落**

---

## D-22: phpcs 違反 997 件（928 errors + 69 warnings）

**根本原因: `phpcs.xml` が CakePHP 標準ルール（`<rule ref="CakePHP"/>`）を参照するが、phpcs/phpcbf が初回一括修正されておらず、コードスタイルが統一されていない状態が残存。**

- 集中ファイル（W5記録より・`src/` の主要例）:
  - `src/Utility/Utils.php` — **277** 件（最大）
  - `src/Controller/Admin/UsersController.php` — 82 + 6
  - `src/Controller/UsersController.php`、`src/Controller/Admin/ContentsController.php`、`src/View/Helper/AppFormHelper.php`、`src/Model/Table/UserTokensTable.php` など
  - `tests/` — 215 errors / 4 warnings / 38 files（W5記録より）
- メカニズム: `phpcs.xml` は CakePHP の CS 標準を読み込むが、phpcbf（自動修正）が初回に実行されていない。`Utils.php` が突出しているのは、PHP 4 時代からのユーティリティにタブインデント・未使用 `use`・型ヒント未宣言が集中し、標準との差分が全件エラーとして出力されるため（`vendor/bin/phpcbf` の存在は確認済み・導入済み、**一括実行がされていない**）。
- 影響: S4。機能的問題はない。自動修正可能件数が大半（W5記録で 940/997 = 94.3%）。
- 分類: **ツール未導入（→ 実際は「未実行」に近い）** — phpcs/phpcbf バイナリは `vendor/bin` に存在する。

---

## 付録: 分類の定義

| 分類 | 意味 |
|------|------|
| 設計欠落 | アーキテクチャ/設計段階で考慮がなかった |
| 実装漏れ | 設計意図はあるが実装が追いついていない |
| 実装バグ | 実装されたが誤りがある |
| 規約なし | コーディング規約/チェックリストが存在しない |
| ツール未導入 | 自動化ツールが未導入または未実行 |
| 未移行 | 過去の実装パターンから新版フレームワーク API への移行が未完了 |

> 本解析は検査記録であり、コード修正は行っていない。
