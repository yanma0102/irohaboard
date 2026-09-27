# トレーサビリティマトリクス

> **対象**: irohaboard CakePHP 2.10 → 5.4 移行
> **作成日**: 2026-09-21
> **根拠**: `Docs/design/12-implementation-test-plan.md`, `Docs/design/06-routing.md`, `Docs/API.md`, `Docs/design/08-rest-api.md`

---

## 1. 設計ドキュメント → 試験項目 対応表

> 参照: `Docs/design/12-implementation-test-plan.md`

### 1.1 ログインフロー (T1-1〜T1-6)

| 設計ID | 設計内容 | 試験項目ID | 試験ファイル |
|--------|----------|-----------|-------------|
| T1-1 | ログイン画面表示 | AUTH-001 | 04-auth.md |
| T1-2 | 正常ログイン（一般ユーザー） | AUTH-002 | 04-auth.md |
| T1-3 | 正常ログイン（管理者） | AUTH-003 | 04-auth.md |
| T1-4 | 異常ログイン（パスワード誤り） | AUTH-004 | 04-auth.md |
| T1-5 | ログアウト | AUTH-005 | 04-auth.md |
| T1-6 | 未ログインアクセス制御 | AUTH-006 | 04-auth.md |

### 1.2 コース管理 (T2-1〜T2-7)

| 設計ID | 設計内容 | 試験項目ID | 試験ファイル |
|--------|----------|-----------|-------------|
| T2-1 | コース一覧表示 | AD-001 | 02-admin.md |
| T2-2 | コース新規作成 | AD-002 | 02-admin.md |
| T2-3 | コース編集 | AD-003 | 02-admin.md |
| T2-4 | コース削除 | AD-004 | 02-admin.md |
| T2-5 | コース並べ替え | AD-005 | 02-admin.md |
| T2-6 | コース-ユーザー紐付け | AD-006 | 02-admin.md |
| T2-7 | コース-グループ紐付け | AD-007 | 02-admin.md |

### 1.3 コンテンツ管理 (T3-1〜T3-11)

| 設計ID | 設計内容 | 試験項目ID | 試験ファイル |
|--------|----------|-----------|-------------|
| T3-1 | コンテンツ一覧表示 | AD-008 | 02-admin.md |
| T3-2 | コンテンツ新規作成 | AD-009 | 02-admin.md |
| T3-3 | コンテンツ編集 | AD-010 | 02-admin.md |
| T3-4 | コンテンツ削除 | AD-011 | 02-admin.md |
| T3-5 | コンテンツ並べ替え | AD-012 | 02-admin.md |
| T3-6 | コンテンツプレビュー | AD-013 | 02-admin.md |
| T3-7 | ファイルアップロード | AD-014 | 02-admin.md |
| T3-8 | 画像アップロード | AD-015 | 02-admin.md |
| T3-9 | ファイルダウンロード | FR-001 | 01-functional-records.md |
| T3-10 | 動画ファイル再生 | FR-002 | 01-functional-records.md |
| T3-11 | 画像表示 | FR-003 | 01-functional-records.md |

### 1.4 管理画面 (T4-1〜T4-11)

| 設計ID | 設計内容 | 試験項目ID | 試験ファイル |
|--------|----------|-----------|-------------|
| T4-1 | ユーザー一覧表示 | AD-016 | 02-admin.md |
| T4-2 | ユーザー新規作成 | AD-017 | 02-admin.md |
| T4-3 | ユーザー編集 | AD-018 | 02-admin.md |
| T4-4 | ユーザー削除 | AD-019 | 02-admin.md |
| T4-5 | CSVインポート | AD-020 | 02-admin.md |
| T4-6 | CSVエクスポート | AD-021 | 02-admin.md |
| T4-7 | グループ一覧表示 | AD-022 | 02-admin.md |
| T4-8 | グループCRUD | AD-023 | 02-admin.md |
| T4-9 | お知らせCRUD | AD-024 | 02-admin.md |
| T4-10 | 設定管理 | AD-025 | 02-admin.md |
| T4-11 | ログ表示 | AD-026 | 02-admin.md |

### 1.5 フロント画面 (T5-1〜T5-6)

| 設計ID | 設計内容 | 試験項目ID | 試験ファイル |
|--------|----------|-----------|-------------|
| T5-1 | ユーザーコース一覧 | FR-004 | 01-functional-records.md |
| T5-2 | コンテンツ一覧表示 | FR-005 | 01-functional-records.md |
| T5-3 | コンテンツ閲覧 | FR-006 | 01-functional-records.md |
| T5-4 | 問題回答 | FR-007 | 01-functional-records.md |
| T5-5 | アンケート回答 | FR-008 | 01-functional-records.md |
| T5-6 | 学習記録追加 | FR-009 | 01-functional-records.md |

