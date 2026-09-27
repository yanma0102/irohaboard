# 05. 入力バリデーション・境界値・アップロード・CSV入出力

| 項目 | 内容 |
|---|---|
| 対象 | 全テーブルの入力フィールドに対するバリデーション規則、ファイルアップロード（拡張子/サイズ）、CSV インポート/エクスポートのフォーマット |
| 根拠 | `src/Model/Table/*.php` の `validationDefault()`、`src/Controller/Admin/ContentsController.php`（upload/uploadImage）、`src/Controller/Admin/UsersController.php`（import/_exportCsv）、`src/Controller/Admin/RecordsController.php`（_exportCsv/_exportCsvDetail）、`config/ib_config.php` |
| 前提データ | DS-0（空状態）で基本 UI 項目を実施。DS-1〜DS-6 投入後、各章の前提に記載するデータセットを使用 |
| 実施手順 | 1. `docker compose up -d` で起動<br>2. ブラウザで `http://localhost:8082/` 確認<br>3. P0 → P1 → P2 の順で実施<br>4. 各行の結果（□）を記入 |

---

## 1. ユーザ管理バリデーション

### ib_users.username（4〜32文字・英数・一意）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-001 | 正常系 | Admin ユーザ作成 | DS-0 | 1. 管理画面でログイン<br>2. ユーザ追加フォームに `abcde`（5文字・英数）を入力<br>3. 保存 | ユーザが作成される。`ib_users` に username=`abcde` の行が追加 | `UsersTable.php:L69-88` | 可 | P0 | □ | |
| VAL-002 | 正常系 | 上限32文字 | DS-0 | 1. ユーザ追加フォームに `A1B2C3D4E5F6G7H8I9J0K1L2M3N4O5P`（32文字）を入力<br>2. 保存 | ユーザが作成される。username が32文字で保存 | `UsersTable.php:L79` | 可 | P0 | □ | |
| VAL-003 | 境界値 | 下限4文字 | DS-0 | 1. ユーザ追加フォームに `abc`（3文字）を入力<br>2. 保存 | バリデーションエラー。`ログインIDは4文字以上32文字以内で入力して下さい` と表示。DB に保存されない | `UsersTable.php:L82-84` | 可 | P0 | □ | |
| VAL-004 | 境界値 | 下限-1（3文字） | DS-0 | 1. ユーザ追加フォームに `abc`（3文字）を入力<br>2. 保存 | バリデーションエラー。上記と同じメッセージ。DB に保存されない | `UsersTable.php:L82-84` | 可 | P0 | □ | |
| VAL-005 | 境界値 | 上限+1（33文字） | DS-0 | 1. ユーザ追加フォームに33文字の英数字を入力<br>2. 保存 | バリデーションエラー。DB に保存されない | `UsersTable.php:L79` | 可 | P0 | □ | |
| VAL-006 | 異常系 | 英数字以外（記号含む） | DS-0 | 1. ユーザ追加フォームに `user@#!` を入力<br>2. 保存 | バリデーションエラー。`ログインIDは英数字で入力して下さい` と表示。DB に保存されない | `UsersTable.php:L80-81` | 可 | P0 | □ | |
| VAL-007 | 異常系 | 全角文字 | DS-0 | 1. ユーザ追加フォームに `テストユーザー` を入力<br>2. 保存 | バリデーションエラー。`ログインIDは英数字で入力して下さい` と表示。DB に保存されない | `UsersTable.php:L80-81` | 可 | P1 | □ | |
| VAL-008 | 異常系 | 絵文字 | DS-0 | 1. ユーザ追加フォームに `test` + 絵文字 を入力<br>2. 保存 | バリデーションエラー。DB に保存されない | `UsersTable.php:L80-81` | 可 | P2 | □ | |
| VAL-009 | 異常系 | 空文字 | DS-0 | 1. ユーザ追加フォームのログインID欄を空のまま保存 | `requirePresence('create')` によりエラー。DB に保存されない | `UsersTable.php:L72` | 可 | P0 | □ | |
| VAL-010 | 異常系 | タブ・改行 | DS-0 | 1. ユーザ追加フォームに `user` + タブ文字 を入力<br>2. 保存 | バリデーションエラー。DB に保存されない | `UsersTable.php:L80-81` | 可 | P2 | □ | |
| VAL-011 | 異常系 | 前後空白あり | DS-0 | 1. ユーザ追加フォームに ` abcde ` （前後空白）を入力<br>2. 保存 | 空白が除去された状態で `abcde` として保存される（CakePHP trimBeforeSave） | `UsersTable.php:L69-88` | 可 | P1 | □ | |
| VAL-012 | 異常系 | 重複ログインID | DS-1 | 1. DS-1 の既存ユーザと同じログインIDを入力<br>2. 保存 | バリデーションエラー。`ログインIDが重複しています` と表示。DB に保存されない | `UsersTable.php:L76` | 可 | P0 | □ | |

