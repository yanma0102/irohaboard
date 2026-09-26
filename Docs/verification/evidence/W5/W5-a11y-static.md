# W5 実施記録（P1 機能/非機能 — DB非依存・静的検査）

| 項目 | 内容 |
|------|------|
| 実施日 | 2026-09-25 |
| 実施範囲 | W5（P1 静的解析・アクセシビリティ・非機能静的点検）: 静的解析(phpcs)、WCAG 2.2 AA アクセシビリティ静的点検、タイムゾーン/ロケール、国際化、入力エスケープ、ログ/観測性 |
| 実施方法 | `vendor/bin/phpcs` 実行、`templates/` 配下 grep/読込による手動点検、`src/` grep によるコード点検。**アプリケーションの修整は行っていない** |
| 対象環境 | PHP 8.4.25 / CakePHP 5.4 / Docker `irohaboard5-web-1` |
| 前提 | phpstan は `composer.json` に `suggest` のみ（未導入）、psalm は未導入。依存追加禁止のため両者とも実行不能 |

---

## 1. 結果サマリ

| ID | 検証項目 | 判定 | 根拠 |
|----|----------|------|------|
| W5-S1 | 静的解析 — phpstan | ❌ 不合格 | `vendor/bin/phpstan` 未存在。config は level 8 設定済みだがバイナリ不在（`suggest` のみ、`require-dev` 未含む） |
| W5-S2 | 静的解析 — psalm | ❌ 不合格 | `vendor/bin/psalm` 未存在。config は level 2 設定済みだがバイナリ不在 |
| W5-S3 | コードスタイル (phpcs) | ⚠️ 一部可 | **928 errors / 69 warnings（99 ファイル）**。自動修正可 940 件。`Utils.php:277` が最大違反ファイル |
| W5-A1 | `<html lang>` 有無 | ❌ 不合格 | 6 テンプレートすべて `<html>` に `lang` 属性なし |
| W5-A2 | `<title>` 有無 | ✅ 適正 | 6 レイアウトすべて `<title>` あり |
| W5-A3 | `<img alt>` 有無 | ✅ 適正 | `<img>` は1件のみ（`Pages/home.php:81`）で `alt="CakePHP"` 設定済み |
| W5-A4 | `<label>` と入力の関連付け | ❌ 不合格 | インストール画面で label `for` と input `id` が不一致。また選択肢/radio に `<label>` なし |
| W5-A5 | `id` の重複 | ❌ 不合格 | `id="option"` 2件、`id="lblStudySec"` 2件、`id="confirmModal"` 2件 など |
| W5-A6 | 見出しレベル | ⚠️ 一部可 | `<h1>` は `Pages/home.php` のみ。他ページでは h4 が出現（h1〜h3 を飛越） |
| W5-A7 | テーブルの `<th>`/`<caption>` | ⚠️ 一部可 | 13 テーブル中 1 件のみ `<caption>` あり。`<th>` は大部分に設定済み |
| W5-A8 | モーダル/ダイアログ フォーカス管理 | ❌ 不合格 | 3モーダルすべて `role="dialog"` / `aria-modal` / フォーカストラップなし |
| W5-A9 | `<div onclick>` クリック代替 | ⚠️ 一部可 | flash メッセージ 5 件が `<div onclick>` で閉じる操作。キーボード操作不可 |
| W5-NF1 | タイムゾーン設定 | ✅ 適正 | `defaultTimezone: Asia/Tokyo`、DB タイムゾーン `UTC` |
| W5-NF2 | `date()` 直接使用 | ⚠️ 一部可 | `src/` 内に 31 箇所の `date()` 直接使用。CakePHP の `FrozenTime`/`DateTime` を使うべき箇所 |
| W5-NF3 | 国際化（i18n） | ⚠️ 一部可 | `ja_JP` のみ（1ファイル 599行）。言語切替 UI なし。`__()` 呼び出しは 267 箇所 |
| W5-NF4 | 入力エスケープ | ⚠️ 一部可 | 大部分は `h()` で安全。`$body`（コンテンツ/問題文）は MarkdownRenderer/HTMLPurifier 経由で安全。ただし `$url` がエスケープなしで error400 に出力 |
| W5-NF5 | ログ/観測性 | ✅ 適正 | `writeLog` 呼び出し 6 箇所。`Error.log: trace=true`。パスワード/トークンの直接ログ記入なし |

---

## 2. 詳細 — 静的解析

### 2.1 phpstan / psalm

