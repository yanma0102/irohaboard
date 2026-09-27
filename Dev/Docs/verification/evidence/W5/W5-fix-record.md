# W5 修正記録（Fix Record）

> 対象: `Docs/verification/evidence/W5/W5-a11y-static.md` および `W5-cause-analysis.md` の D-13〜D-22
> 実施日: 2026-09-26 / 対象アプリ: irohaboard（CakePHP 5.4.2 / PHP 8.4.25 / MariaDB 11.4.13）
> 前提: 記録規約に従い、**既存記録は上書きせず**本ファイルを追加する。

---

## 1. 修正サマリ

| ID | 重大度 | 問題 | 修正方針 | 状態 |
|----|--------|------|----------|------|
| D-13 | S3 | 6 テンプレートの `<html>` に `lang` 属性なし | 各 `<html>` に `lang="ja"` を付与 | ✅ 修正済（`4deb99e`） |
| D-14 | S2 | ラジオ／チェックボックスに `<label for>` と input `id` がない | `id` を `answer_{qid}_{opt}` 形式で付与し `<label for>` で関連付け | ✅ 修正済（`0a2e4fe`） |
| D-15 | S2 | モーダル 3 つに `role="dialog"`／`aria-modal`／フォーカストラップがない | ARIA 属性付与＋JS フォーカストラップ追加 | ✅ 修正済（`0a2e4fe`） |
| D-16 | S3 | インストール画面の `<label for>` と input `id` が全 4 行 `Password` でコピペ | `for` を実 input `id` に一致、username に `id` 追加、空 label は div へ | ✅ 修正済（`4deb99e`） |
| D-17 | S3 | `error400.php` 2 ファイルで `$url` が `h()` なしで出力（XSS 余地） | `$url` を `h()` でエスケープ | ✅ 修正済（`4deb99e`、他レーン fix-13） |
| D-18 | S3 | `date()` 直接使用 31 箇所 | — | ⏭ 意図的に延期 |
| D-19 | S4 | flash 5 ファイルの閉じる操作が `<div onclick>` でキーボード操作不可 | `<button type="button">` へ置換＋CSS リセット | ✅ 修正済（`4deb99e`） |
| D-20 | S4 | 13 テーブル中 12 に `<caption>` がない | `<caption class="sr-only">` を付与 | ✅ 修正済（`4deb99e`） |
| D-21 | S4 | 見出しレベルの飛び（h1→h4、h4 のみ等） | 各ページで意味的に正しい見出しレベルへ是正 | ✅ 修正済（`4deb99e`） |
| D-22 | S4 | phpcs 違反 1011 件 | `phpcbf` 適用＋型ヒント sniff 除外 | ✅ 修正済（`fb8d618`） |

---

## 2. 各修正の詳細

### D-13: `<html lang="ja">` 付与（6 ファイル）

**根本原因**（`W5-cause-analysis.md` §D-13）
`<html>` タグが共通レイアウトに集約されず、6 ファイルがそれぞれ独立に `<html>` を手書きしていたため、`lang` 属性の追加が全ファイルに波及しなかった。

**修正**（6 ファイル）

| ファイル | 行 | Before | After |
|----------|-----|--------|-------|
| `templates/layout/default.php` | 13 | `<html>` | `<html lang="ja">` |
| `templates/layout/error.php` | 2 | `<html>` | `<html lang="ja">` |
| `templates/layout/flash.php` | 2 | `<html>` | `<html lang="ja">` |
| `templates/layout/email/html/default.php` | 18 | `<html>` | `<html lang="ja">` |
| `templates/Contents/view.php` | 2 | `<html>` | `<html lang="ja">` |
| `templates/Pages/home.php` | 61 | `<html>` | `<html lang="ja">` |

本アプリは `ja_JP` のみのため `lang="ja"` を統一適用。`Contents/view.php` は `disableAutoLayout()` でレイアウト不使用のため、iframe 内に埋め込まれる場合でも同一言語で問題なし。

---

### D-14: ラジオ／チェックボックスに `<label for>` と `id` を付与（2 ファイル）

