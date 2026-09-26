# W3 修正記録（Fix Record）

> 対象: `W3-execution-record.md` の D-23〜D-26、および本ウェーブ実施中に新規発見した D-34 / D-35
> 実施日: 2026-09-26
> 前提: すべて的原因解析は `W3-cause-analysis.md` に記録済み。本書は修正内容・検証結果のみ。

---

## 1. 修正サマリ

| ID | 事象 | 分類 | 重要度 | 状態 |
|---|---|---|---|---|
| D-23 | `InstallController` の DB 名検出が常に既定値へフォールバック | 設計バグ（データフロー競合） | S2 / P0 | ✅ 修正済 |
| D-24 | `_executeSQLScript()` が SQLSTATE `23000` を無視しない | 実装バグ（複製漏れ） | S3 | ✅ 修正済 |
| D-25 | `App.fullBaseUrl` 未設定時に debug=false 環境では全リクエストが 500 | 実装バグ | S4 / P0 | ✅ 修正済 |
| D-26 | D-23 により install バリデーションの動的検証が不可 | 二次的欠陥 | S4 | ✅ 解消（D-23 修正により到達可能） |
| **D-34** | `/install` のフォームに CSRF トークンが無く POST が 403 | **新規発見 P0** | **S2 / P0** | ✅ 修正済 |
| **D-35** | install のフォーム送信値が常に空でバリデーションが必ず失敗 | **新規発見 P0** | **S2 / P0** | ✅ 修正済 |

**D-34 / D-35 は、D-23 の修正で「インストール済み」判定の誤りが外れたことで初めて到達可能になった instaler の実経路に、更なる独立原因が2件あることが判明した。** 修正前は D-23 が instaler 全体を無効化していたためこれらも顕在化していなかった。3件すべてがインストールを完全不能にする複合原因である。

---

## 2. 修正内容

### D-23: DB 名取得を実際の接続設定から行う

**ファイル**: `src/Controller/InstallController.php:112-120`

`config/bootstrap.php:187` の `Configure::consume('Datasources')` は値を読み的同时に Configure ストアから除去するため、元の `Configure::read('Datasources')` は常に `null` を返していた。登録済みの接続設定から直接取得する。

```php
// D-23: Configure::consume('Datasources') により bootstrap 時に除去済みのため、
// 実際に登録済みの接続設定から DB 名を取得する。
// getConfig() が例外を投げた場合はフォールバック値 'irohaboard' を使用。
try {
    $dbConfig = ConnectionManager::getConfig('default');
    $database = $dbConfig['database'] ?? 'irohaboard';
} catch (\Exception $e) {
    $database = 'irohaboard';
}
$sql = "SHOW TABLES FROM `" . $database . "` LIKE 'ib_users'";
```

### D-24: SQLSTATE `23000` の握り潰し

**ファイル**: `src/Controller/InstallController.php:290-296`

`42S21`（カラム重複）/ `42S01`（テーブル重複）に加え `23000`（UNIQUE/PRIMARY 制約違反）を無視。`UpdateController:191` と挙動を統一し、インストール中断からの再実行（冪等性）を回復。

```php
// D-24: スキーマ・初期データ投入の再実行のため、
// UNIQUE/PRIMARY 制約違反（例: ib_settings の固定 PK 1〜4）を無視する。
// UpdateController:191 と挙動を統一。
// ※ ユーザデータ側の本物の重複違反は _createRootAccount() 経由で発生するため、
//    このスクリプト実行コンテキストでは握り潰しても安全。
if (($errorInfo[0] ?? '') === '23000') {
    continue;
}
```

### D-25: `App.fullBaseUrl` 未設定時の fail-closed → fail-open + 警告ログ

**方針決定**: @oracle によるセキュリティ評価の結果、`bootstrap.php` で HTTP_HOST 由来の値を `Configure::write('App.fullBaseUrl', ...)` する案は**却下**。攻撃者が `Host: evil.com` を送ると基準側にも `evil.com` が入り、自己一致して Host Header Injection 防御が完全無効化されるため。代わりにミドルウェア側を fail-open 化。

