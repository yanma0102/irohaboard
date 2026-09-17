# 09 — View 設計

## 1. 概要

CakePHP 2.10 の View 層（.ctp テンプレート + Helper）を CakePHP 5.x の View 層（.php テンプレート + Helper）へ移行する設計を定義する。

| 項目 | 現行 | 移行先 |
|---|---|---|
| テンプレート拡張子 | `.ctp` | `.php` |
| テンプレートディレクトリ | `View/` | `templates/` |
| HtmlHelper | `BoostCake.BoostCakeHtml` | `friendsofcake/bootstrap-ui` の `HtmlHelper` |
| FormHelper | `AppBoostCakeForm` | `friendsofcake/bootstrap-ui` の `FormHelper` + AppFormHelper 統合 |
| PaginatorHelper | `BoostCake.BoostCakePaginator` | `friendsofcake/bootstrap-ui` の `PaginatorHelper` |
| Session ヘルパー | `$this->Session->flash()` | `$this->Flash->render()` |
| FormHelper::input() | `$this->Form->input()` | `$this->Form->control()` |

根拠: `Docs/design/README.md:106-117`, `Docs/cakephp5-migration-spec.md:263-270`

---

## 2. テンプレートリネーム一覧

全 51 ファイルの `.ctp` → `.php` リネームと `View/` → `templates/` ディレクトリ変更。

### 2.1 コンテンツ関連

| # | 現行パス | 移行先パス |
|---|---|---|
| 1 | `View/Contents/admin_edit.ctp` | `templates/Admin/Contents/admin_edit.php` |
| 2 | `View/Contents/admin_index.ctp` | `templates/Admin/Contents/admin_index.php` |
| 3 | `View/Contents/admin_upload.ctp` | `templates/Admin/Contents/admin_upload.php` |
| 4 | `View/Contents/index.ctp` | `templates/Contents/index.php` |
| 5 | `View/Contents/view.ctp` | `templates/Contents/view.php` |

### 2.2 テスト問題関連

| # | 現行パス | 移行先パス |
|---|---|---|
| 6 | `View/ContentsQuestions/admin_edit.ctp` | `templates/Admin/ContentsQuestions/admin_edit.php` |
| 7 | `View/ContentsQuestions/admin_index.ctp` | `templates/Admin/ContentsQuestions/admin_index.php` |
| 8 | `View/ContentsQuestions/index.ctp` | `templates/ContentsQuestions/index.php` |

### 2.3 コース関連

| # | 現行パス | 移行先パス |
|---|---|---|
| 9 | `View/Courses/admin_edit.ctp` | `templates/Admin/Courses/admin_edit.php` |
| 10 | `View/Courses/admin_index.ctp` | `templates/Admin/Courses/admin_index.php` |

### 2.4 エレメント

| # | 現行パス | 移行先パス |
|---|---|---|
| 11 | `View/Elements/Flash/default.ctp` | `templates/element/Flash/default.php` |
| 12 | `View/Elements/admin_menu.ctp` | `templates/element/admin_menu.php` |
| 13 | `View/Elements/paging.ctp` | `templates/element/paging.php` |

### 2.5 メールテンプレート

| # | 現行パス | 移行先パス |
|---|---|---|
| 14 | `View/Emails/html/default.ctp` | `templates/email/html/default.php` |
| 15 | `View/Emails/text/default.ctp` | `templates/email/text/default.php` |

### 2.6 アンケート関連

| # | 現行パス | 移行先パス |
|---|---|---|
| 16 | `View/EnquetesQuestions/admin_edit.ctp` | `templates/Admin/EnquetesQuestions/admin_edit.php` |
| 17 | `View/EnquetesQuestions/admin_index.ctp` | `templates/Admin/EnquetesQuestions/admin_index.php` |
| 18 | `View/EnquetesQuestions/index.ctp` | `templates/EnquetesQuestions/index.php` |

### 2.7 エラーページ

| # | 現行パス | 移行先パス |
|---|---|---|
| 19 | `View/Errors/error400.ctp` | `templates/error/error400.php` |
| 20 | `View/Errors/error500.ctp` | `templates/error/error500.php` |

### 2.8 グループ管理

| # | 現行パス | 移行先パス |
|---|---|---|
| 21 | `View/Groups/admin_edit.ctp` | `templates/Admin/Groups/admin_edit.php` |
| 22 | `View/Groups/admin_index.ctp` | `templates/Admin/Groups/admin_index.php` |

### 2.9 お知らせ関連

| # | 現行パス | 移行先パス |
|---|---|---|
| 23 | `View/Infos/admin_edit.ctp` | `templates/Admin/Infos/admin_edit.php` |
| 24 | `View/Infos/admin_index.ctp` | `templates/Admin/Infos/admin_index.php` |
| 25 | `View/Infos/index.ctp` | `templates/Infos/index.php` |
| 26 | `View/Infos/view.ctp` | `templates/Infos/view.php` |

### 2.10 インストーラ

| # | 現行パス | 移行先パス |
|---|---|---|
| 27 | `View/Install/complete.ctp` | `templates/Admin/Install/complete.php` |
| 28 | `View/Install/error.ctp` | `templates/Admin/Install/error.php` |
| 29 | `View/Install/index.ctp` | `templates/Admin/Install/index.php` |
| 30 | `View/Install/installed.ctp` | `templates/Admin/Install/installed.php` |

### 2.11 レイアウト

