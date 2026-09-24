# 12 — 実装計画・テスト設計

## 1. 概要

CakePHP 2.10 → 5.x 移行のフェーズ別実装計画、テスト設計、トレーサビリティ、リスク管理、工数見積を定義する。

根拠: `Docs/cakephp5-migration-spec.md:320-390`, `Docs/design/README.md:137-140`

---

## 2. フェーズ別実装計画

### Phase 0: 準備・検証（1–2 週間）

#### 具体的タスク

| # | タスク | 出力物 | 依存 |
|---|---|---|---|
| 0-1 | CakePHP 5.x 新規アプリの雛形作成（`composer create-project cakephp/app`） | プロジェクト骨格 | なし |
| 0-2 | 既存コードの完全バックアップ（git snapshot + DB dump） | バックアップ | なし |
| 0-3 | `update.sql:37` の `ib_records.group_id` 不整合の修正 | 修正済み SQL | なし |
| 0-4 | 手動テストチェックリストの作成（ログイン→コース→コンテンツ→テスト→管理画面） | チェックリスト | なし |
| 0-5 | API エンドポイント一覧と期待応答の文書化（`Docs/API.md` 準拠） | API 仕様書 | なし |
| 0-6 | ロールバック手順の文書化 | ロールバック手順書 | 0-2 |
| 0-7 | Docker 環境の構築（PHP 8.4 + MariaDB 11.4） | Docker 環境 | 0-1 |

#### 完了条件

- 影響範囲リスト・手順書・テストチェックリストが揃う
- CakePHP 5 の骨格が動作する
- Docker 環境で PHP 8.4 + MariaDB 11.4 が起動する

#### ロールバック方針

- Phase 0 は既存コードに変更を加えないため、ロールバック不要
- git snapshot からブランチを戻す

根拠: `Docs/cakephp5-migration-spec.md:322-330`

---

### Phase 1: Config / Bootstrap / Routes 移行（1–2 週間）

#### 具体的タスク

| # | タスク | 出力物 | 依存 |
|---|---|---|---|
| 1-1 | `config/app.php` 作成（`core.php` の設定を移行） | `config/app.php` | 0-1 |
| 1-2 | `src/Application.php` 作成（`bootstrap.php` の処理を移行） | `src/Application.php` | 1-1 |
| 1-3 | `config/routes.php` 作成（scoped routes 化） | `config/routes.php` | 1-1 |
| 1-4 | `ib_config.php` の読み込み機構移行 | `config/ib_config.php` | 1-2 |
| 1-5 | Custom ディレクトリのオーバーライド機構を autoload + classmap で再実装 | `composer.json` | 1-2 |
| 1-6 | Middleware の設定（CsrfProtection, FormProtection, Session, Routing） | `src/Application.php` | 1-2 |
| 1-7 | DebugKit の環境別ロード設定 | `config/app.php` | 1-1 |

#### 完了条件

- 設定・ルーティング・プラグインロードが動作
- `bin/cake server` で起動可能
- CSRF / FormProtection の基本動作を確認

#### ロールバック方針

- Phase 1 の変更は CakePHP 5 の新規プロジェクトにのみ適用
- 現行コードには変更を加えないため、ブランチ切替でロールバック可能

根拠: `Docs/cakephp5-migration-spec.md:332-341`, `Docs/design/01-system-architecture.md:137-175`

---

### Phase 2: Model / ORM 移行（2–3 週間）

#### 具体的タスク

| # | タスク | 出力物 | 依存 |
|---|---|---|---|
| 2-1 | AppTable の作成（プレフィックスロジック） | `src/Model/Table/AppTable.php` | 1-1 |
| 2-2 | 各モデルの Table / Entity 化（16 テーブル） | `src/Model/Table/*.php` | 2-1 |
| 2-3 | アソシエーション宣言をメソッド呼び出しへ変更 | 各 Table クラス | 2-2 |
| 2-4 | `find()` チェーン廃止、QueryBuilder 移行 | 各 Table クラス | 2-3 |
| 2-5 | raw SQL 16 箇所の移行・修正 | 各 Table クラス | 2-4 |
| 2-6 | GROUP BY 7 箇所の `ONLY_FULL_GROUP_BY` 対応 | SQL / Table クラス | 2-5 |
| 2-7 | バリデーションルールの移行 | 各 Table クラス | 2-2 |
| 2-8 | Search 検索ロジックの自作化（User / Record） | Table クラス | 2-4 |
| 2-9 | setOrder 系の更新クエリを Query オブジェクトに置換 | Table クラス | 2-4 |