**根本原因**（`W5-cause-analysis.md` §D-14）
選択肢出力ロジックで `<input>` と選択肢テキストを同一の `sprintf` 文字列に結合して出力する設計で、`<label for>` で関連付けるコーディングがなされていなかった。

**修正**（`templates/ContentsQuestions/index.php:151-164`、`templates/EnquetesQuestions/index.php:105-107`）
各 `<input>` に `id` 属性を付与し、`<label for>` で選択肢テキストを囲む。

**id の命名規則**: `sprintf('answer_%s_%d', $question_id, $option_index)`（例: `answer_42_1`）
- `$question_id`（DB プライマリキー）× `$option_index`（1 起算の選択肢番号）で決定的に生成
- 設問内で一意、複数回レンダリングでも衝突しない

```php
// ContentsQuestions/index.php:151-153（checkbox）
$option_id = sprintf('answer_%s_%d', $question_id, $option_index);
$option_tag .= sprintf('<label for="%s"><input type="checkbox" id="%s" ...>%s</label><br>',
    $option_id, $option_id, ...);

// ContentsQuestions/index.php:162-164（radio）
$option_id = sprintf('answer_%s_%d', $question_id, $option_index);
$option_tag .= sprintf('<label for="%s"><input type="radio" id="%s" ...>%s</label><br>',
    $option_id, $option_id, ...);

// EnquetesQuestions/index.php:105-107（radio）
$option_id = sprintf('answer_%s_%d', $question_id, $option_index);
$option_tag .= sprintf('<label for="%s"><input type="radio" id="%s" ...>%s</label><br>',
    $option_id, $option_id, ...);
```

**見た目は不変**: `<label>` はデフォルトでインライン表示。`<br>` は `</label>` の外にあるため配置 unchanged。

---

### D-15: モーダルの ARIA 属性付与とフォーカストラップ（3 モーダル＋JS）

**根本原因**（`W5-cause-analysis.md` §D-15）
Bootstrap 3 の `.modal` クラスの見た目・表示切換にのみ依存し、ARIA 属性と JS フォーカストラップが未実装だった。

**修正 ①**: ARIA 属性の付与（3 モーダル）

| ファイル | 行 | モーダル ID | 付与した属性 |
|----------|-----|------------|------------|
| `templates/ContentsQuestions/index.php` | 272 | `confirmModal` | `role="dialog"` `aria-modal="true"` `aria-labelledby="confirmModalLabel"` `aria-describedby="confirmModalDesc"` |
| `templates/EnquetesQuestions/index.php` | 152 | `confirmModal` | 同上 |
| `templates/Admin/Contents/edit.php` | 332 | `uploadDialog` | `role="dialog"` `aria-modal="true"` `aria-labelledby="uploadDialogLabel"` |

見出し要素に `id` を付与（`confirmModalLabel` ／ `confirmModalDesc` ／ `uploadDialogLabel`）。`uploadDialog` は説明文がないため `aria-describedby` なし。

**修正 ②**: `uploadDialog` に閉じるボタンを追加
`templates/Admin/Contents/edit.php:335-338` に `modal-header` と `<button type="button" class="close" data-dismiss="modal">` を追加。sr-only テキストは日本語の「閉じる」に統一（他 2 モーダルも同様に「Close」→「閉じる」に変更済み）。

**修正 ③**: フォーカストラップ（`webroot/js/common.js:39-121`）
jQuery イベント委譲で全モーダルに自動適用。`templates/layout/default.php:45` が全ページで `common.js` を読み込むため、個別のテンプレート変更は不要。

```javascript
// common.js:60-62 — show フェーズで呼び出し元のフォーカスを保存
$(document).on('show.bs.modal', '.modal', function () {
    _previousFocus = document.activeElement;
});

// common.js:65-72 — shown フェーズでモーダル内最初のフォーカス可能要素へ移動
$(document).on('shown.bs.modal', '.modal', function () {
    var $focusable = $modal.find(FOCUSABLE).filter(':visible');
    if ($focusable.length) { $focusable.first().focus(); }
});

// common.js:75-80 — hidden フェーズで呼び出し元へ復帰
$(document).on('hidden.bs.modal', '.modal', function () {
    if (_previousFocus && typeof _previousFocus.focus === 'function') {
        _previousFocus.focus();
        _previousFocus = null;
    }
});

// common.js:83-120 — Tab/Shift+Tab サイクル
$(document).on('keydown', '.modal', function (e) { ... });
```