- `vendor/bin/phpstan`: **不在**（`composer.json` の `suggest` のみ。`require-dev` に未含む）
- `vendor/bin/psalm`: **不在**
- 設定ファイルは存在: `phpstan.neon`（level 8）、`psalm.xml`（level 2）
- **判定: 実行不能** — 依存追加は禁止のためインストール不可

### 2.2 phpcs（composer cs-check）

```
vendor/bin/phpcs --standard=phpcs.xml --extensions=php
```

**結果: 928 errors / 69 warnings / 99 files**

#### ソース別内訳

| 対象 | エラー | 警告 | ファイル数 |
|------|--------|------|-----------|
| `src/` | ~778 | ~53 | 31 |
| `tests/` | 215 | 4 | 38 |

> 注: 合計が 997（928+69）であり、`--report=summary` では 928+69=997。`src/` と `tests/` の個別集計の合計とは一部異なる可能性あり（同一ファイルの二重カウント等）。

#### 違反上位ルール（上位10）

| # | スニッフ | 件数 | 自動修正可 |
|---|----------|------|-----------|
| 1 | Slevomat.Namespaces.ReferenceUsedNamesOnly | 223 | ✅ |
| 2 | Generic.WhiteSpace.DisallowTabIndent | 173 | ✅ |
| 3 | Slevomat.Functions.RequireTrailingCommaInCall | 94 | ✅ |
| 4 | Slevomat.WhiteSpace.DuplicateSpaces | 90 | ✅ |
| 5 | Slevomat.TypeHints.ParameterTypeHint.MissingNativeTypeHint | 63 | ✅ |
| 6 | CakePHP.Formatting.BlankLineBeforeReturn | 56 | ✅ |
| 7 | CakePHP.Commenting.TypeHintIncorrectFormat | 48 | ✅ |
| 8 | Squiz.WhiteSpace.SuperfluousWhitespace | 35 | ✅ |
| 9 | PSR2.Files.EndFileNewline | 30 | ✅ |
| 10 | Slevomat.Variables.UnusedVariable | 26 | ❌ |

PHPCBF 自動修正可能: **940 件**（997 中 94.3%）

---

## 3. 詳細 — アクセシビリティ静的点検（WCAG 2.2 AA）

### 3.1 `<html lang>` 属性（WCAG 3.1.1 — ページの言語）

**全6テンプレートで `lang` 属性が不在:**

| file:line | 内容 |
|-----------|------|
| `templates/layout/default.php:13` | `<html>` （`lang` なし） |
| `templates/layout/error.php:2` | `<html>` （`lang` なし） |
| `templates/layout/flash.php:2` | `<html>` （`lang` なし） |
| `templates/Contents/view.php:2` | `<html>` （`lang` なし） |
| `templates/Pages/home.php:61` | `<html>` （`lang` なし） |
| `templates/layout/email/html/default.php:18` | `<html>` （`lang` なし） |

**重大度: S3** — スクリーンリーダーがページ言語を判別できず、読み上げ精度が低下する。

### 3.2 `<label>` と入力の関連付け（WCAG 1.3.1 / 4.1.2）

#### (a) インストール画面 — `for` と `id` の不一致（4件）

| file:line | `<label for>` | 対応する `<input id>` | 乖離 |
|-----------|---------------|----------------------|------|
| `templates/Install/index.php:15` | `for="Password"` | **id なし** | `for="Password"` は存在しない id |
| `templates/Install/index.php:21` | `for="Password"` | `id="UserPassword"` | `for` が実 ID と不一致 |
| `templates/Install/index.php:27` | `for="Password"` | `id="UserPassword2"` | `for` が実 ID と不一致 |
| `templates/Install/index.py:33` | `for="UserRegistNo"` | **id なし**（submit ボタン） | 存在しない id を参照 |

**重大度: S3** — インストール画面は初期セットアップ時にのみ使用だが、スクリーンリーダーユ ユーザーが ID/パスワード入力栏とラベルの対応関係を理解できない。

#### (b) 選択肢ラジオボタン/チェックボックスに `<label>` なし（60件+）

| ファイル | 内容 |
|----------|------|
| `templates/ContentsQuestions/index.php:124,134` | `<input type="checkbox/radio">` に `<label for>` なし（選択肢テキストは直後にテキストノード） |
| `templates/EnquetesQuestions/index.php:105` | 同上 |

**重大度: S2** — テスト/アンケート画面の主要操作領域。スクリーンリーダーでラジオボタン/チェックボックスの操作が困難。

### 3.3 `id` の重複（WCAG 4.1.1 — parsing / name, role, value）