#### 完了条件

- 全クエリ・保存・削除・検索が正常動作
- raw SQL の実行結果が移行前と一致
- GROUP BY の修正が MariaDB 11.4 でエラーなし

#### ロールバック方針

- 現行の Model ファイルは変更しないため、Phase 0 のバックアップから復元可能
- Phase 2 の出力は `src/Model/Table/` に配置するため、ディレクトリ削除でロールバック

根拠: `Docs/cakephp5-migration-spec.md:343-352`, `Docs/design/03-orm-migration.md`（該当する場合）

---

### Phase 3: Controller / API 移行（2–3 週間）

#### 具体的タスク

| # | タスク | 出力物 | 依存 |
|---|---|---|---|
| 3-1 | AppController の認証移行（Auth → Authentication） | `src/Controller/AppController.php` | 1-2 |
| 3-2 | 管理画面 Controller の `request->data` → `getData()` 等の置換 | 各 Controller | 3-1 |
| 3-3 | API Controller の移行（ClassRegistry → TableRegistry, Response API） | `src/Controller/Api/*.php` | 3-1 |
| 3-4 | FormToken / AppSecurityComponent 削除 + FormProtectionMiddleware 導入 | `src/Middleware/` | 1-2 |
| 3-5 | admin プレフィクス URL 変更への対応 | `config/routes.php` | 3-2 |
| 3-6 | Cookie → CookieMiddleware / CookieAuthenticator 変更 | `src/Middleware/` | 3-1 |
| 3-7 | Flash の設定変更（`$this->Flash->error()` 等） | 各 Controller | 3-1 |

#### 完了条件

- 全 CRUD・認証フロー・API が動作
- ログイン→ログアウトフローが正常
- API の Bearer 認証が動作
- CSRF / FormProtection がフォーム送信を保護

#### ロールバック方針

- 現行の Controller ファイルは変更しないため、Phase 0 のバックアップから復元可能
- Phase 3 の出力は `src/Controller/` に配置するため、ディレクトリ削除でロールバック

根拠: `Docs/cakephp5-migration-spec.md:354-361`, `Docs/design/04-authentication.md`, `Docs/design/05-security.md`

---

### Phase 4: View / テンプレート移行（1–2 週間）

#### 具体的タスク

| # | タスク | 出力物 | 依存 |
|---|---|---|---|
| 4-1 | `.ctp` → `.php` リネーム（51 ファイル） | `templates/**/*.php` | 1-1 |
| 4-2 | `View/` → `templates/` ディレクトリ変更 | ディレクトリ構成 | 4-1 |
| 4-3 | BoostCake → bootstrap-ui ヘルパーへ変更 | ヘルパー登録 | 1-1 |
| 4-4 | `$this->Form->input()` → `$this->Form->control()` 変更 | テンプレート | 4-2 |
| 4-5 | Session ヘルパー → Flash 移行 | テンプレート | 4-2 |
| 4-6 | admin プレフィクス URL 変更への対応 | テンプレート | 3-5 |
| 4-7 | AppView → AppViewHelper へのメソッド移行 | `src/View/Helper/AppViewHelper.php` | 1-1 |
| 4-8 | AppFormHelper のカスタムタグ統合 | `src/View/Helper/AppFormHelper.php` | 4-3 |
| 4-9 | ビジネスロジックの Helper への分離 | `src/View/Helper/*.php` | 4-2 |
| 4-10 | `$this->Html->url()` → `$this->Url->build()` 変更 | テンプレート | 4-2 |
| 4-11 | `Router::url()` → `$this->Url->build()` 変更 | テンプレート | 4-2 |
| 4-12 | `__d('cake', ...)` → `__()` 変更 | エラーテンプレート | 4-2 |

#### 完了条件

- 全画面の描画・フォーム送信が動作
- ブラウザで全テンプレートが表示される
- フォーム送信が正常に動作する

#### ロールバック方針

- 現行の View ファイルは変更しないため、Phase 0 のバックアップから復元可能
- Phase 4 の出力は `templates/` に配置するため、ディレクトリ削除でロールバック