★**教訓（フォーカス復帰の不具合と修正経緯）**:
初版は `shown.bs.modal` で呼び出し元の `document.activeElement` を捕捉していた。しかし Bootstrap 3 は `show` フェーズで `enforceFocus` によりモーダル自身へフォーカスを強制移動するため、`shown` で捕捉すると既にモーダル内の要素を指しており、閉じたときに呼び出し元へ戻せない不具合があった。`show.bs.modal`（アニメーション開始前）で捕捉し、`shown.bs.modal` はフォーカス移動のみを担うよう 2 ハンドラに分割して解決した。

---

### D-16: インストール画面の `<label for>` と input `id` の一致（1 ファイル 4 箇所）

**根本原因**（`W5-cause-analysis.md` §D-16）
手動 HTML 作成時に `<label for="Password">` をコピペで共通化し、各行の input `id` が異なるまま更新されなかった。

**修正**（`templates/Install/index.php:25-46`）

| 行 | Before | After |
|----|--------|-------|
| 25 | `<label for="Password">管理者ログインID</label>` | `<label for="UserUsername">管理者ログインID</label>` |
| 27 | `<input name="...[username]" ...>`（id なし） | `<input name="...[username]" id="UserUsername" ...>` |
| 31 | `<label for="Password">パスワード</label>` | `<label for="UserPassword">パスワード</label>` |
| 37 | `<label for="Password">パスワード(確認用)</label>` | `<label for="UserPassword2">パスワード(確認用)</label>` |
| 43 | `<label for="UserRegistNo" class="..."></label>`（空ラベル） | `<div class="col col-sm-3 control-label"></div>` |

**変更後**: label 3 箇所すべてが実在する input に正しく結びつく。空だった `UserRegistNo` の label は submit ボタンと関連がないため `<div>` へ変更。

---

### D-17: `error400.php` の XSS 修正（2 ファイル、他レーン fix-13 が実施）

**根本原因**（`W5-cause-analysis.md` §D-17）
`templates/error/error400.php:13` と `templates/Error/error400.php:25` の `"<strong>'{$url}'</strong>"` が `$url` を `h()` なしで文字列結合していた。`$url` は GET パラメータ由来のため XSS の余地があった。

**修正**（2 ファイル、同一修正）

```php
// Before
printf(__d('cake', '指定されたアドレス %s へのリクエストは無効です。'), "<strong>'{$url}'</strong>");

// After（templates/error/error400.php:13）
printf(__d('cake', '指定されたアドレス %s へのリクエストは無効です。'), "<strong>'" . h($url) . "'</strong>");

// Before（templates/Error/error400.php:25）
<?= __d('cake', 'The requested address {0} was not found on this server.', "<strong>'{$url}'</strong>") ?>

// After
<?= __d('cake', 'The requested address {0} was not found on this server.', "<strong>'" . h($url) . "'</strong>") ?>
```

★ 注意: 同ファイルには大文字 `Error/` と小文字 `error/` の 2 系統が実在し、**両方を修正**した。

**追加テスト**: `tests/TestCase/Controller/ErrorPageXssTest.php`（6 メソッド、コミット `4deb99e`）
XSS ペイロード入り URL が JSON／エスケープ済みで返ることを検証。

---

### D-19: flash の `<div onclick>` → `<button type="button">`（5 ファイル＋CSS）

**根本原因**（`W5-cause-analysis.md` §D-19）
flash メッセージの閉じる操作を `<div onclick>` で実装し、`<button>` / `role="button"` / `tabindex` / キーボードイベント（Enter/Space）の対応がなかった。

**修正**（5 ファイル）