**ファイル**: `src/Middleware/HostHeaderMiddleware.php:38-47`

```php
$fullBaseUrl = Configure::read('App.fullBaseUrl');
if (!$fullBaseUrl) {
    Log::warning(
        'SECURITY: App.fullBaseUrl is not configured. '
        . 'Host header validation is disabled. '
        . 'Set APP_FULL_BASE_URL environment variable to enable Host Header Injection protection.'
    );

    return $handler->handle($request);
}
```

**セキュリティへの影響**: `APP_FULL_BASE_URL` が設定済みの環境では Host 不一致時の 400 動作は完全に不変。debug=true の早期 return と大文字小文字無視の比較も不変。

**周辺の実装**:
- `docker/docker-compose.yml:21` に `APP_FULL_BASE_URL=http://localhost:8081`（ports `8081:80`）
- `docker/docker-compose.cakephp5.yml:23` に `APP_FULL_BASE_URL=http://localhost:8082`（ports `8082:80`）
- `README.md:33-40` に「本番デプロイ」節を追記（必須 env・未設定時の影響）
- `config/.env.example:17` は既存記述あり（変更不要）

### D-34（新規発見）: install フォームへの CSRF トークン埋め込み

**ファイル**: `templates/Install/index.php:13-22`

`/install` は `CsrfProtectionMiddleware` の除外パスに含まれない。FormHelper を使わない生 HTML フォーム（`<form method="post">`）に `_csrfToken` の hidden input が無く、トークンなし POST は 403 で拒否されていた。

```php
<form method="post" class="form-horizontal">
    <?php
    /*
     * D-34: /install は CsrfProtectionMiddleware の対象外ではないため、
     * CSRF トークンを送信しないと POST が 403 で拒否され、
     * インストーラーが使用不能になる。 生HTMLのフォームなので
     * （FormHelper を使わないので）トークンを明示的に埋め込む。
     */
    $csrfToken = (string)$this->getRequest()->getAttribute('csrfToken');
    ?>
    <input type="hidden" name="_csrfToken" value="<?= h($csrfToken) ?>">
```

### D-35（新規発見）: フォーム送信値の読み出しパスの誤り

**ファイル**: `src/Controller/InstallController.php:130-136`

テンプレートの input name は `data[User][username]` だが、CakePHP 5.4.2 の `ServerRequest::getData()` はトップレベルキー `data` を起点に解釈する。`getData('User.username')` は常に `NULL` を返し、username/password/password2 は常に空文字になっていた。**結果として、有效な入力でもバリデーションが必ず失敗し、インストールは永久に完了しない状態だった。**

```php
// D-35: テンプレートの入力 name は data[User][...] だが、
// CakePHP 5 の ServerRequest::getData() はトップレベルの
// キー（'data'）を起点に解釈するため、'User.username' では
// 一致せず常に既定値 '' になっていた（インストール不能）。
$userData = (array)$this->request->getData('data.User', []);
$username = (string)($userData['username'] ?? '');
$password = (string)($userData['password'] ?? '');
$password2 = (string)($userData['password2'] ?? '');
```

### D-26: 二次的欠陥の解消

D-23 の修正により `SHOW TABLES` が正しいデータベースに対して実行され、空 DB では `ib_users` が見つからないため POST ハンドラ（バリデーション）に到達可能となった。上記のスクラッチDB実証により、D-26 の「動的検証が不可能」も解消。

---

## 3. 追加したテスト

### `tests/TestCase/Controller/InstallControllerTest.php`（新規, 381行, 10件 / 27 assertions）