根拠: `Docs/cakephp5-migration-spec.md:363-371`, `Docs/design/09-views.md`

---

### Phase 5: DB 移行・テスト（1–2 週間）

#### 具体的タスク

| # | タスク | 出力物 | 依存 |
|---|---|---|---|
| 5-1 | MariaDB 11.4 へのデータ移行（mariadb-dump → リストア） | 移行済み DB | 0-2, 0-3 |
| 5-2 | utf8mb3 → utf8mb4 変換（全 16 テーブル） | 変換済み DB | 5-1 |
| 5-3 | GROUP BY 7 箇所の `ONLY_FULL_GROUP_BY` 対応 | SQL 修正 | 5-1 |
| 5-4 | MariaDB 11.4 への認証方式の確認（MariaDB では `caching_sha2_password` が不要、既定の `unix_socket` / PDO `mysql_native_password` で動作） | DB 設定 | 5-1 |
| 5-5 | 全画面回帰テスト（手動テストチェックリスト準拠） | テスト結果 | 4-12 |
| 5-6 | API 回帰テスト（24 エンドポイント全般） | テスト結果 | 3-3 |
| 5-7 | データ整合性の確認（移行前後でデータ照合） | テスト結果 | 5-2 |

#### 完了条件

- テストチェックリスト全項目パス
- 移行前後でデータ整合が確認される
- API の全エンドポイントが正常動作

#### ロールバック方針

- MariaDB 11.4 のコンテナを起動し、Step 1 のバックアップからリストア
- 詳細は `Docs/design/10-database-migration.md:8` 参照

根拠: `Docs/cakephp5-migration-spec.md:373-380`

---

### Phase 6: 安定化・デプロイ（1 週間）

#### 具体的タスク

| # | タスク | 出力物 | 依存 |
|---|---|---|---|
| 6-1 | Dockerfile / compose 変更（`php:8.4-apache` + `mariadb:11.4`） | Docker 設定 | 5-1 |
| 6-2 | DebugKit の環境別ロード確認（本番無効） | 設定確認 | 1-7 |
| 6-3 | セキュリティヘッダー・ログ・監視の確認 | 確認結果 | 6-1 |
| 6-4 | 本番デプロイ | デプロイ完了 | 6-1 |
| 6-5 | デプロイ後の動作確認 | 確認結果 | 6-4 |
| 6-6 | README の更新（動作環境・方針の更新） | README | 6-4 |

#### 完了条件

- 本番環境で全機能動作
- セキュリティヘッダーが正常
- ログが正常に出力される

#### ロールバック方針

- Docker コンテナの旧バージョンへの切替
- DB のロールバック手順（Phase 5 のロールバック手順に従う）

根拠: `Docs/cakephp5-migration-spec.md:382-389`

---

## 3. テスト設計

### 3.1 テスト戦略

| テスト種別 | 対象 | 方法 | 優先度 |
|---|---|---|---|
| **手動テスト** | 全画面フロー | ブラウザでの操作確認 | 最優先 |
| **PHPUnit** | Model / Table クラス | ユニットテスト | 優先 |
| **API テスト** | 24 エンドポイント | curl / Postman | 優先 |

> **注**: 現行にテストコードが無いため、移行回帰テストの新規作成が必須（`Docs/cakephp5-migration-spec.md:316`）。

### 3.2 主要フローのテストケース一覧

#### テストフロー 1: ログインフロー

| # | テストケース | 操作 | 期待結果 |
|---|---|---|---|
| T1-1 | 受講者ログイン成功 | ログインID + パスワードでログイン | コース一覧画面が表示される |
| T1-2 | 受講者ログイン失敗（パスワード間違い） | 不正なパスワードでログイン | エラーメッセージが表示される |
| T1-3 | 管理者ログイン成功 | 管理者ログイン画面でログイン | 管理画面が表示される |
| T1-4 | ログアウト | ログアウトボタンをクリック | ログイン画面に遷移する |
| T1-5 | RememberMe | ログイン状態を保持にチェックしてログイン | ブラウザを閉じてもセッションが維持される |
| T1-6 | HTTPS でのログイン | HTTPS でアクセスしてログイン | RememberMe チェックボックスが表示される |

#### テストフロー 2: コース関連

