# 06. データ移行整合性

| 項目 | 内容 |
|---|---|
| 対象 | CakePHP 2.10 → 5.4 移行に伴う DB データ整合性。文字コード変換、集計クエリ、`group_id` 不整合、カスケード削除、sort_no、シードデータ、旧パスワード、トークン、セッション、移行前後比較 |
| 根拠 | `tests/schema.sql`、`Config/Schema/app.sql`、`Config/Schema/update.sql`、`Docs/design/10-database-migration.md`、`Docs/design/03-orm-migration.md`、`src/Model/Table/*.php` の集計クエリ |
| 前提データ | DS-0〜DS-6 を投入済みの状態で実施 |
| 実施手順 | 1. `docker compose up -d` で起動<br>2. DS-0〜DS-6 を投入<br>3. 各項目で指定した SQL を実行<br>4. 結果を期待値と照合<br>5. P0 → P1 → P2 の順で実施 |

---

## 1. 文字コード（utf8mb4）整合性

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-001 | 整合性 | 全16テーブルの charset | DS-0 | 1. `SELECT TABLE_NAME, CCSA.CHARACTER_SET_NAME FROM information_schema.TABLES T JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA ON T.TABLE_COLLATION = CCSA.COLLATION_NAME WHERE T.TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME;` | 全16テーブルの `CHARACTER_SET_NAME` が `utf8mb4` | `10-database-migration.md:§3` | 可 | P0 | □ | |
| DB-002 | 整合性 | DB 接続 charset 設定 | DS-0 | 1. CakePHP 5 側の DB 設定を確認<br>2. `SHOW VARIABLES LIKE 'character_set_%';` | `character_set_client`=`utf8mb4`, `character_set_connection`=`utf8mb4`, `character_set_results`=`utf8mb4` | `10-database-migration.md:§3.2` | 可 | P0 | □ | |
| DB-003 | 正常系 | 日本語文字の格納・取得 | DS-1 | 1. ユーザ名に `日本語テスト` を含むユーザーを作成<br>2. 一覧で表示<br>3. DB から直接 SELECT | 画面表示と DB の値が一致。文字化けなし | `10-database-migration.md:§3` | 可 | P0 | □ | |
| DB-004 | 正常系 | 絵文字の格納・取得 | DS-1 | 1. ユーザ名に絵文字（`😊`）を含むユーザーを作成<br>2. 一覧で表示<br>3. DB から直接 SELECT | 画面表示と DB の値が一致。utf8mb4 で正常格納 | `10-database-migration.md:§3` | 可 | P1 | □ | |
| DB-005 | 整合性 | ib_settings.title のデフォルト値 | DS-0 | 1. `SELECT title FROM ib_settings WHERE id = 1;` | `title` = `eラーニングシステム` | `tests/schema.sql` INSERT文 | 可 | P0 | □ | |
| DB-006 | 整合性 | ib_settings 全4件確認 | DS-0 | 1. `SELECT COUNT(*) FROM ib_settings;` | 件数 = 4 | `tests/schema.sql` INSERT文 | 可 | P0 | □ | |

---

## 2. 集計・GROUP BY 整合性

### ContentsTable::getContentRecord()

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-007 | 整合性 | コンテンツ一覧（空） | DS-2（コンテンツ0件） | 1. フロントでコース一覧を表示<br>2. `getContentRecord()` の SQL を実行 | コンテンツが0件のコースは表示されない。結果が空 | `ContentsTable.php:getContentRecord()` | 可 | P0 | □ | |
| DB-008 | 整合性 | コンテンツ一覧（1件） | DS-2 | 1. コース1にコンテンツ1件を設定<br>2. `getContentRecord()` の SQL を実行<br>3. 画面表示を確認 | コンテンツ1件が表示される。DB の件数と一致 | `ContentsTable.php:getContentRecord()` | 可 | P0 | □ | |
| DB-009 | 整合性 | コンテンツ一覧（複数件） | DS-2 | 1. コース1にコンテンツ3件を設定<br>2. `getContentRecord()` の SQL を実行<br>3. 画面表示を確認 | コンテンツ3件が表示される。DB の件数と一致 | `ContentsTable.php:getContentRecord()` | 可 | P0 | □ | |
| DB-010 | 整合性 | 学習記録の GROUP BY 連動 | DS-4 | 1. コンテンツ1に学習記録を3件追加<br>2. `getContentRecord()` で学習済み件数を確認 | 学習済みの件数が正しくカウントされる。`GROUP BY h.content_id` で重複なし | `ContentsTable.php:getContentRecord()` | 可 | P0 | □ | |