### ib_users.name（50文字）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-013 | 正常系 | 正常入力 | DS-0 | 1. ユーザ追加フォームに氏名として `テスト太郎` を入力<br>2. 保存 | ユーザが作成される。name=`テスト太郎` | `UsersTable.php:L90-94` | 可 | P0 | □ | |
| VAL-014 | 境界値 | 50文字 | DS-0 | 1. 氏名欄に50文字を入力<br>2. 保存 | 保存される | `UsersTable.php:L91` | 可 | P1 | □ | |
| VAL-015 | 境界値 | 51文字 | DS-0 | 1. 氏名欄に51文字を入力<br>2. 保存 | バリデーションエラー（maxLength(50)）。DB に保存されない | `UsersTable.php:L91` | 可 | P1 | □ | |
| VAL-016 | 異常系 | 空文字 | DS-0 | 1. 氏名欄を空のまま保存 | `氏名が入力されていません` と表示。DB に保存されない | `UsersTable.php:L93` | 可 | P0 | □ | |

### ib_users.role（20文字）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-017 | 正常系 | admin 設定 | DS-0 | 1. ユーザ追加フォームで権限=管理者を選択<br>2. 保存 | role=`admin` で保存される | `UsersTable.php:L96-100` | 可 | P0 | □ | |
| VAL-018 | 異常系 | role 空 | DS-0 | 1. 権限未選択で保存 | `権限が指定されていません` と表示。DB に保存されない | `UsersTable.php:L99` | 可 | P0 | □ | |

### ib_users.password / new_password（4〜32文字・英数）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-019 | 正常系 | 新規パスワード | DS-0 | 1. ユーザ追加でパスワード `pass1234` を入力<br>2. 保存 | パスワードが bcrypt（`$2y$`）でハッシュ化されて保存される | `UsersTable.php:L102-110` | 可 | P0 | □ | |
| VAL-020 | 境界値 | 4文字（下限） | DS-0 | 1. パスワードに `ab12`（4文字）を入力<br>2. 保存 | 保存される | `UsersTable.php:L107` | 可 | P1 | □ | |
| VAL-021 | 境界値 | 3文字（下限-1） | DS-0 | 1. パスワードに `abc`（3文字）を入力<br>2. 保存 | バリデーションエラー。`パスワードは4文字以上32文字以内で入力して下さい` と表示 | `UsersTable.php:L107` | 可 | P1 | □ | |
| VAL-022 | 異常系 | 英数字以外 | DS-0 | 1. パスワードに `pass!@#` を入力<br>2. 保存 | `パスワードは英数字で入力して下さい` と表示。DB に保存されない | `UsersTable.php:L105-106` | 可 | P0 | □ | |
| VAL-023 | 正常系 | パスワード変更 | DS-1 | 1. 管理画面で既存ユーザの new_password に `newpass1` を入力<br>2. 保存 | パスワードが更新される。元のパスワードではログイン不可、新しいパスワードでログイン可能 | `UsersTable.php:L112-122` | 可 | P0 | □ | |
| VAL-024 | 正常系 | new_password 空 | DS-1 | 1. 既存ユーザ編集で new_password を空のまま保存 | パスワードは変更されない。既存のパスワードで引き続きログイン可能 | `UsersTable.php:L112-122` | 可 | P1 | □ | |

---

## 2. コース・コンテンツ・出題バリデーション

### ib_courses.title（200文字）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-025 | 正常系 | 正常入力 | DS-0 | 1. コース追加フォームに `テストコース` を入力<br>2. 保存 | コースが作成される | `CoursesTable.php:L63-67` | 可 | P0 | □ | |
| VAL-026 | 境界値 | 200文字 | DS-0 | 1. コースタイトルに200文字を入力<br>2. 保存 | 保存される | `CoursesTable.php:L64` | 可 | P1 | □ | |
| VAL-027 | 境界値 | 201文字 | DS-0 | 1. コースタイトルに201文字を入力<br>2. 保存 | バリデーションエラー（maxLength(200)）。DB に保存されない | `CoursesTable.php:L64` | 可 | P1 | □ | |
| VAL-028 | 異常系 | 空文字 | DS-0 | 1. コースタイトルを空のまま保存 | requirePresence エラー。DB に保存されない | `CoursesTable.php:L66` | 可 | P0 | □ | |

