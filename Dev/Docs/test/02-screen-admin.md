# 管理画面試験項目マトリクス（管理者向け）

## 対象

管理画面（`/admin` プレフィックス配下の全ルート）。管理者ログイン、ユーザー管理（CSV入出力）、コース管理、コンテンツ管理（アップロード）、問題管理、アンケート問題管理、学習履歴管理（CSV出力）、お知らせ管理、グループ管理、システム設定。

## 根拠

- `config/routes.php`（Admin プレフィックス + fallbacks）
- `src/Controller/Admin/UsersController.php`、`CoursesController.php`、`ContentsController.php`、`ContentsQuestionsController.php`、`EnquetesQuestionsController.php`、`RecordsController.php`、`InfosController.php`、`GroupsController.php`、`SettingsController.php`
- `src/Controller/Trait/UserLoginTrait.php`、`src/Controller/Component/RoleComponent.php`
- `src/Controller/AppController.php`
- `config/ib_config.php`（demo_mode, upload_extensions, theme_colors 等）
- `Docs/design/06-routing.md`、`Docs/design/07-controllers.md`、`Docs/design/09-views.md`
- `Docs/dev/manual-test-checklist.md`（T1-1〜T5-6）

## 前提データ（DS）

実施前に DS-0〜DS-6 を投入する。DS-0 の空状態項目を先に実施する。ログイン系（AD-001〜AD-006）は**最後**にまとめる。

---

## 1. 管理者ログイン・ログアウト

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-001 | 正常系 | 管理者ログイン画面表示 | DS-0 | 1. `GET /admin/users/login` にアクセスする | HTTP 200。ログインID入力欄・パスワード入力欄・「ログイン」ボタンが表示される | T1-3, `Admin UsersController:login` | 可 | P0 | □ | |
| AD-002 | 正常系 | 管理者ログイン成功 | DS-1 | 1. `GET /admin/users/login` にアクセス<br>2. ログインID=admin、パスワードを入力<br>3. POST する | HTTP 302 → `/admin/users/index` にリダイレクト。セッションに admin 情報が保存される | T1-3, `UserLoginTrait:performLogin` | 可 | P0 | □ | |
| AD-003 | 異常系 | 管理者ログイン失敗（パスワード間違い） | DS-1 | 1. ログインID=admin、誤ったパスワードで POST | HTTP 302 → `/admin/users/login`。Flash エラー表示。Logs テーブルに `login_error` レコード追加 | T1-2, `UserLoginTrait:performLogin` | 可 | P0 | □ | |
| AD-004 | 権限 | 非スタッフユーザーの管理画面アクセス拒否 | DS-1（role=user） | 1. role=user でログイン<br>2. `GET /admin/users/index` にアクセス | HTTP 403 ForbiddenException。`RoleComponent::requireStaff()` により admin/manager/editor/teacher 以外は拒否 | `RoleComponent:requireStaff`, staffRoles=[admin,manager,editor,teacher] | 可 | P0 | □ | |
| AD-005 | 正常系 | 管理者ログアウト | DS-1（admin ログイン済み） | 1. ログイン状態で `GET /admin/users/logout` にアクセス | HTTP 302 → `/admin/users/login`。CookieAuth トークン無効化、Auth クッキー削除。セッション user 情報消去 | `Admin UsersController:logout` | 可 | P0 | □ | |
| AD-006 | セキュリティ | admin のみ管理画面にアクセス可能（未ログイン） | DS-0 | 1. Cookie を持たない状態で `GET /admin/users/index` にアクセス | HTTP 302 → `/admin/users/login` にリダイレクト（AuthenticationComponent） | `AppController:beforeFilter` | 可 | P0 | □ | |