### 1.6 APIエンドポイント (A1〜A26)

| 設計ID | 設計内容 | 試験項目ID | 試験ファイル |
|--------|----------|-----------|-------------|
| A1 | POST /api/v1/auth/token | API-001 | 03-api.md |
| A2 | DELETE /api/v1/auth/token | API-002 | 03-api.md |
| A3 | GET /api/v1/users | API-003 | 03-api.md |
| A4 | POST /api/v1/users | API-004 | 03-api.md |
| A5 | GET /api/v1/users/{id} | API-005 | 03-api.md |
| A6 | PUT /api/v1/users/{id} | API-006 | 03-api.md |
| A7 | PATCH /api/v1/users/{id} | API-007 | 03-api.md |
| A8 | DELETE /api/v1/users/{id} | API-008 | 03-api.md |
| A9 | PUT /api/v1/users/{id}/password | API-009 | 03-api.md |
| A10 | PATCH /api/v1/users/{id}/password | API-010 | 03-api.md |
| A11 | GET /api/v1/users/{id}/courses | API-011 | 03-api.md |
| A12 | POST /api/v1/users/{id}/courses | API-012 | 03-api.md |
| A13 | DELETE /api/v1/users/{id}/courses/{course_id} | API-013 | 03-api.md |
| A14 | GET /api/v1/courses | API-014 | 03-api.md |
| A15 | POST /api/v1/courses | API-015 | 03-api.md |
| A16 | GET /api/v1/courses/{id} | API-016 | 03-api.md |
| A17 | DELETE /api/v1/courses/{id} | API-017 | 03-api.md |
| A18 | GET /api/v1/contents | API-018 | 03-api.md |
| A19 | GET /api/v1/contents/{id} | API-019 | 03-api.md |
| A20 | GET /api/v1/records | API-020 | 03-api.md |
| A21 | GET /api/v1/records/{id} | API-021 | 03-api.md |
| A22 | GET /api/v1/groups | API-022 | 03-api.md |
| A23 | GET /api/v1/groups/{id} | API-023 | 03-api.md |
| A24 | GET/POST/DELETE /api/v1/groups/{id}/users | API-024/025/026 | 03-api.md |

### 1.7 入力バリデーション (V1〜V5)

| 設計ID | 設計内容 | 試験項目ID | 試験ファイル |
|--------|----------|-----------|-------------|
| V1 | ユーザー必須フィールド | VAL-001 | 05-validation.md |
| V2 | ユーザー一意制約 | VAL-002 | 05-validation.md |
| V3 | コース必須フィールド | VAL-003 | 05-validation.md |
| V4 | コンテンツ必須フィールド | VAL-004 | 05-validation.md |
| V5 | API入力バリデーション | VAL-005 | 05-validation.md |

### 1.8 GROUP BY集約クエリ (Q1〜Q2)

| 設計ID | 設計内容 | 試験項目ID | 試験ファイル |
|--------|----------|-----------|-------------|
| Q1 | Info集計クエリ (Info.php:119) | DB-001 | 06-database.md |
| Q2 | Content集計クエリ (Content.php:151) | DB-002 | 06-database.md |

### 1.9 リスク → 非機能回帰項目対応 (R1〜R10)

| 設計ID | リスク内容 | 影響度/確率 | 非機能試験項目ID | 試験ファイル |
|--------|-----------|-----------|----------------|-------------|
| R1 | ORM集約結果の変化 | 高/中 | NON-001, NON-002 | 07-nonfunctional-regression.md |
| R2 | Auth移行によるログインフロー破綻 | 高/中 | NON-003, NON-004 | 07-nonfunctional-regression.md |
| R3 | Customディレクトリオーバーライド機構の破綻 | 中/中 | NON-005 | 07-nonfunctional-regression.md |
| R4 | MySQL 8.4 caching_sha2_password | 低/低 | N/A (MariaDB 11.4) | — |
| R5 | GROUP BY集約結果の変化 | 高/低 | NON-006 | 07-nonfunctional-regression.md |
| R6 | REST API v1互換性破綻 | 高/低 | NON-007 | 07-nonfunctional-regression.md |
| R7 | AdminプレフィクスURL変更で既存リンク破綻 | 中/高 | NON-008, NON-009 | 07-nonfunctional-regression.md |
| R8 | FormToken動作変更 | 中/低 | NON-010 | 07-nonfunctional-regression.md |
| R9 | ib_records.group_id不整合 | 中/高 | NON-011 | 07-nonfunctional-regression.md |
| R10 | BoostCake→bootstrap-ui CSSクラス不整合 | 中/中 | NON-012 | 07-nonfunctional-regression.md |