| # | テストケース | 操作 | 期待結果 |
|---|---|---|---|
| T2-1 | コース一覧表示 | 受講者画面でコース一覧を表示 | 自分が受講するコースが表示される |
| T2-2 | コース詳細表示 | コース名をクリック | コンテンツ一覧が表示される |
| T2-3 | コース検索（管理者） | 管理画面でコースを検索 | 検索結果が表示される |
| T2-4 | コース追加（管理者） | 管理画面でコースを追加 | 新規コースが追加される |
| T2-5 | コース編集（管理者） | 管理画面でコースを編集 | コース情報が更新される |
| T2-6 | コース削除（管理者） | 管理画面でコースを削除 | コースが削除される |
| T2-7 | コース並べ替え（管理者） | ドラッグ＆ドロップで並べ替え | 並べ替えが反映される |

#### テストフロー 3: コンテンツ関連

| # | テストケース | 操作 | 期待結果 |
|---|---|---|---|
| T3-1 | コンテンツ一覧表示 | コース内のコンテンツ一覧を表示 | 学習履歴付きで表示される |
| T3-2 | 学習コンテンツ表示 | 学習コンテンツをクリック | コンテンツが表示される |
| T3-3 | テストコンテンツ表示 | テストをクリック | 問題一覧が表示される |
| T3-4 | テスト採点 | 採点ボタンをクリック | 採点結果が表示される |
| T3-5 | アンケート回答 | アンケートに回答して送信 | 回答が保存される |
| T3-6 | ファイルダウンロード | 配布資料をクリック | ファイルがダウンロードされる |
| T3-7 | URL コンテンツ表示 | URL コンテンツをクリック | iframe で URL が表示される |
| T3-8 | 動画コンテンツ表示 | 動画コンテンツをクリック | 動画プレーヤーが表示される |
| T3-9 | テキストコンテンツ表示 | テキストコンテンツをクリック | テキストが表示される |
| T3-10 | HTML コンテンツ表示 | リッチテキストコンテンツをクリック | HTML が表示される |
| T3-11 | 理解度の記録 | 理解度ボタンをクリック | 理解度が保存される |

#### テストフロー 4: 管理画面

| # | テストケース | 操作 | 期待結果 |
|---|---|---|---|
| T4-1 | ユーザ一覧表示 | 管理画面でユーザ一覧を表示 | ユーザ一覧が表示される |
| T4-2 | ユーザ追加 | ユーザを追加 | 新規ユーザが追加される |
| T4-3 | ユーザ編集 | ユーザを編集 | ユーザ情報が更新される |
| T4-4 | ユーザ削除 | ユーザを削除 | ユーザが削除される |
| T4-5 | ユーザインポート | CSV をインポート | ユーザが一括追加される |
| T4-6 | ユーザエクスポート | CSV をエクスポート | CSV がダウンロードされる |
| T4-7 | グループ管理 | グループの追加/編集/削除 | 各操作が正常に動作する |
| T4-8 | システム設定 | システム設定を変更 | 設定が反映される |
| T4-9 | 学習履歴一覧 | 学習履歴を表示 | 履歴が表示される |
| T4-10 | 学習履歴 CSV 出力 | CSV 出力ボタンをクリック | CSV がダウンロードされる |
| T4-11 | お知らせ管理 | お知らせの追加/編集/削除 | 各操作が正常に動作する |

#### テストフロー 5: テスト関連

| # | テストケース | 操作 | 期待結果 |
|---|---|---|---|
| T5-1 | 問題一覧表示 | 管理画面で問題一覧を表示 | 問題一覧が表示される |
| T5-2 | 問題追加 | 問題を追加 | 新規問題が追加される |
| T5-3 | 問題編集 | 問題を編集 | 問題情報が更新される |
| T5-4 | 問題削除 | 問題を削除 | 問題が削除される |
| T5-5 | 問題並べ替え | ドラッグ＆ドロップで並べ替え | 並べ替えが反映される |
| T5-6 | テスト結果表示 | テスト結果を表示 | 合否・得点・解説が表示される |

### 3.3 API 回帰テスト（24 エンドポイント）