## 2. ユーザー管理

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-007 | 正常系 | ユーザー一覧表示 | DS-1 | 1. admin でログイン<br>2. `GET /admin/users/index` にアクセス | HTTP 200。ユーザー一覧がページネーション付き（limit=20）で表示される。各ユーザーにグループタイトル・コースタイトルが表示される | T4-1, `Admin UsersController:index` | 可 | P0 | □ | |
| AD-008 | 正常系 | ユーザー一覧（グループ絞り込み） | DS-1 | 1. `GET /admin/users/index?group_id={group_id}` にアクセス | 指定グループに所属するユーザーのみ表示される。セッションにも group_id が保存される | `Admin UsersController:index`（`?group_id` クエリパラメータ） | 可 | P0 | □ | |
| AD-009 | 正常系 | ユーザー一覧ページネーション | DS-1（21ユーザー以上） | 1. ユーザーを 21 件以上登録<br>2. `GET /admin/users/index` にアクセス<br>3. 次ページリンクをクリック | ページネーションが表示される。次のページに正しく遷移する。limit=20 | `Admin UsersController:index`, `templates/element/paging.php` | 可 | P1 | □ | |
| AD-010 | 正常系 | ユーザー CSV エクスポート | DS-1 | 1. `GET /admin/users/index?cmd=export` にアクセス | CSV ファイルがダウンロードされる。SJIS-WIN エンコーディング。500 レコードバッチ処理。group_concat でグループタイトル・コースタイトルを結合 | T4-6, `Admin UsersController:_exportCsv` | 可 | P0 | □ | |
| AD-011 | 正常系 | ユーザー追加 | DS-1 | 1. `GET /admin/users/add` にアクセス<br>2. ユーザー情報を入力<br>3. POST する | HTTP 302 → `/admin/users/index` にリダイレクト。Flash 成功メッセージ。DB にユーザーが追加される。パスワードは bcrypt 化で保存 | T4-2, `Admin UsersController:add`→`edit` | 可 | P0 | □ | |
| AD-012 | 異常系 | ユーザー追加（バリデーションエラー） | DS-1 | 1. 必須フィールドを空にして POST する | HTTP 302 → add ページ。Flash エラーメッセージ。DB にレコードは追加されない | `Admin UsersController:edit`（バリデーション） | 可 | P0 | □ | |
| AD-013 | 異常系 | ユーザー追加（重複ログインID） | DS-1 | 1. 既存のログインIDで再度 POST する | HTTP 302 → add ページ。Flash エラー「ログインIDが重複しています」。DB にレコードは追加されない | `Admin UsersController:edit`（重複チェック） | 可 | P0 | □ | |
| AD-014 | 正常系 | ユーザー編集 | DS-1 | 1. `GET /admin/users/edit/{user_id}` にアクセス<br>2. ユーザー情報を変更<br>3. POST する | HTTP 302 → `/admin/users/index`。Flash 成功。DB のユーザー情報が更新される | T4-3, `Admin UsersController:edit` | 可 | P0 | □ | |
| AD-015 | 正常系 | ユーザー削除 | DS-1 | 1. `POST /admin/users/delete/{user_id}` を送信 | HTTP 302 → `/admin/users/index`。Flash 成功。DB からユーザーが削除される | T4-4, `Admin UsersController:delete`（`allowMethod(['post', 'delete'])`） | 可 | P0 | □ | |
| AD-016 | 異常系 | ユーザー削除（GET 不許可） | DS-1 | 1. `GET /admin/users/delete/{user_id}` にアクセス | HTTP 405 Method Not Allowed。POST または DELETE のみ許可 | `Admin UsersController:delete` | 可 | P1 | □ | |
| AD-017 | 正常系 | パスワード変更（管理画面） | DS-1 | 1. ユーザー編集画面で新パスワードを入力<br>2. POST する | パスワードが bcrypt 化で更新される。UserTokens が無効化される | `Admin UsersController:edit`（パスワード変更時トークン失効） | 可 | P0 | □ | |
| AD-018 | 正常系 | 学習記録クリア | DS-1, DS-4 | 1. `POST /admin/users/clear/{user_id}` を送信 | HTTP 302 → `/admin/users/index`。Records テーブルから対象ユーザーの全学習記録が削除される | `Admin UsersController:clear`（`allowMethod(['post', 'delete'])`） | 可 | P0 | □ | |
| AD-019 | 異常系 | 学習記録クリア（GET 不許可） | DS-1 | 1. `GET /admin/users/clear/{user_id}` にアクセス | HTTP 405 Method Not Allowed | `Admin UsersController:clear` | 可 | P1 | □ | |