| ファイル | 行 | Before | After |
|----------|-----|--------|-------|
| `templates/element/flash/default.php` | 15 | `<div class="<?= h($class) ?>" onclick="...">` | `<button type="button" class="<?= h($class) ?>" onclick="...">` |
| `templates/element/flash/error.php` | 11 | `<div class="message error" onclick="...">` | `<button type="button" class="message error" onclick="...">` |
| `templates/element/flash/success.php` | 11 | `<div class="message success" onclick="...">` | `<button type="button" class="message success" onclick="...">` |
| `templates/element/flash/warning.php` | 11 | `<div class="message warning" onclick="...">` | `<button type="button" class="message warning" onclick="...">` |
| `templates/element/flash/info.php` | 11 | `<div class="message" onclick="...">` | `<button type="button" class="message" onclick="...">` |

`onclick="this.classList.add('hidden');"` はそのまま維持。`<button type="button">` によりキーボードの Enter/Space で自動的に発火する。

**CSS リセット**（`webroot/css/common.css:269-288`）

```css
/* flash メッセージの <button> 化に伴うブラウザ既定スタイルのリセット。
   <div> と見た目を一致させる */
button.message {
    display: block;
    width: 100%;
    background: none;
    cursor: default;
    font: inherit;
    color: inherit;
    text-align: left;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    outline: none;
}

/* キーボードフォーカス時の視覚フィードバック */
button.message:focus-visible {
    outline: 2px solid #337ab7;
    outline-offset: 2px;
}
```

**見た目が div 時代と同一である根拠**: `.message` は既に `border: 1px solid transparent` を持ち、`appearance: none` によりブラウザ既定のボタン枠も除去される。`background-image` のグラデーションは `.message` クラスがそのまま適用されるため維持。

---

### D-20: `<caption class="sr-only">` 付与（12 ファイル）

**根本原因**（`W5-cause-analysis.md` §D-20）
テーブル作成時に `<caption>` を含めるコーディング規約が不在だった。

**修正**: 12 テーブルに `<caption class="sr-only">` を付与。スクリーンリーダー専用表示で、視覚レイアウトは一切変更なし。

| ファイル | 行 | caption 文言 |
|----------|-----|-------------|
| `templates/Contents/index.php` | 59 | コンテンツ一覧 |
| `templates/Infos/index.php` | 16 | お知らせ一覧 |
| `templates/UsersCourses/index.php` | 19 | 最新のお知らせ |
| `templates/Admin/Courses/index.php` | 66 | コース一覧 |
| `templates/Admin/Contents/index.php` | 66 | コンテンツ一覧 |
| `templates/Admin/EnquetesQuestions/index.php` | 69 | アンケート質問一覧 |
| `templates/Admin/Groups/index.php` | 8 | グループ一覧 |
| `templates/Admin/Users/index.php` | 40 | ユーザ一覧 |
| `templates/Admin/Users/import.php` | 18 | CSV インポート形式 |
| `templates/Admin/Infos/index.php` | 8 | お知らせ一覧 |
| `templates/Admin/ContentsQuestions/index.php` | 68 | テスト問題一覧 |
| `templates/Admin/Records/index.php` | 79 | 学習履歴一覧 |

既存の参照実装: `templates/ContentsQuestions/index.php:58` の `<caption><?= __('テスト結果'); ?></caption>`（可視）に倣い、`sr-only` クラスで非表示化。

---

### D-21: 見出しレベルの是正（5 ファイル）

**根本原因**（`W5-cause-analysis.md` §D-21）
ページごとに独立に見出しレベルを選択しており、`<h1>`〜`<h6>` の階層設計がなかった。CakePHP デフォルトテンプレート由来の h1 とアプリケーション側の h4 が混在していた。

**修正**（5 ファイル）

