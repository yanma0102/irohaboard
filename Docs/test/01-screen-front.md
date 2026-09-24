# フロント画面試験項目マトリクス（受講者向け）

## 対象

フロント画面（`/` 以降の全ルート）。ログイン、トップ、コンテンツ一覧・表示、クイズ受験、アンケート回答、お知らせ、ファイルDL・動画・画像ストリーミング、ユーザー設定、ページ表示、エラーページ。

## 根拠

- `config/routes.php`（フロントは全ルート明示。fallbacks なし）
- `src/Controller/UsersController.php`、`UsersCoursesController.php`、`ContentsController.php`、`ContentsQuestionsController.php`、`EnquetesQuestionsController.php`、`InfosController.php`、`RecordsController.php`、`PagesController.php`
- `src/Controller/Trait/UserLoginTrait.php`
- `src/Controller/AppController.php`、`ErrorController.php`
- `config/ib_config.php`
- `Docs/design/06-routing.md`、`Docs/design/07-controllers.md`、`Docs/design/09-views.md`
- `Docs/dev/manual-test-checklist.md`（T1-1〜T5-6）

## 前提データ（DS）

実施前に DS-0〜DS-6 を投入する。§4「実施手順」に従い、DS-0 の空状態項目を先に実施する。ログイン系（FR-001〜FR-010）は**最後**にまとめる（ログイン画面 GET はセッションを破棄するため）。

---

## 1. ログイン・ログアウト

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-001 | 正常系 | ログイン画面表示 | DS-0 | 1. `GET /users/login` にアクセスする | HTTP 200。画面タイトル「受講者ログイン」、ログインID入力欄・パスワード入力欄・「ログイン」ボタンが表示される。show_admin_link=false なら「管理者ログインへ」リンク非表示 | T1-1, `UsersController:login`, `templates/Users/login.php` | 可 | P0 | □ | |
| FR-002 | 正常系 | 受講者ログイン成功 | DS-1 | 1. `GET /users/login` にアクセスする<br>2. ログインID=user、パスワードを入力<br>3. 「ログイン」ボタンをクリック（POST） | HTTP 302 → `/`（UsersCourses::index）にリダイレクト。セッションに user 情報が保存される | T1-1, `UserLoginTrait:performLogin`, `AuthenticationComponent` | 可 | P0 | □ | |
| FR-003 | 異常系 | ログイン失敗（パスワード間違い） | DS-1 | 1. `GET /users/login` にアクセスする<br>2. ログインID=user、誤ったパスワードを入力<br>3. POST する | HTTP 302 → `/users/login`。Flash エラー「ログインIDまたはパスワードが違います」。Logs テーブルに `login_error` レコード追加 | T1-2, `UserLoginTrait:performLogin` | 可 | P0 | □ | |
| FR-004 | 異常系 | ログインブロック（10回失敗） | DS-1 | 1. ログインID=user でパスワードを誤り 10 回 POST する<br>2. 11 回目に正しいパスワードで POST する | 10 回目まで毎回 Flash エラー。11 回目も `_isLoginBlocked()` によりエラー（Logs に `login_error` >= 10 件）。ログイン不可 | `UserLoginTrait:_isLoginBlocked`, Logs テーブル | 可 | P0 | □ | |
| FR-005 | 正常系 | ログアウト | DS-1（ログイン済み） | 1. ログイン状態で `GET /users/logout` にアクセスする | HTTP 302 → `/users/login`。CookieAuth トークン無効化、Auth クッキー削除、LoginStatus クッキー削除。セッション user 情報消去 | T1-4, `UsersController:logout` | 可 | P0 | □ | |
| FR-006 | 正常系 | RememberMe チェックボックス表示 | DS-1 | 1. HTTPS 環境で `GET /users/login` にアクセスする | RememberMe チェックボックス（「ログイン状態を保持」）が表示される | T1-6, `templates/Users/login.php`, `isHTTPS()` | 可 | P1 | □ | |
| FR-007 | 正常系 | RememberMe ログイン維持 | DS-1 | 1. HTTPS で `GET /users/login` にアクセス<br>2. RememberMe にチェック → ログイン<br>3. ブラウザを閉じる<br>4. 再度 `GET /` にアクセス | ログイン状態が維持され、トップ画面が表示される。CookieAuth トークンが Cookie に保存（有効期限 14 日） | T1-5, `UserLoginTrait:performLogin`, `ib_config:remember_token_expired_days` | 難 | P1 | □ | |
| FR-008 | 境界値 | RememberMe 非 HTTPS 時に非表示 | DS-1 | 1. HTTP（非 HTTPS）で `GET /users/login` にアクセスする | RememberMe チェックボックスが**表示されない** | T1-6（逆）, `templates/Users/login.php`, `isHTTPS()` | 可 | P1 | □ | |
| FR-009 | セキュリティ | ログインID/パスワードの入力形式制限 | DS-1 | 1. ログインIDに `^[a-zA-Z0-9@_.+-]+$` にマッチしない文字（スペース、日本語）を含む値を POST する | HTTP 302 → `/users/login`。Flash エラー「ログインIDまたはパスワードの形式が正しくありません」 | `UserLoginTrait:performLogin`（フォーマットバリデーション） | 可 | P0 | □ | |
| FR-010 | セキュリティ | ログインID/パスワードの最大長超過 | DS-1 | 1. ログインIDに 101 文字以上の文字列を POST する | HTTP 302 → `/users/login`。Flash エラー（最大 100 文字） | `UserLoginTrait:performLogin` | 可 | P1 | □ | |