| テスト | 対象 |
|---|---|
| `testDatabaseNameFromConnectionManager` | D-23: `ConnectionManager` から正しい DB 名を取得 |
| `testDatabaseNameEvenWhenConfigureDatasourcesIsNull` | D-23: `Configure` が null でもフォールバックが機能 |
| `testGetInstallDoesNotFailOnDbName` | D-23: DB 名取得で例外が出ない |
| `testExecuteSQLScriptSkipsDuplicateErrorsOnSecondRun` | D-24: 2 回目の実行で重複エラーが握り潰される |
| `testExecuteSQLScriptSkipsPrimaryKeyDuplicate` | D-24: PRIMARY 制約違反（`23000`）の握り潰し |
| `testExecuteSQLScriptStillCapturesNonIgnoredErrors` | D-24: 対象外のエラーは握り潰されない（過剰抑制の防止） |
| `testInstallFormEmbedsCsrfToken` | D-34: テンプレートに `_csrfToken` が埋め込まれている |
| `testFormDataIsReadFromDataUserKey` | D-35: `data.User` キーでusername/password/password2 が取れる |
| `testIndexDoesNotReadUnprefixedUserDataKeys` | D-35: `getData('User.` の残存をソース検査で禁止 |
| `testGetInstallReturnsProperResponse` | GET /install が正常にレスポンスを返す |

### `tests/TestCase/Middleware/HostHeaderMiddlewareTest.php`（新規, 157行, 5件）

| テスト | 内容 |
|---|---|
| `testMatchingHostPassesThrough` | 設定済みホストと一致 → 通過 |
| `testMismatchedHostReturns` | **Host Header Injection 防御が機能すること**（不一致 → 400） |
| `testUnconfiguredFullBaseUrlPassesThrough` | **D-25 の核心**（未設定でも 500 にならない） |
| `testDebugModeBypassesValidation` | debug=true の既存の早期 return が不変 |
| `testCaseInsensitiveHostMatchPasses` | 大文字小文字を無視した比較が維持 |

---

## 4. 検証結果

### 4.1 全テストスイート

```
bash scripts/test-fresh.sh
→ Tests: 690, Assertions: 3356, Errors: 0, Failures: 0,
         Deprecations: 2, PHPUnit Notices: 8
         Time: 03:51, Memory: 76.50 MB
```

| 段階 | tests | assertions | Errors | Failures |
|---|---|---|---|---|
| W1 修正後 | 645 | 3173 | 0 | 0 |
| W2 修正後 | 675 | 3324 | 0 | 0 |
| W3（D-35 修正前） | 687 | 3347 | 0 | 0 |
| **W3 修正後** | **690** | **3356** | **0** | **0** |

### 4.2 D-25 の証跡（`APP_FULL_BASE_URL` が実際に到達することを実証）

コンテナ内で `config/bootstrap.php` を require するプローブにより確認（プローブは検証後に削除済み）。

| 環境変数 | `Configure::read('App.fullBaseUrl')` | ミドルウェアの挙動 |
|---|---|---|
| 未設定 | `false` | 警告ログ + パススルー |
| `http://localhost:8082` | `'http://localhost:8082'` | ホスト検証（不一致時 400） |
| `http://evil.com` | `'http://evil.com'` | 検証動作（基準値が evil になるため無意味） |

**注意点（記録に残す）**: `config/app_local.php` に `'fullBaseUrl' => ...` を追記しても `App` 名前空間には入らず（トップレベルの `fullBaseUrl` に入るだけ）、`App.fullBaseUrl` は設定できない。**`APP_FULL_BASE_URL` 環境変数が唯一の有効経路**。

### 4.3 D-23 / D-34 / D-35 の統合実証（スクラッチDBでのフルインストール）

以下の手順で、修正前は**永久に完了しない**インストールが実際に成功することを確認した。

1. `config/app_local.php` をバックアップ
2. `CREATE DATABASE irohaboard_scratch` を実行
3. `app_local.php` の `default.database` を `irohaboard_scratch` に差替
4. ホスト／コンテナ両方で `tmp/cache/{persistent,models,views}/*` を削除
5. `GET /install` でフォームと `_csrfToken` を取得
6. `curl --data-urlencode "_csrfToken=$TOKEN" -d "_method=POST" -d "data[User][username]=installadmin" -d "data[User][password]=InstallPass123" -d "data[User][password2]=InstallPass123" -d "data[User][regist_no]=..."` で POST