| ファイル | 行 | Before | After |
|----------|-----|--------|-------|
| `templates/Pages/home.php` | 110 | `<h4>Environment</h4>` | `<h2>Environment</h2>` |
| 同 | 142 | `<h4>Filesystem</h4>` | `<h2>Filesystem</h2>` |
| 同 | 168 | `<h4>Database</h4>` | `<h2>Database</h2>` |
| 同 | 188 | `<h4>DebugKit</h4>` | `<h2>DebugKit</h2>` |
| 同 | 209 | `<h3>Getting Started</h3>` | `<h2>Getting Started</h2>` |
| 同 | 217 | `<h3>Help and Bug Reports</h3>` | `<h2>Help and Bug Reports</h2>` |
| 同 | 226 | `<h3>Docs and Downloads</h3>` | `<h2>Docs and Downloads</h2>` |
| 同 | 239 | `<h3>Training and Certification</h3>` | `<h2>Training and Certification</h2>` |
| `templates/ContentsQuestions/index.php` | 233 | `<h4><?= h($title) ?></h4>` | `<h2><?= h($title) ?></h2>` |
| `templates/EnquetesQuestions/index.php` | 118 | `<h4><?= h($title) ?></h4>` | `<h2><?= h($title) ?></h2>` |
| `templates/UsersCourses/index.php` | 44 | `<h4 class="list-group-item-heading">` | `<h3 class="list-group-item-heading">` |
| `templates/Admin/Contents/upload.php` | 82 | `<h4>アップロード可能なファイル形式</h4>` | `<h2>アップロード可能なファイル形式</h2>` |
| 同 | 87 | `<h4>アップロード可能なファイルサイズ</h4>` | `<h2>アップロード可能なファイルサイズ</h2>` |

**視覚影響なしの根拠**:
- `webroot/css/common.css` に h1〜h6 個別のスタイルルールは存在しない（Bootstrap 3 のデフォルトがそのまま適用）
- `panel-heading` にも h4 セレクタの直接指定なし
- `webroot/css/common.css:879` の `.users-courses-index h4 { font-weight: bold; }` は h3 が Bootstrap で既に `font-weight: bold` のため同等
- `templates/Pages/home.php` は debug モード専用ページで本番では非表示

---

## 3. 付: D-22 コードスタイル違反の一括修正（コミット `fb8d618`）

### 概要

`vendor/bin/phpcbf` を `src/`・`tests/` の 103 ファイルに適用し、phpcs 違反を **1020 errors / 79 warnings → 42 errors / 31 warnings** に削減。

### ★重要: 自動修正による 500（TypeError）の発生と PCR 対応

`phpcbf` を素朴に適用すると、`Slevomat.TypeHints.ParameterTypeHint.MissingNativeTypeHint` が `src/Utility/Utils.php` の `getYMD($str)` に `string $str` を付与する。しかし同関数は `if (!$str) { return ''; }` で null を許容する設計のため、`templates/Contents/index.php:181` で `TypeError` が発生（**17 件の 500**）。

同様に `ReturnTypeHint` も `getHNSBySec()`（実装は `"HH:MM:SS"` 文字列を返すのに旧 docblock が `@return int` と記載していた）で 8 件の 500 を引き起こした。

**PCR 対応**: `phpcs.xml` に型ヒント sniff（Parameter/Return 両 sniff）の除外を `*/src/*` と `*/tests/*` 全体対象で追加。既存の `ReturnTypeHint` の `*/src/Controller/*` 除外の前例に追随。加えて `getHNSBySec` の docblock を `@return string` へ修正（`src/Utility/Utils.php:94`）。

`phpcs.xml` には以下のコメントを付記（`phpcs.xml:3-7, 15-20`）:

```xml
<!--
    このコードベースは意図的に緩い型に依存しており、legacy な docblock にも
    実装と食い違う記述が残っている（例: Utils::getHNSBySec は "HH:MM:SS" の
    文字列を返すのに旧 docblock が `@return int` と記載していた）。
    これらへ native 型ヒントを強制すると実行時 TypeError になるため、
    Parameter/Return の両方を対象外の sniff とする。
-->
```

### ★重要: phpcbf のクラッシュと回避手順

`vendor/bin/phpcbf` は `phpstan/phpdoc-parser` のバグ（`TokenIterator.php:106` の `Undefined array key -1`）で特定ファイルがクラッシュし、**クラスボディ全体を破壊**する。影響を受けたファイル: `src/Controller/Admin/CoursesController.php`、`src/Controller/Admin/GroupsController.php`。

**採用した回避手順**: ファイル単位で `phpcbf` を実行し、直後の `php -l` が失敗したら `git checkout -- <file>` で復元。この手順により破損ゼロで完了。