## 2. トップ画面（ユーザー受講コース一覧）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-011 | 正常系 | トップ画面表示（コースあり） | DS-1, DS-2 | 1. ログイン状態で `GET /` にアクセスする | HTTP 200。受講可能なコース一覧が表示される。各コースに学習進捗（進捗率、理解度）が表示される | T2-1, `UsersCoursesController:index` | 可 | P0 | □ | |
| FR-012 | 正常系 | トップ画面にお知らせ表示 | DS-1, DS-5 | 1. ログイン状態で `GET /` にアクセスする | お知らせが最大 2 件表示される。DS-5 の「全体」お知らせのみ表示（グループ限定は非表示） | `UsersCoursesController:index`, `Infos` テーブル | 可 | P1 | □ | |
| FR-013 | UI | お知らせがない場合 | DS-1（お知らせ 0 件） | 1. ログイン状態で `GET /` にアクセスする | 「お知らせはありません」と表示される | `UsersCoursesController:index` | 可 | P1 | □ | |
| FR-014 | UI | 受講コースがない場合 | DS-0（admin のみ） | 1. admin でログインし `GET /` にアクセスする | 「受講可能なコースはありません」と表示される | `UsersCoursesController:index` | 可 | P1 | □ | |
| FR-015 | 権限 | 未ログインでトップ画面にアクセス | DS-0 | 1. Cookie を持たない状態で `GET /` にアクセスする | HTTP 302 → `/users/login` にリダイレクト | `AppController:beforeFilter` | 可 | P0 | □ | |

## 3. ユーザー設定・パスワード変更

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-016 | 正常系 | ユーザー設定画面表示 | DS-1 | 1. ログイン状態で `GET /users/setting` にアクセスする | HTTP 200。ユーザー情報（ログインID、名前等）が表示される | `UsersController:setting` | 可 | P1 | □ | |
| FR-017 | 正常系 | パスワード変更成功 | DS-1 | 1. `GET /users/setting` にアクセス<br>2. 新パスワードと確認パスワードを一致させて入力<br>3. POST する | HTTP 302 → リダイレクト。Flash 成功「パスワードを変更しました」。DB のパスワードが bcrypt 化で更新される。RememberMe トークン無効化 | `UsersController:setting` | 可 | P0 | □ | |
| FR-018 | 異常系 | パスワード変更（確認パスワード不一致） | DS-1 | 1. 新パスワードと確認パスワードに異なる値を入力<br>2. POST する | Flash エラー。パスワードは変更されない | `UsersController:setting` | 可 | P0 | □ | |
| FR-019 | 異常系 | GET のみ許可 | DS-1 | 1. `DELETE /users/setting` を送信する | HTTP 405 Method Not Allowed | `UsersController:setting`（`allowMethod(['get', 'post'])`） | 可 | P1 | □ | |