| # | 現行パス | 移行先パス |
|---|---|---|
| 31 | `View/Layouts/Emails/html/default.ctp` | `templates/layout/Emails/html/default.php` |
| 32 | `View/Layouts/Emails/text/default.ctp` | `templates/layout/Emails/text/default.php` |
| 33 | `View/Layouts/ajax.ctp` | `templates/layout/ajax.php` |
| 34 | `View/Layouts/default.ctp` | `templates/layout/default.php` |
| 35 | `View/Layouts/error.ctp` | `templates/layout/error.php` |
| 36 | `View/Layouts/flash.ctp` | `templates/layout/flash.php` |
| 37 | `View/Layouts/js/default.ctp` | `templates/layout/js/default.php` |
| 38 | `View/Layouts/rss/default.ctp` | `templates/layout/rss/default.php` |
| 39 | `View/Layouts/xml/default.ctp` | `templates/layout/xml/default.php` |

### 2.12 学習履歴

| # | 現行パス | 移行先パス |
|---|---|---|
| 40 | `View/Records/admin_index.ctp` | `templates/Admin/Records/admin_index.php` |

### 2.13 システム設定

| # | 現行パス | 移行先パス |
|---|---|---|
| 41 | `View/Settings/admin_index.ctp` | `templates/Admin/Settings/admin_index.php` |

### 2.14 アップデート

| # | 現行パス | 移行先パス |
|---|---|---|
| 42 | `View/Update/error.ctp` | `templates/Admin/Update/error.php` |
| 43 | `View/Update/index.ctp` | `templates/Admin/Update/index.php` |

### 2.15 ユーザ管理

| # | 現行パス | 移行先パス |
|---|---|---|
| 44 | `View/Users/admin_edit.ctp` | `templates/Admin/Users/admin_edit.php` |
| 45 | `View/Users/admin_import.ctp` | `templates/Admin/Users/admin_import.php` |
| 46 | `View/Users/admin_index.ctp` | `templates/Admin/Users/admin_index.php` |
| 47 | `View/Users/admin_login.ctp` | `templates/Admin/Users/admin_login.php` |
| 48 | `View/Users/admin_setting.ctp` | `templates/Admin/Users/admin_setting.php` |
| 49 | `View/Users/login.ctp` | `templates/Users/login.php` |
| 50 | `View/Users/setting.ctp` | `templates/Users/setting.php` |

### 2.16 受講者コース

| # | 現行パス | 移行先パス |
|---|---|---|
| 51 | `View/UsersCourses/index.ctp` | `templates/UsersCourses/index.php` |

---

## 3. レイアウト設計

### 3.1 デフォルトレイアウト (`templates/layout/default.php`)

移行後のレイアウト構成。`View/Layouts/default.ctp` をベースに以下の変更を適用する。

#### 変更点一覧

| # | 変更前 | 変更後 | 根拠 |
|---|---|---|---|
| 1 | `$this->readSession('Setting.title')` | `$this->request->getSession()->read('Setting.title')` | `View/AppView.php:23-26` → CakePHP 5 の Session アクセス |
| 2 | `$this->isAdminPage()` | `$this->is('admin')` または Helper メソッド | `View/AppView.php:91-93` |
| 3 | `$this->isLoginPage()` | Helper メソッド | `View/AppView.php:115-118` |
| 4 | `$this->Session->flash()` | `$this->Flash->render()` | `View/Layouts/default.ctp:101` |
| 5 | `$this->readSession('Setting.color')` | `$this->request->getSession()->read('Setting.color')` | `View/Layouts/default.ctp:66` |
| 6 | `$this->readSession('Setting.copyright')` | `$this->request->getSession()->read('Setting.copyright')` | `View/Layouts/default.ctp:107` |
| 7 | `echo $this->Html->css(...)` | `echo $this->Html->css(...)` | 変更なし（bootstrap-ui 互換） |
| 8 | `echo $this->Html->script(...)` | `echo $this->Html->script(...)` | 変更なし（bootstrap-ui 互換） |
| 9 | `echo $this->Html->url('/')` | `echo $this->Url->build('/')` | CakePHP 5 では `$this->Html->url()` → `$this->Url->build()` |
| 10 | `$this->element('sql_dump')` | `$this->element('sql_dump')` | 変更なし |
| 11 | `Configure::read('demo_mode')` | `Configure::read('demo_mode')` | 変更なし |

#### CSS/JS 読み込み方法

 CakePHP 5 の `templates/layout/default.php` では以下のパターンを採用する。

```php
<!-- CSS 読み込み（bootstrap-ui 対応） -->
<?= $this->Html->css('jquery-ui') ?>
<?= $this->Html->css('bootstrap.min') ?>
<?= $this->Html->css('common.css?20210701') ?>

<!-- JS 読み込み -->
<?= $this->Html->script('jquery-3.6.0.min.js') ?>
<?= $this->Html->script('bootstrap.bundle.min.js') ?>
<?= $this->Html->script('common.js?20220401') ?>

<!-- テンプレートブロック -->
<?= $this->fetch('meta') ?>
<?= $this->fetch('css') ?>
<?= $this->fetch('script') ?>
<?= $this->fetch('css-embedded') ?>
<?= $this->fetch('script-embedded') ?>
```

根拠: `View/Layouts/default.ctp:28-61`（CSS/JS 読み込み箇所）

> **注意**: jQuery のバージョンは 1.9.1 → 3.6.0 等へ更新が推奨されるが、UI の動作確認が必要。移行時は一旦バージョンを維持し、後日アップデートする方針。

#### フラッシュメッセージ

CakePHP 2 の `$this->Session->flash()` は CakePHP 5 では `$this->Flash->render()` に置換する。表示対象のクセキを指定する場合:

```php
<!-- CakePHP 2 -->
<?= $this->Session->flash(); ?>

<!-- CakePHP 5 -->
<?= $this->Flash->render(); ?>

<!-- CakePHP 5（特定クセキ） -->
<?= $this->Flash->render('auth'); ?>
```

根拠: `View/Layouts/default.ctp:101`, `View/Layouts/error.ctp:21`

### 3.2 エラーレイアウト (`templates/layout/error.php`)

`View/Layouts/error.ctp` の `$this->Session->flash()` を `$this->Flash->render()` に変更する。

根拠: `View/Layouts/error.ctp:21`

### 3.3 その他のレイアウト

| レイアウト | 変更内容 |
|---|---|
| `ajax.php` | `$this->fetch('content')` のみ。変更なし |
| `flash.php` | CakePHP 5 の標準レイアウトに置き換え（実質的に不要になる可能性が高い） |
| `js/default.php` | `$scripts_for_layout` は CakePHP 5 で廃止。`$this->fetch('script')` に変更 |
| `rss/default.php` | `RssHelper` は CakePHP 5 でコアから削除。必要に応じて別パッケージ |
| `xml/default.php` | `$this->fetch('content')` のみ。変更なし |
| `Emails/html/default.php` | 変更なし |
| `Emails/text/default.php` | 変更なし |

根拠: `View/Layouts/ajax.ctp`, `View/Layouts/flash.ctp`, `View/Layouts/js/default.ctp`, `View/Layouts/rss/default.ctp`, `View/Layouts/xml/default.ctp`

---

## 4. カスタムヘルパー設計

### 4.1 AppHelper → CakePHP 5 Helper への統合

`View/Helper/AppHelper.php` の各メソッドの移行先を定義する。

| メソッド | 現行の機能 | CakePHP 5 での対応 |
|---|---|---|
| `inputExp()` | 説明付きテキストボックス出力 | `AppHelper` に継続。`$this->Form->control()` + `after` オプション |
| `inputRadio()` | ラジオボタン出力 | `AppHelper` に継続。`$this->Form->control('field', ['type' => 'radio'])` |
| `searchField()` | 検索フィールド出力 | `AppHelper` に継続。`$this->Form->control()` にデフォルトオプション付与 |
| `inputDate()` | 日付指定リストボックス | `AppHelper` に継続。`$this->Form->control('field', ['type' => 'date'])` |
| `searchDate()` | 検索用日付指定リストボックス | `AppHelper` に継続。`inputDate()` と同様 |
| `block()` | 説明用ブロック出力 | **廃止**。HTML テンプレートに分離 |

根拠: `View/Helper/AppHelper.php:30-179`

#### AppHelper 移行後の定義（`src/View/Helper/AppHelper.php`）

```php
<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;
use Cake\View\View;

class AppHelper extends Helper
{
    protected array $helpers = ['Form', 'Html'];

    public function inputExp(string $fieldName, array $options = [], string $exp = ''): string
    {
        $options['after'] = '<div class="col col-sm-3"></div>'
            . '<div class="col col-sm-9 status-exp">' . $exp . '</div>';
        return $this->Form->control($fieldName, $options);
    }

    public function inputRadio(string $fieldName, array $options = [], string $exp = ''): string
    {
        $options['type'] = 'radio';
        $options['div'] = $options['div'] ?? 'form-group required';
        $options['before'] = $options['before'] ?? '<label class="col col-sm-3 control-label">' . ($options['label'] ?? '') . '</label>';
        $options['separator'] = $options['separator'] ?? '　';
        $options['legend'] = $options['legend'] ?? false;
        $options['class'] = $options['class'] ?? false;
        $options['default'] = $options['default'] ?? null;

        if ($exp !== '') {
            $options['after'] = '<div class="col col-sm-3">&nbsp;</div>'
                . '<div class="col col-sm-9 col-exp status-exp">' . $exp . '</div>';
        }

        return $this->Form->control($fieldName, $options);
    }

    public function searchField(string $fieldName, array $additionalOptions = []): string
    {
        $options = [
            'class' => 'form-control',
            'required' => false,
        ];
        $options = array_merge($options, $additionalOptions);

        if (isset($options['label'])) {
            $options['label'] .= ' :';
        }

        return $this->Form->control($fieldName, $options);
    }

    public function inputDate(string $fieldName, array $additionalOptions = []): string
    {
        $options = [
            'type' => 'date',
            'dateFormat' => 'YMD',
            'monthNames' => false,
            'timeFormat' => '24',
            'minYear' => date('Y') - 5,
            'maxYear' => date('Y') + 5,
            'separator' => ' / ',
            'class' => 'form-control',
            'style' => 'width:initial; display: inline;',
        ];
        $options = array_merge($options, $additionalOptions);

        if (isset($options['label']) && $options['label'] !== '～') {
            $options['label'] .= ' :';
        }

        return $this->Form->control($fieldName, $options);
    }

    public function searchDate(string $fieldName, array $additionalOptions = []): string
    {
        $options = [
            'type' => 'date',
            'dateFormat' => 'YMD',
            'monthNames' => false,
            'timeFormat' => '24',
            'minYear' => date('Y') - 5,
            'maxYear' => date('Y'),
            'separator' => ' / ',
            'class' => 'form-control',
            'style' => 'width:initial; display: inline;',
        ];
        $options = array_merge($options, $additionalOptions);

        if (isset($options['label']) && $options['label'] !== '～') {
            $options['label'] .= ' :';
        }

        return $this->Form->control($fieldName, $options);
    }

    // block() メソッドは廃止。HTML テンプレートに分離する
}
```

### 4.2 AppFormHelper → friendsofcake/bootstrap-ui への統合