## 3. ユーザー CSV インポート

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-020 | 正常系 | CSV インポート成功 | DS-1 | 1. `GET /admin/users/import` にアクセス<br>2. CSV ファイルを選択して POST | HTTP 302 → `/admin/users/index`。Flash 成功メッセージ。CSV のユーザーが一括追加される。グループ・コースはタイトル参照で自動紐付け。トランザクション内で処理 | T4-5, `Admin UsersController:import`, `\Utils::getCsvData()` | 可 | P0 | □ | |
| AD-021 | 異常系 | CSV インポート（不正な CSV） | DS-1 | 1. 不正なフォーマットの CSV を POST する | Flash エラー。不正行はスキップされ、正常行のみ投入される。トランザクションでロールバックの可能性あり | `Admin UsersController:import`, `\Utils::getCsvData()` | 可 | P0 | □ | |
| AD-022 | 回帰 | CSV インポートの \Utils 依存確認 | DS-1 | 1. `\Utils::getCsvData()` が正しく動作するか確認<br>2. SJIS-WIN / UTF-8 両方でテスト | CSV データが正しくパースされる。レガシー Utils の動作確認 | `Admin UsersController:import`, `Vendor/Utils.php` | 難 | P0 | □ | |

## 4. コース管理

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-023 | 正常系 | コース一覧表示 | DS-2 | 1. admin でログイン<br>2. `GET /admin/courses/index` にアクセス | HTTP 200。コース一覧が sort_no 順に表示される | T2-1, `Admin CoursesController:index` | 可 | P0 | □ | |
| AD-024 | 正常系 | コース追加 | DS-1 | 1. `GET /admin/courses/add` にアクセス<br>2. コース情報を入力<br>3. POST する | HTTP 302 → `/admin/courses/index`。Flash 成功。DB にコースが追加される。user_id（登録者）が保存される | T2-4, `Admin CoursesController:add`→`edit` | 可 | P0 | □ | |
| AD-025 | 異常系 | コース追加（バリデーションエラー） | DS-1 | 1. 必須フィールドを空にして POST | HTTP 302 → add ページ。Flash エラー。DB にレコードは追加されない | `Admin CoursesController:edit` | 可 | P0 | □ | |
| AD-026 | 正常系 | コース編集 | DS-2 | 1. `GET /admin/courses/edit/{course_id}` にアクセス<br>2. 情報を変更<br>3. POST | HTTP 302 → `/admin/courses/index`。Flash 成功。DB のコース情報が更新される | T2-5, `Admin CoursesController:edit` | 可 | P0 | □ | |
| AD-027 | 正常系 | コース削除 | DS-2 | 1. `POST /admin/courses/delete/{course_id}` を送信 | HTTP 302 → `/admin/courses/index`。Flash 成功。DB からコースが削除される | T2-6, `Admin CoursesController:delete` | 可 | P0 | □ | |
| AD-028 | 正常系 | コース並べ替え（AJAX） | DS-2 | 1. `POST /admin/courses/order` に id_list を送信（AJAX, Content-Type: application/x-www-form-urlencoded） | HTTP 200。sort_no が更新される。並べ替えが反映される | T2-7, `Admin CoursesController:order`（AJAX のみ） | 可 | P0 | □ | |
| AD-029 | 異常系 | コース並べ替え（非 AJAX POST） | DS-2 | 1. `POST /admin/courses/order` を AJAX なしで送信 | HTTP 405 Method Not Allowed。AJAX のみ許可 | `Admin CoursesController:order`（`$this->request->is('ajax')`） | 可 | P1 | □ | |
| AD-030 | 回帰 | コース検索（T2-3 対応） | DS-2 | 1. 管理画面のコース管理にアクセス<br>2. 検索条件を入力して検索する | `Admin/CoursesController` に検索アクションは存在しない（index/add/edit/delete/order のみ）。レガシー版では Search プラグインを使用していた。**検索 UI/機能が提供されない** | T2-3, `Admin/CoursesController`（検索アクション不在の確認）, 設計参照 T2-3 | 難 | P1 | □ | `src/Controller/Admin/CoursesController.php` に検索アクションが無い（レガシーは Search プラグイン使用） |