### ib_contents 入力フィールド

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-029 | 正常系 | コンテンツ作成 | DS-2 | 1. コンテンツ追加フォームに必須項目を入力<br>2. 保存 | コンテンツが作成される | `ContentsTable.php:L60-106` | 可 | P0 | □ | |
| VAL-030 | 異常系 | course_id 空 | DS-2 | 1. コンテンツ追加フォームでコース未選択で保存 | requirePresence エラー。DB に保存されない | `ContentsTable.php:L63` | 可 | P0 | □ | |
| VAL-031 | 異常系 | user_id 空 | DS-2 | 1. コンテンツ追加フォームで担当者未選択で保存 | requirePresence エラー。DB に保存されない | `ContentsTable.php:L68` | 可 | P0 | □ | |
| VAL-032 | 異常系 | title 空 | DS-2 | 1. コンテンツタイトルを空のまま保存 | requirePresence エラー。DB に保存されない | `ContentsTable.php:L74` | 可 | P0 | □ | |
| VAL-033 | 異常系 | title 201文字 | DS-2 | 1. コンテンツタイトルに201文字を入力<br>2. 保存 | バリデーションエラー（maxLength(200)）。DB に保存されない | `ContentsTable.php:L73` | 可 | P1 | □ | |
| VAL-034 | 異常系 | kind 空 | DS-2 | 1. kind を空のまま保存 | requirePresence エラー。DB に保存されない | `ContentsTable.php:L79` | 可 | P0 | □ | |
| VAL-035 | 境界値 | timelimit=0 | DS-2 | 1. 制限時間に `0` を入力<br>2. 保存 | 保存される。制限時間なしとして扱われる | `ContentsTable.php:L88-90` | 可 | P1 | □ | |
| VAL-036 | 境界値 | timelimit=100 | DS-2 | 1. 制限時間に `100` を入力<br>2. 保存 | 保存される（range([0,101]) の範囲内） | `ContentsTable.php:L89` | 可 | P1 | □ | |
| VAL-037 | 境界値 | timelimit=101 | DS-2 | 1. 制限時間に `101` を入力<br>2. 保存 | 保存される（range([0,101]) の境界値） | `ContentsTable.php:L89` | 可 | P1 | □ | |
| VAL-038 | 境界値 | timelimit=102 | DS-2 | 1. 制限時間に `102` を入力<br>2. 保存 | バリデーションエラー（range [0,101] 超過）。DB に保存されない | `ContentsTable.php:L89` | 可 | P2 | □ | |
| VAL-039 | 境界値 | pass_rate=0〜101 | DS-2 | 1. 合格点に `0`〜`101` の各値を順に試行 | range([0,101]) の範囲内であれば保存される | `ContentsTable.php:L93-95` | 可 | P1 | □ | |
| VAL-040 | 境界値 | pass_rate=102 | DS-2 | 1. 合格点に `102` を入力<br>2. 保存 | バリデーションエラー（range [0,101] 超過）。DB に保存されない | `ContentsTable.php:L93-95` | 可 | P2 | □ | |
| VAL-041 | 境界値 | question_count=0〜101 | DS-2 | 1. 問題数に `0`〜`101` の各値を順に試行 | range([0,101]) の範囲内であれば保存される | `ContentsTable.php:L98-100` | 可 | P1 | □ | |
| VAL-042 | 境界値 | question_count=102 | DS-2 | 1. 問題数に `102` を入力<br>2. 保存 | バリデーションエラー（range [0,101] 超過）。DB に保存されない | `ContentsTable.php:L98-100` | 可 | P2 | □ | |

### ib_contents_questions

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-043 | 正常系 | 問題作成 | DS-3 | 1. 問題追加フォームに必須項目を入力<br>2. 正解を選択<br>3. 保存 | 問題が作成される | `ContentsQuestionsTable.php:L56-88` | 可 | P0 | □ | |
| VAL-044 | 異常系 | 正解未選択 | DS-3 | 1. 問題追加フォームで正解を選択せずに保存 | `正解を選択してください` と表示。DB に保存されない | `ContentsQuestionsTable.php:L81-86` | 可 | P0 | □ | |
| VAL-045 | 境界値 | score=-1 | DS-3 | 1. 配点に `-1` を入力<br>2. 保存 | 保存される（range([-1,101]) の範囲内） | `ContentsQuestionsTable.php:L74-76` | 可 | P1 | □ | |
| VAL-046 | 境界値 | score=-2 | DS-3 | 1. 配点に `-2` を入力<br>2. 保存 | バリデーションエラー（range [-1,101] 外）。DB に保存されない | `ContentsQuestionsTable.php:L74-76` | 可 | P2 | □ | |
| VAL-047 | 境界値 | score=101 | DS-3 | 1. 配点に `101` を入力<br>2. 保存 | 保存される（range([-1,101]) の範囲内） | `ContentsQuestionsTable.php:L74-76` | 可 | P1 | □ | |
| VAL-048 | 境界値 | score=102 | DS-3 | 1. 配点に `102` を入力<br>2. 保存 | バリデーションエラー。DB に保存されない | `ContentsQuestionsTable.php:L74-76` | 可 | P2 | □ | |
| VAL-049 | 異常系 | body 空 | DS-3 | 1. 問題本文を空のまま保存 | requirePresence + notBlank エラー。DB に保存されない | `ContentsQuestionsTable.php:L69-71` | 可 | P0 | □ | |
| VAL-050 | 異常系 | question_type 空 | DS-3 | 1. 問題種別を空のまま保存 | requirePresence エラー。DB に保存されない | `ContentsQuestionsTable.php:L64` | 可 | P0 | □ | |
| VAL-051 | 異常系 | question_type 21文字 | DS-3 | 1. question_type に21文字の文字列を設定<br>2. 保存 | バリデーションエラー（maxLength(20)）。DB に保存されない | `ContentsQuestionsTable.php:L65` | 可 | P2 | □ | |

---

## 3. 学習記録バリデーション

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-052 | 異常系 | answer 2001文字 | DS-4 | 1. ib_records_questions.answer に2001文字を設定 | バリデーションエラー（maxLength(2000)）。DB に保存されない | `RecordsQuestionsTable.php:L74-77` | 可 | P2 | □ | |
| VAL-053 | 異常系 | correct 201文字 | DS-4 | 1. ib_records_questions.correct に201文字を設定 | バリデーションエラー（maxLength(200)）。DB に保存されない | `RecordsQuestionsTable.php:L79-82` | 可 | P2 | □ | |
| VAL-054 | 正常系 | answer 空文字 | DS-4 | 1. answer を空文字で保存 | 保存される（allowEmptyString） | `RecordsQuestionsTable.php:L75` | 可 | P1 | □ | |