| id | 件数 | ファイル | 重大度 |
|----|------|----------|--------|
| `id="option"` | 2 | `Admin/EnquetesQuestions/edit.php:197`, `Admin/ContentsQuestions/edit.php:166` | S3 |
| `id="lblStudySec"` | 2 | `EnquetesQuestions/index.php:47`, `ContentsQuestions/index.php:50` | S3（別ページなので実害軽） |
| `id="confirmModal"` | 2 | `EnquetesQuestions/index.php:151`, `ContentsQuestions/index.php:252` | S3（別ページなので実害軽） |
| `id="btnUpload"` | 2 | `Admin/Contents/edit.php:15`（JS生成）, `Admin/Contents/upload.php:98` | S3 |

**同一ページ内重複**: `id="option"` が `EnquetesQuestions/edit.php` と `ContentsQuestions/edit.php` の各ページに1つずつ存在。`id="confirmModal"` も同様に別ページに1つずつ。同一ページ内重複は見つからず。ただし `for="Password"` の4件は、label が存在しない id を参照するため実質的な不一致。

### 3.4 見出しレベル（WCAG 1.3.1 — info and relationships）

| ファイル | 見出し | 問題 |
|----------|--------|------|
| `Pages/home.php` | h1, h3, h4 | h1 → h3 → h4（h2 欠落）。CakePHP デフォルトテンプレート |
| `ContentsQuestions/index.php` | h4 のみ | h1〜h3 が不在。テスト結果ページに見出しがない |
| `EnquetesQuestions/index.php` | h4 のみ | 同上 |
| `UsersCourses/index.php` | h4 のみ | コース一覧に h1〜h3 不在 |
| `Admin/Contents/upload.php` | h4 のみ | アップロードダイアログ内 |
| `Error/error400.php` | h2 のみ | 適切（エラーページ） |
| `Error/error500.php` | h2 のみ | 適切（エラーページ） |

**重大度: S4** — 軽微。見出しの飛びはありだが、各ページに1種類の見出しレベルのみで構成されているため、ナビゲーションへの影響は限定的。

### 3.5 テーブル `<th>`/`<caption>`（WCAG 1.3.1）

13 テーブル中 `<caption>` あり: **1件**（`ContentsQuestions/index.php:58` — 「テスト結果」）

12 テーブルで `<caption>` なし:

| ファイル | テーブル |
|----------|----------|
| `Contents/index.php:58` | コンテンツ一覧 |
| `Infos/index.php:15` | お知らせ一覧 |
| `UsersCourses/index.php:18` | コース一覧 |
| `Admin/Courses/index.php:65` | コース管理 |
| `Admin/Contents/index.php:65` | コンテンツ管理 |
| `Admin/EnquetesQuestions/index.php:68` | アンケート問題管理 |
| `Admin/Groups/index.php:7` | グループ管理 |
| `Admin/Users/index.php:39` | ユーザー管理 |
| `Admin/Users/import.php:17` | CSV形式説明 |
| `Admin/Infos/index.php:7` | お知らせ管理 |
| `Admin/ContentsQuestions/index.php:67` | テスト問題管理 |
| `Admin/Records/index.php:78` | 学習履歴管理 |

**重大度: S4** — `<th>` はすべてのテーブルに設定済み。`<caption>` 欠如はスクリーンリーダーのテーブル識別をやや低下させるが、直前の見出しが文脈として機能する。

### 3.6 モーダル/ダイアログ — フォーカス管理（WCAG 2.4.3 / 4.1.2）

| file:line | モーダル ID | 欠落 |
|-----------|------------|------|
| `EnquetesQuestions/index.php:151` | `confirmModal` | `role="dialog"`, `aria-modal`, `aria-labelledby` なし、フォーカストラップなし |
| `ContentsQuestions/index.php:252` | `confirmModal` | 同上 |
| `Admin/Contents/edit.php:332` | `uploadDialog` | 同上。さらに閉じるボタンなし（JS での `modal('hide')` のみ） |

**重大度: S2** — テスト/アンケートの採点確認モーダルは主要操作フローに含まれる。フォーカストラップがないため、キーボードユーザーがモーダル外の要素にフォーカスが逸脱しやすい。`Admin/Contents/edit.php` のアップロードダイアログはキーボードのみでは閉じられない。

### 3.7 `<div onclick>` クリック代替（WCAG 2.1.1 — Keyboard）