## 5. コンテンツ管理

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-031 | 正常系 | コンテンツ一覧表示 | DS-2 | 1. `GET /admin/contents/index/{course_id}` にアクセス | HTTP 200。コース内のコンテンツ一覧が sort_no 順に表示される | T3-1, `Admin ContentsController:index` | 可 | P0 | □ | |
| AD-032 | 正常系 | コンテンツ追加 | DS-1, DS-2 | 1. `GET /admin/contents/add/{course_id}` にアクセス<br>2. コンテンツ情報を入力<br>3. POST する | HTTP 302 → `/admin/contents/index/{course_id}`。Flash 成功。DB にコンテンツが追加される。user_id, course_id, sort_no が自動設定 | `Admin ContentsController:add`→`edit` | 可 | P0 | □ | |
| AD-033 | 正常系 | コンテンツ編集 | DS-2 | 1. `GET /admin/contents/edit/{course_id}/{content_id}` にアクセス<br>2. 情報を変更<br>3. POST | HTTP 302 → コンテンツ一覧。Flash 成功。DB が更新される | `Admin ContentsController:edit` | 可 | P0 | □ | |
| AD-034 | 正常系 | コンテンツ削除 | DS-2 | 1. `POST /admin/contents/delete/{course_id}/{content_id}` を送信 | HTTP 302 → コンテンツ一覧。Flash 成功。DB のコンテンツと関連する ContentsQuestions が削除される | `Admin ContentsController:delete` | 可 | P0 | □ | |
| AD-035 | 正常系 | コンテンツ並べ替え（AJAX） | DS-2 | 1. `POST /admin/contents/order` に id_list を送信（AJAX） | HTTP 200。sort_no が更新される | `Admin ContentsController:order`（AJAX のみ） | 可 | P0 | □ | |
| AD-036 | 正常系 | コンテンツコピー | DS-2 | 1. `POST /admin/contents/copy/{course_id}/{content_id}` を送信 | HTTP 302 → コンテンツ一覧。Flash 成功。コンテンツと関連する問題が複製される。タイトルに「の複製」が追加される | `Admin ContentsController:copy`（POST のみ） | 可 | P0 | □ | |
| AD-037 | 異常系 | コンテンツコピー（GET 不許可） | DS-2 | 1. `GET /admin/contents/copy/{course_id}/{content_id}` にアクセス | HTTP 405 Method Not Allowed | `Admin ContentsController:copy`（`$this->request->allowMethod(['post'])`） | 可 | P1 | □ | |
| AD-038 | 正常系 | 学習記録一覧（コンテンツ別） | DS-1, DS-2, DS-4 | 1. `GET /admin/contents/record/{course_id}/{user_id}` にアクセス | HTTP 200。指定ユーザーのコンテンツ別学習履歴が表示される | `Admin ContentsController:record` | 可 | P1 | □ | |

## 6. ファイルアップロード

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-039 | 正常系 | ファイルアップロード（一般ファイル） | DS-1, DS-2 | 1. コンテンツ編集画面でファイルを添付<br>2. POST でアップロード（upload アクション） | HTTP 200。ファイルが files/ ディレクトリに保存される。ファイル名は日付+4文字のランダム。拡張子は upload_extensions（22種）に含まれるもののみ許可 | `Admin ContentsController:upload` | 可 | P0 | □ | |
| AD-040 | 正常系 | 画像アップロード（AJAX） | DS-1, DS-2 | 1. 画像ファイルを指定して `POST /admin/contents/uploadImage` に送信（AJAX） | HTTP 200。JSON で URL が返される。拡張子は png/gif/jpg/jpeg のみ。upload_image_maxsize: 2MB | `Admin ContentsController:uploadImage` | 可 | P0 | □ | |
| AD-041 | 正常系 | 動画ファイルアップロード | DS-1, DS-2 | 1. 動画ファイルを添付して POST | HTTP 200。ファイルが保存される。拡張子は mov/mp4/wmv/asx のみ。upload_movie_maxsize: 10MB | `Admin ContentsController:upload`（`$file_type` パラメータ） | 可 | P0 | □ | |
| AD-042 | 異常系 | アップロード（許可外拡張子） | DS-1 | 1. .exe ファイルを POST でアップロード | HTTP 200。拡張子バリデーションにより拒否。Flash エラー「アップロードできないファイルタイプです」 | `Admin ContentsController:upload`（`upload_extensions` チェック） | 可 | P0 | □ | |
| AD-043 | 異常系 | アップロード（サイズ超過） | DS-1 | 1. upload_maxsize（10MB）以上のファイルを POST | HTTP 200。PHP の upload_max_filesize エラーまたは Flash エラー。ファイルは保存されない | `Admin ContentsController:upload`, PHP ini 設定 | 難 | P1 | □ | |
| AD-044 | 境界値 | アップロード（最大サイズぴったり） | DS-1 | 1. upload_maxsize（10MB）ぴったりのファイルを POST | HTTP 200。ファイルが正常に保存される | `Admin ContentsController:upload` | 難 | P2 | □ | |
| AD-045 | セキュリティ | アップロードファイル名のランダム化確認 | DS-1 | 1. ファイルをアップロード<br>2. 保存されたファイル名を確認 | 元のファイル名ではなく、日付+4文字のランダム名で保存される。パストラバーサルを含むファイル名は防止される | `Admin ContentsController:upload`（ランダム名生成） | 難 | P1 | □ | |