`View/Helper/AppFormHelper.php` のカスタムタグ処理（`help`, `helpText`, `helpList`, `example`）を移行する。

根拠: `View/Helper/AppFormHelper.php:12-14`, `View/Helper/AppFormHelper.php:25-131`

#### 移行方針

CakePHP 5 の `friendsofcake/bootstrap-ui` の `FormHelper` は `help` オプションをネイティブでサポートしている。現行の `AppFormHelper` のカスタムタグは以下の方法で統合する:

| カスタムタグ | 現行の動作 | CakePHP 5 での対応 |
|---|---|---|
| `help` | `helpText` or `helpList` に分岐 | bootstrap-ui の `help` オプションで対応 |
| `helpText` | `<p class="help-text">` でラップし `after` に追加 | bootstrap-ui の `help` オプションに文字列を渡す |
| `helpList` | `<ul class="help-list">` でラップし `after` に追加 | bootstrap-ui の `help` オプションに配列を渡す |
| `example` | `<p class="example">` でラップし `after` の先頭に追加 | カスタム Helper メソッドとして継続 |

> **注意**: `String::insert()` は CakePHP 5 で `Text::insert()` に変更（`AppFormHelper.php:80,122`）。CakePHP 5 では `Text::insert()` も非推奨のため、直接文字列連結に変更する。

#### 移行後の `src/View/Helper/AppFormHelper.php`

```php
<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper\FormHelper;

class AppFormHelper extends FormHelper
{
    public function input(string $fieldName, array $options = []): string
    {
        // help タグの処理（bootstrap-ui と互換性を保ちつつ拡張）
        if (isset($options['help'])) {
            $options = $this->processHelp($options);
        }

        // example タグの処理
        if (isset($options['example'])) {
            $options = $this->processExample($options);
        }

        return parent::control($fieldName, $options);
    }

    public function bs_input(string $fieldName, array $options = []): string
    {
        $options['label'] = false;
        return parent::control($fieldName, $options);
    }

    private function processHelp(array $options): array
    {
        if (is_array($options['help'])) {
            $ul = '<ul class="help-list">'
                . implode('', array_map(fn($item) => '<li>' . h($item) . '</li>', $options['help']))
                . '</ul>';
            $options['help'] = $ul;
        } else {
            $options['help'] = '<p class="help-text">' . $options['help'] . '</p>';
        }
        return $options;
    }

    private function processExample(array $options): array
    {
        $text = '<p class="example">入力例) ' . h($options['example']) . '</p>';
        if (isset($options['after'])) {
            $options['after'] = $text . $options['after'];
        } else {
            $options['after'] = $text;
        }
        unset($options['example']);
        return $options;
    }
}
```

### 4.3 AppBoostCakeFormHelper → 廃止

`View/Helper/AppBoostCakeFormHelper.php` の `secure()` メソッドは CakePHP 5 の `FormProtectionMiddleware` に完全に置換されるため、**この Helper は削除する**。

| 現行の機能 | CakePHP 5 での代替 |
|---|---|
| `secure()` — フォームトークン生成 | `FormProtectionMiddleware`（自動的にフォーム保護を適用） |
| `FormToken::hash()` — HMAC ハッシュ | `FormProtectionMiddleware` の内部処理 |
| `_Token.fields` / `_Token.unlocked` hidden フィールド | `FormProtectionMiddleware` が自動生成 |

根拠: `View/Helper/AppBoostCakeFormHelper.php:20-91`, `Docs/cakephp5-migration-spec.md:121-122`, `Docs/design/README.md:95-97`

---

## 5. AppView.php の移行

### 5.1 現行 AppView のメソッド一覧

`View/AppView.php` に定義されている全メソッドの CakePHP 5 での移行先。

| # | メソッド | 行 | 現行の動作 | CakePHP 5 での対応 |
|---|---|---|---|---|
| 1 | `readSession($key)` | :23-26 | `$this->Session->read($key)` | **廃止**。テンプレートから直接 `$this->request->getSession()->read($key)` を呼ぶ |
| 2 | `deleteSession($key)` | :32-35 | `$this->Session->delete($key)` | **廃止**。Controller 側で処理 |
| 3 | `hasSession($key)` | :42-45 | `$this->Session->check($key)` | **廃止**。`$this->request->getSession()->check($key)` に置換 |
| 4 | `writeSession($key, $value)` | :52-55 | `$this->Session->write($key, $value)` | **廃止**。Controller 側で処理 |
| 5 | `readAuthUser($key)` | :62-73 | `$this->Session->read('Auth.User')` | **廃止**。`$this->request->getAttribute('identity')` に置換 |
| 6 | `isLogined()` | :80-85 | ログイン状態の確認 | **廃止**。`$this->request->getAttribute('identity') !== null` に置換 |
| 7 | `isAdminPage()` | :91-93 | `$this->request->params['admin']` | **廃止**。`$this->request->getParam('admin')` に置換 |
| 8 | `isEditPage()` | :99-102 | `$this->action == 'edit' \|\| 'admin_edit'` | `AppViewHelper` メソッドに移行 |
| 9 | `isRecordPage()` | :107-109 | `$this->action == 'record' \|\| 'admin_record'` | `AppViewHelper` メソッドに移行 |
| 10 | `isLoginPage()` | :115-118 | `$this->action == 'login' \|\| 'admin_login'` | `AppViewHelper` メソッドに移行 |
| 11 | `isHTTPS()` | :123-134 | `$_SERVER['HTTPS']` 等の確認 | `AppViewHelper` メソッドに移行 |
| 12 | `isLocalIP()` | :139-147 | `$_SERVER['REMOTE_ADDR']` の確認 | `AppViewHelper` メソッドに移行 |

