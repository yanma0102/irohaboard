`# 開発漏れ一覧（`Docs/test/` 格子調査で検出した実装乖離）

| 項目 | 内容 |
|---|---|
| 作成日 | 2026-09-21 |
| 対象 | CakePHP 2.10 → 5.4 移行後の iroha Board |
| 基準 | `Docs/design/*`・`Docs/API.md` の設計に対し、`src/`・`config/`・`templates/`・`tests/` の実装が不足または相違する箇所 |
| 根拠 | `Docs/test/traceability.md` §4 Migration Gap List、各試験ファイルの「設計参照」列、および 2026-09-21 時点の実コード確認 |
| 重大度 | **高** = 機能欠落・セキュリティ欠陥 / **中** = 設計未準拠（動作はする） / **低** = 保守性・整理 |

---

## 1. 機能欠落（設計・レガシーにあるが実装がない）

| # | 漏れ内容 | 根拠（設計/レガシー） | 実装の現状 | 影響 | 重大度 |
|---|---|---|---|---|---|
| G-1 | コース検索機能（管理画面） | `Docs/design/12-implementation-test-plan.md` T2-3「コース検索（管理者）」、レガシーは `Plugin/Search`（PrgComponent/SearchableBehavior） | `src/Controller/Admin/CoursesController.php` に検索アクションなし（index/add/edit/delete/order のみ）。`src/` に Search 相当の実装なし | 管理画面でコースを絞り込めない。運用で多数のコースを扱う場合に影響 | 高 |
| G-2 | グループ作成 API | `Docs/design/08-rest-api.md` A10 `POST /api/v1/groups` | `config/routes.php` にルートなし。`Api/GroupsController` に add アクションなし | API からグループを作成できない | 中 |
| G-3 | グループ更新 API | `08-rest-api.md` A12 `PUT /api/v1/groups/:id` | ルートなし・アクションなし | API からグループを更新できない | 中 |
| G-4 | グループ削除 API | `08-rest-api.md` A13 `DELETE /api/v1/groups/:id` | ルートなし・アクションなし（catch-all で 404） | API からグループを削除できない | 中 |
| G-5 | コース更新 API | `08-rest-api.md` A18 `PUT /api/v1/courses/:id` | ルートなし。`Api/CoursesController` は index/view/add/delete のみ | API からコースを更新できない | 中 |
| G-6 | フロント `CoursesController` | `Docs/design/07-controllers.md` §2.2（受講者用コース操作） | `src/Controller/CoursesController.php` は `initialize()` のみでアクション未実装 | 設計上の受講者向けコース操作が提供されない | 中 |
| G-7 | フロント `GroupsController` | `07-controllers.md` §2.4 | `initialize()` のみでアクション未実装 | 受講者向けグループ表示が提供されない | 中 |
| G-8 | フロント `SettingsController` | `07-controllers.md` §2.6 | クラス定義のみでアクション未実装 | 受講者向け設定表示が提供されない | 中 |
| G-9 | `Plugin/Search` 相当の検索基盤 | `06-routing.md`・レガシー `Plugin/Search` | 代替プラグイン未導入。`Admin/RecordsController` のみ手書きクエリで代替（46行目コメント「Search.Prg に代わり…」） | 設計で規定された検索機構が存在しない（G-1 以外の一覧画面も同様の手書き対応が必要） | 中 |

## 2. セキュリティ上の欠落（設計 `05-security.md` との乖離）

| # | 漏れ内容 | 根拠 | 実装の現状 | 影響 | 重大度 |
|---|---|---|---|---|---|
| S-1 | 画像アップロードの拡張子検証 | `05-security.md`（アップロード制限）、`09-views.md`（Summernote 画像アップロード） | `src/Controller/Admin/ContentsController.php::uploadImage()` に拡張子・サイズ検証が一切ない（`upload()` は22種を検証）。任意拡張子を `ROOT/files/` に `moveTo` し、`/contents/file_image/{name}` で配信 | 任意ファイル配置・コンテンツ種別偽装のリスク | 高 |
| S-2 | ユーザー CSV の数式インジェクション対策 | 一般的なCSV 出力要件、内部実装の非対称 | 対策は `Admin/RecordsController.php:242` の詳細CSVのみ。`Admin/UsersController.php:574-631`・`src/Controller/UsersController.php:182-239` には対策なし | CSV を Excel で開いた際に数式が実行されるリスク | 中 |
| S-3 | `Vendor/Utils.php` の未移行依存（サプライチェーン汚染） | `03-orm-migration.md`・`12-implementation-test-plan.md`（レガシー排除） | グローバルクラス `Utils` を `composer.json` の `classmap: ["Vendor/"]` で autoload し、`src/Controller/Admin/UsersController.php`・`Admin/RecordsController.php` の**10箇所**で使用 | 名前空間なしレガシーコードが残存。将来の改修・脆弱性対応が困難 | 中 |
| S-4 | `.htaccess` の `test_pi.php` 許可 | `webroot/.htaccess` セキュリティ規則 | 本番用として `test_pi.php` が許可拡張子・ファイルに含まれる（開発用残骸の疑い） | 情報漏えいリスク | 中 |

## 3. 設計未準拠（動作はするが設計と異なる）

| # | 内容 | 根拠 | 実装の現状 | 重大度 |
|---|---|---|---|---|
| D-1 | API エラーハンドリング方式 | `08-rest-api.md`（`fail()` が Response を返す方式） | `ApiException` + `ApiErrorMiddleware` + `fail(): never` 方式を採用（Phase 5 で実装）。動作は検証済み | 低 |
| D-2 | API 認可の一時回避 | `08-rest-api.md`、`BaseController` | `BaseController::beforeFilter()` が毎回 `allowUnauthenticated` に現在のアクションを追加する実装 | 低 |
| D-3 | `friendsofcake/bootstrap-ui` 未導入 | `09-views.md` §3（導入予定） | `composer.json` に未追加。`webroot/css/bootstrap.min.css` 等の生 Bootstrap 3 と `ib_config.php` の `form_defaults` で代替 | 中 |
| D-4 | セッション保存先 | `11-config-bootstrap.md`、`10-database-migration.md` | `config/app.php` の Session は `defaults => 'php'`（ファイル保存）。`ib_cake_sessions` テーブルは未使用のまま存在 | 低 |
| D-5 | i18n 翻訳ファイル | CakePHP 5 標準、`09-views.md` | `resources/` は `.gitkeep` のみ。`__()` は 100 箇所以上で使用されるが翻訳リソースなし。キー文字列がそのまま表示される | 中 |
| D-6 | API ルートの設計差分 | `08-rest-api.md` A1〜A24 | 実装のみ存在: `PUT\|PATCH /users/:id/password`、`GET /users/:id/courses`、`DELETE /users/:id/courses/:course_id`、`DELETE /groups/:id/users/:user_id`。設計の24に対し実装は28ルート | 低 |
| D-7 | `Api/ErrorsController::notFound` のメッセージ | `Docs/test/03-api.md` API-081 期待値 | 期待は `{"error":{"code":404,"message":"Endpoint not found"}}`。実装のメッセージ文言は要確認 | 低 |

## 4. コード整理・残骸

| # | 内容 | 現状 | 重大度 |
|---|---|---|---|
| C-1 | テンプレートの大小重複ディレクトリ | `templates/Error/` と `templates/error/`、`templates/element/Flash/` と `templates/element/flash/`、`templates/layout/Emails/` と `templates/layout/email/` が併存 | 低 |
| C-2 | `templates/element/Flash/default.php` 不在 | `element/` に Flash 用ファイルがなく、`layout/flash.php` へのフォールバックに依存 | 低 |
| C-3 | `templates/cell/` の未使用確認 | 空または未使用の可能性 | 低 |
| C-4 | `webroot/index_cake2.php` の残置 | CakePHP 2 エントリポイント（211行）が残存。PHP 8.4 では直接アクセス時に Fatal Error | 低 |
| C-5 | `uploads` と `files` の採用揺れ | `Admin/ContentsController` は `ROOT/files` へ保存し `docs/design` は `uploads` を前提とする箇所がある。実装と設計のディレクトリ名の不整合 | 低 |
| C-6 | `Admin/RecordsController` の画面側 JS | `templates/Admin/Records/index.php` の `downloadCSVDetail()` が存在しない `#MembersEventEventId` を参照。実 URL は `?cmd=csv_detail` | 低 |

## 5. テスト・運用基盤の欠落

| # | 内容 | 現状 | 重大度 |
|---|---|---|---|
| T-1 | Controller/API 自動テスト | `tests/TestCase/Controller/` は `PagesControllerTest` のみ。Admin 9・API 7・フロント 5 コントローラは 0 件 | 高 |
| T-2 | Fixture クラス | `tests/Fixture/` は `.gitkeep` のみ。`tests/schema.sql` + SchemaLoader で代替 | 中 |
| T-3 | `config/Migrations/` | ディレクトリ自体が存在しない。`bin/cake migrations` でのスキーマ管理不可 | 中 |
| T-4 | 統合/E2E テスト | 0 件。回帰検知は手動試験に依存 | 高 |
| T-5 | `tests/schema.sql` の文字コード | 全 16 テーブルが `CHARSET=utf8`（旧 utf8mb3）。本番は utf8mb4 済み | 中 |

## 6. データ整合性（DB 移行の申し送り）

| # | 内容 | 根拠 | 現状 | 重大度 |
|---|---|---|---|---|
| B-1 | `ib_records.group_id` のカラム不存在 | `Config/Schema/update.sql`（`update.sql` 側） | テーブルに `group_id` カラムは実在しないが、旧 SQL にインデックス定義が残存 | 低 |
| B-2 | `ib_settings` の `title` 値 | `10-database-migration.md` | 以前 `updated_1789960516` に破損。現在は旧値 `eラーニングシステム` に修復済み。移行後環境でも同値を確認する必要 | 低 |
| B-3 | 移行前後データの未突合 | `12-implementation-test-plan.md` Phase 5・Q1-Q2 | 画面用実データが新旧DBとも空（ib_contents/records/infos=0、courses=1、groups=2、users=admin 1件）。本番データでの突合は未実施 | 高 |
| B-4 | 旧 SHA1 パスワード互換の検証実績 | `05-security.md` §3.2 | 一時ユーザで検証済み（申請者・APIいずれも成功し bcrypt へ自動更新）。ただしテストユーザは削除済みで、本番相当の検証は未実施 | 中 |

## 7. 対応優先順位（推奨）

1. **S-1**（画像アップロード無検証）・**S-4**（`test_pi.php` 許可） — セキュリティ。即時。
2. **G-1**（コース検索）・**B-3**（本番データ突合） — 機能・移行成立に直結。
3. **G-2〜G-5**（グループ/コース API の CRUD 欠落） — 設計契約違反。実装 or 設計側の改訂の決定が必要。
4. **S-2**（CSV 数式インジェクション）・**T-1/T-4**（Controller/API/統合テスト） — 品質基盤。
5. **S-3・D-3・D-5・G-6〜G-9** — 設計準拠と保守性（優先度中）。
6. **C-1〜C-6・D-1・D-2・D-4・D-6・D-7・T-2・T-3・T-5・B-1・B-2** — 整理・運用改善。
`