## 7. プレビュー

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-046 | 正常系 | コンテンツプレビュー（AJAX） | DS-1, DS-2 | 1. `POST /admin/contents/preview` に AJAX で送信（content_id を含む） | HTTP 200。セッションに `Iroha.preview_content` が保存される。プレビュー画面が表示される | `Admin ContentsController:preview`（AJAX のみ） | 可 | P0 | □ | |
| AD-047 | 異常系 | プレビュー（非 AJAX POST） | DS-2 | 1. `POST /admin/contents/preview` を非 AJAX で送信 | HTTP 405 Method Not Allowed | `Admin ContentsController:preview`（`$this->request->is('ajax')`） | 可 | P1 | □ | |
| AD-048 | 正常系 | 動画プレビュー | DS-1, DS-2（kind=movie） | 1. 動画コンテンツの `GET /admin/contents/previewMovie/{file_name}` にアクセス | HTTP 200。動画ファイルがストリーミングで返される。拡張子・正規表現バリデーションあり | `Admin ContentsController:previewMovie` | 可 | P0 | □ | |
| AD-049 | セキュリティ | 動画プレビューのパストラバーサル | DS-1 | 1. `GET /admin/contents/previewMovie/..%2F..%2Fetc%2Fpasswd` にアクセス | HTTP 400 または 404。正規表現バリデーションにより拒否 | `Admin ContentsController:previewMovie` | 可 | P0 | □ | |

## 8. 問題管理

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-050 | 正常系 | 問題一覧表示 | DS-2（kind=test）, DS-3 | 1. `GET /admin/contents-questions/index/{content_id}` にアクセス | HTTP 200。問題一覧が sort_no 順に表示される | T5-1, `Admin ContentsQuestionsController:index` | 可 | P0 | □ | |
| AD-051 | 正常系 | 問題追加 | DS-3 | 1. `GET /admin/contents-questions/add/{content_id}` にアクセス<br>2. 問題情報を入力<br>3. POST | HTTP 302 → 問題一覧。Flash 成功。DB に問題が追加される | T5-2, `Admin ContentsQuestionsController:add`→`edit` | 可 | P0 | □ | |
| AD-052 | 正常系 | 問題編集 | DS-3 | 1. 問題一覧から編集<br>2. 情報を変更<br>3. POST | HTTP 302 → 問題一覧。Flash 成功。DB が更新される | T5-3, `Admin ContentsQuestionsController:edit` | 可 | P0 | □ | |
| AD-053 | 正常系 | 問題削除 | DS-3 | 1. `POST /admin/contents-questions/delete/{content_id}/{question_id}` を送信 | HTTP 302 → 問題一覧。Flash 成功。DB から問題が削除される | T5-4, `Admin ContentsQuestionsController:delete` | 可 | P0 | □ | |
| AD-054 | 正常系 | 問題並べ替え（AJAX） | DS-3 | 1. `POST /admin/contents-questions/order` に id_list を送信（AJAX） | HTTP 200。sort_no が更新される | T5-5, `Admin ContentsQuestionsController:order` | 可 | P0 | □ | |