---

### 1.10 Markdown / Contents API Write / MCP（design 13, Phase 1–3）

| 設計ID | 設計内容 | 試験項目ID | 試験ファイル |
|--------|----------|-----------|-------------|
| 13-§3 | Markdown レンダリング & サニタイズ（kind='markdown'、XSS 除去） | MCP-001〜003 | 03-api.md §11 |
| 13-§4 | Contents API Write（POST / PUT / PATCH / DELETE /api/v1/contents） | 13 付録D-2（ContentsControllerWriteTest） | 03-api.md §5 |
| 13-§5.3 | MCP 認証（Bearer、401、ミドルウェア） | MCP-004〜006 | 03-api.md §11 |
| 13-§5.7 | MCP 権限モデル（コース所属 / staff 書き込み） | MCP-007〜012 | 03-api.md §11 |
| 13-§5.8 | MCP レート制限（read 60/min、write 20/min 分離） | MCP-014〜015 | 03-api.md §11 |
| 13-§6 Phase 3 | EasyMDE エディタ + 画像アップロード + 保存 E2E | 03-api §11 E2E（16 項目） | 03-api.md §11（第2ブロック） |

---

## 2. ルーティング設計 → 試験項目 対応表

> 参照: `Docs/design/06-routing.md`, `config/routes.php`

### 2.1 フロントルート（全明示ルート、`fallbacks()` なし）

| 路 | 設定行 | コントローラ::アクション | 試験項目ID | 期待ステータス |
|----|--------|------------------------|-----------|-------------|
| `GET /` | L24-27 | UsersCourses::index | NON-013 | 200 |
| `GET /users-courses` | L52-55 | UsersCourses::index | NON-013 | 200 |
| `GET /users/login` | L58-61 | Users::login | NON-013 | 200 |
| `GET /users/logout` | L63-65 | Users::logout | NON-013 | 302→/ |
| `GET /users/setting` | L67-69 | Users::setting | NON-013 | 302（未ログイン） |
| `GET /users/index` | L71-73 | Users::index | NON-013 | 302（未ログイン） |
| `GET /contents/index/{course_id}/{user_id}` | L77-79 | Contents::index | NON-013 | 200/302 |
| `GET /contents/index/{course_id}` | L81-83 | Contents::index | NON-013 | 200/302 |
| `GET /contents/view/{content_id}` | L85-87 | Contents::view | NON-013 | 200/302 |
| `GET /contents/preview` | L89-91 | Contents::preview | NON-013 | 200（管理者） |
| `GET /contents/preview/{course_id}` | L93-95 | Contents::preview | NON-013 | 200（管理者） |
| `GET /contents/file-download/{content_id}` | L97-99 | Contents::fileDownload | NON-013 | 200/404 |
| `GET /contents/file-movie/{content_id}` | L101-103 | Contents::fileMovie | NON-013 | 200/404 |
| `GET /contents/file-image/{file_name}` | L105-107 | Contents::fileImage | NON-013 | 200/404 |
| `GET /contents-questions/index/{content_id}/{record_id}` | L111-113 | ContentsQuestions::index | NON-013 | 200/302 |
| `GET /contents-questions/index/{content_id}` | L115-117 | ContentsQuestions::index | NON-013 | 200/302 |
| `GET /contents-questions/record/{content_id}/{record_id}` | L119-121 | ContentsQuestions::record | NON-013 | 200/302 |
| `GET /enquetes-questions/index/{content_id}/{record_id}` | L125-127 | EnquetesQuestions::index | NON-013 | 200/302 |
| `GET /enquetes-questions/index/{content_id}` | L129-131 | EnquetesQuestions::index | NON-013 | 200/302 |
| `GET /enquetes-questions/record/{content_id}/{record_id}` | L133-135 | EnquetesQuestions::record | NON-013 | 200/302 |
| `GET /infos` | L139-141 | Infos::index | NON-013 | 200 |
| `GET /infos/index` | L143-145 | Infos::index | NON-013 | 200 |
| `GET /infos/view/{info_id}` | L147-149 | Infos::view | NON-013 | 200/404 |
| `GET /records/add/{content_id}` | L153-155 | Records::add | NON-013 | 200/302 |
| `GET /install` | L159-161 | Install::index | NON-018 | 200/403 |
| `GET /install/*` | L163-165 | Install::installed | NON-018 | 200/403 |
| `GET /update` | L169-171 | Update::index | NON-018 | 200/403 |
| `GET /update/*` | L173-175 | Update::error | NON-018 | 200/403 |
| `GET /pages/*` | L49-50 | Pages::display | NON-013 | 200/404 |