### UsersCoursesTable::getCourseRecord()

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-011 | 整合性 | 受講コース一覧（空） | DS-0（コース0件） | 1. フロントで受講コース一覧を表示<br>2. `getCourseRecord()` の SQL を実行 | 結果が空 | `UsersCoursesTable.php:getCourseRecord()` | 可 | P0 | □ | |
| DB-012 | 整合性 | 受講コース一覧（1件） | DS-1 | 1. ユーザ1にコース1件を紐付け<br>2. `getCourseRecord()` の SQL を実行 | コース1件が返る。DB の件数と一致 | `UsersCoursesTable.php:getCourseRecord()` | 可 | P0 | □ | |
| DB-013 | 整合性 | 受講コース一覧（複数件） | DS-1 | 1. ユーザ1にコース3件を紐付け<br>2. `getCourseRecord()` の SQL を実行 | コース3件が返る。DB の件数と一致 | `UsersCoursesTable.php:getCourseRecord()` | 可 | P0 | □ | |
| DB-014 | 権限 | 権限なしユーザーの結果 | DS-1 | 1. ユーザ2にコースを紐付けない<br>2. ユーザ2として `getCourseRecord()` を確認 | 結果が空。コース一覧に表示されない | `UsersCoursesTable.php:getCourseRecord()` | 可 | P0 | □ | |

### CoursesTable::hasRight()

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-015 | 整合性 | 個人受講の権限判定 | DS-1 | 1. ユーザ1にコース1を紐付け<br>2. `hasRight(user_id, course_id)` を確認 | true（ib_users_courses 経由で存在） | `CoursesTable.php:hasRight()` | 可 | P0 | □ | |
| DB-016 | 整合性 | グループ経由の権限判定 | DS-1 | 1. グループ1にコース1を紐付け<br>2. ユーザ1をグループ1に追加<br>3. `hasRight(user_id, course_id)` を確認 | true（ib_groups_courses JOIN ib_users_groups 経由） | `CoursesTable.php:hasRight()` | 可 | P0 | □ | |
| DB-017 | 権限 | 権限なしの判定 | DS-1 | 1. ユーザ2にコース1を紐付けない<br>2. グループにもコース1を紐付けない<br>3. `hasRight(user_id, course_id)` を確認 | false | `CoursesTable.php:hasRight()` | 可 | P0 | □ | |

### GroupsTable::getUserIdByGroupID()

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-018 | 整合性 | グループに所属ユーザーなし | DS-0 | 1. グループ1を作成（ユーザーなし）<br>2. `getUserIdByGroupID(1)` を確認 | 結果が空（list が空） | `GroupsTable.php:getUserIdByGroupID()` | 可 | P1 | □ | |
| DB-019 | 整合性 | グループに所属ユーザー1件 | DS-1 | 1. ユーザ1をグループ1に追加<br>2. `getUserIdByGroupID(1)` を確認 | ユーザ1の ID が含まれる | `GroupsTable.php:getUserIdByGroupID()` | 可 | P0 | □ | |
| DB-020 | 整合性 | グループに所属ユーザー複数件 | DS-1 | 1. ユーザ1,2 をグループ1に追加<br>2. `getUserIdByGroupID(1)` を確認 | ユーザ1,2 の ID が含まれる | `GroupsTable.php:getUserIdByGroupID()` | 可 | P0 | □ | |

### InfosTable::getInfos() / getInfoIdList()

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-021 | 整合性 | お知らせ一覧（空） | DS-0（お知らせ0件） | 1. フロントでお知らせ一覧を表示<br>2. `getInfos()` の SQL を実行 | 結果が空 | `InfosTable.php:getInfos()` | 可 | P0 | □ | |
| DB-022 | 整合性 | お知らせ一覧（1件） | DS-5 | 1. お知らせ1件を作成<br>2. `getInfos()` の SQL を実行 | お知らせ1件が返る | `InfosTable.php:getInfos()` | 可 | P0 | □ | |
| DB-023 | 整合性 | お知らせ一覧（複数件） | DS-5 | 1. お知らせ3件を作成<br>2. `getInfos()` の SQL を実行 | お知らせ3件が返る。DB の件数と一致 | `InfosTable.php:getInfos()` | 可 | P0 | □ | |
| DB-024 | 整合性 | getInfoIdList の結果 | DS-5 | 1. お知らせ3件を作成<br>2. `getInfoIdList()` の SQL を実行 | ID のリストが返る。件数が一致 | `InfosTable.php:getInfoIdList()` | 可 | P1 | □ | |