| # | エンドポイント | メソッド | テスト内容 |
|---|---|---|---|
| A1 | `POST /api/v1/auth/token` | POST | トークン発行 |
| A2 | `DELETE /api/v1/auth/token` | DELETE | トークン失効 |
| A3 | `GET /api/v1/users` | GET | ユーザ一覧取得 |
| A4 | `POST /api/v1/users` | POST | ユーザ作成 |
| A5 | `GET /api/v1/users/:id` | GET | ユーザ詳細取得 |
| A6 | `PUT /api/v1/users/:id` | PUT | ユーザ更新 |
| A7 | `DELETE /api/v1/users/:id` | DELETE | ユーザ削除 |
| A8 | `POST /api/v1/users/:id/courses` | POST | コース割当 |
| A9 | `GET /api/v1/groups` | GET | グループ一覧取得 |
| A10 | `POST /api/v1/groups` | POST | グループ作成 |
| A11 | `GET /api/v1/groups/:id` | GET | グループ詳細取得 |
| A12 | `PUT /api/v1/groups/:id` | PUT | グループ更新 |
| A13 | `DELETE /api/v1/groups/:id` | DELETE | グループ削除 |
| A14 | `POST /api/v1/groups/:id/users` | POST | ユーザ割当 |
| A15 | `GET /api/v1/courses` | GET | コース一覧取得 |
| A16 | `POST /api/v1/courses` | POST | コース作成 |
| A17 | `GET /api/v1/courses/:id` | GET | コース詳細取得 |
| A18 | `PUT /api/v1/courses/:id` | PUT | コース更新 |
| A19 | `DELETE /api/v1/courses/:id` | DELETE | コース削除 |
| A20 | `GET /api/v1/contents` | GET | コンテンツ一覧取得 |
| A21 | `GET /api/v1/contents/:id` | GET | コンテンツ詳細取得 |
| A22 | `GET /api/v1/records` | GET | 学習履歴一覧取得 |
| A23 | `GET /api/v1/records/:id` | GET | 学習履歴詳細取得 |
| A24 | `GET /api/v1/errors` | GET | 404 フォールバック |

#### API テストの検証項目

| # | 検証項目 | 内容 |
|---|---|---|
| V1 | レスポンス形式 | `{ data: ... }` / `{ data: [...], meta: {page,limit,total,count} }` / `{ error: {code,message,errors?} }` |
| V2 | ステータスコード | 200/201/400/401/403/404/429/500 |
| V3 | Bearer 認証 | トークンなしで 401 が返る |
| V4 | ページング | `meta` の `page`, `limit`, `total`, `count` が正しい |
| V5 | エラーハンドリング | 不正リクエストで 400 エラーが返る |

根拠: `Docs/API.md`, `Docs/design/README.md:132-134`

### 3.4 MariaDB 11.4 での要検証クエリ

移行元（CakePHP 2.x）に存在する raw SQL うち、MariaDB 11.4 で `ONLY_FULL_GROUP_BY` 等の挙動が異なる可能性がある箇所。Phase 2 完了時に個別検証が必須。

| # | ファイル | 行番号 | 概要 | 検証内容 |
|---|---|---|---|---|
| Q1 | `Model/Info.php` | 119 | `GROUP BY Info.id`（SELECT に集約関数なし） | MariaDB 11.4 の `ONLY_FULL_GROUP_BY` モードでエラーとならないか、正しい結果が返るか |
| Q2 | `Model/Content.php` | 151 | `GROUP BY r.content_id`（SELECT に集約関数なし） | MariaDB 11.4 の `ONLY_FULL_GROUP_BY` モードでエラーとならないか、正しい結果が返るか |

> **補足**: 上記クエリは `SELECT` 列が `GROUP BY` 列に包含されていないため、`ONLY_FULL_GROUP_BY` 有効時に警告/エラーとなる可能性がある。MariaDB 11.4 では既定で `ONLY_FULL_GROUP_BY` が有効（MySQL 8.4 と同等）であるため、修正が必要な場合は `ONLY_FULL_GROUP_BY` の除外対象列を明示する、またはクエリを改修する。

---

## 4. トレーサビリティ

移行仕様書のフェーズと詳細設計ドキュメントの対応マトリクス。

### 4.1 仕様書フェーズ → 詳細設計ドキュメント