## 4. コンテンツ一覧

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-020 | 正常系 | コンテンツ一覧表示 | DS-1, DS-2 | 1. ログイン状態で `GET /contents/index/{course_id}` にアクセスする | HTTP 200。コース内のコンテンツ一覧が sort_no 順に表示される。種別アイコン・タイトルが表示される | T3-1, `ContentsController:index` | 可 | P0 | □ | |
| FR-021 | 正常系 | 学習履歴付き表示 | DS-1, DS-2, DS-4 | 1. ログイン状態で `GET /contents/index/{course_id}` にアクセスする | 学習履歴（合格/不合格/未完了）が各コンテンツに表示される。理解度表示あり | T3-1, `ContentsController:index`, `Records` テーブル | 可 | P0 | □ | |
| FR-022 | 権限 | 他コースのコンテンツ一覧に直接アクセス | DS-1, DS-2 | 1. DS-2 に含まれない course_id で `GET /contents/index/{id}` にアクセスする | HTTP 404。`hasRight()` チェックにより拒否 | `ContentsController:index`, `Courses::hasRight` | 可 | P0 | □ | |
| FR-023 | セキュリティ | 非公開コンテンツ（status=0）の非表示 | DS-1, DS-2 | 1. 受講者（user）でログイン<br>2. 非公開コンテンツを含むコースの `GET /contents/index/{course_id}` にアクセス | 非公開コンテンツ（status=0）は一覧に表示されない。公開コンテンツのみ表示 | `ContentsController:index`（`status != 1` チェック） | 可 | P0 | □ | |
| FR-024 | 整合性 | admin は非公開コンテンツも表示 | DS-1, DS-2 | 1. admin でログイン<br>2. `GET /contents/index/{course_id}/{user_id}` で履歴確認モード | 非公開コンテンツも一覧に表示される | `ContentsController:index`（admin 判定分岐） | 可 | P1 | □ | |
| FR-025 | 正常系 | 他ユーザー履歴確認（admin） | DS-1, DS-2, DS-4 | 1. admin でログイン<br>2. `GET /contents/index/{course_id}/{user_id}` にアクセス | 指定 user_id の学習履歴が表示される | `ContentsController:index` | 可 | P1 | □ | |
| FR-026 | セキュリティ | 非 admin が他ユーザー履歴にアクセス | DS-1 | 1. user でログイン<br>2. `GET /contents/index/{course_id}/{other_user_id}` にアクセス | HTTP 404。非 admin は他ユーザー履歴を参照不可 | `ContentsController:index` | 可 | P0 | □ | |

## 5. コンテンツ表示（種別ごと）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-027 | 正常系 | HTML コンテンツ表示 | DS-1, DS-2（kind=html） | 1. ログイン状態で `GET /contents/view/{content_id}` にアクセス（kind=html） | HTTP 200。HTML コンテンツが fullPage=false で表示される | T3-10, `ContentsController:view` | 可 | P0 | □ | |
| FR-028 | 正常系 | 動画（movie）コンテンツ表示 | DS-1, DS-2（kind=movie） | 1. `GET /contents/view/{content_id}` にアクセス（kind=movie） | HTTP 200。動画プレーヤーが表示される | T3-8, `ContentsController:view` | 可 | P0 | □ | |
| FR-029 | 正常系 | URL コンテンツ表示 | DS-1, DS-2（kind=url） | 1. `GET /contents/view/{content_id}` にアクセス（kind=url） | HTTP 200。iframe で指定 URL が表示される | T3-7, `ContentsController:view` | 可 | P0 | □ | |
| FR-030 | 正常系 | ファイル（file）コンテンツ表示 | DS-1, DS-2（kind=file） | 1. `GET /contents/view/{content_id}` にアクセス（kind=file） | HTTP 200。ダウンロードリンクが表示される | T3-6, `ContentsController:view` | 可 | P0 | □ | |
| FR-031 | 正常系 | テスト（test）コンテンツ → 問題一覧へ遷移 | DS-1, DS-2（kind=test） | 1. `GET /contents/view/{content_id}` にアクセス（kind=test） | HTTP 302 → `/contents-questions/index/{content_id}` にリダイレクト | T3-3, `ContentsController:view` | 可 | P0 | □ | |
| FR-032 | 正常系 | アンケート（enquete）コンテンツ → 回答画面へ遷移 | DS-1, DS-2（kind=enquete） | 1. `GET /contents/view/{content_id}` にアクセス（kind=enquete） | HTTP 302 → `/enquetes-questions/index/{content_id}` にリダイレクト | `ContentsController:view` | 可 | P0 | □ | |
| FR-033 | 異常系 | 存在しないコンテンツにアクセス | DS-1 | 1. `GET /contents/view/99999` にアクセスする | HTTP 404。NotFoundException | `ContentsController:view` | 可 | P0 | □ | |
| FR-034 | 権限 | 他コースのコンテンツに直接アクセス | DS-1, DS-2 | 1. 自分のコースに含まれない content_id で `GET /contents/view/{content_id}` にアクセス | HTTP 404。`hasRight()` チェックにより拒否 | `ContentsController:view`, `Courses::hasRight` | 可 | P0 | □ | |
| FR-035 | セキュリティ | 非公開コンテンツ（status=0）へのアクセス拒否（受講者） | DS-1, DS-2 | 1. 受講者（user）でログイン<br>2. 非公開コンテンツの `GET /contents/view/{content_id}` にアクセス | HTTP 404。非公開コンテンツへの直接アクセスは拒否 | `ContentsController:view`（`status != 1`） | 可 | P0 | □ | |
| FR-036 | 整合性 | admin は非公開コンテンツにアクセス可能 | DS-1, DS-2 | 1. admin でログイン<br>2. 非公開コンテンツの `GET /contents/view/{content_id}` にアクセス | HTTP 200。コンテンツが表示される | `ContentsController:view`（admin 判定分岐） | 可 | P1 | □ | |