---

## 3. ib_records.group_id 不整合の影響

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-025 | 整合性 | group_id=NULL のレコード表示 | DS-4 | 1. ib_records の group_id が NULL の行を1件作成<br>2. 学習履歴一覧を表示 | レコードが正常に表示される（group_id は一覧表示に影響しない） | `10-database-migration.md:§9` | 可 | P0 | □ | |
| DB-026 | 整合性 | 存在しない group_id のレコード表示 | DS-4 | 1. ib_records の group_id に存在しない ID（99999）を設定<br>2. 学習履歴一覧を表示 | レコードが正常に表示される（外部キー制約なし） | `10-database-migration.md:§9` | 可 | P0 | □ | |
| DB-027 | 整合性 | group_id 不整合時の CSV 出力 | DS-4 | 1. ib_records の group_id が不整合のレコードを含む状態で CSV 出力 | CSV に正常にレコードが含まれる。group_id の不整合で出力が阻害されない | `RecordsController.php:_exportCsv()` | 可 | P0 | □ | |
| DB-028 | 整合性 | group_id 不整合時の権限判定 | DS-4 | 1. ib_records の group_id が不整合のレコードに対して学習記録へのアクセス権を確認 | group_id が使用されないため（個人受講の ib_users_courses 経由で判定）、権限判定に影響しない | `CoursesTable.php:hasRight()` | 可 | P1 | □ | |

---

## 4. カスケード削除の整合性

### CoursesTable::deleteCourse()

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-029 | 整合性 | コース削除時の contents_questions 削除 | DS-2 | 1. コース1にコンテンツ1件、問題2件を作成<br>2. 削除前に件数を確認: `SELECT COUNT(*) FROM ib_contents_questions WHERE content_id IN (SELECT id FROM ib_contents WHERE course_id = 1);` → 件数=2<br>3. コース1を削除<br>4. 削除後に件数を確認: 同 SQL → 件数=0 | ib_contents_questions の関連行が削除される。孤児行なし | `CoursesTable.php:deleteCourse()` | 可 | P0 | □ | |
| DB-030 | 整合性 | コース削除時の contents 削除 | DS-2 | 1. コース1にコンテンツ3件を作成<br>2. 削除前に件数を確認: `SELECT COUNT(*) FROM ib_contents WHERE course_id = 1;` → 件数=3<br>3. コース1を削除<br>4. 削除後に件数を確認: 同 SQL → 件数=0 | ib_contents の関連行が削除される。孤児行なし | `CoursesTable.php:deleteCourse()` | 可 | P0 | □ | |
| DB-031 | 整合性 | コース削除後の ib_courses | DS-2 | 1. コース1を作成<br>2. 削除前に件数を確認: `SELECT COUNT(*) FROM ib_courses WHERE id = 1;` → 件数=1<br>3. コース1を削除<br>4. 削除後に件数を確認: 同 SQL → 件数=0 | ib_courses の行が削除される | `CoursesTable.php:deleteCourse()` | 可 | P0 | □ | |
| DB-032 | 整合性 | コース削除順序（contents_questions → contents → courses） | DS-2 | 1. コース1にコンテンツ1件、問題2件を作成<br>2. `DELETE FROM ib_contents_questions ...` → `DELETE FROM ib_contents ...` → `DELETE FROM ib_courses ...` の順序で削除されることを確認 | 3つの DELETE が正しい順序で実行される。外部キー制約でエラーにならない | `CoursesTable.php:deleteCourse()` | 可 | P0 | □ | |