### 5.2 移行方針

CakePHP 5 の `AppView` は `View` を継承するが、テンプレートから呼ばれるメソッドは `Helper` に移行するのが推奨パターンである。

#### AppView の残存（`src/View/AppView.php`）

```php
<?php
declare(strict_types=1);

namespace App\View;

use Cake\View\View;

class AppView extends View
{
    public function initialize(): void
    {
        parent::initialize();
        // ヘルパーの登録は AppController または AppView で行う
    }
}
```

#### AppViewHelper（`src/View/Helper/AppViewHelper.php`）新規作成

```php
<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;
use Cake\View\View;

class AppViewHelper extends Helper
{
    protected array $helpers = ['Html'];

    /** isEditPage: action が edit 或いは admin_edit か */
    public function isEditPage(): bool
    {
        $action = $this->_View->get('action') ?? '';
        return ($action === 'edit' || $action === 'admin_edit');
    }

    /** isRecordPage: action が record 或いは admin_record か */
    public function isRecordPage(): bool
    {
        $action = $this->_View->get('action') ?? '';
        return ($action === 'record' || $action === 'admin_record');
    }

    /** isLoginPage: action が login 或いは admin_login か */
    public function isLoginPage(): bool
    {
        $action = $this->_View->get('action') ?? '';
        return ($action === 'login' || $action === 'admin_login');
    }

    /** isHTTPS: HTTPS 接続か */
    public function isHTTPS(): bool
    {
        return (
            !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off'
        ) || (
            !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
        ) || (
            !empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off'
        ) || (
            !empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443
        );
    }

    /** isLocalIP: 接続元がローカルIP か */
    public function isLocalIP(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '::1') {
            return true;
        }
        return (bool) preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip);
    }
}
```

#### テンプレートからの呼び出し変更

| 現行 | CakePHP 5 |
|---|---|
| `$this->isEditPage()` | `$this->AppView->isEditPage()` |
| `$this->isRecordPage()` | `$this->AppView->isRecordPage()` |
| `$this->isLoginPage()` | `$this->AppView->isLoginPage()` |
| `$this->isHTTPS()` | `$this->AppView->isHTTPS()` |
| `$this->isLocalIP()` | `$this->AppView->isLocalIP()` |
| `$this->readSession('key')` | `$this->request->getSession()->read('key')` |
| `$this->isAdminPage()` | `$this->request->getParam('admin')` |

#### Helper の登録（`AppController.php` または `AppView::initialize()`）

```php
// src/Controller/AppController.php の initialize() で
$this->loadHelper('AppView');
```

根拠: `View/AppView.php:1-148`

---

## 6. テンプレート別変更点サマリ

全 51 テンプレートについて、CakePHP 2 固有記法の使用箇所と CakePHP 5 での対応を列挙する。

### 6.1 共通変更（全テンプレートに適用）

| # | 変更内容 | 変更前 | 変更後 | 該当ファイル数 |
|---|---|---|---|---|
| C1 | ファイル拡張子 | `.ctp` | `.php` | 51 |
| C2 | ディレクトリ | `View/` | `templates/` | 51 |
| C3 | `$this->Session->flash()` | SessionHelper | `$this->Flash->render()` | 2（default.ctp, error.ctp） |
| C4 | `$this->Form->input()` | FormHelper::input() | `$this->Form->control()` | ~50（ほぼ全テンプレート） |
| C5 | `Router::url()` | RouterHelper | `$this->Url->build()` | ~30 |
| C6 | `$this->readSession()` | AppView メソッド | `$this->request->getSession()->read()` | 5（default.ctp 等） |
| C7 | `$this->isAdminPage()` | AppView メソッド | `$this->AppView->isAdminPage()` | 2（default.ctp, Contents/index.ctp） |
| C8 | `$this->isEditPage()` | AppView メソッド | `$this->AppView->isEditPage()` | 6（admin_edit 系） |
| C9 | `$this->isLoginPage()` | AppView メソッド | `$this->AppView->isLoginPage()` | 1（default.ctp） |
| C10 | `$this->isRecordPage()` | AppView メソッド | `$this->AppView->isRecordPage()` | 1（Contents/index.ctp） |
| C11 | `$this->isHTTPS()` | AppView メソッド | `$this->AppView->isHTTPS()` | 1（Users/login.ctp） |
| C12 | `__d('cake', ...)` | CakePHP 2 ドメイン翻訳 | `__()` に変更 | 2（error400.ctp, error500.ctp） |

### 6.2 ファイル別詳細変更

#### `templates/layout/default.php`（`View/Layouts/default.ctp`）

| 行 | 変更前 | 変更後 |
|---|---|---|
| :16 | `$this->readSession('Setting.title')` | `$this->request->getSession()->read('Setting.title')` |
| :20 | `$this->isAdminPage()` | `$this->AppView->isAdminPage()` |
| :20 | `$this->isLoginPage()` | `$this->AppView->isLoginPage()` |
| :41 | `jquery-1.9.1.min.js` | `jquery-3.6.0.min.js`（推奨） |
| :43 | `bootstrap.min.js` | `bootstrap.bundle.min.js`（推奨） |
| :66 | `$this->readSession('Setting.color')` | `$this->request->getSession()->read('Setting.color')` |
| :80 | `$this->Html->url('/')` | `$this->Url->build('/')` |
| :101 | `$this->Session->flash()` | `$this->Flash->render()` |
| :107 | `$this->readSession('Setting.copyright')` | `$this->request->getSession()->read('Setting.copyright')` |
| :110 | `$this->isAdminPage()` | `$this->AppView->isAdminPage()` |