### 2.2 Adminプレフィクスルート（`fallbacks()` あり）

| 路 | 設定行 | コントローラ::アクション | 試験項目ID | 期待ステータス |
|----|--------|------------------------|-----------|-------------|
| `GET /admin` | L32-34 | Admin/Users::index | NON-008, NON-014 | 302→/admin/users/login |
| `GET /admin/users/login` | L38-40 | Admin/Users::login | NON-008, NON-014 | 200 |
| `GET /admin/{controller}/{action}` (fallbacks) | L44-45 | 各Adminコントローラ | NON-008, NON-014 | 200/302 |

### 2.3 レガシーURL（意図的に削除されたルート）

| レガシー路 | 新ルート | 試験項目ID | 期待結果 |
|-----------|---------|-----------|---------|
| `/users/admin_login` | `/admin/users/login` | NON-009 | 404（レガシー） |
| `/users/admin_index` | `/admin/users/index` | NON-009 | 404（レガシー） |
| `/users/admin_add` | `/admin/users/add` | NON-009 | 404（レガシー） |
| `/users/admin_edit/{id}` | `/admin/users/edit/{id}` | NON-009 | 404（レガシー） |
| `/users/admin_delete/{id}` | `/admin/users/delete/{id}` | NON-009 | 404（レガシー） |
| `/courses/admin_index` | `/admin/courses/index` | NON-009 | 404（レガシー） |
| `/records/admin_index` | `/admin/records/index` | NON-009 | 404（レガシー） |
| `/contents/admin_index` | `/admin/contents/index` | NON-009 | 404（レガシー） |
| `/groups/admin_index` | `/admin/groups/index` | NON-009 | 404（レガシー） |
| `/infos/admin_index` | `/admin/infos/index` | NON-009 | 404（レガシー） |
| `/users_courses/*` | `/users-courses` | NON-009 | 404（レガシー） |

### 2.4 API v1ルート（28エンドポイント + キャッチオール）

| 路 | 設定行 | コントローラ::アクション | 試験項目ID | 期待ステータス |
|----|--------|------------------------|-----------|-------------|
| `POST /api/v1/auth/token` | L183-185 | Api/Auth::token | API-001 | 201 |
| `DELETE /api/v1/auth/token` | L187-189 | Api/Auth::deleteToken | API-002 | 200 |
| `GET /api/v1/users` | L192-194 | Api/Users::index | API-003 | 200 |
| `POST /api/v1/users` | L196-198 | Api/Users::add | API-004 | 201 |
| `GET /api/v1/users/{id}` | L200-202 | Api/Users::view | API-005 | 200 |
| `PUT /api/v1/users/{id}` | L204-206 | Api/Users::edit | API-006 | 200 |
| `PATCH /api/v1/users/{id}` | L208-210 | Api/Users::edit | API-007 | 200 |
| `DELETE /api/v1/users/{id}` | L212-214 | Api/Users::delete | API-008 | 200 |
| `PUT /api/v1/users/{id}/password` | L216-218 | Api/Users::changePassword | API-009 | 200 |
| `PATCH /api/v1/users/{id}/password` | L220-222 | Api/Users::changePassword | API-010 | 200 |
| `GET /api/v1/users/{id}/courses` | L224-226 | Api/Users::courses | API-011 | 200 |
| `POST /api/v1/users/{id}/courses` | L228-230 | Api/Users::addCourse | API-012 | 201 |
| `DELETE /api/v1/users/{id}/courses/{course_id}` | L232-234 | Api/Users::deleteCourse | API-013 | 200 |
| `GET /api/v1/courses` | L237-239 | Api/Courses::index | API-014 | 200 |
| `POST /api/v1/courses` | L241-243 | Api/Courses::add | API-015 | 201 |
| `GET /api/v1/courses/{id}` | L245-247 | Api/Courses::view | API-016 | 200 |
| `DELETE /api/v1/courses/{id}` | L249-251 | Api/Courses::delete | API-017 | 200 |
| `GET /api/v1/contents` | L254-256 | Api/Contents::index | API-018 | 200 |
| `GET /api/v1/contents/{id}` | L258-260 | Api/Contents::view | API-019 | 200 |
| `GET /api/v1/records` | L263-265 | Api/Records::index | API-020 | 200 |
| `GET /api/v1/records/{id}` | L267-269 | Api/Records::view | API-021 | 200 |
| `GET /api/v1/groups` | L272-274 | Api/Groups::index | API-022 | 200 |
| `GET /api/v1/groups/{id}` | L276-278 | Api/Groups::view | API-023 | 200 |
| `GET /api/v1/groups/{id}/users` | L280-282 | Api/Groups::users | API-024 | 200 |
| `POST /api/v1/groups/{id}/users` | L284-286 | Api/Groups::addUser | API-025 | 201 |
| `DELETE /api/v1/groups/{id}/users/{user_id}` | L288-290 | Api/Groups::deleteUser | API-026 | 200 |
| `* /api/*` | L332-334 | Api/Errors::notFound | NON-016 | 404 |
| `GET /api` | L337-339 | Api/Errors::notFound | NON-016 | 404 |