### UsersTable::deleteUserRecords()

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-033 | 整合性 | ユーザ削除時の records_questions 削除 | DS-4 | 1. ユーザ1に学習記録1件、回答記録2件を作成<br>2. 削除前に件数を確認: `SELECT COUNT(*) FROM ib_records_questions WHERE record_id IN (SELECT id FROM ib_records WHERE user_id = 1);` → 件数=2<br>3. ユーザ1を削除<br>4. 削除後に件数を確認: 同 SQL → 件数=0 | ib_records_questions の関連行が削除される。孤児行なし | `UsersTable.php:deleteUserRecords()` | 可 | P0 | □ | |
| DB-034 | 整合性 | ユーザ削除時の records 削除 | DS-4 | 1. ユーザ1に学習記録3件を作成<br>2. 削除前に件数を確認: `SELECT COUNT(*) FROM ib_records WHERE user_id = 1;` → 件数=3<br>3. ユーザ1を削除<br>4. 削除後に件数を確認: 同 SQL → 件数=0 | ib_records の関連行が削除される。孤児行なし | `UsersTable.php:deleteUserRecords()` | 可 | P0 | □ | |
| DB-035 | 整合性 | ユーザ削除順序（records_questions → records） | DS-4 | 1. ユーザ1に学習記録1件、回答記録2件を作成<br>2. `DELETE FROM ib_records_questions ...` → `DELETE FROM ib_records ...` の順序で削除されることを確認 | 2つの DELETE が正しい順序で実行される | `UsersTable.php:deleteUserRecords()` | 可 | P0 | □ | |

---

## 5. 並び順（sort_no）の一覧

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-036 | 整合性 | sort_no の一意性 | DS-2 | 1. 同一コース内にコンテンツ3件を作成<br>2. `SELECT sort_no, COUNT(*) FROM ib_contents WHERE course_id = 1 GROUP BY sort_no HAVING COUNT(*) > 1;` | 結果が空。同一コース内で sort_no が重複しない | `ContentsTable.php:setOrder()` | 可 | P0 | □ | |
| DB-037 | 整合性 | sort_no の抜け番確認 | DS-2 | 1. コース1にコンテンツ3件を作成（sort_no: 1,2,3）<br>2. コンテンツ2を削除<br>3. `SELECT sort_no FROM ib_contents WHERE course_id = 1 ORDER BY sort_no;` | 削除後は sort_no=1,3 となり、2 が欠番 | `ContentsTable.php:setOrder()` | 可 | P1 | □ | |
| DB-038 | 整合性 | getNextSortNo の連番 | DS-2 | 1. コース1にコンテンツ0件の状態で `getNextSortNo(1)` を確認 → 1<br>2. コンテンツ1件目を追加 → `getNextSortNo(1)` → 2<br>3. コンテンツ2件目を追加 → `getNextSortNo(1)` → 3 | `MAX(sort_no) + 1` が正しく返される | `ContentsTable.php:getNextSortNo()` | 可 | P0 | □ | |
| DB-039 | UI | displayField の表示 | DS-2 | 1. コース一覧を表示<br>2. コンテンツ一覧を表示 | `displayField` が正しく設定されたフィールド（title 等）が表示される | 各 Table の `setDisplayField()` | 可 | P1 | □ | |

---

## 6. シードデータ・移行後の行数・主キー

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-040 | 整合性 | ib_settings 4件 | DS-0 | 1. `SELECT COUNT(*) FROM ib_settings;` | 件数 = 4（title, copyright, color, information） | `tests/schema.sql` | 可 | P0 | □ | |
| DB-041 | 整合性 | ib_users の admin 1件（id=1） | DS-0 | 1. `SELECT id, username, role FROM ib_users WHERE role = 'admin';` | admin が1件存在。id=1, username が設定値と一致 | `tests/schema.sql` | 可 | P0 | □ | |
| DB-042 | 整合性 | パスワードが bcrypt | DS-0 | 1. `SELECT password FROM ib_users WHERE id = 1;` | password の先頭6文字が `$2y$` である（bcrypt ハッシュ） | `UsersTable.php:beforeSave()` | 可 | P0 | □ | |
| DB-043 | 整合性 | 16テーブルの主キー存在 | DS-0 | 1. `SELECT TABLE_NAME, COLUMN_NAME, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_KEY = 'PRI' ORDER BY TABLE_NAME;` | 全16テーブルに主キー列が存在 | `tests/schema.sql` | 可 | P0 | □ | |
| DB-044 | 整合性 | ib_user_tokens のインデックス | DS-0 | 1. `SHOW INDEX FROM ib_user_tokens;` | UNIQUE KEY `uk_token_selector`、KEY `idx_user_type`、KEY `idx_expired` が存在 | `tests/schema.sql` | 可 | P1 | □ | |
| DB-045 | 整合性 | ib_cake_sessions テーブル構造 | DS-0 | 1. `DESCRIBE ib_cake_sessions;` | id VARCHAR(255), data TEXT, expires INT が存在 | `tests/schema.sql` | 可 | P1 | □ | |