#### `templates/Contents/index.php`（`View/Contents/index.ctp`）

| 行 | 変更前 | 変更後 |
|---|---|---|
| :3 | `$this->isAdminPage()` | `$this->AppView->isAdminPage()` |
| :3 | `$this->isRecordPage()` | `$this->AppView->isRecordPage()` |
| :79-160 | `switch($content['Content']['kind'])` — ビジネスロジック | → Helper メソッドに分離（§7 参照） |
| :94 | `$content[0]['is_passed']` | エンティティアクセサに変更（推測） |
| :179 | `Utils::getYMD()` | `Utils::getYMD()` — そのまま（Custom に移行） |
| :181 | `Utils::getHNSBySec()` | `Utils::getHNSBySec()` — そのまま |

#### `templates/Contents/view.php`（`View/Contents/view.ctp`）

| 行 | 変更前 | 変更後 |
|---|---|---|
| :33 | `Router::url(...)` | `$this->Url->build(...)` |
| :34 | `Router::url(...)` | `$this->Url->build(...)` |
| :35-36 | `Configure::read(...)` | 変更なし |
| :41-65 | `switch($content['Content']['kind'])` — ビジネスロジック | → Helper メソッドに分離（§7 参照） |
| :49 | `Router::url(...)` | `$this->Url->build(...)` |
| :51 | `Router::url(...)` | `$this->Url->build(...)` |
| :58 | `$this->Text->autoLinkUrls()` | `$this->Text->autoLinkUrls()` — 変更なし |

#### `templates/ContentsQuestions/index.php`（`View/ContentsQuestions/index.ctp`）

| 行 | 変更前 | 変更後 |
|---|---|---|
| :19 | `$content['Content']['timelimit']` | エンティティ構文に変更（推測） |
| :89 | `$this->Form->create('ContentsQuestion')` | `$this->Form->create('ContentsQuestion')` — 変更なし |
| :124-135 | `sprintf('<input type="checkbox"...')` — 手動 HTML 生成 | CakePHP 5 の FormHelper で生成（推奨） |
| :178-189 | `switch($wrong_mode)` — ビジネスロジック | → Helper メソッドに分離（§7 参照） |
| :229 | `Router::url($course_url)` | `$this->Url->build($course_url)` |
| :236-249 | `function getExplain()` — 関数定義 | → Helper メソッドに分離（§7 参照） |

#### `templates/Admin/Contents/admin_edit.php`（`View/Contents/admin_edit.ctp`）

| 行 | 変更前 | 変更後 |
|---|---|---|
| :29 | `Router::url(...)` | `$this->Url->build(...)` |
| :107 | `Router::url(...)` | `$this->Url->build(...)` |
| :112 | `Router::url(...)` | `$this->Url->build(...)` |
| :124 | `Router::url(...)` | `$this->Url->build(...)` |
| :167 | `$this->Form->input(...)` | `$this->Form->control(...)` |
| :170 | `$this->Form->inputRadio(...)` | `$this->AppView->inputRadio(...)` |
| :189 | `$this->Form->inputExp(...)` | `$this->AppView->inputExp(...)` |

#### `templates/Admin/Contents/admin_index.php`（`View/Contents/admin_index.ctp`）

| 行 | 変更前 | 変更後 |
|---|---|---|
| :29 | `Router::url(...)` | `$this->Url->build(...)` |
| :61 | `Router::url(...)` | `$this->Url->build(...)` |
| :78-89 | `switch($content['Content']['kind'])` | → Helper メソッドに分離 |
| :98 | `Router::url(...)` | `$this->Url->build(...)` |

#### `templates/Admin/Users/admin_index.php`（`View/Users/admin_index.ctp`）

| 行 | 変更前 | 変更後 |
|---|---|---|
| :17 | `Router::url(...)` | `$this->Url->build(...)` |
| :18 | `Router::url(...)` | `$this->Url->build(...)` |
| :24 | `$this->Form->searchField(...)` | `$this->App->searchField(...)` |
| :65 | `Router::url(...)` | `$this->Url->build(...)` |

#### `templates/Admin/Users/admin_edit.php`（`View/Users/admin_edit.ctp`）

| 行 | 変更前 | 変更後 |
|---|---|---|
| :4 | `$this->Html->scriptStart(...)` | `$this->Html->scriptStart(...)` — 変更なし |
| :16 | `$this->isEditPage()` | `$this->AppView->isEditPage()` |
| :20 | `$this->Form->input(...)` | `$this->Form->control(...)` |
| :32 | `$this->Form->inputRadio(...)` | `$this->AppView->inputRadio(...)` |
| :47 | `$this->request->data['User']['id']` | `$this->request->getData('User.id')` |

#### `templates/Users/login.php`（`View/Users/login.ctp`）

| 行 | 変更前 | 変更後 |
|---|---|---|
| :8 | `Router::url(...)` | `$this->Url->build(...)` |
| :14 | `$this->Form->input(...)` | `$this->Form->control(...)` |
| :18 | `$this->isHTTPS()` | `$this->AppView->isHTTPS()` |

#### `templates/Admin/Users/admin_login.php`（`View/Users/admin_login.ctp`）

| 行 | 変更前 | 変更後 |
|---|---|---|
| :7 | `Router::url(...)` | `$this->Url->build(...)` |
| :12 | `$this->Form->input(...)` | `$this->Form->control(...)` |

#### `templates/element/admin_menu.php`（`View/Elements/admin_menu.ctp`）

| 行 | 変更前 | 変更後 |
|---|---|---|
| :6 | `$this->params["action"]` | `$this->request->getParam('action')` |
| :6 | `$this->name` | `$this->request->getParam('controller')` |