---

## 4. お知らせ・グループ・設定・ログ バリデーション

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-055 | 正常系 | お知らせ作成 | DS-5 | 1. お知らせ追加フォームに必須項目を入力<br>2. 保存 | お知らせが作成される | `InfosTable.php:L58-71` | 可 | P0 | □ | |
| VAL-056 | 異常系 | お知らせ title 空 | DS-5 | 1. お知らせタイトルを空のまま保存 | requirePresence エラー。DB に保存されない | `InfosTable.php:L61` | 可 | P0 | □ | |
| VAL-057 | 境界値 | お知らせ title 200文字 | DS-5 | 1. お知らせタイトルに200文字を入力<br>2. 保存 | 保存される | `InfosTable.php:L61` | 可 | P1 | □ | |
| VAL-058 | 境界値 | お知らせ title 201文字 | DS-5 | 1. お知らせタイトルに201文字を入力<br>2. 保存 | バリデーションエラー（maxLength(200)）。DB に保存されない | `InfosTable.php:L61` | 可 | P1 | □ | |
| VAL-059 | 正常系 | グループ作成 | DS-0 | 1. グループ追加フォームに `テストグループ` を入力<br>2. 保存 | グループが作成される | `GroupsTable.php:L58-70` | 可 | P0 | □ | |
| VAL-060 | 異常系 | グループ title 空 | DS-0 | 1. グループタイトルを空のまま保存 | requirePresence エラー。DB に保存されない | `GroupsTable.php:L61` | 可 | P0 | □ | |
| VAL-061 | 境界値 | グループ title 200文字 | DS-0 | 1. グループタイトルに200文字を入力<br>2. 保存 | 保存される | `GroupsTable.php:L61` | 可 | P1 | □ | |
| VAL-062 | 境界値 | グループ title 201文字 | DS-0 | 1. グループタイトルに201文字を入力<br>2. 保存 | バリデーションエラー（maxLength(200)）。DB に保存されない | `GroupsTable.php:L61` | 可 | P1 | □ | |
| VAL-063 | 正常系 | 設定値変更 | DS-5 | 1. 設定画面で設定名・設定値を変更<br>2. 保存 | 設定が保存される | `SettingsTable.php:L47-66` | 可 | P0 | □ | |
| VAL-064 | 境界値 | setting_key 100文字 | DS-5 | 1. setting_key に100文字を入力<br>2. 保存 | 保存される | `SettingsTable.php:L50` | 可 | P2 | □ | |
| VAL-065 | 境界値 | setting_value 1000文字 | DS-5 | 1. setting_value に1000文字を入力<br>2. 保存 | 保存される | `SettingsTable.php:L62` | 可 | P2 | □ | |
| VAL-066 | 境界値 | setting_value 1001文字 | DS-5 | 1. setting_value に1001文字を入力<br>2. 保存 | バリデーションエラー（maxLength(1000)）。DB に保存されない | `SettingsTable.php:L62` | 可 | P2 | □ | |
| VAL-067 | 異常系 | ログ log_type 51文字 | DS-1 | 1. ib_logs.log_type に51文字を設定 | バリデーションエラー（maxLength(50)）。DB に保存されない | `LogsTable.php:L60` | 可 | P2 | □ | |
| VAL-068 | 異常系 | ログ log_content 1001文字 | DS-1 | 1. ib_logs.log_content に1001文字を設定 | バリデーションエラー（maxLength(1000)）。DB に保存されない | `LogsTable.php:L65` | 可 | P2 | □ | |
| VAL-069 | 異常系 | ログ user_ip 51文字 | DS-1 | 1. ib_logs.user_ip に51文字を設定 | バリデーションエラー（maxLength(50)）。DB に保存されない | `LogsTable.php:L70` | 可 | P2 | □ | |
| VAL-070 | 異常系 | ログ user_agent 1001文字 | DS-1 | 1. ib_logs.user_agent に1001文字を設定 | バリデーションエラー（maxLength(1000)）。DB に保存されない | `LogsTable.php:L75` | 可 | P2 | □ | |

---