**結果**:

- HTTP **200**
- `irohaboard_scratch` に **16 テーブル**が作成された
- `ib_users` に `id=1 / username=installadmin / role=admin` が作成された
- 修正前は 403（トークン無し）→ 200 でバリデーション失敗（値が空）→ **インストール完了不可**という状態だった

**検証ハーネスの注意点**: 素の `curl -d "_csrfToken=$TOKEN"` では、CSRF トークンが base64 で含む `+` / `/` / `=` が URL エンコードされず、form-urlencoded 復号時に欠落して 403 になる。`--data-urlencode` が必要。アプリ側の不具合ではない。

### 4.4 環境の復旧確認

検証後、`config/app_local.php` を pristine なバックアップ（`'database' => env('DB_NAME', 'irohaboard')`）から復元し、スクラッチDBを DROP、キャッシュを削除。

| 確認 | 結果 |
|---|---|
| `GET /` | **302**（ログインへリダイレクト＝正常） |
| `GET /users/login` | **200** |
| `GET /api/v1/contents`（invalid token） | **401** |

`config/app_local.php` は `.gitignore` 対象のため追跡外。

---

## 5. 変更ファイル一覧

### 製品コード

| ファイル | 変更 |
|---|---|
| `src/Controller/InstallController.php` | D-23（DB 名取得）、D-24（`23000` 握り潰し）、D-35（`data.User` 読み出し） |
| `src/Middleware/HostHeaderMiddleware.php` | D-25（`InternalErrorException` → `Log::warning` + パススルー、`InternalErrorException` を `Cake\Log\Log` に置換） |
| `templates/Install/index.php` | D-34（`_csrfToken` hidden input 追加） |
| `docker/docker-compose.yml` | D-25（`APP_FULL_BASE_URL=http://localhost:8081`） |
| `docker/docker-compose.cakephp5.yml` | D-25（`APP_FULL_BASE_URL=http://localhost:8082`） |
| `README.md` | D-25（「本番デプロイ」節） |

### テスト（新規）

| ファイル | 件数 |
|---|---|
| `tests/TestCase/Controller/InstallControllerTest.php` | 10 |
| `tests/TestCase/Middleware/HostHeaderMiddlewareTest.php` | 5 |

差分合計: 製品6ファイル +53/−10、テスト2ファイル 538行（新規）。

---

## 6. 残課題・留意点

1. **D-25 の fail-open は意図的なトレードオフ**。`APP_FULL_BASE_URL` 未設定時は Host 検証が無効化される。`README.md` と `docker-compose` に必須性を明記したが、本番デプロイ時に未設定のまま運用するリスクは残る。fail-closed（現状の 500）への回帰を選択肢として再検討する場合は、`APP_FULL_BASE_URL` 必須を起動時チェックにする案が考えられる。
2. **`HostHeaderMiddleware` に `$this->getRequest()` のような状態依存はない**が、`Router::fullBaseUrl()`（bootstrap が設定）と `Configure::read('App.fullBaseUrl')`（bootstrap が設定しない）の不整合は残存している。D-25 の fail-open により実害はなくなったが、設計上の不整合は残る。
3. **未修正の不備**: D-13〜D-22（W5）、D-27〜D-30（W4）、D-31〜D-33（W6）が未修正。W4 分の修正が次の候補。
4. **Deprecations 2 件 / PHPUnit Notices 8 件**は残存（`Query::order` deprecated、テスト側の `define` 警告の一部等）。
5. **phpcs** は 997 件の違反が未処理（`vendor/bin/phpcbf` は導入済みだが一括実行していない）。
6. `/install` のフォームは FormHelper を使わない生 HTML のままであり、CSRF トークンは hidden input で補っているだけ。フォームヘルパーを使う全方位な改修は本記録の範囲外。