#### `templates/Admin/Records/admin_index.php`（`View/Records/admin_index.ctp`）

| 行 | 変更前 | 変更後 |
|---|---|---|
| :7 | `Router::url(...)` | `$this->Url->build(...)` |
| :16 | `Router::url(...)` | `$this->Url->build(...)` |
| :25 | `Router::url(...)` | `$this->Url->build(...)` |
| :40 | `Router::url(...)` | `$this->Url->build(...)` |
| :60-74 | `$this->Form->searchField(...)` / `$this->Form->searchDate(...)` | `$this->App->searchField(...)` / `$this->App->searchDate(...)` |

#### その他の admin テンプレート

| テンプレート | 主な変更 |
|---|---|
| `Admin/Courses/admin_edit.php` | `$this->Form->input()` → `control()`、`$this->isEditPage()` |
| `Admin/Courses/admin_index.php` | `Router::url()` → `$this->Url->build()` |
| `Admin/Groups/admin_edit.php` | `$this->Form->input()` → `control()`、`$this->isEditPage()` |
| `Admin/Groups/admin_index.php` | `Router::url()` → `$this->Url->build()` |
| `Admin/Groups/admin_edit.php` | `$this->Form->input()` → `control()` |
| `Admin/Infos/admin_edit.php` | `$this->Form->input()` → `control()`、`$this->isEditPage()` |
| `Admin/Infos/admin_index.php` | `Router::url()` → `$this->Url->build()` |
| `Admin/ContentsQuestions/admin_edit.php` | `$this->Form->input()` → `control()`、`$this->isEditPage()` |
| `Admin/ContentsQuestions/admin_index.php` | `Router::url()` → `$this->Url->build()` |
| `Admin/EnquetesQuestions/admin_edit.php` | `$this->Form->input()` → `control()`、`$this->isEditPage()` |
| `Admin/EnquetesQuestions/admin_index.php` | `Router::url()` → `$this->Url->build()` |
| `Admin/Settings/admin_index.php` | `$this->Form->input()` → `control()` |
| `Admin/Install/*.php` | `Router::url()` → `$this->Url->build()` |
| `Admin/Update/*.php` | `Router::url()` → `$this->Url->build()` |
| `UsersCourses/index.php` | `Router::url()` → `$this->Url->build()`、`Configure::read()` 変更なし |
| `Infos/index.php` | `Router::url()` → `$this->Url->build()` |
| `Infos/view.php` | `Router::url()` → `$this->Url->build()` |
| `error/error400.php` | `__d('cake', ...)` → `__()` |
| `error/error500.php` | `__d('cake', ...)` → `__()` |
| `element/paging.php` | 変更なし（PaginatorHelper は互換） |

---

## 7. View 内ビジネスロジック — Controller/Helper への分離方針

テンプレート内に存在する `switch` 分岐や関数定義を、Controller の Helper メソッドや View ブロックに分離する。

根拠: `Docs/design/README.md:115-117`（View 内ロジックの分離方針）

### 7.1 ContentsQuestions/index.ctp の switch 分岐

**箇所**: `View/ContentsQuestions/index.ctp:178-189`

```php
// 現行の switch 分岐
switch($wrong_mode)
{
    case 0: // 正解と解説を表示しない
        break;
    case 1: // 正解と解説を表示する
        $correct_tag = ...;
        $explain_tag = getExplain($question['explain']);
        break;
    case 2: // 解説のみ表示する
        $explain_tag = getExplain($question['explain']);
        break;
}
```

**分離先**: `src/View/Helper/ContentsQuestionHelper.php`

```php
public function renderWrongMode(int $wrongMode, string $correctLabel, string $explain): array
{
    $correctTag = '';
    $explainTag = '';

    switch ($wrongMode) {
        case 0: // 正解と解説を表示しない
            break;
        case 1: // 正解と解説を表示する
            $correctTag = sprintf(
                '<p class="correct-text bg-success">%s : %s</p>',
                __('正解'),
                $correctLabel
            );
            $explainTag = $this->renderExplain($explain);
            break;
        case 2: // 解説のみ表示する
            $explainTag = $this->renderExplain($explain);
            break;
    }

    return ['correct_tag' => $correctTag, 'explain_tag' => $explainTag];
}
```

### 7.2 ContentsQuestions/index.ctp の getExplain() 関数

**箇所**: `View/ContentsQuestions/index.ctp:236-249`

```php
// 現行の関数定義（テンプレート内）
function getExplain($explain)
{
    $tag = '';
    $check = str_replace(['<p>','</p>','<br>'], '', $explain);
    if($check != '')
    {
        $tag = sprintf('<div class="correct-text bg-danger">%s : %s</div>', __('解説'), $explain);
    }
    return $tag;
}
```

**分離先**: `src/View/Helper/ContentsQuestionHelper.php`

```php
public function renderExplain(string $explain): string
{
    $check = str_replace(['<p>', '</p>', '<br>'], '', $explain);
    if ($check !== '') {
        return sprintf(
            '<div class="correct-text bg-danger">%s : %s</div>',
            __('解説'),
            $explain
        );
    }
    return '';
}
```

### 7.3 Contents/index.ctp の switch 分岐

**箇所**: `View/Contents/index.ctp:79-160`

```php
// 現行の switch 分岐（コンテンツ種別による分岐）
switch($content['Content']['kind'])
{
    case 'test':     // テスト
    case 'enquete':  // アンケート
    case 'file':     // 配布資料
    default:         // 学習
}
```

**分離先**: `src/View/Helper/ContentHelper.php`