## 6. クイズ受験

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-037 | 正常系 | テスト問題一覧表示 | DS-1, DS-2（kind=test）, DS-3 | 1. `GET /contents-questions/index/{content_id}` にアクセスする | HTTP 200。問題一覧が表示される。問題は RAND() でランダム選出、FIELD() で順序付け。セッションにランダム問題リストがキャッシュされる | T3-3, `ContentsQuestionsController:index` | 可 | P0 | □ | |
| FR-038 | 正常系 | クイズ受験・採点（single） | DS-1, DS-2, DS-3 | 1. 問題一覧を表示<br>2. 各問題に回答<br>3. POST で送信 | 採点結果が表示される。単一正解は配列一致で判定。pass_rate に応じて合格/不合格。Records に is_passed=1（合格）または 0（不合格）保存 | T3-4, `ContentsQuestionsController:index`, `isMultiCorrect` | 可 | P0 | □ | |
| FR-039 | 正常系 | クイズ受験・採点（複数正解） | DS-1, DS-2, DS-3（複数正解） | 1. 複数正解の問題に回答<br>2. 一部の正解のみ選択して POST | 完全一致のみ正解。部分一致は不正解（`isMultiCorrect` で exact match）。pass_rate で合否判定 | `ContentsQuestionsController:isMultiCorrect` | 可 | P0 | □ | |
| FR-040 | 正常系 | タイムリミットありテスト | DS-1, DS-2, DS-3 | 1. タイムリミット付きテストを開始<br>2. 制限時間を超えて回答を POST | タイムリミット超過時は回答が記録されない、または警告表示（設計確認要）。Records に is_passed=0（不合格）が記録される | `ContentsQuestionsController:index` | 難 | P1 | □ | |
| FR-041 | 正常系 | テスト結果レコード表示 | DS-1, DS-2, DS-3, DS-4 | 1. テストを受験して結果を得る<br>2. `GET /contents-questions/record/{content_id}/{record_id}` にアクセス | HTTP 200。合否・得点・各問題の正解/不正解が表示される | T5-6, `ContentsQuestionsController:record` | 可 | P0 | □ | |
| FR-042 | 異常系 | 存在しないテストコンテンツにアクセス | DS-1 | 1. `GET /contents-questions/index/99999` にアクセスする | HTTP 404。NotFoundException | `ContentsQuestionsController:index` | 可 | P0 | □ | |
| FR-043 | 権限 | 他コースのテストに直接アクセス | DS-1, DS-2 | 1. 自分のコースに含まれない test コンテンツの `GET /contents-questions/index/{content_id}` にアクセス | HTTP 404。`hasRight()` チェックにより拒否 | `ContentsQuestionsController:index` | 可 | P0 | □ | |