---

## 3. APIエンドポイント → 試験項目 対応表

> 参照: `Docs/API.md`（全28エンドポイント + MCP `/mcp`）、`Docs/design/08-rest-api.md`

### 3.1 認証

| APIエンドポイント | API.md参照 | 試験項目ID | 権限 |
|-----------------|-----------|-----------|------|
| `POST /api/v1/auth/token` | API.md §1 | API-001 | 未認証 |
| `DELETE /api/v1/auth/token` | API.md §1 | API-002 | Bearer認証 |

### 3.2 ユーザー

| APIエンドポイント | API.md参照 | 試験項目ID | 権限 |
|-----------------|-----------|-----------|------|
| `GET /api/v1/users` | API.md §2 | API-003 | staff |
| `POST /api/v1/users` | API.md §2 | API-004 | staff |
| `GET /api/v1/users/{id}` | API.md §2 | API-005 | staff / self |
| `PUT /api/v1/users/{id}` | API.md §2 | API-006 | staff |
| `PATCH /api/v1/users/{id}` | API.md §2 | API-007 | staff |
| `DELETE /api/v1/users/{id}` | API.md §2 | API-008 | staff |
| `PUT /api/v1/users/{id}/password` | API.md §2 | API-009 | staff |
| `PATCH /api/v1/users/{id}/password` | API.md §2 | API-010 | staff |
| `GET /api/v1/users/{id}/courses` | API.md §2 | API-011 | staff / self |
| `POST /api/v1/users/{id}/courses` | API.md §2 | API-012 | staff |
| `DELETE /api/v1/users/{id}/courses/{course_id}` | API.md §2 | API-013 | staff |

### 3.3 コース

| APIエンドポイント | API.md参照 | 試験項目ID | 権限 |
|-----------------|-----------|-----------|------|
| `GET /api/v1/courses` | API.md §3 | API-014 | staff |
| `POST /api/v1/courses` | API.md §3 | API-015 | staff |
| `GET /api/v1/courses/{id}` | API.md §3 | API-016 | staff |
| `DELETE /api/v1/courses/{id}` | API.md §3 | API-017 | staff |

### 3.4 コンテンツ

| APIエンドポイント | API.md参照 | 試験項目ID | 権限 |
|-----------------|-----------|-----------|------|
| `GET /api/v1/contents` | API.md §4 | API-018 | staff |
| `GET /api/v1/contents/{id}` | API.md §4 | API-019 | staff |
| `POST /api/v1/contents` | API.md §7.3 | 13 付録D-2 | staff |
| `PUT / PATCH /api/v1/contents/{id}` | API.md §7.4 | 13 付録D-2 | staff + コース権限 |
| `DELETE /api/v1/contents/{id}` | API.md §7.5 | 13 付録D-2 | staff + コース権限 |

### 3.5 記録

| APIエンドポイント | API.md参照 | 試験項目ID | 権限 |
|-----------------|-----------|-----------|------|
| `GET /api/v1/records` | API.md §5 | API-020 | staff |
| `GET /api/v1/records/{id}` | API.md §5 | API-021 | staff |

### 3.6 グループ