```php
public function renderContentLink(array $content, bool $isAdminRecord): array
{
    $icon = '';
    $titleLink = '';
    $kind = Configure::read('content_kind.' . $content['Content']['kind']);
    $understanding = '';

    switch ($content['Content']['kind']) {
        case 'test':
            // テスト用リンク生成ロジック
            break;
        case 'enquete':
            // アンケート用リンク生成ロジック
            break;
        case 'file':
            // 配布資料用リンク生成ロジック
            break;
        default:
            // 学習用リンク生成ロジック
            break;
    }

    return compact('icon', 'titleLink', 'kind', 'understanding');
}
```

### 7.4 Contents/view.ctp の switch 分岐

**箇所**: `View/Contents/view.ctp:41-65`

```php
// 現行の switch 分岐（コンテンツ表示種別）
switch($content['Content']['kind'])
{
    case 'url':   // URLコンテンツ
    case 'movie': // 動画コンテンツ
    case 'text':  // テキスト型コンテンツ
    case 'html':  // リッチテキストコンテンツ
}
```

**分離先**: `src/View/Helper/ContentHelper.php`

```php
public function renderContentBody(array $content): string
{
    switch ($content['Content']['kind']) {
        case 'url':
            return '<iframe ... src="' . h($content['Content']['url']) . '"></iframe>';
        case 'movie':
            $url = h($content['Content']['url']);
            if (strpos($url, 'http') === false) {
                $url = $this->Url->build([...]);
            }
            return '<video src="' . $url . '" controls ...></video>';
        case 'text':
            $body = h($content['Content']['body']);
            $body = $this->Text->autoLinkUrls($body);
            return nl2br($body);
        case 'html':
            return $content['Content']['body'];
        default:
            return '';
    }
}
```

### 7.5 EnquetesQuestions/index.ctp の switch 分岐

**箇所**: `View/EnquetesQuestions/index.ctp:87-110`

```php
// 現行の switch 分岐（回答形式）
switch($question_type)
{
    case 'text':   // テキスト入力
    case 'single': // 単一選択
}
```

**分離先**: `src/View/Helper/EnqueteHelper.php`

```php
public function renderQuestionOption(string $questionType, array $question, array $answerList, bool $isRecord): string
{
    switch ($questionType) {
        case 'text':
            // テキスト入力フィールド生成
            break;
        case 'single':
            // ラジオボタン生成
            break;
    }
    return $optionTag;
}
```

---

## 8. ヘルパー登録設計

### 8.1 AppController でのヘルパー登録

```php
// src/Controller/AppController.php
public function initialize(): void
{
    parent::initialize();
    $this->loadHelper('Flash');
    $this->loadHelper('Html', ['className' => 'BootstrapUi.Html']);
    $this->loadHelper('Form', ['className' => 'BootstrapUi.Form']);
    $this->loadHelper('Paginator', ['className' => 'BootstrapUi.Paginator']);
    $this->loadHelper('App');
    $this->loadHelper('AppView');
    $this->loadHelper('Content');
    $this->loadHelper('ContentsQuestion');
    $this->loadHelper('Enquete');
}
```

根拠: `Docs/design/README.md:106-117`

### 8.2 ヘルパー依存関係

| Helper | 依存する Helper | 用途 |
|---|---|---|
| `AppHelper` | `Form`, `Html` | inputExp, inputRadio, searchField, inputDate, searchDate |
| `AppViewHelper` | `Html` | isEditPage, isRecordPage, isLoginPage, isHTTPS, isLocalIP |
| `AppFormHelper` | `Form` (bootstrap-ui), `Html` | help, helpText, helpList, example カスタムタグ |
| `ContentHelper` | `Html`, `Text`, `Url` | コンテンツ種別によるリンク・表示生成 |
| `ContentsQuestionHelper` | `Html` | テスト結果表示（wrong_mode 分岐、getExplain） |
| `EnqueteHelper` | `Form`, `Html` | 回答形式による選択肢表示 |

---

## 9. 移行時の注意事項

### 9.1 `$this->request->data` → `$this->request->getData()`

テンプレート内の `$this->request->data['User']['id']` は `$this->request->getData('User.id')` に変更する。

該当箇所: `View/Users/admin_edit.ctp:47,49`

### 9.2 jQuery のバージョン

`jquery-1.9.1.min.js` は CakePHP 5 の bootstrap-ui と互換性があるか要確認。推奨は jQuery 3.x。

### 9.3 `$scripts_for_layout`

`View/Layouts/js/default.ctp:1` の `$scripts_for_layout` は CakePHP 5 で廃止。`$this->fetch('script')` に変更する。

### 9.4 `$this->Html->scriptStart()` / `scriptEnd()`

`View/Users/admin_edit.ctp:4-11` 等で使用。CakePHP 5 では `$this->Html->scriptStart()` / `$this->Html->scriptEnd()` は非推奨。`$this->start('script')` / `$this->end()` に変更する。

### 9.5 `String::insert()` の廃止

`View/Helper/AppFormHelper.php:80,122` で使用。CakePHP 5 では `Text::insert()` に変更、さらに非推奨のため直接文字列結合に変更する。

### 9.6 `document.all()` の廃止

`View/ContentsQuestions/admin_edit.ctp:14-96`, `View/EnquetesQuestions/admin_edit.ctp:14-95` で `document.all()` を使用。これは非推奨のため `document.getElementById()` に変更する。

### 9.7 ファイルアップロードの `data[Model][field]` 形式

`View/Contents/admin_upload.ctp:96` の `name="data[Content][file]"` は CakePHP 5 の FormHelper との互換性を確認する。