| file:line | 要素 | 影響 |
|-----------|------|------|
| `element/flash/error.php:11` | `<div onclick="this.classList.add('hidden');">` | キーボード操作不可、`role`/`aria-live` なし |
| `element/flash/success.php:11` | 同上 | 同上 |
| `element/flash/warning.php:11` | 同上 | 同上 |
| `element/flash/info.php:11` | 同上 | 同上 |
| `element/flash/default.php:15` | 同上 | 同上 |

**重大度: S4** — flash メッセージの閉じる操作のみであり、メッセージ自体は表示後に自動消失する可能性がある（CakePHP の Flash 行為による）。操作不能にはならないが、キーボードユーザーが閉じる操作を実行できない。

### 3.8 その他のアクセシビリティ確認

- **`<img alt>`**: 1件のみ（`Pages/home.php:81`）で `alt="CakePHP"` 設定済み。装飾画像（`aria-hidden="true"` 付き `<span>`）は適切に隠蔽。
- **`<html lang>`**: 上記3.1で全件不在。本アプリは `ja_JP` のみのため、`lang="ja"` を設定すべき。
- **ラジオボタン/チェックボックスの `<label>` なし**: 上記3.2(b)で列挙。

---

## 4. 詳細 — その他の非機能（静的）

### 4.1 タイムゾーン / ロケール

**設定値（`config/app.php`）:**
- `defaultTimezone`: `Asia/Tokyo`（line 56）
- `defaultLocale`: `ja_JP`（line 55）
- DB timezone: `UTC`（line 306）

**`date()` 直接使用: 31 箇所**

| 対象ファイル群 | 件数 | 内容 |
|---------------|------|------|
| `src/Model/Table/UserTokensTable.php` | 16 | トークン有効期限・最終利用日時・無効化日時 |
| `src/Controller/Trait/UserLoginTrait.php` | 3 | 最終ログイン日時、閾値計算 |
| `src/Controller/Api/AuthController.php` | 2 | トークン有効期限、レート制限閾値 |
| `src/Controller/UsersController.php` | 1 | CSV ファイル名 |
| `src/Controller/Admin/UsersController.php` | 1 | CSV ファイル名 |
| `src/Controller/Admin/ContentsController.php` | 2 | ファイルリネーム（`YmdHis`） |
| `src/Controller/Admin/RecordsController.php` | 2 | 日付範囲デフォルト |
| `src/Middleware/ApiRateLimitMiddleware.php` | 2 | ウィンドウキー、残り秒数 |
| `src/View/Helper/AppFormHelper.php` | 2 | 現在年 |

**重大度: S4** — 大部分は DB 格納用の日付文字列であり、UTC で統一されているため実害は軽微。ただし `defaultTimezone` と `date()` のタイムゾーンが不一致（`Asia/Tokyo` vs PHP デフォルト）になる可能性がある。

### 4.2 国際化（i18n）

| 項目 | 状況 |
|------|------|
| ロケールファイル | `src/Locale/ja_JP/default.po`（599行、1ファイルのみ） |
| 対応言語 | `ja_JP` のみ |
| 言語切替 UI | なし |
| `__()` 使用箇所 | テンプレート内 267 箇所 |
| スクリプトロケール | `lang/summernote-ja-JP.js` のみ |

**重大度: N/A** — 日本語単一言語のアプリケーションであるため、多言語対応は要求されない場合がある。ただし将来の国際化対応を考慮すると、`__()` の使用は適切。

### 4.3 入力エスケープ

**安全（`h()` 使用済みまたはサニタイズ経由）:**
- 大部分のテンプレート出力は `h()` でエスケープ済み
- `Contents/view.php:76` の `$body` は `MarkdownRenderer::toHtml()` / `MarkdownRenderer::purifyHtml()` / `h()` 経由で安全
- `Infos/view.php:29,32` の `$title`/`$body` は `h()` / `autoLinkUrls()` 経由で安全
- flash メッセージは `element/flash/*.php` で条件付き `h()` 済み

**未エスケープの箇所:**

| file:line | 出力 | リスク |
|-----------|------|--------|
| `Contents/view.php:6` | `<title><?= $content['title']; ?></title>` | コンテンツタイトルが管理者入力のため XSS 低リスク。ただし `<title>` 内の HTML 解釈はブラウザにより異なる |
| `error/error400.php:13` | `"指定されたアドレス {$url} へのリクエスト..."` | `$url` が `h()` なしで直接文字列結合。**GET パラメータ由来の XSS 余地あり** |
| `error/error500.php:1` | `<?= $message; ?>` | エラーメッセージ。`debug` 時にフレームワーク例外メッセージが含まれる |
| `error/error400.php:1` | `<?= $message; ?>` | 同上 |