| APIエンドポイント | API.md参照 | 試験項目ID | 権限 |
|-----------------|-----------|-----------|------|
| `GET /api/v1/groups` | API.md §6 | API-022 | staff |
| `GET /api/v1/groups/{id}` | API.md §6 | API-023 | staff |
| `GET /api/v1/groups/{id}/users` | API.md §6 | API-024 | staff |
| `POST /api/v1/groups/{id}/users` | API.md §6 | API-025 | staff |
| `DELETE /api/v1/groups/{id}/users/{user_id}` | API.md §6 | API-026 | staff |

### 3.7 エラーハンドリング

| APIエンドポイント | API.md参照 | 試験項目ID | 期待結果 |
|-----------------|-----------|-----------|---------|
| `GET /api/unknown` | API.md §7 | NON-016 | 404 JSON `{error:{code:404}}` |
| `GET /api` | API.md §7 | NON-016 | 404 JSON `{error:{code:404}}` |


---


---

### 3.8 MCP

| APIエンドポイント | API.md参照 | 試験項目ID | 権限 |
|-----------------|-----------|-----------|------|
| `POST /mcp`（tools/list・tools/call 読み取り 7 種） | API.md §12 | MCP-001〜013 | Bearer + コース所属 |
| `POST /mcp`（create_content / update_content） | API.md §12 | MCP-014 | Bearer + staff |
| `POST /mcp`（レート制限 read 60/min・write 20/min） | API.md §12 | MCP-015 | Bearer |
| `GET /mcp` → 405 | API.md §12 | MCP-005 | Bearer |

---

## 4. Migration Gap List (Design vs Implementation)

| # | Item | Design Reference | Status | Reason | Impact |
|---|---|---|---|---|---|
| 1 | Plugin/Search (PrgComponent/SearchableBehavior) | 06-routing.md, search functionality | **Not migrated** | CakePHP 2 Plugin/Search is not compatible with CakePHP 5. No replacement plugin installed. | Search functionality on admin screens may be degraded or use a different mechanism. Verify that any search/filter on list screens still works correctly. |
| 2 | friendsofcake/bootstrap-ui | 09-views.md §3 (planned integration) | **Not installed** | `composer.json` does not include `friendsofcake/bootstrap-ui`. The app uses raw Bootstrap 3 CSS/JS from `webroot/css/` and `webroot/js/` instead. | CakePHP 5 FormHelper `control()` output may not match Bootstrap 3 form classes. The app manually applies CSS classes via `ib_config.php` form_defaults. Functional but not using the CakePHP 5 ecosystem plugin. Tested as part of NON-012. |
| 3 | Vendor/Utils.php dependency | Legacy codebase | **Active dependency** | 10 static methods; 6 actively used in `src/Controller/Admin/` (getCsvData, getYMDHN, getHNSBySec, getKeyByValue, getIdByTitle, issetOr) and 24 occurrences in `templates/`. classmap autoloaded via `composer.json`. | Legacy PHP code working on PHP 8.4 but uses no namespaces. Long-term maintenance risk. Tested as NON-028 to NON-034. |
| 4 | templates/ case-sensitive duplicates | 09-views.md migration plan | **Unresolved** | `templates/Error/` vs `templates/error/`, `templates/element/Flash/` vs `templates/element/flash/`, `templates/layout/Emails/` vs `templates/layout/email/` all coexist. Only one set is active per ErrorController.php:59 and CakePHP 5 defaults. | Unused duplicate files increase confusion. On case-insensitive filesystems (macOS) these would collide. Linux (Docker) is case-sensitive so no runtime issue. Tested as NON-024 to NON-027. |
| 5 | i18n translation files | CakePHP 5 standard | **Not created** | `resources/` directory contains only `.gitkeep`. `__()` is used in 100+ places across controllers and templates with Japanese string keys. | CakePHP 5 returns the key string as fallback when no translation file exists, so the app displays correctly. However, no i18n support is available. Tested as NON-051. |
| 6 | Password reissue email | Not in current scope | **Not implemented (new)** | Password reset by email is not implemented in either CakePHP 2 or 5 version. | Users must rely on admin to reset passwords. |
| 7 | Self-registration | Not in current scope | **Not implemented (new)** | User self-registration is not implemented in either version. | Users must be created by admin. |
| 8 | Email notifications | Not in current scope | **Not implemented (new)** | Email notification on course assignment etc. is not implemented. | No notification feature exists. |
| 9 | Maintenance mode screen | Not in current scope | **Not implemented (new)** | No maintenance mode feature in either version. | N/A |
| 10 | PDF/print feature | Not in current scope | **Not implemented (new)** | PDF export or print-optimized view not implemented. | N/A |
| 11 | config/Migrations/ | 12-implementation-test-plan.md Phase 5 | **Not created** | No CakePHP migration files exist. Database schema changes were applied via direct SQL. | Cannot use `bin/cake migrations migrate` for schema management. Manual SQL must be used for any future changes. |
| 12 | Fixture classes | CakePHP 5 testing standard | **Not created** | No Fixture classes in `tests/Fixture/`. Tests use `tests/schema.sql` loaded by SchemaLoader. | Test data setup is done via raw SQL rather than CakePHP fixtures. Functional but less maintainable. |
| 13 | Controller/API automated tests | 12-implementation-test-plan.md Phase 5 | **Not created** | Only `PagesControllerTest` exists for controllers. No Admin controller tests, no API controller tests, no integration tests for CRUD operations. | Regression detection relies on manual testing. See section 5 (Automation Gap). |