---

## 7. 旧パスワード（SHA1）互換

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-046 | 正常系 | sha1(legacy_salt . pass) でログイン | DS-6 | 1. DS-6 のユーザー（legacy_security_salt + pass の SHA1 ハッシュ保存済み）でログイン<br>2. ログインIDとパスワードを入力してログイン | ログイン成功。セッションが作成される | `UserLoginTrait.php:_login()` / `ib_config.php:legacy_security_salt` | 可 | P0 | □ | |
| DB-047 | 正常系 | sha1(pass) でログイン（legacy_salt 不使用） | DS-6 | 1. DS-6 のユーザーで legacy_salt なしの SHA1 ハッシュ（`sha1(pass)`）を保存<br>2. ログイン | ログイン成功。`sha1($password)` のフォールバックで照合成功 | `UserLoginTrait.php:_login()` | 可 | P0 | □ | |
| DB-048 | 整合性 | SHA1 → bcrypt 自動更新 | DS-6 | 1. DS-6 のユーザーでログイン<br>2. `SELECT password FROM ib_users WHERE id = <ds6_user_id>;` | ログイン成功後、password が SHA1 から bcrypt（`$2y$`）に更新される | `UserLoginTrait.php:_login()` | 可 | P0 | □ | |
| DB-049 | 正常系 | bcrypt 更新後は旧パスワード不可 | DS-6 | 1. DS-6 のユーザーで一度ログイン（bcrypt に更新）<br>2. 同じパスワードでもう一度ログイン | 2回目もログイン成功（今度は bcrypt の `password_verify` で照合） | `UserLoginTrait.php:_login()` | 可 | P1 | □ | |

---

## 8. トークン管理

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-050 | 正常系 | remember トークン発行 | DS-1 | 1. ログインフォームで「ログイン状態を保持」にチェックを入れてログイン<br>2. `SELECT * FROM ib_user_tokens WHERE user_id = <user_id> AND type = 'remember';` | トークンが1件発行される。expired が現在日時+14日 | `UserTokensTable.php:issueRememberToken()` / `ib_config.php:remember_token_expired_days` | 可 | P0 | □ | |
| DB-051 | 正常系 | API トークン発行 | DS-1 | 1. `POST /api/auth` でユーザー名・パスワードを送信<br>2. `SELECT * FROM ib_user_tokens WHERE user_id = <user_id> AND type = 'api';` | トークンが1件発行される。expired が現在日時+30日（`api_token_expired_days`） | `AuthController.php:issueToken()` | 可 | P0 | □ | |
| DB-052 | 正常系 | permanent API トークン（admin のみ） | DS-1 | 1. admin で `POST /api/auth` に `permanent=true` を付与<br>2. `SELECT expired FROM ib_user_tokens WHERE type = 'api' AND user_id = 1 ORDER BY id DESC LIMIT 1;` | expired = `9999-12-31 23:59:59` | `AuthController.php:issueToken()` / `AuthController.php:PERMANENT_EXPIRED` | 可 | P0 | □ | |
| DB-053 | セキュリティ | permanent トークンは admin のみ | DS-1 | 1. 一般ユーザーで `POST /api/auth` に `permanent=true` を付与 | permanent トークンは発行されない（admin 以外は無視） | `AuthController.php:issueToken()` | 可 | P0 | □ | |
| DB-054 | 整合性 | revoke 後の revoked 更新 | DS-1 | 1. API トークンを発行<br>2. `POST /api/auth/revoke` で失効<br>3. `SELECT revoked FROM ib_user_tokens WHERE id = <token_id>;` | revoked が現在日時に更新される | `AuthController.php:revokeToken()` | 可 | P0 | □ | |
| DB-055 | 整合性 | revoke 後はトークン使用不可 | DS-1 | 1. API トークンを発行<br>2. revoke 実行<br>3. revoke 後のトークンで API リクエスト | 認証エラー（401）が返る | `UserTokensTable.php:authenticate()` | 可 | P0 | □ | |
| DB-056 | 整合性 | 期限切れトークンの無効化 | DS-1 | 1. ib_user_tokens の expired を過去日時に手動設定<br>2. 該当トークンで API リクエスト | 認証エラー（401）が返る。期限切れトークンは使用不可 | `UserTokensTable.php:authenticate()` | 可 | P1 | □ | |
| DB-057 | 整合性 | remember トークン期限14日 | DS-1 | 1. remember トークンを発行<br>2. `SELECT DATEDIFF(expired, NOW()) FROM ib_user_tokens WHERE type = 'remember' ORDER BY id DESC LIMIT 1;` | 14日前後（13〜14） | `UserTokensTable.php:issueRememberToken()` / `ib_config.php:remember_token_expired_days` | 可 | P1 | □ | |
| DB-058 | 整合性 | 孤児トークン（ユーザー削除後） | DS-1 | 1. ユーザ1にトークンを2件発行<br>2. ユーザ1を削除<br>3. `SELECT COUNT(*) FROM ib_user_tokens WHERE user_id = <deleted_user_id>;` | 件数 = 0（外部キー制約で削除される、または手動削除が必要） | `ib_user_tokens` DDL | 可 | P1 | □ | |