## 7. アンケート回答

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-044 | 正常系 | アンケート問題一覧表示 | DS-1, DS-2（kind=enquete）, DS-3 | 1. `GET /enquetes-questions/index/{content_id}` にアクセスする | HTTP 200。アンケート問題一覧が表示される | `EnquetesQuestionsController:index` | 可 | P0 | □ | |
| FR-045 | 正常系 | アンケート回答・保存 | DS-1, DS-2, DS-3 | 1. アンケート問題一覧を表示<br>2. 各問題に回答<br>3. POST で送信 | 回答が保存される。is_passed=2（回答）、is_correct=-1 が Records に保存。採点なし | `EnquetesQuestionsController:index` | 可 | P0 | □ | |
| FR-046 | 正常系 | アンケート結果レコード表示 | DS-1, DS-2, DS-4 | 1. アンケートを回答する<br>2. `GET /enquetes-questions/record/{content_id}/{record_id}` にアクセス | HTTP 200。回答内容が表示される | `EnquetesQuestionsController:record` | 可 | P1 | □ | |
| FR-047 | 異常系 | 存在しないアンケートにアクセス | DS-1 | 1. `GET /enquetes-questions/index/99999` にアクセスする | HTTP 404。NotFoundException | `EnquetesQuestionsController:index` | 可 | P0 | □ | |

## 8. お知らせ一覧・詳細

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-048 | 正常系 | お知らせ一覧表示 | DS-1, DS-5 | 1. `GET /infos` にアクセスする | HTTP 200。お知らせ一覧がページネーション付きで表示される。公開範囲フィルタリング済み | `InfosController:index` | 可 | P0 | □ | |
| FR-049 | 正常系 | お知らせ詳細表示 | DS-1, DS-5 | 1. お知らせ一覧から一件クリック<br>2. `GET /infos/view/{info_id}` にアクセス | HTTP 200。お知らせの詳細が表示される | `InfosController:view` | 可 | P0 | □ | |
| FR-050 | セキュリティ | グループ限定お知らせの出し分け | DS-1, DS-5 | 1. user A（グループ 1 所属）でログイン<br>2. グループ 2 限定のお知らせに `GET /infos/view/{info_id}` でアクセス | HTTP 404（hasRight により拒否） | `InfosController:view`, `hasRight` | 可 | P0 | □ | |
| FR-051 | セキュリティ | 非公開お知らせへのアクセス拒否 | DS-1, DS-5 | 1. 非公開のお知らせの `GET /infos/view/{info_id}` にアクセス | HTTP 404。非公開お知らせにはアクセス不可 | `InfosController:view` | 可 | P0 | □ | |
| FR-052 | UI | グループ未所属ユーザーにお知らせの出し分け | DS-1（グループ未所属ユーザー） | 1. グループ未所属のユーザーでログイン<br>2. `GET /infos` にアクセス | グループ限定お知らせは一覧に表示されない。全体のお知らせのみ表示 | `InfosController:index`, `getInfoOption` | 可 | P1 | □ | |