| 仕様書フェーズ | 詳細設計ドキュメント | 内容 |
|---|---|---|
| Phase 0: 準備・検証 | `01-system-architecture.md` | システム構成・ディレクトリ・Docker |
| Phase 1: Config/Bootstrap/Routes | `01-system-architecture.md` | Composer・autoload・環境変数 |
| Phase 1: Config/Bootstrap/Routes | `11-config-bootstrap.md` | config/app.php・Application.php |
| Phase 2: Model/ORM | `02-domain-model.md` | ドメインモデル・Table/Entity |
| Phase 2: Model/ORM | `03-orm-migration.md` | ORM 移行・raw SQL |
| Phase 3: Controller/API | `04-authentication.md` | 認証・認可 |
| Phase 3: Controller/API | `05-security.md` | セキュリティ |
| Phase 3: Controller/API | `06-routing.md` | ルーティング |
| Phase 3: Controller/API | `07-controllers.md` | Controller 設計 |
| Phase 3: Controller/API | `08-rest-api.md` | REST API |
| Phase 4: View | `09-views.md` | View 設計 |
| Phase 5: DB 移行 | `10-database-migration.md` | DB 移行設計 |
| Phase 6: デプロイ | `01-system-architecture.md` | Docker・Apache |

### 4.2 詳細設計ドキュメント → 仕様書セクション

| 詳細設計ドキュメント | 仕様書セクション | 関連フェーズ |
|---|---|---|
| `01-system-architecture.md` | §3（移行先ターゲット）、§5（移行対象の全体像） | Phase 0, 1, 6 |
| `02-domain-model.md` | §5.3（ORM 移行の詳細） | Phase 2 |
| `03-orm-migration.md` | §5.3（ORM 移行の詳細）、§6.3（ORM 移行の詳細） | Phase 2 |
| `04-authentication.md` | §5.3（認証・認可・セキュリティの現状）、§6.4（API 層の移行） | Phase 3 |
| `05-security.md` | §5.3（認証・認可・セキュリティの現状） | Phase 3 |
| `06-routing.md` | §6.4（API 層の移行） | Phase 3 |
| `07-controllers.md` | §5.2（Controller の分類） | Phase 3 |
| `08-rest-api.md` | §6.4（API 層の移行） | Phase 3 |
| `09-views.md` | §6.5（View 層の移行） | Phase 4 |
| `10-database-migration.md` | §6.2（設計やり直しが必要な領域） | Phase 5 |
| `11-config-bootstrap.md` | §6.6（Config / 起動の移行） | Phase 1 |

---

## 5. リスクリジェスター

仕様書の 10 リスクと各フェーズでの検知方法・緩和策。

| # | リスク | 影響 | 確率 | 検知フェーズ | 検知方法 | 緩和策 |
|---|---|---|---|---|---|---|
| R1 | ORM 全面書き換えで集計・サブクエリ結果が変わる | 高 | 中 | Phase 2 | raw SQL の実行結果を MariaDB 11.4 で突合 | 移行前後の SQL 結果を照合 |
| R2 | Auth 移行でログインフローが壊れる | 高 | 中 | Phase 3 | ログイン→ログアウトフローの手動テスト | Phase 1 で認証フローを先行移植・検証 |
| R3 | Custom ディレクトリのオーバーライド機構が動作しない | 中 | 中 | Phase 1 | autoload + classmap の動作検証 | Phase 1 で検証 |
| R4 | MySQL 8.4 の `caching_sha2_password` で接続失敗 | 低 | 低 | Phase 5 | MariaDB 11.4 では `caching_sha2_password` が存在しないため該当リスクなし。既定認証（`unix_socket` / `mysql_native_password`）で接続テスト | MariaDB 11.4 では認証方式対応が不要 |
| R5 | GROUP BY 修正で集計結果が変わる | 高 | 低 | Phase 5 | GROUP BY 修正前後の結果を突合 | 修正前後で結果を照合 |
| R6 | REST API v1 の互換性が壊れる | 高 | 低 | Phase 5 | API 回帰テスト（24 エンドポイント） | `Docs/API.md` 準拠の回帰テスト |
| R7 | admin プレフィクス URL 変更で既存リンクが壊れる | 中 | 高 | Phase 3 | URL の動作確認 | 旧 URL からのリダイレクト |
| R8 | FormToken の動作が変わる | 中 | 低 | Phase 4 | フォーム送信の全画面テスト | フォーム送信の全画面テスト |
| R9 | `ib_records.group_id` の不整合が顕在化 | 中 | 高 | Phase 0 | update.sql の適用テスト | Phase 0 でスキーマ修正 |
| R10 | BoostCake → bootstrap-ui の CSS クラス不整合 | 中 | 中 | Phase 4 | テンプレート毎のビジュアル確認 | テンプレート毎のビジュアル確認 |