---

## 9. セッション管理

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-059 | 正常系 | ib_cake_sessions への格納 | DS-1 | 1. ユーザーがログイン<br>2. `SELECT COUNT(*) FROM ib_cake_sessions;` | セッションレコードが1件以上存在 | `tests/schema.sql` | 可 | P0 | □ | |
| DB-060 | 整合性 | セッションの有効期限 | DS-1 | 1. ログイン<br>2. `SELECT expires FROM ib_cake_sessions ORDER BY expires DESC LIMIT 1;` | expires が現在時刻より未来の値 | `tests/schema.sql` | 可 | P1 | □ | |
| DB-061 | 正常系 | ログアウト後のセッション削除 | DS-1 | 1. ログイン後、ログアウト<br>2. 同じセッション ID でリクエスト | セッションが無効化される。再ログインが必要 | CakePHP Session | 難 | P1 | □ | |

---

## 10. 移行前後比較（レガシー DB ↔ 移行後 DB）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-062 | 整合性 | テーブル行数一致（全16テーブル） | DS-0 | 1. レガシー DB（`docker-db-1`）と移行後 DB（`irohaboard5-db-1`）で全テーブルの `SELECT COUNT(*)` を実行<br>2. 結果を照合 | 16テーブルすべてで行数が一致 | `10-database-migration.md:§12` | 可 | P0 | □ | |
| DB-063 | 整合性 | ib_users 行数・admin 存在 | DS-0 | 1. レガシー: `SELECT COUNT(*) FROM ib_users;`<br>2. 移行後: `SELECT COUNT(*) FROM ib_users;`<br>3. `SELECT id, username, role FROM ib_users WHERE id = 1;` | 件数一致。id=1 の admin レコードが存在 | `10-database-migration.md:§12` | 可 | P0 | □ | |
| DB-064 | 整合性 | ib_courses 行数・タイトル | DS-0 | 1. レガシー: `SELECT COUNT(*) FROM ib_courses;`<br>2. 移行後: 同 SQL<br>3. `SELECT id, title FROM ib_courses ORDER BY id;` | 件数一致。コースタイトルが一致 | `10-database-migration.md:§12` | 可 | P0 | □ | |
| DB-065 | 整合性 | ib_contents 行数・主要カラム | DS-0 | 1. レガシー: `SELECT COUNT(*) FROM ib_contents;`<br>2. 移行後: 同 SQL<br>3. `SELECT id, title, kind, status FROM ib_contents ORDER BY id;` | 件数一致。タイトル・種別・ステータスが一致 | `10-database-migration.md:§12` | 可 | P0 | □ | |
| DB-066 | 整合性 | ib_settings の文字列値 | DS-0 | 1. レガシー: `SELECT setting_key, setting_value FROM ib_settings ORDER BY id;`<br>2. 移行後: 同 SQL<br>3. 結果を照合 | 4件すべての setting_key と setting_value が一致 | `10-database-migration.md:§12` | 可 | P0 | □ | |
| DB-067 | 整合性 | ib_records の集計値 | DS-0 | 1. レガシー: `SELECT COUNT(*), SUM(score) FROM ib_records;`<br>2. 移行後: 同 SQL | 件数と合計得点が一致 | `10-database-migration.md:§12` | 可 | P1 | □ | |
| DB-068 | 整合性 | 文字列カラムの一致（日本語） | DS-0 | 1. レガシー: `SELECT id, title FROM ib_courses ORDER BY id;`<br>2. 移行後: 同 SQL<br>3. 結果を照合 | 日本語文字列が文字化けなしで一致 | `10-database-migration.md:§3` | 可 | P0 | □ | |
| DB-069 | 整合性 | インデックスの一致 | DS-0 | 1. レガシー: `SHOW INDEX FROM <各テーブル>;`<br>2. 移行後: 同 SQL<br>3. 比較 | 主要インデックス（PRIMARY, UNIQUE, KEY）が一致。`update.sql` の修正により ib_records の group_id インデックスは移行後側にのみ存在しない | `10-database-migration.md:§9` / `update.sql` | 可 | P1 | □ | |
| DB-070 | 整合性 | MariaDB バージョン確認 | DS-0 | 1. `SELECT VERSION();` | MariaDB 11.4.x である | `10-database-migration.md:§1` | 可 | P0 | □ | |
| DB-071 | 整合性 | ENGINE=InnoDB 確認 | DS-0 | 1. `SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME;` | 全16テーブルの ENGINE が `InnoDB` | `tests/schema.sql` | 可 | P1 | □ | |