## 5. ファイルアップロード（一般ファイル）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-071 | 正常系 | 許可拡張子（.pdf） | DS-2 | 1. コンテンツ追加で種別=ファイル<br>2. ファイル欄に `test.pdf` を選択<br>3. 保存 | ファイルが `ROOT/files/` に保存される。`mode=complete` で表示。DB にファイル名が保存される | `ContentsController.php:L258-272` | 可 | P0 | □ | |
| VAL-072 | 正常系 | 許可拡張子一覧確認 | DS-2 | 1. 22種の許可拡張子（.png/.gif/.jpg/.jpeg/.pdf/.zip/.ppt/.pptx/.pps/.ppsx/.doc/.docx/.xls/.xlsx/.txt/.mov/.mp4/.wmv/.asx/.mp3/.wma/.m4a）を全て試行 | 全てアップロード成功 | `ib_config.php:upload_extensions` | 可 | P1 | □ | |
| VAL-073 | 異常系 | 非許可拡張子（.exe） | DS-2 | 1. ファイル欄に `evil.exe` を選択<br>2. 保存 | `アップロードされたファイルの形式は許可されていません` と表示。DB に保存されない | `ContentsController.php:L260-262` | 可 | P0 | □ | |
| VAL-074 | 異常系 | 非許可拡張子（.php） | DS-2 | 1. ファイル欄に `shell.php` を選択<br>2. 保存 | `アップロードされたファイルの形式は許可されていません` と表示。DB に保存されない | `ContentsController.php:L260-262` | 可 | P0 | □ | |
| VAL-075 | セキュリティ | 二重拡張子（evil.php.jpg） | DS-2 | 1. ファイル名 `evil.php.jpg` を選択<br>2. 保存 | `strtolower(pathinfo(…, PATHINFO_EXTENSION))` で `jpg` が判定されるため許可。ただし実行されないようファイル名は日時+ランダムに変更される | `ContentsController.php:L258,265` | 難 | P0 | □ | |
| VAL-076 | セキュリティ | 拡張子なしファイル | DS-2 | 1. 拡張子のないファイル `Makefile` を選択<br>2. 保存 | pathinfo が空文字を返す → `'.' . '' = '.'` → 配列にないため「形式は許可されていません」 | `ContentsController.php:L258-262` | 難 | P1 | □ | |
| VAL-077 | 異常系 | 大文字拡張子（.PDF） | DS-2 | 1. ファイル名 `TEST.PDF` を選択<br>2. 保存 | `strtolower()` で `.pdf` に変換される。アップロード成功 | `ContentsController.php:L258` | 可 | P1 | □ | |
| VAL-078 | 境界値 | サイズ上限ちょうど（10MB） | DS-2 | 1. 10MB（10×1024×1024 = 10485760 バイト）の `.pdf` をアップロード | アップロード成功。`upload_maxsize=10*1024*1024` の範囲内 | `ib_config.php:upload_maxsize` | 可 | P0 | □ | |
| VAL-079 | 境界値 | サイズ上限+1（10MB+1） | DS-2 | 1. 10485761 バイトの `.pdf` をアップロード | アップロード失敗（PHP の upload_max_filesize に依存、CakePHP の validation では制限なし）。ファイルは保存されない | `ib_config.php:upload_maxsize` | 可 | P1 | □ | |
| VAL-080 | 異常系 | ファイル未指定 | DS-2 | 1. ファイル欄を指定せずに保存 | `ファイルが指定されていません` と表示 | `ContentsController.php:L279-281` | 可 | P0 | □ | |
| VAL-081 | セキュリティ | ファイル名パストラバーサル | DS-2 | 1. ファイル名を `../../etc/passwd` に改変して送信（HTTP リクエスト直接操作） | pathinfo で拡張子 `passwd` が取得される → 非許可拡張子として拒否。また moveTo でディレクトリ外出力が防止される | `ContentsController.php:L258,268` | 難 | P0 | □ | |
| VAL-082 | セキュリティ | マルチバイト拡張子 | DS-2 | 1. ファイル名に UTF-8 のマルチバイト拡張子 `test。pdf`（全角句点）を設定 | pathinfo が空文字を返す → 非許可拡張子として拒否 | `ContentsController.php:L258-262` | 難 | P2 | □ | |

---

## 6. ファイルアップロード（画像ファイル / uploadImage AJAX）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-083 | 正常系 | 画像アップロード（AJAX） | DS-2 | 1. リッチテキストエディタから画像 `photo.jpg` を貼り付け | JSON 応答 `[{「url」:「…/contents/file_image/…」}]` が返る。`ROOT/files/` に保存される | `ContentsController.php:L295-326` | 可 | P0 | □ | |
| VAL-084 | 正常系 | 画像サイズ上限（2MB） | DS-2 | 1. 2MB（2097152 バイト）の `.png` を AJAX でアップロード | アップロード成功。JSON に URL が返る | `ib_config.php:upload_image_maxsize` | 可 | P1 | □ | |
| VAL-085 | 異常系 | 画像サイズ超過 | DS-2 | 1. 3MB の `.png` を AJAX でアップロード | PHP の upload_max_filesize に依存。エラー時は JSON に `false` が返る | `ib_config.php:upload_image_maxsize` | 難 | P1 | □ | |
| VAL-086 | セキュリティ | uploadImage 拡張子検証なし | DS-2 | 1. AJAX で `evil.php` を画像として送信（Content-Type: image/jpeg で偽装） | **拡張子チェックが `uploadImage` にないため**、`.php` ファイルがそのまま保存される可能性あり（`ROOT/files/` に `…php` で保存）。これは既知の脆弱性 | `ContentsController.php:L307-312`（拡張子チェックなし） | 難 | P0 | □ | |
| VAL-087 | セキュリティ | Content-Type 偽装 | DS-2 | 1. テキストファイルの Content-Type を `image/jpeg` に変更して送信 | pathinfo で実際の拡張子が判定される。非許可拡張子なら拒否 | `ContentsController.php:L258` | 難 | P1 | □ | |
| VAL-088 | 異常系 | 非AJAX で uploadImage アクセス | DS-0 | 1. ブラウザから `POST /admin/contents/upload_image` を直接アクセス（非 AJAX） | `autoRender=false` のため空レスポンス。アップロード処理は実行されない | `ContentsController.php:L297-299` | 可 | P1 | □ | |
| VAL-089 | 異常系 | 動画サイズ上限確認 | DS-2 | 1. 種別=動画で `upload_movie_maxsize=10MB` の範囲内で動画ファイルをアップロード | アップロード成功。`upload_movie_extensions`（.mov/.mp4/.wmv/.asx）で許可される | `ib_config.php:upload_movie_maxsize,upload_movie_extensions` | 可 | P1 | □ | |