★ 教訓: `phpcs.xml` の `--sniffs` オプションでは `No sniffs were registered` で個別 sniff の指定が不可能なため、問題ファイルの除外は `.phpcs.xml` の `<exclude-pattern>` で対応せざるを得なかった。

★ 教訓: `php -l` の通過は意味的無変更を保証しない（型ヒント付与は構文的には有効だが、実行時に null が渡されると TypeError になる）。全スイート検証が必須。

### 残存 42 errors / 31 warnings の内訳

| スニッフ | 件数 | 備考 |
|----------|------|------|
| `UnusedVariable` | 34 | テストの side-effect 変数・意図的 |
| `LineLength.TooLong` | 21 | — |
| `NoSilencedErrors` | 10 | テストの `@unlink()` 等・意図的 |
| `UnusedUses` | 4 | phpcbf がクラッシュするファイル群（`--sniffs` で指定不可のため未処理） |
| `FunctionComment.Missing` | 2 | — |
| `ParameterTypeHint.MissingAnyTypeHint` | 2 | — |

---

## 4. 検証結果

### 4.1 全スイート

```
Tests: 744, Assertions: 3689, Deprecations: 0, PHPUnit Notices: 8, Time: 04:xx
Errors: 0, Failures: 0
```

| ウェーブ | Tests | Assertions | Errors | Failures | Deprecations |
|----------|-------|------------|--------|----------|--------------|
| W1 | 645 | 3173 | 0 | 0 | 2 |
| W2 | 675 | 3324 | 0 | 0 | 2 |
| W3 | 690 | 3356 | 0 | 0 | 2 |
| W4 | 726 | 3622 | 0 | 0 | 2 |
| **W5** | **744** | **3689** | **0** | **0** | **0** |

**Deprecations 2 → 0 への削減**: W4 以前からの既存 2 件（`Query::order()` → `orderBy()`、`AppController::beforeFilter` の `$event->setResult()` 化）を W5 のコミット `4deb99e` で解消。

**テスト数値の推移**: W1 645/3173 → W2 675/3324 → W3 690/3356 → W4 726/3622 → **W5 744/3689**。

### 4.2 ライブ実測（稼働中アプリ）

| 検証 | ステータス | 出力確認 |
|------|-----------|---------|
| `GET /contents-questions/index/7` | 200 | `<input id="answer_2_1">`（D-14）、`role="dialog"` `aria-modal="true"`（D-15）を確認 |
| `GET /enquetes-questions/index/8` | 200 | `<input id="answer_4_1">`（D-14）、`role="dialog"` `aria-modal="true"`（D-15）を確認 |
| `GET /contents-questions/record/7/6` | 200 | 結果ページ正常表示 |

### 4.3 PagesControllerTest の `assertResponseContains('<html>')` に関する教訓

★ 重要: D-13 の `<html lang="ja">` 変更後、`PagesControllerTest::testDisplay`（`tests/TestCase/Controller/PagesControllerTest.php:43`）の `assertResponseContains('<html>')` が想定通りパスすることを前提として報告された。しかし実際には `<html lang="ja">` は `<html>` を部分文字列として含まない（`>` まで含んだ完全一致のため）ため、テストは **失敗**した。Orchestrator が実際に失敗を検出し、テストを `assertResponseContains('<html lang="ja">')` に強化して修正済み。

**教訓**: 「部分文字列として含む」との推論は `>` などのデリミタで成り立たない。HTML タグの属性追加時は、テストの期待値も追加した属性を含む形に更新する必要がある。

---

## 5. 変更ファイル一覧（コミット別）

### コミット `0a2e4fe`（D-14/D-15）

| ファイル | 種別 | 不備 |
|----------|------|------|
| `templates/ContentsQuestions/index.php` | 修正 | D-14: label/id 付与、D-15: ARIA 属性 |
| `templates/EnquetesQuestions/index.php` | 修正 | D-14: label/id 付与、D-15: ARIA 属性 |
| `templates/Admin/Contents/edit.php` | 修正 | D-15: ARIA 属性＋閉じるボタン追加 |
| `webroot/js/common.js` | 修正 | D-15: フォーカストラップ追加（`show`/`shown`/`hidden`/`keydown`） |

### コミット `4deb99e`（D-13/D-16/D-17/D-19/D-20/D-21 他）