---

## 11. パスワード保存形式

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| DB-072 | 整合性 | 新規パスワードは bcrypt | DS-0 | 1. ユーザを新規作成（パスワード: `test1234`）<br>2. `SELECT password FROM ib_users ORDER BY id DESC LIMIT 1;` | password が `$2y$` で始まる bcrypt ハッシュ（60文字） | `UsersTable.php:beforeSave()` | 可 | P0 | □ | |
| DB-073 | 整合性 | パスワード変更後も bcrypt | DS-1 | 1. 既存ユーザーのパスワードを変更<br>2. `SELECT password FROM ib_users WHERE id = <user_id>;` | password が `$2y$` で始まる bcrypt ハッシュ | `UsersTable.php:beforeSave()` | 可 | P0 | □ | |
| DB-074 | セキュリティ | bcrypt ハッシュの長さ | DS-0 | 1. 全ユーザーの password を確認<br>2. `SELECT LENGTH(password), LEFT(password, 7) FROM ib_users;` | 全行で LENGTH=60, LEFT が `$2y$10$` | `UsersTable.php:beforeSave()` | 可 | P1 | □ | |

---

## 集計表

| 分類 | 項目数 | 項目ID一覧 |
|---|---|---|
| 正常系 | 10 | DB-003,DB-004,DB-046,DB-047,DB-049,DB-050,DB-051,DB-052,DB-059,DB-061 |
| 整合性 | 59 | DB-001,DB-002,DB-005,DB-006,DB-007,DB-008,DB-009,DB-010,DB-011,DB-012,DB-013,DB-015,DB-016,DB-018,DB-019,DB-020,DB-021,DB-022,DB-023,DB-024,DB-025,DB-026,DB-027,DB-029,DB-030,DB-031,DB-032,DB-033,DB-034,DB-035,DB-036,DB-037,DB-038,DB-040,DB-041,DB-042,DB-043,DB-044,DB-045,DB-048,DB-054,DB-055,DB-056,DB-057,DB-058,DB-060,DB-062,DB-063,DB-064,DB-065,DB-066,DB-067,DB-068,DB-069,DB-070,DB-071,DB-072,DB-073 |
| 権限 | 2 | DB-014,DB-017 |
| セキュリティ | 2 | DB-053,DB-074 |
| UI | 1 | DB-039 |
| **合計** | **74** | |

| 優先度 | 項目数 |
|---|---|
| P0 | 56 |
| P1 | 18 |
| P2 | 0 |