---

## 5. Automation Gap List

### 5.1 Coverage Summary

| Category | Existing Tests | Coverage |
|---|---|---|
| Application bootstrap | 3 (ApplicationTest) | Good |
| Pages controller | 6 (PagesControllerTest) | Good |
| ContentsQuestionsTable | 4 | Good |
| ContentsTable | 6 | Good |
| CoursesTable | 5 | Good |
| UsersTable | 8 | Good |
| GroupsTable | 2 | Good |
| UsersCoursesTable | 4 | Good |
| InfosTable | 4 | Good |
| UserTokensTable | 13 | Good |
| SettingsTable | 3 | Good |
| Admin controllers (9) | **0** | **No coverage** |
| API controllers (7) | **0** | **No coverage** |
| Front controllers (5) | **0** (except PagesController) | **No coverage** |
| Integration/E2E | **0** | **No coverage** |
| Non-functional (07) | **0** | **No coverage** |

### 5.2 Priority Test Additions

| Priority | File to Add/Modify | Test Type | What to Test | Justification |
|---|---|---|---|---|
| P0 | `tests/TestCase/Controller/Admin/UsersControllerTest.php` (new) | Integration | Login, list, add, edit, delete, CSV import/export | Highest-risk admin controller with most Utils usage |
| P0 | `tests/TestCase/Controller/Admin/CoursesControllerTest.php` (new) | Integration | CRUD, order, enrollment | Core functionality |
| P0 | `tests/TestCase/Controller/Admin/ContentsControllerTest.php` (new) | Integration | CRUD, order, file upload, preview | Complex controller with multiple unlockActions |
| P0 | `tests/TestCase/Controller/Admin/RecordsControllerTest.php` (new) | Integration | List, CSV export | Uses Utils::getYMDHN/getHNSBySec |
| P0 | `tests/TestCase/Controller/Api/ApiAuthControllerTest.php` (new) | Integration | Token creation/deletion, auth error cases | API entry point |
| P0 | `tests/TestCase/Controller/Api/ApiUsersControllerTest.php` (new) | Integration | CRUD, courses, password | Largest API controller (9 actions) |
| P1 | `tests/TestCase/Controller/Admin/GroupsControllerTest.php` (new) | Integration | CRUD, user/course enrollment | Moderate complexity |
| P1 | `tests/TestCase/Controller/Admin/InfosControllerTest.php` (new) | Integration | CRUD | Moderate complexity |
| P1 | `tests/TestCase/Controller/Admin/SettingsControllerTest.php` (new) | Integration | Read/update settings | Moderate complexity |
| P1 | `tests/TestCase/Controller/UsersControllerTest.php` (new) | Integration | Login, settings, index | Front user controller |
| P1 | `tests/TestCase/Controller/CoursesControllerTest.php` (new) | Integration | List | Front course list |
| P1 | `tests/TestCase/Controller/ContentsControllerTest.php` (new) | Integration | Index, view, file operations | Complex front controller |
| P2 | `tests/TestCase/Controller/Api/ApiCoursesControllerTest.php` (new) | Integration | CRUD | API course management |
| P2 | `tests/TestCase/Controller/Api/ApiGroupsControllerTest.php` (new) | Integration | CRUD, user management | API group management |
| P2 | `tests/TestCase/Controller/Api/ApiContentsControllerTest.php` (new) | Integration | Read-only | API content read |
| P2 | `tests/TestCase/Controller/Api/ApiRecordsControllerTest.php` (new) | Integration | Read-only | API record read |

---

## 6. Test File to Design Document Cross-Reference