## 9. アンケート問題管理

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-055 | 正常系 | アンケート問題一覧表示 | DS-2（kind=enquete）, DS-3 | 1. `GET /admin/enquetes-questions/index/{content_id}` にアクセス | HTTP 200。アンケート問題一覧が sort_no 順に表示される | `Admin EnquetesQuestionsController:index` | 可 | P0 | □ | |
| AD-056 | 正常系 | アンケート問題追加 | DS-3 | 1. `GET /admin/enquetes-questions/add/{content_id}` にアクセス<br>2. 問題情報を入力<br>3. POST | HTTP 302 → アンケート問題一覧。Flash 成功。DB に問題が追加される | `Admin EnquetesQuestionsController:add`→`edit` | 可 | P0 | □ | |
| AD-057 | 正常系 | アンケート問題編集 | DS-3 | 1. 問題一覧から編集<br>2. 情報を変更<br>3. POST | HTTP 302 → アンケート問題一覧。Flash 成功。DB が更新される | `Admin EnquetesQuestionsController:edit` | 可 | P0 | □ | |
| AD-058 | 正常系 | アンケート問題削除 | DS-3 | 1. `POST /admin/enquetes-questions/delete/{content_id}/{question_id}` を送信 | HTTP 302 → アンケート問題一覧。Flash 成功。DB から削除 | `Admin EnquetesQuestionsController:delete` | 可 | P0 | □ | |
| AD-059 | 正常系 | アンケート問題並べ替え（AJAX） | DS-3 | 1. `POST /admin/enquetes-questions/order` に id_list を送信（AJAX） | HTTP 200。sort_no が更新される | `Admin EnquetesQuestionsController:order` | 可 | P0 | □ | |

## 10. 学習履歴管理

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-060 | 正常系 | 学習履歴一覧表示 | DS-1, DS-4 | 1. `GET /admin/records/index` にアクセス | HTTP 200。学習履歴一覧がページネーション付きで表示される。グループ・カテゴリ・日付で絞り込み可能 | T4-9, `Admin RecordsController:index` | 可 | P0 | □ | |
| AD-061 | 正常系 | 学習履歴 CSV 出力（基本） | DS-1, DS-4 | 1. `GET /admin/records/index?cmd=csv` にアクセス | CSV ファイル（user_records.csv）がダウンロードされる。SJIS-WIN エンコーディング。`\Utils::getHNSBySec()` / `\Utils::getYMDHN()` を使用 | T4-10, `Admin RecordsController:_exportCsv` | 可 | P0 | □ | |
| AD-062 | 正常系 | 学習履歴 CSV 出力（詳細） | DS-1, DS-4 | 1. `GET /admin/records/index?cmd=csv_detail` にアクセス | CSV ファイル（record_details.csv）がダウンロードされる。RecordsQuestions の詳細を含む | `Admin RecordsController:_exportCsvDetail` | 可 | P0 | □ | |
| AD-063 | 正常系 | 学習履歴（グループ絞り込み） | DS-1, DS-4 | 1. `GET /admin/records/index?group_id={group_id}` にアクセス | 指定グループのユーザーの学習履歴のみ表示される | `Admin RecordsController:index` | 可 | P1 | □ | |
| AD-064 | 回帰 | 学習履歴 CSV の \Utils 依存確認 | DS-1, DS-4 | 1. CSV 出力が正常に完了するか確認<br>2. `\Utils::getHNSBySec()` の戻り値を検証<br>3. `\Utils::getYMDHN()` の戻り値を検証 | レガシー Utils の関数が CakePHP 5 環境で正常動作する。秒数→時間分秒変換、日時フォーマットが正しい | `Admin RecordsController:_exportCsv`, `Vendor/Utils.php` | 難 | P0 | □ | `Vendor/Utils.php` のレガシー関数に依存`getHNSBySec()`, `getYMDHN()` |

## 11. お知らせ管理

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-065 | 正常系 | お知らせ一覧表示 | DS-5 | 1. `GET /admin/infos/index` にアクセス | HTTP 200。お知らせ一覧がページネーション付きで表示される。グループタイトルが group_concat で結合表示 | T4-11, `Admin InfosController:index` | 可 | P0 | □ | |
| AD-066 | 正常系 | お知らせ追加 | DS-1, DS-5 | 1. `GET /admin/infos/add` にアクセス<br>2. お知らせ情報を入力<br>3. POST | HTTP 302 → お知らせ一覧。Flash 成功。DB にお知らせが追加される | T4-11, `Admin InfosController:add`→`edit` | 可 | P0 | □ | |
| AD-067 | 正常系 | お知らせ編集 | DS-5 | 1. お知らせ一覧から編集<br>2. 情報を変更<br>3. POST | HTTP 302 → お知らせ一覧。Flash 成功。DB が更新される | T4-11, `Admin InfosController:edit` | 可 | P0 | □ | |
| AD-068 | 正常系 | お知らせ削除 | DS-5 | 1. `POST /admin/infos/delete/{info_id}` を送信 | HTTP 302 → お知らせ一覧。Flash 成功。DB から削除 | T4-11, `Admin InfosController:delete` | 可 | P0 | □ | |