## 9. ファイルダウンロード・動画・画像ストリーミング

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-053 | 正常系 | ファイルダウンロード | DS-1, DS-2（kind=file） | 1. ログイン状態で `GET /contents/file-download/{content_id}` にアクセス（kind=file） | HTTP 200。Content-Disposition: attachment でファイルがダウンロードされる。ファイルは files/ または webroot/uploads/ から検索される | `ContentsController:file_download` | 可 | P0 | □ | |
| FR-054 | 正常系 | 動画ストリーミング | DS-1, DS-2（kind=movie） | 1. ログイン状態で `GET /contents/file-movie/{content_id}` にアクセス（kind=movie） | HTTP 200。動画ファイルがストリーミングで返される。拡張子は mov/mp4/wmv/asx のみ許可 | T3-8, `ContentsController:file_movie` | 可 | P0 | □ | |
| FR-055 | 正常系 | 画像ストリーミング | DS-1, DS-2 | 1. ログイン状態で `GET /contents/file-image/{file_name}` にアクセス（有効な画像ファイル名） | HTTP 200。画像ファイルが返される。拡張子は png/gif/jpg/jpeg のみ許可 | `ContentsController:file_image` | 可 | P0 | □ | |
| FR-056 | 異常系 | 存在しないファイルのダウンロード | DS-1, DS-2 | 1. 存在しない content_id で `GET /contents/file-download/{content_id}` にアクセス | HTTP 404。NotFoundException | `ContentsController:file_download` | 可 | P0 | □ | |
| FR-057 | 異常系 | 存在しない動画ファイルにアクセス | DS-1, DS-2 | 1. 存在しない content_id で `GET /contents/file-movie/{content_id}` にアクセス | HTTP 404。NotFoundException | `ContentsController:file_movie` | 可 | P0 | □ | |
| FR-058 | セキュリティ | 存在しない画像ファイルにアクセス | DS-1 | 1. `GET /contents/file-image/nonexistent.jpg` にアクセス | HTTP 404。 NotFoundException | `ContentsController:file_image` | 可 | P0 | □ | |
| FR-059 | セキュリティ | 画像ストリーミングのパストラバーサル | DS-1 | 1. `GET /contents/file-image/..%2F..%2F..%2Fetc%2Fpasswd` にアクセス | HTTP 400 または 404。`^[a-zA-Z0-9_\-\.]+$` バリデーションと `.` 先頭禁止により拒否。ファイルが返されない | `ContentsController:file_image`（正規表現 + ドット先頭チェック） | 可 | P0 | □ | |
| FR-060 | セキュリティ | 画像ファイル名に不正な拡張子 | DS-1 | 1. `GET /contents/file-image/hack.exe` にアクセス | HTTP 404。image 拡張子（png/gif/jpg/jpeg）以外は拒否 | `ContentsController:file_image` | 可 | P0 | □ | |
| FR-061 | 権限 | 非公開コンテンツのファイルダウンロード拒否（受講者） | DS-1, DS-2 | 1. 受講者（user）でログイン<br>2. 非公開コンテンツの `GET /contents/file-download/{content_id}` にアクセス | HTTP 404。非公開コンテンツのファイルはダウンロード不可 | `ContentsController:file_download`（status チェック） | 可 | P0 | □ | |
| FR-062 | 整合性 | 動画ファイルの拡張子制限（境界値） | DS-1, DS-2 | 1. 許可外拡張子の動画ファイル名で `GET /contents/file-movie/{content_id}` にアクセス | HTTP 404。mov/mp4/wmv/asx 以外は拒否 | `ContentsController:file_movie` | 可 | P1 | □ | |

## 10. 学習記録保存

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-063 | 正常系 | 学習記録の追加 | DS-1, DS-2 | 1. ログイン状態で `POST /records/add/{content_id}` を送信（study_sec, understanding, is_complete を含む） | HTTP 302 → `/contents/index/{course_id}` にリダイレクト。Flash 成功「学習履歴を保存しました」。Records テーブルに user_id, course_id, content_id, study_sec, understanding, is_passed=-1, is_complete が保存される | `RecordsController:add` | 可 | P0 | □ | |
| FR-064 | 異常系 | GET で学習記録にアクセス | DS-1 | 1. `GET /records/add/{content_id}` にアクセス | HTTP 405 Method Not Allowed（`allowMethod(['post'])`） | `RecordsController:add` | 可 | P1 | □ | |
| FR-065 | 異常系 | 存在しないコンテンツの学習記録 | DS-1 | 1. `POST /records/add/99999` を送信 | HTTP 404。NotFoundException | `RecordsController:add` | 可 | P0 | □ | |
| FR-066 | 権限 | 他コースコンテンツの学習記録 | DS-1, DS-2 | 1. 自分のコースに含まれない content_id で `POST /records/add/{content_id}` を送信 | HTTP 404。`hasRight()` チェックにより拒否 | `RecordsController:add` | 可 | P0 | □ | |
| FR-067 | セキュリティ | 非公開コンテンツの学習記録（受講者） | DS-1, DS-2 | 1. 受講者（user）でログイン<br>2. 非公開コンテンツの `POST /records/add/{content_id}` を送信 | HTTP 404。非公開コンテンツの記録保存は不可 | `RecordsController:add`（status チェック） | 可 | P0 | □ | |