**重大度: S3** — `error400.php:13` の `$url` は注目に値する。ただし CakePHP の ErrorController がエラーメッセージをフィルタリングする場合があるため、実際の影響は限定的。管理画面由来のコンテンツタイトルは XSS 低リスク（管理者限定入力）。

### 4.4 ログ / 観測性

**`writeLog` 呼び出し: 6 箇所**

| ファイル:行 | log_type | log_content |
|------------|----------|-------------|
| `UsersController.php:245` | `user_exported` | 空文字 |
| `UserLoginTrait.php:60` | `user_logined` | 空文字 |
| `UserLoginTrait.php:98` | `login_blocked` | `$inputUsername` |
| `UserLoginTrait.php:133` | `user_logined` | 空文字 |
| `UserLoginTrait.php:144` | `login_error` | `$inputUsername` |
| `Admin/UsersController.php:639` | `user_exported` | 空文字 |

**ログ設定（`config/app.php`）:**
- `Error.log`: `'log' => true`（line 193）
- `Error.trace`: `'trace' => true`（line 194）
- `Log.error`: `FileLog` → `logs/error.log`
- `Log.debug`: `FileLog` → `logs/debug.log`

**機密情報ログ漏洩リスク:**
- パスワード: `writeLog` にパスワードは含まれない ✅
- トークン: `writeLog` にトークンは含まれない ✅
- ただし `trace: true` により、スタックトレースに変数値が含まれる可能性はある

---

## 5. 不備一覧

| ID | 重大度 | 内容 | 該当 |
|----|--------|------|------|
| D-13 | **S3** | `<html lang>` 属性が全テンプレート（6件）で不在。スクリーンリーダーがページ言語を判別できない（WCAG 3.1.1） | W5-A1 |
| D-14 | **S2** | テスト/アンケート画面のラジオボタン・チェックボックスに `<label>` なし。スクリーンリーダーで選択肢の操作が困難（WCAG 1.3.1/4.1.2） | W5-A4 |
| D-15 | **S2** | モーダル/ダイアログに `role="dialog"` / `aria-modal` / フォーカストラップなし。キーボードユーザーの操作性に影響（WCAG 2.4.3/4.1.2） | W5-A8 |
| D-16 | **S3** | インストール画面の `<label for="Password">` が実際の input id と不一致（4件）。ラベルと入力栏の対応関係が不明（WCAG 1.3.1） | W5-A4 |
| D-17 | **S3** | `error/error400.php:13` で URL パラメータ `$url` がエスケープなしで出力。XSS の余地あり | W5-NF4 |
| D-18 | **S3** | `src/` 内に `date()` 直接使用が 31 箇所。`defaultTimezone`（`Asia/Tokyo`）と `date()` のタイムゾーンが不一致になる可能性 | W5-NF2 |
| D-19 | **S4** | flash メッセージの `<div onclick>` がキーボード操作不可（5件）。`role` / `aria-live` も不在 | W5-A9 |
| D-20 | **S4** | 全テンプレートで 13 テーブル中 12 テーブルに `<caption>` なし。スクリーンリーダーのテーブル識別が困難（WCAG 1.3.1） | W5-A7 |
| D-21 | **S4** | 見出しレベルの飛び。`Pages/home.php` で h1→h3（h2 欠落）、他ページでは h4 のみ（h1〜h3 不在） | W5-A6 |
| D-22 | **S4** | phpcs 違反 997 件（928 errors + 69 warnings）。`Utils.php:277` が最大違反ファイル | W5-S3 |

---

## 6. 教訓

1. **W0/W1/W2 への追記**: phpcs の自動修正（`cs-fix`）は 940/997 件（94.3%）が自動修正可能。初回リリース前に一度 `composer cs-fix` を実行し、コードスタイルを統一すべき。ただし本波では「修整禁止」のため実施していない。
2. **ア クセシビリティの初期対応が重要**: `<html lang>` やモーダルの `role="dialog"` は、テンプレート作成時に1行ずつ追加するだけの軽微な変更だが、後から全テンプレートに追加するのは工数がかかる。新規テンプレート作成時のチェックリストに含めるべき。
3. **`date()` の CakePHP ユーティリティ化**: `UserTokensTable.php` に `date()` が 16 箇所集中。CakePHP の `FrozenTime` / `DateTime` に統一することで、タイムゾーン設定との整合性とテスト容易性が向上する。