## 12. グループ管理

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-069 | 正常系 | グループ一覧表示 | DS-1 | 1. `GET /admin/groups/index` にアクセス | HTTP 200。グループ一覧がページネーション付きで表示される。コースタイトルが group_concat で結合。deleted IS NULL でフィルタリング | T4-7, `Admin GroupsController:index` | 可 | P0 | □ | |
| AD-070 | 正常系 | グループ追加 | DS-1 | 1. `GET /admin/groups/add` にアクセス<br>2. グループ情報を入力<br>3. POST | HTTP 302 → グループ一覧。Flash 成功。DB にグループが追加される | T4-7, `Admin GroupsController:add`→`edit` | 可 | P0 | □ | |
| AD-071 | 正常系 | グループ編集 | DS-1 | 1. グループ一覧から編集<br>2. 情報を変更<br>3. POST | HTTP 302 → グループ一覧。Flash 成功。DB が更新される | T4-7, `Admin GroupsController:edit` | 可 | P0 | □ | |
| AD-072 | 正常系 | グループ削除 | DS-1 | 1. `POST /admin/groups/delete/{group_id}` を送信 | HTTP 302 → グループ一覧。Flash 成功。DB から削除（論理削除） | T4-7, `Admin GroupsController:delete` | 可 | P0 | □ | |

## 13. システム設定

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-073 | 正常系 | システム設定画面表示 | DS-1 | 1. `GET /admin/settings/index` にアクセス | HTTP 200。現在の設定値が表示される。theme_colors の選択肢が表示される | T4-8, `Admin SettingsController:index` | 可 | P0 | □ | |
| AD-074 | 正常系 | システム設定保存 | DS-1 | 1. 設定値を変更<br>2. POST する | HTTP 302 → 設定画面。Flash 成功。DB の設定値が更新され、セッションにも反映される。theme_colors が変更可能 | T4-8, `Admin SettingsController:index` | 可 | P0 | □ | |
| AD-075 | 整合性 | 設定変更のセッション反映確認 | DS-1 | 1. `theme_colors` を変更して保存<br>2. ページをリロード<br>3. セッション内の設定値を確認 | セッションの `Setting` が更新された値になる。`AppController:beforeFilter` で DB 設定がセッションに反映される | `AppController:beforeFilter`, `Admin SettingsController:index` | 難 | P0 | □ | |

## 14. demo_mode 分岐

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-076 | 権限 | demo_mode 時にユーザー編集が制限される | DS-1（`demo_mode=true`） | 1. `ib_config.php` の `demo_mode` を `true` に変更<br>2. ユーザー編集を POST する | Flash エラーまたは変更が無視される。demo_mode=true の場合、Admin の 8 コントローラすべてで変更操作が制限される | `config/ib_config.php:demo_mode`, `Admin UsersController:edit` 他 | 難 | P1 | □ | |
| AD-077 | 権限 | demo_mode 時にユーザー削除が制限される | DS-1（`demo_mode=true`） | 1. `demo_mode=true` に設定<br>2. `POST /admin/users/delete/{user_id}` を送信 | ユーザーが削除されない。demo_mode ガードにより null を返す | `Admin UsersController:delete`（demo_mode チェック） | 難 | P1 | □ | |
| AD-078 | 権限 | demo_mode 時にコース削除が制限される | DS-2（`demo_mode=true`） | 1. `demo_mode=true` に設定<br>2. `POST /admin/courses/delete/{course_id}` を送信 | コースが削除されない | `Admin CoursesController:delete`（demo_mode チェック） | 難 | P1 | □ | |
| AD-079 | 権限 | demo_mode 時にコンテンツ削除が制限される | DS-2（`demo_mode=true`） | 1. `demo_mode=true` に設定<br>2. コンテンツ削除 POST | コンテンツが削除されない | `Admin ContentsController:delete`（demo_mode チェック） | 難 | P1 | □ | |
| AD-080 | 権限 | demo_mode 時に CSV インポートが制限される | DS-1（`demo_mode=true`） | 1. `demo_mode=true` に設定<br>2. CSV インポート POST | インポートが実行されない | `Admin UsersController:import`（demo_mode チェック） | 難 | P1 | □ | |
| AD-081 | 権限 | demo_mode 時にシステム設定が制限される | DS-1（`demo_mode=true`） | 1. `demo_mode=true` に設定<br>2. 設定変更 POST | 設定が変更されない | `Admin SettingsController:index`（demo_mode チェック） | 難 | P1 | □ | |
| AD-082 | 権限 | demo_mode=false での正常操作確認 | DS-1（`demo_mode=false`） | 1. `demo_mode=false`（デフォルト）に設定<br>2. 各 CRUD 操作を実行 | 全ての変更操作が正常に動作する。demo_mode ガードが発動しない | `config/ib_config.php:demo_mode=false` | 可 | P0 | □ | |