---

## 6. 工数見積

### 6.1 フェーズ別工数

| フェーズ | 工数（人日） | 内訳 |
|---|---|---|
| Phase 0: 準備・検証 | 3–5 | 環境構築（2）、テストチェックリスト作成（1–3） |
| Phase 1: Config/Bootstrap/Routes | 3–5 | app.php（1）、Application.php（1）、routes.php（1）、ib_config（0.5）、autoload（0.5） |
| Phase 2: Model/ORM | 10–14 | Table/Entity 化（5–7）、raw SQL 移行（3–5）、GROUP BY 修正（1）、検索ロジック（1） |
| Phase 3: Controller/API | 8–12 | Auth 移行（3–4）、Controller 置換（3–5）、API 移行（2–3） |
| Phase 4: View | 5–8 | リネーム（0.5）、ヘルパー修正（2–3）、テンプレート修正（2–4） |
| Phase 5: DB 移行・テスト | 5–8 | DB 移行（2）、utf8mb4（1）、回帰テスト（2–5） |
| Phase 6: 安定化・デプロイ | 2–3 | Docker（1）、デプロイ（1）、確認（0.5） |
| **合計** | **36–55** | |

### 6.2 レイヤー別工数

| レイヤー | 工数（人日） | 内訳 |
|---|---|---|
| Backend Controller（管理画面 10 + API 8 + 共通 1） | 10–15 | 機械的変換 + Auth/Security 再設計 |
| Data（Model 16 + AppModel + raw SQL） | 5–7 | ORM 全面書き換え + SQL 修正 |
| View（.ctp 51 + Helper 3） | 5–7 | リネーム + ヘルパー修正 + BoostCake 置換 |
| Config / Routing / Bootstrap | 3–5 | 全面書き換え |
| DB 移行（utf8mb4 + MariaDB 11.4） | 1.5–2.5 | GROUP BY 修正 + 文字セット変換 |
| テスト / 検証 | 5–7 | 全画面回帰 + API + CSRF 動作確認 |
| Docker / デプロイ | 1–2 | Dockerfile / compose / Apache 設定 |
| **合計** | **31–46** | |

> 注: 仕様書（`Docs/cakephp5-migration-spec.md:303-314`）の 32–48 人日と概ね一致。

### 6.3 要員構成案

| 方案 | 体制 | 期間 | 特徴 |
|---|---|---|---|
| **A: 1 名** | エンジニア 1 名 | 6–9 週間 | コストが安いが、長期化リスク |
| **B: 2 名** | エンジニア 2 名 | 4–5 週間 | 並行作業が可能。推奨 |
| **C: ハイブリッド** | エンジニア 1 名 + 外部コンサル 1 名 | 3–4 週間 | 知識移転が可能 |

#### 推奨: 方案 B（2 名体制）

| 役割 | 担当フェーズ | 備考 |
|---|---|---|
| エンジニア A | Phase 0–2（準備→Config→Model） | バックエンド中心 |
| エンジニア B | Phase 3–4（Controller→View） | フロントエンド中心 |
| 両名 | Phase 5–6（テスト→デプロイ） | 協力して回帰テスト |

---

## 7. 移行完了条件チェックリスト

| # | チェック項目 | 確認方法 | ステータス |
|---|---|---|---|
| 1 | CakePHP 5 で全画面が表示される | ブラウザで確認 | □ |
| 2 | ログイン→ログアウトフローが動作 | 手動テスト | □ |
| 3 | 全 CRUD 操作が動作 | 手動テスト | □ |
| 4 | フォーム送信が正常（CSRF / FormProtection） | 手動テスト | □ |
| 5 | API の全 24 エンドポイントが動作 | API テスト | □ |
| 6 | データ整合性が確認される | SQL での照合 | □ |
| 7 | MariaDB 11.4 で GROUP BY エラーなし | ログ確認 | □ |
| 8 | utf8mb4 変換が完了 | `SHOW CREATE TABLE` で確認 | □ |
| 9 | Docker 環境が正常に構築される | `docker compose up` | □ |
| 10 | 本番環境で全機能動作 | 本番デプロイ後の確認 | □ |