---

## 7. CSV インポート（ユーザー一括取込）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-090 | 正常系 | 正常インポート | DS-0 | 1. ヘッダ行 + データ1行の SJIS-Win CSV を用意<br>2. 管理画面 > ユーザ > CSV インポートでファイル選択<br>3. 実行 | `インポートが完了しました` と表示。DB にユーザーが1件追加。リダイレクト先の一覧に表示される | `UsersController.php:L343-523` | 可 | P0 | □ | |
| VAL-091 | 正常系 | ヘッダ行スキップ確認 | DS-0 | 1. ヘッダ行を含む CSV をインポート | 1行目（ヘッダ）はスキップされ、2行目からデータとして処理される | `UsersController.php:L401-403` | 可 | P0 | □ | |
| VAL-092 | 異常系 | ヘッダなしCSV | DS-0 | 1. ヘッダ行なしの CSV（データ行のみ）をインポート | 1行目がスキップされるため、実質1行目データが失われる | `UsersController.php:L401-403` | 可 | P1 | □ | |
| VAL-093 | 異常系 | 列数不足（5列未満） | DS-0 | 1. ヘッダ + データ行が4列（ログインID,パスワード,氏名のみ）の CSV を用意<br>2. インポート | count($row) < 5 でスキップ。`ib_users` に追加されない | `UsersController.php:L405-407` | 可 | P0 | □ | |
| VAL-094 | 正常系 | 列数過多（余分な列あり） | DS-0 | 1. ヘッダ + データ行に余分な列を追加した CSV をインポート | 余分な列は無視され、5列以上のデータは正常に処理される | `UsersController.php:L405-407` | 可 | P2 | □ | |
| VAL-095 | 異常系 | 重複ログインID | DS-1 | 1. DS-1 の既存ユーザーと同じログインIDを持つ CSV をインポート | 既存ユーザーが patch される（上書き）。エラーにはならない | `UsersController.php:L414-419,484-486` | 可 | P0 | □ | |
| VAL-096 | 異常系 | 存在しないグループ名 | DS-0 | 1. グループ列に存在しないグループ名 `存在しないグループ` を記載した CSV をインポート | `getIdByTitle` が null を返すため、そのグループ割り当てはスキップ。エラーにはならない | `UsersController.php:L450-454` | 可 | P1 | □ | |
| VAL-097 | 異常系 | 存在しないコース名 | DS-0 | 1. コース列に存在しないコース名を記載した CSV をインポート | `getIdByTitle` が null を返すため、そのコース割り当てはスキップ。エラーにはならない | `UsersController.php:L467-471` | 可 | P1 | □ | |
| VAL-098 | 異常系 | 不正な権限値 | DS-0 | 1. 権限列に `superuser`（設定にない値）を記載した CSV をインポート | `getKeyByValue('user_role', 'superuser')` が null を返し、role が null で保存を試行 → バリデーションエラー（`権限が指定されていません`）。全行ロールバック | `UsersController.php:L432,488-507` | 可 | P0 | □ | |
| VAL-099 | 異常系 | 空行を含むCSV | DS-0 | 1. データ行の間に空行を含む CSV をインポート | count($row) < 5 で空行はスキップ。空行以外のデータは正常処理 | `UsersController.php:L405-407` | 可 | P2 | □ | |
| VAL-100 | 正常系 | Shift_JIS エンコーディング | DS-0 | 1. SJIS-Win エンコーディングの CSV を用意<br>2. インポート | `mb_convert_encoding('UTF-8', 'SJIS-Win')` で UTF-8 変換後、正常処理 | `Utils.php:L103-104` | 可 | P0 | □ | |
| VAL-101 | 異常系 | UTF-8 のままインポート | DS-0 | 1. UTF-8 エンコーディングの CSV をそのままインポート | SJIS→UTF-8 変換が二重に実行され、文字化けの可能性。動作は環境依存 | `Utils.php:L103-104` | 難 | P1 | □ | |
| VAL-102 | 異常系 | 空ファイル | DS-0 | 1. 空ファイル（0バイト）をインポート | インポートファイル読み込みエラー、または空の CSV として処理。DB に変化なし | `UsersController.php:L373-380` | 可 | P1 | □ | |
| VAL-103 | 異常系 | ファイル未指定 | DS-0 | 1. CSV ファイルを指定せずにインポート実行 | `インポートファイルが指定されていません` と表示。DB に変化なし | `UsersController.php:L376-380` | 可 | P0 | □ | |
| VAL-104 | 整合性 | インポート後件数 | DS-0 | 1. ユーザ2行の CSV をインポート<br>2. `SELECT COUNT(*) FROM ib_users` を実行 | 件数がインポート行数分増加する（admin + 新規2件 = 3件） | `UsersController.php:L482-488` | 可 | P0 | □ | |
| VAL-105 | 異常系 | 10超のグループ列 | DS-0 | 1. グループ列を11列分持つ CSV をインポート | `import_group_count=10` のため、11列目以降は無視される（ループが10回） | `UsersController.php:L443,L460` | 可 | P2 | □ | |
| VAL-106 | 異常系 | 20超のコース列 | DS-0 | 1. コース列を21列分持つ CSV をインポート | `import_course_count=20` のため、21列目以降は無視される | `UsersController.php:L460` | 可 | P2 | □ | |