## 11. ページ表示（Pages::display）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-068 | 正常系 | 静的ページ表示 | DS-0 | 1. `GET /pages/{path}` にアクセスする（templates/Pages/ に存在するページ） | HTTP 200。対応するテンプレートが表示される | `PagesController:display` | 可 | P1 | □ | |
| FR-069 | セキュリティ | パストラバーサル「..」の拒否 | DS-0 | 1. `GET /pages/../etc/passwd` にアクセスする | HTTP 403 ForbiddenException。`..` と `.` はブロックされる | `PagesController:display`（`..` と `.` チェック） | 可 | P0 | □ | |
| FR-070 | 異常系 | 存在しないページにアクセス | DS-0 | 1. `GET /pages/nonexistent` にアクセスする | HTTP 404。templates/Pages/nonexistent.php が存在しないため | `PagesController:display` | 可 | P1 | □ | |

## 12. エラーページ

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-071 | 回帰 | 400 エラーページ表示 | DS-0 | 1. CSRF トークン不正な POST を送信して blackHole を発生させる | HTTP 400。templates/Error/error400.php が表示される。CSRF エラーメッセージが表示される | `AppController:blackHole`, `templates/Error/error400.php` | 難 | P0 | □ | |
| FR-072 | 回帰 | 500 エラーページ表示 | DS-0 | 1. 意意図的にサーバーエラーを発生させる（例: 不正な DB 接続） | HTTP 500。templates/Error/error500.php が表示される | `ErrorController:beforeRender`, `templates/Error/error500.php` | 難 | P0 | □ | |
| FR-073 | 回帰 | テンプレート大小文字重複確認 | DS-0 | 1. `ls templates/Error/` と `ls templates/error/` を確認<br>2. 各ディレクトリの error400.php, error500.php を比較 | templates/Error/ と templates/error/ が両方存在する場合、Linux 大小文字区別により誤表示の可能性がある。一致確認が必要 | `templates/Error/` と `templates/error/` 重複 | 難 | P0 | □ | |

## 13. ページネーション・パンくず・レイアウト

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-074 | UI | ページネーション表示（お知らせ一覧） | DS-1, DS-5（6件以上） | 1. お知らせを 6 件以上登録<br>2. `GET /infos` にアクセス<br>3. 次ページリンクをクリック | ページネーションが表示される。次のページに正しく遷移する。件数表示が正しい | `templates/element/paging.php`, `InfosController:index` | 可 | P1 | □ | |
| FR-075 | UI | 空データ時のページネーション非表示 | DS-1（お知らせ 0 件） | 1. `GET /infos` にアクセス | ページネーションが表示されない（件数 0 のため） | `templates/element/paging.php` | 可 | P2 | □ | |
| FR-076 | UI | パンくずリスト表示 | DS-1, DS-2 | 1. ログイン状態で `GET /contents/view/{content_id}` にアクセス | パンくずリストが正しく表示される（トップ > コース名 > コンテンツ名） | `templates/layout/default.php` | 難 | P2 | □ | |
| FR-077 | UI | レスポンシブ表示（PC） | DS-1, DS-2 | 1. PC ブラウザで `GET /` にアクセス | PC レイアウトで正しく表示される。サイドバー・ヘッダー・フッターが表示される | `templates/layout/default.php` | 難 | P1 | □ | |
| FR-078 | UI | レスポンシブ表示（スマホ） | DS-1, DS-2 | 1. スマホ エミュレータで `GET /` にアクセス | スマホ レイアウトで正しく表示される。要素が横切れしない | `templates/layout/default.php` | 難 | P1 | □ | |

## 14. 空データ時の表示（DS-0）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-079 | UI | 空状態トップ画面 | DS-0（admin のみ） | 1. admin でログインし `GET /` にアクセス | 「お知らせはありません」「受講可能なコースはありません」が表示される。エラーにならない | `UsersCoursesController:index` | 可 | P1 | □ | |
| FR-080 | UI | 空状態コンテンツ一覧 | DS-0（admin のみ）, DS-2（空コース） | 1. admin でログインし空コースの `GET /contents/index/{course_id}` にアクセス | コンテンツ一覧が空でもエラーにならない。適切なメッセージまたは空一覧が表示される | `ContentsController:index` | 可 | P1 | □ | |
| FR-081 | UI | 空状態お知らせ一覧 | DS-1（お知らせ 0 件） | 1. ログイン状態で `GET /infos` にアクセス | お知らせ一覧が空でもエラーにならない。ページネーションなし | `InfosController:index` | 可 | P1 | □ | |