| Test File | Design Document Reference |
|---|---|
| `01-functional-records.md` (FR-) | 12-implementation-test-plan.md T3, T5 |
| `02-admin.md` (AD-) | 12-implementation-test-plan.md T4 |
| `03-api.md` (API-) | 12-implementation-test-plan.md A1-A24, API.md |
| `04-auth.md` (AUTH-) | 12-implementation-test-plan.md T1, R2 |
| `05-validation.md` (VAL-) | 12-implementation-test-plan.md V1-V5 |
| `06-database.md` (DB-) | 12-implementation-test-plan.md Q1-Q2 |
| `07-nonfunctional-regression.md` (NON-) | 12-implementation-test-plan.md R1-R10 |
| `03-api.md` §11 (MCP-) | 13-markdown-mcp.md §5・付録F, 05-security.md §7 |
| `03-api.md` §5（Contents Write） | 13-markdown-mcp.md §4・付録D, 08-rest-api.md §7.7 |
| `03-api.md` §11（E2E ブロック） | 13-markdown-mcp.md §6 Phase 3 |

---

## 7. Risk to Non-Functional Item Cross-Reference

| Risk | NON Items | Additional Notes |
|---|---|---|
| R1: ORM aggregate | NON-001, NON-002 | MariaDB 11.4 may return different aggregate results than MySQL |
| R2: Auth migration | NON-003, NON-004 | Session + Form authenticators replace CakePHP 2 AuthComponent |
| R3: Custom override | NON-005 | PSR-4 autoload for `App\Custom\` namespace |
| R4: MySQL auth | N/A | MariaDB 11.4 uses mysql_native_password; N/A |
| R5: GROUP BY | NON-006 | ONLY_FULL_GROUP_BY mode in MariaDB 11.4 |
| R6: API compat | NON-007 | 24 endpoints; Bearer token replaces session-based API auth |
| R7: Admin prefix URLs | NON-008, NON-009 | All admin URLs changed from /users/admin_* to /admin/* |
| R8: FormToken | NON-010 | CakePHP 5 FormProtection replaces FormTokenSecurity |
| R9: group_id | NON-0011 | ib_records.group_id may reference deleted groups |
| R10: Bootstrap CSS | NON-012 | No bootstrap-ui plugin; raw Bootstrap 3 used instead |

---

## 8. File:Line Reference Index

| File:Line | Referenced By |
|---|---|
| `config/routes.php:22` | NON-019 |
| `config/routes.php:24-177` | NON-013 |
| `config/routes.php:32-46` | NON-014 |
| `config/routes.php:159-177` | NON-018 |
| `config/routes.php:180-329` | NON-015 |
| `config/routes.php:331-341` | NON-016 |
| `config/routes.php:343` | NON-013, NON-017 |
| `config/ib_config.php:12` | NON-065 |
| `config/ib_config.php:131` | NON-050 |
| `config/ib_config.php:140-167` | NON-059 |
| `config/ib_config.php:198-199` | NON-018, NON-052 |
| `config/bootstrap.php:67` | NON-058 |
| `config/bootstrap.php:159-180` | NON-037, NON-058 |
| `src/Application.php:110-130` | NON-055 |
| `src/Application.php:118-129` | NON-039 |
| `src/Application.php:141-182` | NON-003, NON-056 |
| `src/Controller/ErrorController.php:59` | NON-024 |
| `src/Controller/Api/BaseController.php:32-38` | NON-057 |
| `src/Controller/InstallController.php:71` | NON-018, NON-052 |
| `src/Controller/UpdateController.php:69` | NON-018, NON-052 |
| `src/Middleware/HostHeaderMiddleware.php:34-53` | NON-037 |
| `src/Controller/Admin/RecordsController.php:165-166` | NON-028, NON-029 |
| `src/Controller/Admin/UsersController.php:383` | NON-030 |
| `src/Controller/Admin/UsersController.php:432-467` | NON-031 |
| `templates/layout/default.php:21-25` | NON-046 |
| `templates/layout/default.php:30-31` | NON-012 |
| `templates/layout/default.php:42-44` | NON-012, NON-048 |
| `templates/layout/default.php:52` | NON-050 |
| `templates/element/admin_menu.php:10-27` | NON-009 |
| `webroot/.htaccess:22-35` | NON-036 |
| `webroot/.htaccess:30-32` | NON-036, NON-040 |
| `webroot/index_cake2.php` | NON-035 |
| `webroot/js/demo.js` | NON-050 |
| `composer.json` (classmap) | NON-034 |
| `Vendor/Utils.php` | NON-028 to NON-034 |
| `tests/TestCase/ApplicationTest.php:23-30` | NON-038 |
| `tests/schema.sql` | All DB tests |