---

## 8. CSV エクスポート（ユーザー一覧）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-107 | 正常系 | ヘッダ行確認 | DS-1 | 1. ユーザ一覧で CSV ダウンロード<br>2. ダウンロードしたファイルの1行目を確認 | ヘッダ: ログインID,パスワード,氏名,権限,メールアドレス,備考 + グループ1〜10 + コース1〜20（計36列） | `UsersController.php:L544-559` | 可 | P0 | □ | |
| VAL-108 | 正常系 | データ行・列順 | DS-1 | 1. CSV ダウンロード<br>2. データ行の列順を確認 | ヘッダ順序と一致。パスワード列は空文字 | `UsersController.php:L611-628` | 可 | P0 | □ | |
| VAL-109 | 正常系 | SJIS-WIN エンコーディング | DS-1 | 1. CSV ダウンロード<br>2. `file` コマンドまたはヘッダで文字コードを確認 | SJIS-WIN で出力される | `UsersController.php:L574,631` | 可 | P0 | □ | |
| VAL-110 | 正常系 | Content-Disposition | DS-1 | 1. CSV ダウンロード<br>2. レスポンスヘッダを確認 | `Content-Disposition: attachment; filename="users_Ymd.csv"` | `UsersController.php:L640` | 可 | P1 | □ | |
| VAL-111 | 正常系 | パスワード列は空 | DS-1 | 1. CSV ダウンロード<br>2. パスワード列の値を確認 | 全行でパスワード列は空文字 `""` | `UsersController.php:L613` | 可 | P0 | □ | |
| VAL-112 | 異常系 | ユーザ0件時の出力 | DS-0 | 1. ユーザが admin のみの状態で CSV ダウンロード | ヘッダ行のみ出力（ヘッダ + admin 1行） | `UsersController.php:L567` | 可 | P1 | □ | |

---

## 9. CSV エクスポート（学習履歴）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-113 | 正常系 | ヘッダ行確認 | DS-4 | 1. 学習履歴で CSV ダウンロード<br>2. 1行目を確認 | ヘッダ: ログインID,氏名,コース,コンテンツ,得点,合格点,結果,理解度,学習時間,学習日時（計10列） | `RecordsController.php:L136-147` | 可 | P0 | □ | |
| VAL-114 | 正常系 | 学習時間のフォーマット | DS-4 | 1. 学習履歴 CSV をダウンロード<br>2. 学習時間列の値を確認 | `getHNSBySec()` で `HH:MM:SS` 形式。例: 3661秒 → `01:01:01` | `Utils.php:L82-91` | 可 | P1 | □ | |
| VAL-115 | 正常系 | 学習日時のフォーマット | DS-4 | 1. 学習履歴 CSV をダウンロード<br>2. 学習日時列の値を確認 | `getYMDHN()` で `Y-m-d H:i` 形式 | `Utils.php:L65-74` | 可 | P1 | □ | |
| VAL-116 | 正常系 | SJIS-WIN エンコーディング | DS-4 | 1. CSV ダウンロード<br>2. 文字コードを確認 | SJIS-WIN で出力 | `RecordsController.php:L152,169` | 可 | P1 | □ | |

---