| ファイル | 種別 | 不備 |
|----------|------|------|
| `templates/layout/default.php` | 修正 | D-13: `lang="ja"` |
| `templates/layout/error.php` | 修正 | D-13: `lang="ja"` |
| `templates/layout/flash.php` | 修正 | D-13: `lang="ja"` |
| `templates/layout/email/html/default.php` | 修正 | D-13: `lang="ja"` |
| `templates/Contents/view.php` | 修正 | D-13: `lang="ja"` |
| `templates/Pages/home.php` | 修正 | D-13: `lang="ja"`、D-21: h3/h4→h2 |
| `templates/Install/index.php` | 修正 | D-16: label/id 一致 |
| `templates/error/error400.php` | 修正 | D-17: `h($url)` |
| `templates/Error/error400.php` | 修正 | D-17: `h($url)` |
| `templates/element/flash/default.php` | 修正 | D-19: div→button |
| `templates/element/flash/error.php` | 修正 | D-19: div→button |
| `templates/element/flash/success.php` | 修正 | D-19: div→button |
| `templates/element/flash/warning.php` | 修正 | D-19: div→button |
| `templates/element/flash/info.php` | 修正 | D-19: div→button |
| `webroot/css/common.css` | 修正 | D-19: button.message リセット CSS |
| `templates/Contents/index.php` | 修正 | D-20: caption |
| `templates/Infos/index.php` | 修正 | D-20: caption |
| `templates/UsersCourses/index.php` | 修正 | D-20: caption、D-21: h4→h3 |
| `templates/Admin/Courses/index.php` | 修正 | D-20: caption |
| `templates/Admin/Contents/index.php` | 修正 | D-20: caption |
| `templates/Admin/EnquetesQuestions/index.php` | 修正 | D-20: caption |
| `templates/Admin/Groups/index.php` | 修正 | D-20: caption |
| `templates/Admin/Users/index.php` | 修正 | D-20: caption |
| `templates/Admin/Users/import.php` | 修正 | D-20: caption |
| `templates/Admin/Infos/index.php` | 修正 | D-20: caption |
| `templates/Admin/ContentsQuestions/index.php` | 修正 | D-20: caption |
| `templates/Admin/Records/index.php` | 修正 | D-20: caption |
| `templates/ContentsQuestions/index.php` | 修正 | D-21: h4→h2 |
| `templates/EnquetesQuestions/index.php` | 修正 | D-21: h4→h2 |
| `templates/Admin/Contents/upload.php` | 修正 | D-21: h4→h2 |
| `tests/TestCase/Controller/ErrorPageXssTest.php` | 新規 | D-17: XSS 回帰テスト（6 メソッド） |
| `tests/TestCase/Controller/PagesControllerTest.php` | 修正 | D-13: 期待値を `<html lang="ja">` に強化 |

### コミット `fb8d618`（D-22）

| ファイル | 種別 | 不備 |
|----------|------|------|
| `phpcs.xml` | 修正 | D-22: 型ヒント sniff 除外ルール追加 |
| `src/` 配下 65 ファイル | 修正 | D-22: phpcbf 適用（余分な空白、インポート順、関数コメント等） |
| `tests/` 配下 38 ファイル | 修正 | D-22: phpcbf 適用 |

---

## 6. 残課題

1. **D-18**（S3）: `date()` 直接使用 31 箇所（`src/Model/Table/UserTokensTable.php` に 16 箇所集中）。意図的に延期。タイムゾーン前提の変換リスク（`date_default_timezone_get()` と `defaultTimezone` の不一致）に対して機能価値がほぼ無いため。
2. **D-22 の残存 42 errors / 31 warnings**: 上記 §3 の内訳参照。`UnusedVariable` 34 件はテストの side-effect 変数で意図的。`UnusedUses` 4 件は phpcbf クラッシュファイル群で未処理。
3. **Playwright / axe / k6 未導入**: ブラウザ E2E・アクセシビリティ実測・負荷試験は未実施。
4. **D-07** は HTTPS 環境での Cookie `Secure` 再計測が必要。

---

> 本記録は検査・修正の記録であり、成果物ではない。
