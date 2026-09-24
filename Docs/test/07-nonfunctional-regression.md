# 07 — 非機能回帰試験マトリクス

## 前書き

| 項目 | 内容 |
|---|---|
| 対象 | 非機能要件・インフラ・UI・セキュリティの移行回帰 |
| 根拠 | `Docs/design/12-implementation-test-plan.md` R1〜R10、`Docs/dev/manual-test-checklist.md` |
| 前提データ | DS-0〜DS-6（`Docs/test/README.md` §3 参照） |
| 実施手順 | README.md §4 に準じる。500ゼロ・ルーティング回帰は DS-0 空状態で先に実施し、DS-1〜DS-6 投入後に UI・性能・整合性を実施する |
| 項目ID接頭辞 | `NON-` |
| 対象環境 | PHP 8.4.25 / CakePHP 5.4.x / MariaDB 11.4 / Apache（Docker: `irohaboard5-web-1`, `irohaboard5-db-1`） |
| 対象範囲 | 移行リスク R1〜R10 全件、ルーティング全件解決確認、500ゼロクロール、テンプレート大小重複、レガシー依存、UI/性能/セキュリティ/整合性 |

---

## 1. 移行リスク回帰（R1〜R10）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| NON-001 | 回帰 | R1: ORM 集計結果 | DS-4（学習記録あり） | 1. `/admin/records/index` を開き学習履歴一覧の件数・合計学習時間を確認<br>2. `/contents-questions/record/{content_id}/{record_id}` で採点結果の正解率を確認 | 1. 一覧件数が CakePHP 2 時代と一致<br>2. 正解率の計算結果が誤差なく表示される | R1 `12-implementation-test-plan.md`（Phase 2.2） | 可 | P0 | □ |  |
| NON-002 | 回帰 | R1: ORM サブクエリ結果 | DS-1（グループ・ユーザー・受講登録あり） | 1. `/admin/groups/index` で各グループのユーザー数・コース数を確認<br>2. API `GET /api/v1/groups/{id}/users` の件数と画面表示を照合 | 1. グループ一覧のユーザー数・コース数が正しい<br>2. API と画面の件数が一致する | R1 `12-implementation-test-plan.md`（Phase 2.2） | 難 | P0 | □ |  |
| NON-003 | 回帰 | R2: Auth セッション認証 | DS-1（admin ユーザーあり） | 1. admin でログインしセッションが正しく生成されることを確認<br>2. ページ遷移してもログイン状態が維持されることを確認<br>3. ログアウト後に `/admin/users/index` に直接アクセスしリダイレクトを確認 | 1. ログイン後 `/admin/users/index` に遷移<br>2. セッション有効中はログイン状態が維持<br>3. 未ログインで管理画面にアクセスすると `/admin/users/login` にリダイレクト | R2 `12-implementation-test-plan.md`（Phase 3.2）、`src/Application.php:141-182` | 可 | P0 | □ |  |
| NON-004 | 回帰 | R2: Auth Form 認証 | DS-1（admin/user ユーザー各1件以上） | 1. `/users/login` に admin でログイン<br>2. `/admin/users/login` に user ロールでログインを試みる<br>3. 不正なパスワードでログインを試みる | 1. admin は管理画面にログイン成功<br>2. user は管理画面にアクセスできない<br>3. 不正パスワードではログイン画面のままエラーメッセージ表示 | R2 `12-implementation-test-plan.md`、`src/Application.php:176-179` | 可 | P0 | □ |  |
| NON-005 | 回帰 | R3: Custom ディレクトリ | DS-0 | 1. `src/Custom/` 配下のファイルが存在することを確認<br>2. `composer.json` PSR-4 に `App\Custom\` が設定されていることを確認<br>3. Custom 内のクラスが autoload 経由で正しくロードされることを確認 | 1. `src/Custom/` が存在し PSR-4 設定が正しい<br>2. `App\Custom\` 命名空間のクラスが正常にインスタンス化される | R3 `12-implementation-test-plan.md`（Phase 1） | 難 | P1 | □ |  |
| NON-006 | 回帰 | R5: GROUP BY 結果 | DS-4（学習記録あり） | 1. `/admin/records/index` の学習履歴一覧で集計値を確認<br>2. `/admin/contents/index/{course_id}` の進捗集計を確認 | 1. MariaDB 11.4 の `ONLY_FULL_GROUP_BY` でも集計結果が正しい<br>2. 進捗率の計算が CakePHP 2 時代と一致する | R5 `12-implementation-test-plan.md`（Phase 5.1）、Q1-Q2 | 難 | P0 | □ |  |
| NON-007 | 回帰 | R6: REST API v1 互換性 | DS-1〜DS-6 投入済み | 1. 全24エンドポイント（`Docs/API.md` §3）に各 HTTP メソッドでリクエスト<br>2. レスポンス形式（`{data}` / `{data:[],meta:{}}` / `{error:{code,message}}`）を確認<br>3. Bearer トークン認証が正しく機能することを確認 | 1. 各エンドポイントが正しい HTTP ステータスコードを返す<br>2. レスポンス形式が `Docs/API.md` の仕様と一致<br>3. 認証なし/不正トークンで 401 が返る | R6 `12-implementation-test-plan.md`（Phase 5.2）、`Docs/API.md` §3 | 可 | P0 | □ |  |
| NON-008 | 回帰 | R7: Admin プレフィクス URL（操作系） | DS-1（admin ユーザーあり） | 1. 管理画面の全画面（ユーザ/グループ/コース/コンテンツ/お知らせ/学習履歴/設定 各一覧）に順番にアクセス<br>2. 画面上の全リンク（ナビゲーションバー・一覧内リンク・戻るボタン等）を全てクリック<br>3. 各画面の「追加」「編集」「削除」ボタンの遷移先を確認<br>4. ログアウト→再ログインの遷移先を確認 | 1. 全画面が正常に表示される（500エラーなし）<br>2. 全リンクが `/admin/{controller}/{action}` に遷移する<br>3. リダイレクトループが発生しない | R7 `12-implementation-test-plan.md`（Phase 3.5）、`config/routes.php:32-46` | 難 | P0 | □ |  |
| NON-009 | 回帰 | R7: Admin テンプレート内リンク | DS-1（admin ユーザーあり） | 1. `templates/Admin/` 配下全ファイルの `Html->link` / `Url->build` の遷移先を確認<br>2. `templates/element/admin_menu.php:10-27` の6リンク（ユーザ/グループ/コース/お知らせ/学習履歴/設定）の URL を確認 | 1. 全テンプレート内のリンクが `/admin/{controller}/{action}` パターン<br>2. admin_menu.php の6リンクが admin プレフィクスを含む | R7 `12-implementation-test-plan.md`、`templates/element/admin_menu.php:10-27` | 難 | P0 | □ |  |
| NON-010 | 回帰 | R8: FormToken → FormProtection | DS-1（admin ユーザーあり） | 1. 管理画面の各 CRUD フォーム（ユーザー追加/コース追加/コンテンツ追加等）を送信<br>2. 各コントローラの `unlockActions` 設定を確認（ContentsController: `order/preview/uploadImage`、CoursesController: `order` 等）<br>3. 正常送信と不正送信（CSRF トークンなし）の結果を確認 | 1. 正常なフォーム送信が成功する（405 黒穴エラーなし）<br>2. 不正な送信はブロックされる<br>3. `unlockActions` が意図通りに設定されている | R8 `12-implementation-test-plan.md`（Phase 4.1）、`src/Controller/Admin/*/Controller.php` | 難 | P1 | □ |  |
| NON-011 | 回帰 | R9: ib_records.group_id | DS-4（group_id 不整合行1件含む） | 1. `ib_records` テーブルで `group_id` が不正な行（存在しないグループID）が1件含まれていることを確認<br>2. `/admin/records/index` でそのレコードが500エラーなく処理されることを確認<br>3. API `GET /api/v1/records` でも不整合行がエラーなく返されることを確認 | 1. 画面上で group_id 不整合行が問題なく処理される<br>2. API でも同様にエラーなくレスポンスされる | R9 `12-implementation-test-plan.md`（Phase 0） | 難 | P1 | □ |  |
| NON-012 | 回帰 | R10: BoostCake→bootstrap-ui CSS | DS-0 | 1. `composer.json` を確認し `friendsofcake/bootstrap-ui` が依存に含まれていないことを確認<br>2. `templates/layout/default.php:30-31,42-44` で読み込まれる CSS/JS を確認<br>3. 管理画面全画面のフォーム要素の CSS クラスを確認 | 1. `composer.json` に `friendsofcake/bootstrap-ui` がない<br>2. `webroot/css/bootstrap.min.css` + `webroot/js/bootstrap.min.js` の Bootstrap 3 を使用<br>3. FormHelper.control() 出力と Bootstrap 3 クラスに不整合がない | R10 `12-implementation-test-plan.md`、`composer.json`、`templates/layout/default.php:30-44` | 難 | P1 | □ |  |
| NON-067 | 回帰 | R4: MariaDB 11.4 認証方式 | DS-0 | 1. `config/app_local.php` の接続情報で DB 接続が成功することを確認<br>2. `mariadb -uroot -prootpass -h irohaboard5-db-1` で直接接続できることを確認<br>3. アプリ起動時に接続エラーが発生しないことを確認<br>4. `logs/error.log` に接続エラーがないことを確認 | 1. MariaDB 11.4 は MySQL 8.4 の `caching_sha2_password` を使用しないため R4 は該当なしだが、接続が成功する<br>2. アプリからの接続認証が成立する<br>3. `logs/error.log` に接続系エラーが記録されていない | R4 `12-implementation-test-plan.md`（Phase 5.4） | 可 | P1 | □ |  |

> NON-067 は R4（MariaDB 11.4 の認証方式）対応のため追記。ID は追記順のため既存項目と前後する。

## 2. ルーティング回帰（全ルート解決確認）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| NON-013 | 回帰 | フロントルート（全18件） | DS-0（空状態） | 1. 未ログイン状態で全フロントURLにGET: `/`, `/users/login`, `/users-courses`, `/contents/index/1`, `/contents/view/1`, `/contents/preview`, `/contents/file-download/1`, `/contents/file-movie/1`, `/contents/file-image/test`, `/contents-questions/index/1`, `/contents-questions/record/1/1`, `/enquetes-questions/index/1`, `/enquetes-questions/record/1/1`, `/infos`, `/infos/index`, `/infos/view/1`, `/records/add/1`, `/pages/home`<br>2. 各URLのHTTPステータスを記録 | 1. 全URLで 500 エラーが発生しない<br>2. 存在しないID指定は 404 か適切なエラーページ<br>3. リダイレクトループがない | `config/routes.php:24-177`、`:343`（fallbacks撤去注記） | 可 | P0 | □ |  |
| NON-014 | 回帰 | Admin ルート（全10件） | DS-1（admin ユーザーあり）admin ログイン済み | 1. 管理画面の全URLにアクセス: `/admin`, `/admin/users/index`, `/admin/users/login`, `/admin/groups/index`, `/admin/courses/index`, `/admin/contents/index`, `/admin/contents/index/1`, `/admin/infos/index`, `/admin/records/index`, `/admin/settings/index` | 1. 全URLが 200 で表示<br>2. `/admin` が `/admin/users/index` として表示<br>3. 500エラーなし | `config/routes.php:32-46`（Admin prefix + fallbacks） | 可 | P0 | □ |  |
| NON-015 | 回帰 | API ルート（全28件） | DS-1 投入済み。トークンなし | 1. API v1 の全エンドポイントに未認証でリクエストしステータスを記録<br>2. `POST /api/v1/auth/token`（不正クレデンシャル）のレスポンスを確認 | 1. 全認証必要エンドポイントは 401 を返す<br>2. `auth/token` は不正クレデンシャルで 401<br>3. 500エラーなし | `config/routes.php:180-329`（API v1）、`Docs/API.md` §3 | 可 | P0 | □ |  |
| NON-016 | 回帰 | API catch-all ルート | DS-0 | 1. 未定義API URLにアクセス: `GET /api/v1/nonexistent`, `GET /api/v1/users/99999/nonexistent`, `GET /api` | 1. 全URL → JSON 404（`Api/Errors::notFound`）<br>2. HTML エラーページにならず JSON で返る<br>3. 500エラーなし | `config/routes.php:331-341`（API catch-all） | 可 | P0 | □ |  |
| NON-017 | 回帰 | レガシールート（404確認） | DS-0 | 1. CakePHP 2 の旧URLにアクセス: `/users/admin_login`, `/users/admin_index`, `/records/admin_index`, `/contents/admin_index`, `/groups/admin_index`, `/users_courses`, `/users/courses` | 1. 全URLが 404 を返す<br>2. 500エラー/リダイレクトループなし<br>3. `admin_` アクション名ルールが残っていない | `Docs/design/06-routing.md` §2、`config/routes.php:343` | 可 | P0 | □ |  |
| NON-018 | 回帰 | Install/Update ルート | DS-0。`ib_config.php:199` の `deny_install_update_access=false` | 1. `/install` にアクセスしHTTPステータスを確認<br>2. `/update` にアクセスしHTTPステータスを確認<br>3. `deny_install_update_access` を `true` に変更し再度アクセス | 1. `false` 時にインストーラー/アップデータが表示（200）<br>2. `true` 時にアクセス拒否（403 またはリダイレクト） | `config/routes.php:159-177`、`ib_config.php:198-199`、`InstallController.php:71`、`UpdateController.php:69` | 可 | P1 | □ |  |
| NON-019 | 回帰 | ルート解決（DashedRoute） | DS-0 | 1. `config/routes.php:22` の `DashedRoute` クラスが正しく設定されていることを確認<br>2. コントローラ名が CamelCase でURLが dash 区切りになることを確認<br>3. パラメータ（`{course_id}` 等）が正しくパースされることを確認 | 1. `DashedRoute` がロードされ URL が dash 形式<br>2. 各ルートで正しいURLが生成される<br>3. パラメータが正しく controller action に渡される | `config/routes.php:22`、`Docs/design/06-routing.md` §2 | 可 | P1 | □ |  |
## 3. 500ゼロ（全画面クロール）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| NON-020 | 回帰 | フロント画面 500ゼロ | DS-0（空状態）。`debug=false` | 1. ブラウザで各フロント画面に順番にアクセスし各リンクを全てクリックし 500エラーの有無を記録<br>2. `curl -s -o /dev/null -w "%{http_code}"` で全フロントルートのステータスを一括確認<br>3. `logs/error.log` に500エラーのスタックトレースがないことを確認 | 1. 全フロント画面で 500 エラーが発生しない<br>2. `logs/error.log` に移行起因のエラーが記録されない<br>3. HTTPステータスが全て 200/302/404 のいずれか | `12-implementation-test-plan.md` Phase 5.3、`Docs/dev/manual-test-checklist.md` | 可 | P0 | □ |  |
| NON-021 | 回帰 | 管理画面 500ゼロ | DS-1（admin ユーザーあり）ログイン済み。`debug=false` | 1. admin でログインし全画面（9コントローラの全アクション）にアクセス<br>2. 全リンク・ボタンをクリックし 500エラーを確認<br>3. CSV インポート/エクスポート・ファイルアップロード等も含める<br>4. `logs/error.log` を確認 | 1. 全管理画面で 500 エラーなし<br>2. `logs/error.log` に移行起因のエラーなし<br>3. HTTPステータスが全て 200/302/404 | `12-implementation-test-plan.md` Phase 5.3 | 難 | P0 | □ |  |
| NON-022 | 回帰 | API 500ゼロ | DS-1 投入済み。トークンなし | 1. API v1 の全24エンドポイントにリクエスト<br>2. 各レスポンスのステータスとボディを確認<br>3. `logs/error.log` を確認 | 1. 全エンドポイントで 500 エラーなし<br>2. 未認証時は全て 401<br>3. `logs/error.log` に移行起因のエラーなし | `Docs/API.md` §3 | 可 | P0 | □ |  |
| NON-023 | 回帰 | 500ゼロクロール手順（再現可能） | DS-0 | 1. `config/routes.php` から全ルート定義を抽出<br>2. 各ルートに `curl` でGETリクエストし、HTTPステータスとレスポンスボディに「500」「Internal Server Error」「Exception」がないことを確認<br>3. レスポンスコードをCSVに記録<br>4. 500以外の異常ステータス（502/503等）も記録 | 1. 全ルートで 500 エラーなし<br>2. CSV 出力結果に500が含まれない<br>3. 環境に依存しない手動手順として再現可能 | `12-implementation-test-plan.md` Phase 5.3 | 可 | P0 | □ |  |

## 4. テンプレート大小重複・未使用確認

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| NON-024 | 整合性 | Error テンプレート大小重複 | DS-0 | 1. `templates/Error/error500.php` と `templates/error/error500.php` の内容を比較<br>2. `src/Controller/ErrorController.php:59` で `setTemplatePath('Error')`（大文字）が使われていることを確認 | 1. `Error/`（大文字）が使用されている（ErrorController.php:59）<br>2. `error/`（小文字）は未使用の重複ファイル<br>3. CakePHP 5 標準は小文字だがこのアプリは大文字を使用 | `src/Controller/ErrorController.php:59`、`templates/Error/`、`templates/error/` | 可 | P1 | □ |  |
| NON-025 | 整合性 | Flash テンプレート大小重複 | DS-0 | 1. `templates/element/Flash/default.php` と `templates/element/flash/default.php` を比較<br>2. `templates/element/flash/` に error.php/info.php/success.php/warning.php が存在することを確認 | 1. `flash/`（小文字）が CakePHP 5 標準として使用されている<br>2. `Flash/`（大文字）は CakePHP 2 残骸の重複<br>3. `flash/error.php` 等が `Flash->render()` で正しく使われること | `templates/element/flash/`、`templates/element/Flash/` | 可 | P1 | □ |  |
| NON-026 | 整合性 | Email テンプレート大小重複 | DS-0 | 1. `templates/layout/Emails/` と `templates/layout/email/` の内容を比較<br>2. `templates/email/` の存在を確認<br>3. 実際にメール送信が必要な機能があるか確認 | 1. 3つのEmailテンプレートディレクトリが存在<br>2. 使用されているディレクトリを特定<br>3. 未使用ディレクトリは削除候補として記録 | `templates/layout/Emails/`、`templates/layout/email/`、`templates/email/` | 可 | P2 | □ |  |
| NON-027 | 整合性 | templates/cell 未使用確認 | DS-0 | 1. `templates/cell/` の内容を確認<br>2. `.gitkeep` のみであることを確認<br>3. `src/View/Cell/` に Cell クラスが存在するか確認 | 1. `templates/cell/` に `.gitkeep` のみ（空ディレクトリ）<br>2. Cell テンプレートが未使用<br>3. 不要なディレクトリとして記録 | `templates/cell/` | 可 | P2 | □ |  |

## 5. レガシー依存（Vendor/Utils.php）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| NON-028 | 回帰 | Utils::getYMDHN 使用箇所 | DS-1（学習記録あり） | 1. `/admin/records/index` で日時列表示を確認（RecordsController.php:166）<br>2. `/admin/contents-questions/index`、`/admin/infos/index`、`/admin/users/index`、`/admin/groups/index`、`/admin/contents/index`、`/admin/courses/index`、`/admin/enquetes-questions/index` で同様に確認<br>3. 出力形式が `YYYY/MM/DD HH:MM` 形式であることを確認 | 1. 全管理画面一覧で日時が正しくフォーマットされて表示<br>2. CakePHP 5 の `I18n\Time` との互換性が維持<br>3. フォーマットが CakePHP 2 時代と同一 | `Vendor/Utils.php` getYMDHN、`RecordsController.php:166`、`templates/Admin/*/index.php`（24箇所） | 難 | P0 | □ |  |
| NON-029 | 回帰 | Utils::getHNSBySec 使用箇所 | DS-1（学習記録あり） | 1. `/admin/records/index` で学習時間列表示を確認（RecordsController.php:165）<br>2. `/contents/index/{course_id}` のフロント表示でも確認<br>3. 秒数から HH:MM:SS 形式への変換が正しいことを確認 | 1. 学習時間が正しく「XX時間XX分XX秒」形式で表示<br>2. 0秒・24時間以上の境界値でもエラーがない | `Vendor/Utils.php` getHNSBySec、`RecordsController.php:165` | 難 | P1 | □ |  |
| NON-030 | 回帰 | Utils::getCsvData 使用箇所 | DS-1（ユーザーあり）admin ログイン済み | 1. ユーザー CSV インポート画面にアクセス<br>2. テスト CSV ファイルをアップロードし getCsvData() が正しく動作することを確認（UsersController.php:383）<br>3. UTF-8/BOM付き/Shift_JIS 各形式でテスト | 1. CSV ファイルが正しくパースされデータが一覧に表示<br>2. 不正な CSV でエラーメッセージが表示 | `Vendor/Utils.php` getCsvData、`UsersController.php:383` | 難 | P1 | □ |  |
| NON-031 | 回帰 | Utils::getKeyByValue / issetOr / getIdByTitle | DS-1（ユーザー・グループあり） | 1. ユーザー CSV エクスポートで getKeyByValue() の出力を確認（UsersController.php:432）<br>2. CSV インポートで issetOr() のデフォルト値処理を確認（UsersController.php:434,444,461）<br>3. getIdByTitle() によるグループ名→ID変換を確認（UsersController.php:450,467） | 1. CSV エクスポートのロール列表示が正しい<br>2. 空フィールドにデフォルト値が設定<br>3. グループ名から正しくIDに変換 | `Vendor/Utils.php`、`UsersController.php:432-467` | 難 | P1 | □ |  |
| NON-032 | 整合性 | Utils::未使用メソッド確認 | DS-0 | 1. `Vendor/Utils.php` の `getNewPassword`、`getDMY`、`getMDY` が `src/` および `templates/` から未使用であることを grep で確認 | 1. `getNewPassword` → 未使用（grep で0件）<br>2. `getDMY` → 未使用（grep で0件）<br>3. `getMDY` → 未使用（grep で0件） | `Vendor/Utils.php` | 可 | P2 | □ |  |
| NON-033 | 整合性 | Vendor/FileUpload.php 未使用確認 | DS-0 | 1. `Vendor/FileUpload.php` が `src/` から参照されていないことを grep で確認<br>2. `templates/` からも参照されていないことを確認 | 1. `FileUpload.php` → 未使用（grep で0件）<br>2. 削除候補として記録 | `Vendor/FileUpload.php` | 可 | P2 | □ |  |
| NON-034 | 整合性 | Utils の classmap autoloading | DS-0 | 1. `composer.json` の `classmap: ["Vendor/"]` 設定を確認<br>2. `composer dump-autoload` 後に `\Utils` クラスが正しくロードされることを確認<br>3. `vendor/autoload_classmap.php` に Utils が含まれることを確認 | 1. `classmap` 設定が正しい<br>2. `\Utils` クラスが namespace なしでロード<br>3. `composer dump-autoload` が成功 | `composer.json` classmap 設定、`Vendor/Utils.php` | 可 | P1 | □ |  |

## 6. レガシー依存（index_cake2.php）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| NON-035 | 回帰 | index_cake2.php 残置影響 | DS-0 | 1. `webroot/index_cake2.php` に直接アクセス<br>2. CakePHP 2 の Dispatcher が PHP 8.4 で Fatal Error になることを確認<br>3. アプリケーションの動作に影響がないことを確認 | 1. index_cake2.php → PHP Fatal Error（CakePHP 2 コードが PHP 8.4 非互換）<br>2. 本体アプリケーションの動作に影響なし | `webroot/index_cake2.php`（211行） | 可 | P1 | □ |  |

## 7. 実行環境・セキュリティ

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| NON-036 | セキュリティ | .htaccess セキュリティ規則 | DS-0 | 1. `webroot/.env` にアクセスし 403/404 になることを確認<br>2. `webroot/vendor/` にアクセスし拒否されることを確認<br>3. `webroot/.git/` にアクセスし拒否されることを確認<br>4. `webroot/test.sql` / `webroot/.bak` / `webroot/.ini` / `webroot/.py` にアクセス<br>5. `webroot/.well-known/` にアクセス | 1. .env/vendor/phpunit/.git/.sql/.bak/.ini/.cgi/.py → 拒否<br>2. .well-known → 404<br>3. wp-admin/wp-includes/wp-content → 拒否<br>4. index.php 以外の .php → 拒否（test_pi.php は許可） | `webroot/.htaccess:22-35,30-32` | 可 | P0 | □ |  |
| NON-037 | セキュリティ | Host ヘッダ検証 | DS-0。`debug=false` | 1. `src/Middleware/HostHeaderMiddleware.php` の動作を確認<br>2. 不正な Host ヘッダでリクエストし InternalErrorException が発生することを確認<br>3. `debug=true` 時にスキップされることを確認 | 1. 不正 Host → InternalErrorException（500）<br>2. debug=true 時に Host ヘッダ検証がスキップされる<br>3. `App.fullBaseUrl` 未設定で本番環境起動時にエラー | `src/Middleware/HostHeaderMiddleware.php:34-53`、`config/bootstrap.php:159-180` | 可 | P0 | □ |  |
| NON-038 | セキュリティ | DebugKit 環境別ロード | DS-0 | 1. `src/Application.php` で DebugKit が bootstrap でロードされないことを確認<br>2. `tests/TestCase/ApplicationTest.php:testBootstrapInDebug` で debug=true 時にロードされることを確認<br>3. 本番（debug=false）で DebugKit パネルが表示されないことを確認 | 1. `src/Application.php` の bootstrap に DebugKit の明示ロードがない<br>2. `tests/bootstrap.php` 経由でテスト時のみロード<br>3. 本番環境で DebugKit パネルが非表示 | `src/Application.php`、`tests/TestCase/ApplicationTest.php:23-30` | 可 | P0 | □ |  |
| NON-039 | セキュリティ | CSRF 保護 | DS-1（admin ユーザーあり） | 1. `src/Application.php:118-129` の CSRF スキップ対象（`/api/*`、`/users/login`、`/users/logout`）を確認<br>2. スキップ対象以外の POST リクエストで CSRF トークンが要求されることを確認<br>3. API は CSRF チェックがスキップされることを確認 | 1. `/api/*`、`/users/login`、`/users/logout` は CSRF スキップ<br>2. それ以外のフォーム送信は CSRF トークンが必要<br>3. API リクエストで 405 黒穴エラーが発生しない | `src/Application.php:118-129`、`src/Controller/AppController.php` | 可 | P0 | □ |  |
| NON-040 | セキュリティ | denied ファイルの .htaccess 規則 | DS-0 | 1. `webroot/.htaccess:30-32` の `.php` 拡張子制限を確認<br>2. `index.php` と `test_pi.php` のみ許可されていることを確認<br>3. それ以外の .php ファイル（`phpinfo.php` 作成）にアクセスし拒否されることを確認 | 1. `index.php` → 許可<br>2. `test_pi.php` → 許可<br>3. `phpinfo.php` → 拒否<br>4. `.git/config` 等の隠しファイル → 拒否 | `webroot/.htaccess:30-32` | 可 | P0 | □ |  |
| NON-041 | セキュリティ | ログ出力確認 | DS-0 | 1. `logs/error.log` が書き込み可能であることを確認<br>2. 意図的に500エラーを発生させログに記録されることを確認<br>3. CakePHP の log 設定（`config/app_local.php`）を確認 | 1. `logs/error.log` が存在し書き込み可能<br>2. 500エラー発生時にスタックトレースが記録される<br>3. Log engine が `Cake\Log\Engine\FileLog` に設定されている | `config/app_local.php` Log 設定 | 可 | P1 | □ |  |
| NON-042 | セキュリティ | パーミッション確認 | DS-0 | 1. `tmp/` ディレクトリが書き込み可能（777 or www-data writable）<br>2. `logs/` ディレクトリが書き込み可能<br>3. `webroot/files/` ディレクトリが書き込み可能 | 1. `tmp/` → 書き込み可能<br>2. `logs/` → 書き込み可能<br>3. `webroot/files/` → 書き込み可能<br>4. CakePHP のキャッシュ・セッションが正常に動作 | `config/paths.php:TMP,LOGS` | 可 | P0 | □ |  |

## 8. UI・非機能

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| NON-043 | UI | ブラウザ差異（Chrome） | DS-1（admin ユーザーあり） | 1. Chrome 最新版で管理画面の主要画面（一覧・追加・編集・詳細）を閲覧<br>2. CSS/JS の表示崩れがないことを確認<br>3. フォーム送信・モーダル表示が正しく動作することを確認 | 1. 主要画面が正しく表示される<br>2. CSS/JS の表示崩れがない<br>3. 操作が正常に動作 | `templates/layout/default.php`（Bootstrap 3 CSS/JS） | 難 | P1 | □ |  |
| NON-044 | UI | ブラウザ差異（Firefox） | DS-1（admin ユーザーあり） | 1. Firefox 最新版で管理画面の主要画面を閲覧<br>2. CSS/JS の表示崩れがないことを確認<br>3. フォーム送信・モーダル表示が正しく動作することを確認 | 1. 主要画面が正しく表示される<br>2. CSS/JS の表示崩れがない<br>3. 操作が正常に動作 | `templates/layout/default.php` | 難 | P1 | □ |  |
| NON-045 | UI | ブラウザ差異（Safari） | DS-1（admin ユーザーあり） | 1. Safari 最新版で管理画面の主要画面を閲覧<br>2. CSS/JS の表示崩れがないことを確認<br>3. フォーム送信・モーダル表示が正しく動作することを確認 | 1. 主要画面が正しく表示される<br>2. CSS/JS の表示崩れがない<br>3. 操作が正常に動作 | `templates/layout/default.php` | 難 | P2 | □ |  |
| NON-046 | UI | スマホ幅表示 | DS-1（admin ユーザーあり） | 1. レスポンシブデザインが正しいことを確認<br>2. `templates/layout/default.php:21-25` の viewport メタタグを確認<br>3. 管理画面で横スクロールが発生しないことを確認 | 1. viewport メタタグが設定されている<br>2. 管理画面は固定幅（非レスポンシブ）のためスクロールが発生するが、機能的に問題がない | `templates/layout/default.php:21-25` | 可 | P1 | □ |  |
| NON-047 | UI | タブ操作・Enter 送信 | DS-1（admin ユーザーあり） | 1. 各フォームで Tab キーのフォーカス移動が正しいことを確認<br>2. Enter キーでフォーム送信されないことを確認（テキスト入力欄で Enter 押下時）<br>3. テキストエリア内でのみ Enter が許可されることを確認 | 1. Tab でフォーカスが正しく移動<br>2. テキスト入力欄での Enter でフォーム送信が発生しない<br>3. 操作が直感的 | `templates/Admin/*/add.php`、`templates/Admin/*/edit.php` | 難 | P1 | □ |  |
| NON-048 | UI | JS 無効時の主要画面 | DS-1（admin ユーザーあり） | 1. JavaScript を無効にして管理画面にアクセス<br>2. 基本的な一覧表示・ナビゲーションが動作することを確認<br>3. JS 必須機能（ファイルアップロード、サマノート等）は JS 無効時でもフォールバックがあるか確認 | 1. 一覧表示・ナビゲーションは JS 無効でも動作<br>2. JS 必須機能は JS 無効時に入力不可の旨が表示されるか、利用不能なことが明示される | `templates/layout/default.php:42-44` | 可 | P2 | □ |  |
| NON-049 | 性能 | ページ読み込み性能 | DS-1〜DS-6 投入済み | 1. 主要画面（フロント: `/`, `/users-courses`, `/contents/view/1`; Admin: `/admin`, `/admin/users/index`, `/admin/courses/index`, `/admin/records/index`）の応答時間を計測<br>2. `curl -w '%{time_total}'` で計測<br>3. 3秒以内に応答することを確認 | 1. メインページ: 1秒以内<br>2. 一覧系: 2秒以内<br>3. 詳細系: 2秒以内<br>4. 3秒超過の画面がないこと | `templates/layout/default.php` | 可 | P0 | □ |  |
| NON-050 | UI | demo_mode 表示分岐 | DS-0。`config/ib_config.php:131` の `demo_mode=false` | 1. `demo_mode=false` 時にデモ用ボタンが非表示であることを確認<br>2. `demo_mode=true` に変更しデモ用ボタンが表示されることを確認<br>3. `webroot/js/demo.js` が正しく読み込まれることを確認<br>4. 参照箇所（8コントローラ + layout）での分岐が正しいことを確認 | 1. `false` 時: デモボタン非表示、demo.js 未読込<br>2. `true` 時: デモボタン表示、demo.js が読込<br>3. 全8コントローラ（Contents/Infos/Users/Settings/Courses + UserLoginTrait + front Users）で分岐が正しい | `config/ib_config.php:131`、`templates/layout/default.php:52`、`webroot/js/demo.js` | 可 | P1 | □ |  |
| NON-051 | 整合性 | i18n（__使用箇所） | DS-0 | 1. `src/` 及び `templates/` 内の `__()` 呼び出しが翻訳ファイルなしでもエラーなく動作することを確認<br>2. `resources/` 配下にロケールファイルがないことを確認<br>3. `__()` の戻り値がキー文字列自身であることを確認 | 1. 翻訳ファイルなしでも画面が表示される<br>2. `__()` がキー文字列をそのまま返す（CakePHP 5 のフォールバック動作）<br>3. PHP エラーや例外が発生しない | CakePHP 5 i18n フォールバック動作 | 可 | P1 | □ |  |
| NON-052 | 整合性 | deny_install_update_access 制御 | DS-0 | 1. `config/ib_config.php:198-199` の `deny_install_update_access=false` を確認<br>2. InstallController.php:71 と UpdateController.php:69 のチェックロジックを確認<br>3. `true` に変更して `/install` `/update` にアクセスし拒否されることを確認 | 1. `false` 時: インストーラー/アップデータにアクセス可能<br>2. `true` 時: アクセスが拒否される（403/リダイレクト） | `config/ib_config.php:198-199`、`InstallController.php:71`、`UpdateController.php:69` | 可 | P0 | □ |  |
| NON-053 | 性能 | 同時ログイン複数セッション | DS-1（admin/user ユーザー各1件以上） | 1. 同一ブラウザで2つのタブを開きadminとuserにそれぞれログイン<br>2. 異なるブラウザで同一ユーザーに2重ログイン<br>3. セッションが意図通りに管理されることを確認 | 1. 2つのセッションが独立して動作<br>2. 2重ログイン時に既存セッションが無効化される（設定による）<br>3. ログアウト時に正しいセッションが終了 | `src/Application.php:141-182`、`ib_cake_sessions` テーブル | 可 | P1 | □ |  |
| NON-054 | セキュリティ | bin/cake 起動 | DS-0 | 1. `bin/cake` が実行可能であること<br>2. `bin/cake version` で CakePHP バージョンが表示されること<br>3. CLI PHP が正しく検出されること | 1. `bin/cake version` → CakePHP 5.4.x が表示<br>2. エラーなく実行可能 | `bin/cake` | 可 | P1 | □ |  |

## 9. その他の移行回帰

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| NON-055 | 回帰 | Application.php ミドルウェア順序 | DS-0 | 1. `src/Application.php:110-130` のミドルウェア登録順序を確認<br>2. ErrorHandler → ApiError → HostHeader → Asset → Routing → BodyParser → Authentication → CSRF の順序<br>3. ApiError が ErrorHandler の直後に挿入されていることを確認 | 1. ミドルウェアが正しい順序で登録されている<br>2. CSRF が最後に設定される（CSRF skip 対象が正しく動作）<br>3. HostHeader が Routing の前に設定される | `src/Application.php:110-130` | 可 | P0 | □ |  |
| NON-056 | 回帰 | Authentication ミドルウェア設定 | DS-1（admin/user ユーザーあり） | 1. `src/Application.php:141-182` の Session + Form authenticator 設定を確認<br>2. `loginUrl` が Admin プレフィクス別に正しく設定されていることを確認<br>3. フロント: `/users/login`、Admin: `/admin/users/login` | 1. Session authenticator が正しく設定<br>2. Form authenticator が正しく設定<br>3. loginUrl がプレフィクス別に分岐している | `src/Application.php:141-182` | 可 | P0 | □ |  |
| NON-057 | 回帰 | ApiBaseController 初期化 | DS-0 | 1. `src/Controller/Api/BaseController.php:32-38` で `parent::initialize()` が呼ばれていないことを確認<br>2. FormProtection/Flash コンポーネントが API でロードされないことを確認<br>3. `Authentication` のみがロードされることを確認 | 1. parent::initialize() をスキップ<br>2. FormProtection がロードされない（API では不要）<br>3. Flash がロードされない | `src/Controller/Api/BaseController.php:32-38` | 可 | P1 | □ |  |
| NON-058 | 回帰 | config/bootstrap.php 設定 | DS-0 | 1. `config/bootstrap.php` で ib_config がロードされていることを確認<br>2. fullBaseUrl 棜出（dev 環境 fallback）が正しいことを確認<br>3. MobileDetect が設定されていることを確認<br>4. Cache/ConnectionManager/Log の設定を確認 | 1. ib_config が bootstrap 67行目でロード<br>2. fullBaseUrl が 159-180行目で検出<br>3. MobileDetect が 198-207行目で設定<br>4. 各サービスが正しく初期化 | `config/bootstrap.php:67,159-180,198-207` | 可 | P1 | □ |  |
| NON-059 | 回帰 | form_defaults / ib_config 設定 | DS-0 | 1. `config/ib_config.php:140-167` の form_defaults を確認<br>2. Bootstrap 3 CSS クラスが正しいことを確認<br>3. テーマカラー等の設定が画面に反映されることを確認 | 1. form_defaults が Bootstrap 3 クラス（`form-group`/`form-control`/`btn btn-primary` 等）を参照<br>2. 設定が画面上のフォームに反映される | `config/ib_config.php:140-167` | 可 | P1 | □ |  |
| NON-060 | 回帰 | api_base_url / api_auth_key 設定 | DS-0 | 1. `config/ib_config.php` の api_base_url と api_auth_key を確認<br>2. API リクエスト時に正しいベースURLが使われることを確認 | 1. api_base_url が正しい<br>2. API リクエストが正しいURLに送信される | `config/ib_config.php` | 可 | P1 | □ |  |
| NON-061 | 回帰 | Cookie 設定 | DS-1（admin ユーザーあり） | 1. `config/ib_config.php` の cookie 設定を確認<br>2. Cookie の有効期限・ドメイン・パスが正しいことを確認<br>3. ログイン時に Cookie が正しく設定されることを確認 | 1. Cookie 設定が正しい<br>2. Cookie が正しく送受信される | `config/ib_config.php`、`src/Controller/AppController.php` | 可 | P1 | □ |  |
| NON-062 | 回帰 | theme_color 設定 | DS-0 | 1. `config/ib_config.php` の theme_color 設定を確認<br>2. `webroot/css/common.css` で CSS 変数/スタイルが使用されていることを確認<br>3. 設定変更時に画面の色が変わることを確認 | 1. theme_color が設定されている<br>2. CSS に反映されている<br>3. 変更時に画面が更新される | `config/ib_config.php`、`webroot/css/common.css` | 可 | P2 | □ |  |
| NON-063 | 回帰 | logs/error.log へのエラー記録 | DS-0 | 1. 意図的に存在しないURLにアクセス<br>2. `logs/error.log` に 404 エラーが記録されていることを確認<br>3. 500 エラーを意図的に発生させスタックトレースが記録されることを確認 | 1. 404 エラーがログに記録される<br>2. 500 エラー時にスタックトレースが記録される | CakePHP Log 設定 | 可 | P1 | □ |  |
| NON-064 | 整合性 | config/routes.php の DashedRoute | DS-0 | 1. `config/routes.php:22` の `App\Routing\Route\DashedRoute` クラスが存在することを確認<br>2. このクラスが正しく URL を生成することを確認 | 1. DashedRoute クラスが存在し autoload される<br>2. dash 区切り URL が正しく解決される | `config/routes.php:22` | 可 | P1 | □ |  |
| NON-065 | 整合性 | ib_config.php の legacy_security_salt | DS-0 | 1. `config/ib_config.php:12` の `legacy_security_salt` 設定を確認<br>2. SHA1 パスワード検証が正しく動作することを確認<br>3. 新規パスワードが bcrypt でハッシュされることを確認 | 1. legacy_security_salt が設定されている<br>2. 旧パスワード（SHA1）のユーザーがログイン可能<br>3. 新規パスワードは bcrypt | `config/ib_config.php:12` | 可 | P0 | □ |  |
| NON-066 | 回帰 | records/upload_files 設定 | DS-0 | 1. `config/ib_config.php` の upload_files 設定を確認<br>2. ファイルアップロード先ディレクトリが書き込み可能であることを確認<br>3. アップロード時に正しくファイルが保存されることを確認 | 1. upload_files 設定が正しい<br>2. アップロード先ディレクトリが存在し書き込み可能<br>3. ファイルが正しく保存される | `config/ib_config.php`、`webroot/files/` | 可 | P1 | □ |  |

---

## 集計表

| 分類 | 項目数 | P0 | P1 | P2 |
|---|---|---|---|---|
| 回帰 | 39 | 20 | 18 | 1 |
| セキュリティ | 8 | 6 | 2 | 0 |
| 整合性 | 11 | 2 | 5 | 4 |
| UI | 7 | 0 | 5 | 2 |
| 性能 | 2 | 1 | 1 | 0 |
| **合計** | **67** | **29** | **31** | **7** |

> P0 は移行失敗に相当。全 P0 項目の Pass が移行完了の条件。
