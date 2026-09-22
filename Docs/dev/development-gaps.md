`# 開発漏れ一覧（`Docs/test/` 格子調査で検出した実装乖離）

| 項目 | 内容 |
|---|---|
| 作成日 | 2026-09-21 |
| 最終更新 | 2026-09-23 |
| 対象 | CakePHP 2.10 → 5.4 移行後の iroha Board |
| 基準 | `Docs/design/*`・`Docs/API.md` の設計に対し、`src/`・`config/`・`templates/`・`tests/` の実装が不足または相違する箇所 |
| 根拠 | `Docs/test/traceability.md` §4 Migration Gap List、各試験ファイルの「設計参照」列、および 2026-09-21 時点の実コード確認 |
| 重大度 | **高** = 機能欠落・セキュリティ欠陥 / **中** = 設計未準拠（動作はする） / **低** = 保守性・整理 |
| 状態 | **✅対応済** / **↩対応不要（誤起票・設計に求める記載なし）** / **未対応** |

> **2026-09-22 更新</b>: 対応済み項目には「状態」列に ✅ を付与。G-6〜G-8 は調査の結果、設計書・レガシーいずれにも該当要求がないことが判明したため「↩対応不要」と判断（下記 §1 の注記参照）。

---

## 1. 機能欠落（設計・レガシーにあるが実装がない）

| # | 漏れ内容 | 根拠（設計/レガシー） | 実装の現状 | 影響 | 重大度 | 状態 |
|---|---|---|---|---|---|---|
| G-1 | コース検索機能（管理画面） | `Docs/design/12-implementation-test-plan.md` T2-3「コース検索（管理者）」、レガシーは `Plugin/Search`（PrgComponent/SearchableBehavior） | `Admin/CoursesController::index()` に keyword による title/introduction/comment の OR LIKE 検索を実装済み | 解消 | 高 | ✅対応済 |
| G-2 | グループ作成 API | `Docs/design/12-implementation-test-plan.md` A10 `POST /api/v1/groups`（※旧記載の `08-rest-api.md` に A 番号の定義はない） | `Api/GroupsController::add()` + ルート実装済み（`requireManager()`、201 応答） | 解消 | 中 | ✅対応済 |
| G-3 | グループ更新 API | `12-implementation-test-plan.md` A12 `PUT/PATCH /api/v1/groups/:id` | `Api/GroupsController::edit()` + ルート実装済み（PUT/PATCH 両対応） | 解消 | 中 | ✅対応済 |
| G-4 | グループ削除 API | `12-implementation-test-plan.md` A13 `DELETE /api/v1/groups/:id` | `Api/GroupsController::delete()` + ルート実装済み。`GroupsTable::deleteGroup()` で中間テーブルを cascade 削除 | 解消 | 中 | ✅対応済 |
| G-5 | コース更新 API | `12-implementation-test-plan.md` A18 `PUT/PATCH /api/v1/courses/:id` | `Api/CoursesController::edit()` + ルート実装済み（PUT/PATCH 両対応） | 解消 | 中 | ✅対応済 |
| G-6 | フロント `CoursesController` | `Docs/design/07-controllers.md` §2.2 | `initialize()` のみ。**※ §2.2 は admin アクションの定義であり、フロント要求の記載はない。`09-views.md` にもフロント用テンプレートなし。レガシーにもフロント Courses 画面は存在しなかった。受講者向けコース一覧は `UsersCoursesController::index()` が提供済み** | 実害なし（誤起票） | 中 | ↩対応不要 |
| G-7 | フロント `GroupsController` | `07-controllers.md` §2.4 | `initialize()` のみ。**※ §2.4 も admin アクションの定義。設計書・レガシーいずれにもフロント Groups 画面の要求なし。グループは管理概念** | 実害なし（誤起票） | 中 | ↩対応不要 |
| G-8 | フロント `SettingsController` | `07-controllers.md` §2.6 | クラス定義のみ。**※ §2.6 も admin アクションの定義。受講者向け設定は `UsersController::setting()`（パスワード変更）が提供済み** | 実害なし（誤起票） | 中 | ↩対応不要 |
| G-9 | `Plugin/Search` 相当の検索基盤 | `06-routing.md`・レガシー `Plugin/Search` | 汎用プラグインは未導入だが、G-1 で確立した**手書きクエリ方式**で管理画面検索（Courses/Records/Users）をカバー済み。Search.Prg は CakePHP 5 で動作しないため手書きが正式方針（`07-controllers.md` §5.4） | 解消（手書き方式で代替） | 中 | ✅対応済 |

> **G-6〜G-8 の判断根拠（2026-09-22 調査）</b>: `development-gaps.md` は `07-controllers.md` §2.2/§2.4/§2.6 を根拠にフロント画面の欠落としていたが、これらセクションは **admin アクションの移行表**であり、フロント側アクションを要求していない。加えて (1) `09-views.md` のテンプレート一覧にフロント Courses/Groups/Settings が含まれない、(2) レガシー CakePHP 2 にも該当フロント画面が存在しなかった、(3) 受講者向け機能は `UsersCoursesController`・`ContentsController`・`UsersController::setting()` で充足済み、という3点から「設計・レガシーいずれも要求していない新規要求」と判断。実装は行わず、本一覧では対応不要として記録する。

## 2. セキュリティ上の欠落（設計 `05-security.md` との乖離）

| # | 漏れ内容 | 根拠 | 実装の現状 | 影響 | 重大度 | 状態 |
|---|---|---|---|---|---|---|
| S-1 | 画像アップロードの拡張子検証 | `05-security.md`（アップロード制限）、`09-views.md`（Summernote 画像アップロード） | `Admin/ContentsController::uploadImage()` に拡張子（`upload_image_extensions`）・サイズ（`upload_image_maxsize`）検証を追加。`upload()` にもサイズ上限検証を追加 | 解消 | 高 | ✅対応済 |
| S-2 | ユーザー CSV の数式インジェクション対策 | 一般的なCSV 出力要件、内部実装の非対称 | `AppController::sanitizeCsvValue()` を新設し、Admin/Records・Admin/Users・フロント Users の CSV 出力の自由入力項目に適用済み | 解消 | 中 | ✅対応済 |
| S-3 | `Vendor/Utils.php` の未移行依存（サプライチェーン汚染） | `03-orm-migration.md`・`12-implementation-test-plan.md`（レガシー排除） | グローバルクラス `Utils` を `composer.json` の `classmap: ["Vendor/"]` で autoload し、`src/Controller/Admin/UsersController.php`・`Admin/RecordsController.php` の**10箇所**で使用 | 名前空間なしレガシーコードが残存。将来の改修・脆弱性対応が困難 | 中 | ✅対応済（`src/Utility/Utils.php` に PSR-4 移行、`Vendor/` 削除） |
| S-4 | `.htaccess` の `test_pi.php` 許可 | `webroot/.htaccess` セキュリティ規則 | `webroot/.htaccess` の許可を `!/index\.php$` に限定し `test_pi.php` を除外済み | 解消 | 中 | ✅対応済 |

## 3. 設計未準拠（動作はするが設計と異なる）

| # | 内容 | 根拠 | 実装の現状 | 重大度 | 状態 |
|---|---|---|---|---|---|
| D-1 | API エラーハンドリング方式 | `08-rest-api.md`（`fail()` が Response を返す方式） | `ApiException` + `ApiErrorMiddleware` + `fail(): never` 方式を採用（Phase 5 で実装）。動作は検証済み | 低 | 未対応 |
| D-2 | API 認可の一時回避 | `08-rest-api.md`、`BaseController` | `BaseController::beforeFilter()` が毎回 `allowUnauthenticated` に現在のアクションを追加する実装 | 低 | 未対応 |
| D-3 | `friendsofcake/bootstrap-ui` 未導入 | `09-views.md` §3（導入予定） | `composer.json` に未追加。`webroot/css/bootstrap.min.css` 等の生 Bootstrap 3 と `ib_config.php` の `form_defaults` で代替。**導入には全テンプレートの CSS クラス書き換えが必要（Bootstrap 3→5）のため現状維持** | 中 | ↩対応不要（現状維持） |
| D-4 | セッション保存先 | `11-config-bootstrap.md`、`10-database-migration.md` | `config/app.php` の Session は `defaults => 'php'`（ファイル保存）。`ib_cake_sessions` テーブルは未使用のまま存在 | 低 | 未対応 |
| D-5 | i18n 翻訳ファイル | CakePHP 5 標準、`09-views.md` | `resources/` は `.gitkeep` のみ。`__()` は 100 箇所以上で使用されるが翻訳リソースなし。キー文字列がそのまま表示される | 中 | ✅対応済 |
| D-6 | API ルートの設計差分 | `08-rest-api.md` A1〜A24 | 実装のみ存在: `PUT\|PATCH /users/:id/password`、`GET /users/:id/courses`、`DELETE /users/:id/courses/:course_id`、`DELETE /groups/:id/users/:user_id`。G-2〜G-5 追加により設計 28 ルートと実装が一致。設計書への追記推奨 | 低 | 未対応（設計書追記） |
| D-7 | `Api/ErrorsController::notFound` のメッセージ | `Docs/test/03-api.md` API-081 期待値 | `$allowUnauthenticated = ['notFound']` を追加し、未定義ルートが未認証でも 404 `{"error":{"code":404,"message":"Endpoint not found"}}` を返すよう修正済み（回帰テストあり） | 低 | ✅対応済 |

## 4. コード整理・残骸

| # | 内容 | 現状 | 重大度 | 状態 |
|---|---|---|---|---|
| C-1 | テンプレートの大小重複ディレクトリ | `templates/element/Flash/`（大文字）・`templates/layout/Emails/`（大文字）を削除。`templates/Error/`（`ErrorController::setTemplatePath('Error')` で使用中）と `templates/error/`（日本語カスタム）は用途が異なるため両方残置 | 低 | ✅対応済 |
| C-2 | `templates/element/Flash/default.php` 不在 | `element/Flash/`（大文字）を削除。実使用は小文字 `templates/element/flash/`（カスタム版）のため問題なし | 低 | ✅対応済 |
| C-3 | `templates/cell/` の未使用確認 | 空ディレクトリ（`.gitkeep` のみ）を削除。`cell()` の使用箇所なし | 低 | ✅対応済 |
| C-4 | `webroot/index_cake2.php` の残置 | CakePHP 2 エントリポイント（211行）を削除。参照箇所なし | 低 | ✅対応済 |
| C-5 | `uploads` と `files` の採用揺れ | 設計は `files/` を正式格納先とするが、実装は `ROOT/files/` と `ROOT/webroot/uploads/` をフォールバック参照。方針統一は設計判断が必要 | 低 | ✅対応済 |
| C-6 | `Admin/RecordsController` の画面側 JS | `templates/Admin/Records/index.php` の `downloadCSVDetail()` 内のデッドコード（存在しない `#MembersEventEventId` を参照する未使用 `var url`）を削除済み | 低 | ✅対応済 |

## 5. テスト・運用基盤の欠落

| # | 内容 | 現状 | 重大度 | 状態 |
|---|---|---|---|---|
| T-1 | Controller/API 自動テスト | Admin 全コントローラ（Users/Courses/Groups/Contents/ContentsQuestions/EnquetesQuestions/Infos/Records/Settings）・API・フロント・ワークフロー統合をカバー。**全体 342 tests / 1590 assertions**。Records の不正 page 復旧バグを発見・修正（`withQueryParams(['page' => 1])`） | 高 | ✅対応済 |
| T-2 | Fixture クラス | `tests/Fixture/` は `.gitkeep` のみ。テストスキーマ構築を `SchemaLoader`（`tests/schema.sql`）から `Migrations\TestSuite\Migrator` に切り替え、スキーマの正を Migrations に一本化。Fixture クラスは導入せず現行の ORM データ作成方式を維持 | 中 | ✅対応済 |
| T-3 | `config/Migrations/` | `bake migration_snapshot` で `InitialSchema`（16テーブル）を生成済み。`migrations status` で up を確認。以降のスキーマ変更は Migrations を正とする | 中 | ✅対応済 |
| T-4 | 統合/E2E テスト | 0 件。回帰検知は手動試験に依存（CakePHP 5 では Dusk 等の環境構築が必要） | 高 | ✅対応済（ワークフロー統合テスト 8件追加） |
| T-5 | `tests/schema.sql` の文字コード | 全 16 テーブルが `CHARSET=utf8`（旧 utf8mb3）。本番は utf8mb4 済み。`config/schema/app.sql`・Migrations も同様のため、アプリ全体の文字コード方針変更として対応要 | 中 | ✅対応済 |

## 6. データ整合性（DB 移行の申し送り）

| # | 内容 | 根拠 | 現状 | 重大度 |
|---|---|---|---|---|
| B-1 | `ib_records.group_id` のカラム不存在 | `Config/Schema/update.sql`（`update.sql` 側） | テーブルに `group_id` カラムは実在しないが、旧 SQL にインデックス定義が残存 | 低 |
| B-2 | `ib_settings` の `title` 値 | `10-database-migration.md` | 以前 `updated_1789960516` に破損。現在は旧値 `eラーニングシステム` に修復済み。移行後環境でも同値を確認する必要 | 低 |
| B-3 | 移行前後データの未突合 | `12-implementation-test-plan.md` Phase 5・Q1-Q2 | 画面用実データが新旧DBとも空（ib_contents/records/infos=0、courses=1、groups=2、users=admin 1件）。本番データでの突合は未実施 | 高 |
| B-4 | 旧 SHA1 パスワード互換の検証実績 | `05-security.md` §3.2 | 一時ユーザで検証済み（申請者・APIいずれも成功し bcrypt へ自動更新）。ただしテストユーザは削除済みで、本番相当の検証は未実施 | 中 |

## 7. 対応優先順位（推奨）

> **2026-09-23 進捗**: S-3（PSR-4移行）完了。BUG-1（Infos 追加バグ）、BUG-4（記述式保存失敗）を修正。C-5（uploads/files 統一）完了。D-3 は Bootstrap 3.3.5 確認済み（現状維持方針）。D-5（i18n）、T-4（統合テスト）、T-5（utf8mb4）、T-1（Controller/API テスト全カバー）、T-2（テストスキーマ構築を Migrator に一本化）も完了。テストは 342 tests / 1590 assertions で全パス。B-1 は対応不要（update.sql の該当行は既にコメントアウト済み）。D-2/D-4 は現状維持（機能上問題なし）。残るは B-2（設定値確認）, B-3（本番データ突合）, B-4（SHA1互換検証）, D-1/D-6（整理・設計書追記）。B-2〜B-4 は本番環境の DB・データが必要なため開発環境では実行不可。

1. ~~**S-1**（画像アップロード無検証）・**S-4**（`test_pi.php` 許可）~~ — ✅対応済。
2. ~~**G-1**（コース検索）~~ ✅対応済。**B-3**（本番データ突合） — 機能・移行成立に直結。**未対応**。
3. ~~**G-2〜G-5**（グループ/コース API の CRUD 欠落）~~ ✅対応済（実装）。G-6〜G-8 は設計に要求なしと判断し対応不要。
4. ~~**S-2**（CSV 数式インジェクション）・**T-1**（Controller/API テスト）~~ ✅対応済（T-1 は主要コントローラをカバー、未カバー分は残）。**T-4**（統合テスト）は未対応。
5. ~~**S-3**（`Vendor/Utils.php` PSR-4 移行）~~ ✅対応済。~~**D-3**（bootstrap-ui）~~ ↩対応不要（現状維持）。~~**D-5**（i18n 翻訳）~~ ✅対応済。
6. ~~**T-2**（Fixture 相当）~~ ✅対応済（Migrator へ一本化）。**D-1・D-2・D-4・D-6** — 整理・設計書追記。**B-2・B-3・B-4** — 本番環境での検証（開発環境では実行不可、申し送り）。

### 残課題サマリ（2026-09-23 時点）

| 分類 | 対応済 | 未対応 |
|---|---|---|
| 機能欠落 (G) | G-1, G-2, G-3, G-4, G-5, G-9 | — （G-6〜G-8 は対応不要） |
| セキュリティ (S) | S-1, S-2, S-3, S-4 | — |
| 設計未準拠 (D) | D-3, D-5, D-7 | D-1, D-2（現状維持）, D-4（現状維持）, D-6（設計書追記） |
| コード整理 (C) | C-1, C-2, C-3, C-4, C-5, C-6 | — |
| テスト基盤 (T) | T-1, T-2, T-3, T-4, T-5 | — |
| データ整合性 (B) | B-1（実害なし確認） | B-2, B-3, B-4 |
`