## 15. CSRF / FormProtection

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-082 | セキュリティ | CSRF トークン不正 POST | DS-1 | 1. ログイン状態で CSRF トークンを削除または変更した POST を送信 | HTTP 400。FormProtection の blackHole が発動し、`/users/login` にリダイレクトされる（CSRF エラーメッセージ付き） | `AppController:blackHole`, FormProtection | 可 | P0 | □ | |
| FR-083 | セキュリティ | ログイン POST の FormProtection 解除確認 | DS-0 | 1. `POST /users/login` に CSRF トークンなしでアクセス | ログインが正常に処理される（FormProtection 解除済み）。blackHole が発動しない | `UsersController`（FormProtection unlocks: login） | 可 | P0 | □ | |

## 16. セッション

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-084 | セキュリティ | app_dir 不一致時のセッション破棄 | DS-1 | 1. セッションの Setting.app_dir を ROOT と異なる値に書き換える<br>2. ページをリロード | セッションが破棄され、未ログイン状態に戻る | `AppController:beforeFilter`（app_dir チェック） | 難 | P0 | □ | |
| FR-085 | セキュリティ | ログイン後セッションにユーザー情報が保存される | DS-1 | 1. ログイン<br>2. セッション Cookie を確認<br>3. セッション内の user 情報を確認 | ログイン成功後、セッションに id, username, role 等が保存される | `AppController:readAuthUser`, AuthenticationComponent | 難 | P0 | □ | |

## 17. ルーティング回帰

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-086 | 回帰 | フロント全ルートの存在確認 | DS-0 | 1. config/routes.php に定義されたフロントルートを全て GET する | 全ルートが HTTP 200 または適切なリダイレクト（302）を返す。404 となるルートはない | `config/routes.php`（フロントルート一覧）, `Docs/design/06-routing.md` | 可 | P0 | □ | |
| FR-087 | 回帰 | フロント fallbacks なしの確認 | DS-0 | 1. 存在しないフロントルート `GET /nonexistent/route` にアクセス | HTTP 404。CakePHP のデフォルト fallbacks がフロントに存在しないため、未定義ルートは全て 404 | `config/routes.php`（fallbacks なし） | 可 | P0 | □ | |
| FR-088 | 回帰 | install/update ルートの動作 | DS-0 | 1. `GET /install` にアクセスする | HTTP 200 または 403（`deny_install_update_access` 設定による）。エラーにならない | `config/routes.php`, `ib_config:deny_install_update_access` | 難 | P1 | □ | |

## 18. フラッシュメッセージ

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| FR-089 | 回帰 | Flash メッセージの表示確認 | DS-1 | 1. ログイン成功時に Flash メッセージが表示されるか確認<br>2. パスワード変更成功時の Flash メッセージを確認 | 成功時・エラー時に Flash メッセージが画面に表示される。templates/element/ に Flash/default.php が存在しない場合でもエラーにならない（layout/flash.php にフォールバック） | `templates/element/`（Flash/default.php 不存在）, `templates/layout/flash.php` | 難 | P1 | □ | |
| FR-090 | 回帰 | Flash メッセージの連続表示 | DS-1 | 1. ログイン成功 → トップ画面で Flash が表示される<br>2. 別ページに遷移 | Flash メッセージは一度表示後に消去される。2 回目は表示されない | `templates/layout/flash.php` | 難 | P2 | □ | |

---

## 集計表

### 分類別

| 分類 | 項目数 |
|---|---|
| 正常系 | 33 |
| 異常系 | 12 |
| 境界値 | 1 |
| 権限 | 6 |
| セキュリティ | 16 |
| 回帰 | 8 |
| 整合性 | 3 |
| UI | 11 |
| 性能 | 0 |
| **合計** | **90** |

### 優先度別

| 優先 | 項目数 |
|---|---|
| P0 | 60 |
| P1 | 27 |
| P2 | 3 |
| **合計** | **90** |