## 10. CSV エクスポート（テスト結果詳細）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-117 | 正常系 | ヘッダ行確認 | DS-4 | 1. テスト結果詳細で CSV ダウンロード<br>2. 1行目を確認 | ヘッダ: ログインID,氏名,コース,コンテンツ,番号,タイトル,問題/質問,解答/回答,正誤,学習日時（計10列） | `RecordsController.php:L207-218` | 可 | P0 | □ | |
| VAL-118 | 正常系 | label 種別は除外 | DS-3 | 1. アンケート問題（kind=enquete、question_type=label）が存在する状態で詳細 CSV を出力 | question_type=label の行は含まれない（`!=` 条件で除外） | `RecordsController.php:L192` | 可 | P1 | □ | |
| VAL-119 | 正常系 | 番号の連番 | DS-4 | 1. 同一レコードに複数問題がある状態で CSV を出力<br>2. 番号列を確認 | 同一レコード内で 1, 2, 3… と連番。次のレコードで再び 1 から開始 | `RecordsController.php:L230-234` | 可 | P1 | □ | |
| VAL-120 | セキュリティ | 数式インジェクション防止 | DS-4 | 1. answer が `=SUM(A1:A10)` のような数式を含む text 問題を作成<br>2. 詳細 CSV を出力<br>3. Excel で開く | `preg_match('/^\s*[=+\-@]/', $answer)` で先頭が `=` の場合、先頭に `'` が付与され `'=SUM(A1:A10)` として出力。Excel で数式として実行されない | `RecordsController.php:L242-243` | 可 | P0 | □ | |
| VAL-121 | セキュリティ | 数式インジェクション（+） | DS-4 | 1. answer が `+123` のような文字列を含む text 問題を作成<br>2. 詳細 CSV を出力 | 先頭に `'` が付与され `'+123` として出力 | `RecordsController.php:L242-243` | 可 | P1 | □ | |
| VAL-122 | セキュリティ | 数式インジェクション（-） | DS-4 | 1. answer が `-100` のような文字列を含む text 問題を作成<br>2. 詳細 CSV を出力 | 先頭に `'` が付与され `'-100` として出力 | `RecordsController.php:L242-243` | 可 | P1 | □ | |
| VAL-123 | セキュリティ | 数式インジェクション（@） | DS-4 | 1. answer が `@SUM` のような文字列を含む text 問題を作成<br>2. 詳細 CSV を出力 | 先頭に `'` が付与され `'@SUM` として出力 | `RecordsController.php:L242-243` | 可 | P1 | □ | |
| VAL-124 | 正常系 | single 問題の解答変換 | DS-4 | 1. single 問題の選択式回答（例: `1`）を含む CSV を出力 | `options` を `\|` で分割し、回答番号から選択肢テキストに変換。例: options=`A\|B\|C`, answer=`2` → `B` | `RecordsController.php:L246-255` | 可 | P1 | □ | |
| VAL-125 | 正常系 | ダブルクォート・カンマ・改行を含む値 | DS-4 | 1. 問題タイトルに `テ"スト,値` を含む問題を作成<br>2. CSV を出力 | fputcsv でダブルクォートで囲まれ、内部ダブルクォートはエスケープされる | `RecordsController.php:L276` / `Utils.php:L294` | 可 | P1 | □ | |
| VAL-126 | 正常系 | 正誤列表示 | DS-4 | 1. テスト結果（kind=test）の詳細 CSV を出力 | `is_correct` に応じた表示（`Configure::read('is_correct.' . $row->is_correct)`） | `RecordsController.php:L257-259` | 可 | P1 | □ | |
| VAL-127 | 正常系 | strip_tags 適用 | DS-4 | 1. 問題本文に `<p>テスト</p>` を含む問題を作成<br>2. CSV を出力 | body から HTML タグが除去され `テスト` として出力 | `RecordsController.php:L269` | 可 | P1 | □ | |

---

## 11. 画面表示・エラー表示の整合性

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| VAL-128 | UI | バリデーションエラー時の再表示 | DS-0 | 1. ユーザ追加で全必須欄を空にして保存 | エラーメッセージが表示され、フォーム入力値（空のまま）が再表示される | 全 Table validationDefault | 難 | P1 | □ | |
| VAL-129 | UI | 既存値保持（編集時） | DS-1 | 1. 既存ユーザを編集画面で開く<br>2. name を変更せずに保存 | name 以外の既存値（username, role 等）が保持される | CakePHP entity | 難 | P1 | □ | |
| VAL-130 | UI | 設定値の maxLength エラー表示 | DS-5 | 1. setting_value に1001文字を入力して保存 | フォームにエラーメッセージが表示され、入力値が再表示される | `SettingsTable.php:L62` | 難 | P2 | □ | |

---

## 集計表

| 分類 | 項目数 | 項目ID一覧 |
|---|---|---|
| 正常系 | 38 | VAL-001,VAL-002,VAL-013,VAL-017,VAL-019,VAL-023,VAL-024,VAL-025,VAL-029,VAL-043,VAL-054,VAL-055,VAL-059,VAL-063,VAL-071,VAL-072,VAL-083,VAL-084,VAL-090,VAL-091,VAL-094,VAL-100,VAL-107,VAL-108,VAL-109,VAL-110,VAL-111,VAL-113,VAL-114,VAL-115,VAL-116,VAL-117,VAL-118,VAL-119,VAL-124,VAL-125,VAL-126,VAL-127 |
| 異常系 | 48 | VAL-006,VAL-007,VAL-008,VAL-009,VAL-010,VAL-011,VAL-012,VAL-016,VAL-018,VAL-022,VAL-028,VAL-030,VAL-031,VAL-032,VAL-033,VAL-034,VAL-044,VAL-049,VAL-050,VAL-051,VAL-052,VAL-053,VAL-056,VAL-060,VAL-067,VAL-068,VAL-069,VAL-070,VAL-073,VAL-074,VAL-077,VAL-080,VAL-085,VAL-088,VAL-089,VAL-092,VAL-093,VAL-095,VAL-096,VAL-097,VAL-098,VAL-099,VAL-101,VAL-102,VAL-103,VAL-105,VAL-106,VAL-112 |
| 境界値 | 30 | VAL-003,VAL-004,VAL-005,VAL-014,VAL-015,VAL-020,VAL-021,VAL-026,VAL-027,VAL-035,VAL-036,VAL-037,VAL-038,VAL-039,VAL-040,VAL-041,VAL-042,VAL-045,VAL-046,VAL-047,VAL-048,VAL-057,VAL-058,VAL-061,VAL-062,VAL-064,VAL-065,VAL-066,VAL-078,VAL-079 |
| セキュリティ | 10 | VAL-075,VAL-076,VAL-081,VAL-082,VAL-086,VAL-087,VAL-120,VAL-121,VAL-122,VAL-123 |
| 整合性 | 1 | VAL-104 |
| UI | 3 | VAL-128,VAL-129,VAL-130 |
| **合計** | **130** | |

| 優先度 | 項目数 |
|---|---|
| P0 | 55 |
| P1 | 52 |
| P2 | 23 |