## 15. 管理メニュー・パンくず

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-083 | UI | 管理メニュー表示 | DS-1 | 1. admin でログイン<br>2. 管理画面の任意のページにアクセス | 管理メニュー（admin_menu エレメント）が正しく表示される。リンクが正しいルートを指している | `templates/element/admin_menu.php` | 難 | P1 | □ | |
| AD-084 | UI | 管理画面パンくずリスト | DS-1, DS-2 | 1. 管理画面のコンテンツ編集ページにアクセス | パンくずリストが正しく表示される（トップ > コース管理 > コース名 > コンテンツ管理） | `templates/layout/default.php` | 難 | P2 | □ | |

## 16. AJAX 操作の失敗時挙動

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-085 | 異常系 | コース並べ替え AJAX 失敗 | DS-2 | 1. 不正な id_list（存在しない ID）で `POST /admin/courses/order` を AJAX で送信 | HTTP 200 但し、sort_no が正しく更新されない可能性がある。エラーが silently される | `Admin CoursesController:order`, `setOrder()` | 難 | P2 | □ | |
| AD-086 | 異常系 | コンテンツ並べ替え AJAX 失敗 | DS-2 | 1. 不正な id_list で `POST /admin/contents/order` を AJAX で送信 | 同上。setOrder 内のエラー処理を確認 | `Admin ContentsController:order`, `setOrder()` | 難 | P2 | □ | |
| AD-087 | 異常系 | 画像アップロード AJAX 失敗 | DS-1 | 1. 不正なファイルで `POST /admin/contents/uploadImage` を AJAX で送信 | HTTP 200。JSON でエラーが返される（URL が空、またはエラーメッセージ） | `Admin ContentsController:uploadImage` | 難 | P1 | □ | |

## 17. ルーティング回帰

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| AD-088 | 回帰 | Admin ルートの存在確認 | DS-0 | 1. config/routes.php の Admin プレフィックスルートを GET する | 全 Admin ルートが HTTP 200 またはリダイレクト（302）。未定義ルートは CakePHP fallbacks によりコントローラー解決 | `config/routes.php`（Admin fallbacks）, `Docs/design/06-routing.md` | 可 | P0 | □ | |
| AD-089 | 回帰 | FormProtection アンロック確認 | DS-1 | 1. Admin 各コントローラの FormProtection アンロック済みアクション（login, logout, order, preview, uploadImage）を CSRF トークンなしで POST | 各アンロック済みアクションが正常に処理される。blackHole が発動しない | `Admin UsersController`, `CoursesController`, `ContentsController`（FormProtection unlocks） | 可 | P0 | □ | |
| AD-090 | 回帰 | ファイルアップロード拡張子一覧の整合性 | DS-1 | 1. `config/ib_config.php` の `upload_extensions` と `upload_image_extensions` を確認<br>2. 実際のアップロードで各拡張子をテスト | 22 種の拡張子が許可される。image 拡張子（png/gif/jpg/jpeg）のみ画像アップロードに許可。movie 拡張子（mov/mp4/wmv/asx）のみ動画に許可 | `config/ib_config.php:upload_extensions`, `Admin ContentsController:upload` | 可 | P1 | □ | |

---

## 集計表

### 分類別

| 分類 | 項目数 |
|---|---|
| 正常系 | 54 |
| 異常系 | 15 |
| 境界値 | 1 |
| 権限 | 8 |
| セキュリティ | 3 |
| 回帰 | 6 |
| 整合性 | 1 |
| UI | 2 |
| 性能 | 0 |
| **合計** | **90** |

### 優先度別

| 優先 | 項目数 |
|---|---|
| P0 | 66 |
| P1 | 20 |
| P2 | 4 |
| **合計** | **90** |