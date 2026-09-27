# 全画面インベントリ × 検証項目マトリクス（Screen Inventory Matrix）

> 上位: `README.md` / 観点: `01-verification-perspective-matrix.md` / 項目: `02`〜`04` / E2E: `05` / 画面操作: `08-ui-operation.md`

---

## 0. 位置づけと目的

本ドキュメントは、iroha Board の**全画面（テンプレート単位）を列挙し、各画面がどの検証項目でカバーされるかを 1:1 で対応付ける**ことで、「全画面を検証した」ことを証明可能にする。`02`〜`04` は機能テーマ別のカタログであり、本ファイルは**画面軸の網羅保証**を担う。

- 目的1: 画面の取りこぼし（項目が 1 つも無い画面）を機械的に検出する。
- 目的2: 各画面の「機能検証（What）」と `08-ui-operation.md` の「操作検証（How）」を接続する。
- 目的3: 画面遷移の動線を可視化し、E2E ジャーニー（`05`）との対応を与える。

---

## 1. 画面の定義と範囲

- **1 画面 = 1 テンプレートファイル**（`templates/**/*.php`）を基本単位とする。
- 対象: 受講者/共通画面（Front）・管理画面（Admin）・システム/エラー画面。
- 除外: `templates/layout/`、`templates/element/`、`templates/email/`、`templates/pdf/`、`templates/Cell/`、`templates/Bake/`、`templates/Ajax` レイアウト。
- 同一テンプレートを複数アクションが共用する場合は、画面 ID を分けず備考に明記する（例: テスト受験/結果）。

### 記号凡例

| 記号 | 意味 |
|---|---|
| ◎ | その画面を主対象とした専用項目が存在する |
| ○ | 関連項目でカバーされる（間接） |
| △ | 既存 `Docs/test/`（移行回帰）または他ファイルで僅かに触れるのみ |
| × | 対応項目なし（要追加 or 対象外の根拠要記録） |

認証列: `不要`（未認証可）/ `user`（要ログイン）/ `staff`（管理権限）。

---

## 2. 受講者/共通画面インベントリ（Front）

| 画面ID | 画面名 | テンプレート | ルート | 認証 | 主担当検証項目 | 対応 | 操作(VR-UIS) |
|---|---|---|---|---|---|---|---|
| SCR-F-01 | ログイン | `Users/login.php` | `/users/login` | 不要 | `VR-AUTH-001`〜`012` | ◎ | VR-UIS-001 |
| SCR-F-02 | ユーザー設定（パスワード変更） | `Users/setting.php` | `/users/setting` | user | `VR-AUTH-014`〜`017`, `VR-AUTH-043` | ◎ | VR-UIS-002 |
| SCR-F-03 | 受講者トップ | `UsersCourses/index.php` | `/`, `/users-courses` | user | `VR-FRNT-001`〜`005/010/011/019` | ◎ | VR-UIS-003 |
| SCR-F-04 | コンテンツ一覧 | `Contents/index.php` | `/contents/index/{course_id}` | user | `VR-CONT-001`〜`005` | ◎ | VR-UIS-004 |
| SCR-F-05 | コンテンツ表示 | `Contents/view.php` | `/contents/view/{content_id}` | user | `VR-CONT-006`〜`039` | ◎ | VR-UIS-005 |
| SCR-F-06 | テスト受験 | `ContentsQuestions/index.php` | `/contents-questions/index/{content_id}` | user | `VR-QUIZ-001`〜`031` | ◎ | VR-UIS-006 |
| SCR-F-07 | テスト結果（受験テンプレート共用） | `ContentsQuestions/index.php`（共用） | `/contents-questions/record/{cid}/{rid}` | user | `VR-QUIZ-020`〜`031` | ○ | VR-UIS-006 |
| SCR-F-08 | アンケート回答 | `EnquetesQuestions/index.php` | `/enquetes-questions/index/{content_id}` | user | `VR-ENQ-001`〜`012` | ◎ | VR-UIS-007 |
| SCR-F-09 | お知らせ一覧 | `Infos/index.php` | `/infos`, `/infos/index` | user | `VR-INFO-001`〜`006` | ◎ | VR-UIS-008 |
| SCR-F-10 | お知らせ詳細 | `Infos/view.php` | `/infos/view/{info_id}` | user | `VR-INFO-007`〜`012` | ◎ | VR-UIS-008 |
| SCR-F-11 | 静的ページ | `Pages/home.php` | `/pages/*` | 不要 | 既存 `NON-*` のみ | △ | VR-UIS-009 |
| SCR-F-12 | インストール | `Install/index.php` | `/install` | 不要 | `VR-OPS-001/004/005/006/021` | ◎ | VR-UIS-010 |
| SCR-F-13 | インストール済表示 | `Install/installed.php` | `/install/*`（テーブル有） | 不要 | `VR-OPS-003` | ○ | VR-UIS-010 |
| SCR-F-14 | インストール完了 | `Install/complete.php` | `/install/complete` | 不要 | `VR-OPS-002` | ○ | VR-UIS-010 |
| SCR-F-15 | インストールエラー | `Install/error.php` | `/install/error` | 不要 | `VR-OPS-004` | △ | VR-UIS-010 |
| SCR-F-16 | 更新実行 | `Update/index.php` | `/update` | 不要 | `VR-OPS-007/022` | ◎ | VR-UIS-011 |
| SCR-F-17 | 更新エラー | `Update/error.php` | `/update/*`（失敗） | 不要 | `VR-OPS-008` | ○ | VR-UIS-011 |
| SCR-F-18 | エラー（400/404） | `Error/error400.php`（大文字・CakePHP5 規約） | 任意（不正ルート等） | 不要 | `VR-ADMN-014`, `VR-NFR-013` | △ | VR-UIS-012 |
| SCR-F-19 | エラー（500） | `Error/error500.php` | 任意（サーバ例外） | 不要 | `VR-NFR-013` | △ | VR-UIS-012 |

> 備考: SCR-F-06/07 は同一テンプレートを共用するため、テンプレート数としては **19 行 = 18 ファイル**。コンテンツプレビュー（`/contents/preview`）は管理画面から遷移する専用ビューで、`templates/Contents/view.php` を共用する（`VR-ACON-013` で確認）。

---

## 3. 管理画面インベントリ（Admin）

| 画面ID | 画面名 | テンプレート | ルート | 認証 | 主担当検証項目 | 対応 | 操作(VR-UIS) |
|---|---|---|---|---|---|---|---|
| SCR-A-01 | 管理者ログイン | `Admin/Users/login.php` | `/admin/users/login` | 不要 | `VR-ADMN-001`, `VR-ADMN-013` | ◎ | VR-UIS-001 |
| SCR-A-02 | ユーザー一覧 | `Admin/Users/index.php` | `/admin/users` | staff | `VR-ACNT-001`〜`012/016`〜`026` | ◎ | VR-UIS-013 |
| SCR-A-03 | ユーザー追加/編集 | `Admin/Users/edit.php` | `/admin/users/add`, `/edit` | staff | `VR-ACNT-013`〜`024` | ◎ | VR-UIS-014 |
| SCR-A-04 | ユーザーCSV取込 | `Admin/Users/import.php` | `/admin/users/import` | staff | `VR-ACNT-016`〜`026`, `VR-VAL-*` | ◎ | VR-UIS-015 |
| SCR-A-05 | 管理者設定（パスワード変更） | `Admin/Users/setting.php` | `/admin/users/setting` | staff | `VR-AUTH-016`, `VR-ACNT-024` | △ | VR-UIS-002 |
| SCR-A-06 | コース一覧 | `Admin/Courses/index.php` | `/admin/courses` | staff | `VR-COUR-001`〜`008` | ◎ | VR-UIS-016 |
| SCR-A-07 | コース追加/編集 | `Admin/Courses/edit.php` | `/admin/courses/add`, `/edit` | staff | `VR-COUR-009`〜`015` | ◎ | VR-UIS-016 |
| SCR-A-08 | コンテンツ一覧 | `Admin/Contents/index.php` | `/admin/contents/index/{course_id}` | staff | `VR-ACON-001`〜`004/014`〜`019` | ◎ | VR-UIS-017 |
| SCR-A-09 | コンテンツ追加/編集 | `Admin/Contents/edit.php` | `/admin/contents/edit/{course_id}[/{cid}]` | staff | `VR-ACON-005`〜`013/020`〜`023` | ◎ | VR-UIS-018 |
| SCR-A-10 | ファイルアップロード（iframe） | `Admin/Contents/upload.php` | `/admin/contents/upload/{file_type}` | staff | `VR-ACON-005/005b/025`, `VR-VAL-*` | ◎ | VR-UIS-019 |
| SCR-A-11 | 問題一覧 | `Admin/ContentsQuestions/index.php` | `/admin/contents-questions/index/{content_id}` | staff | `VR-QUST-001`〜`008` | ◎ | VR-UIS-020 |
| SCR-A-12 | 問題追加/編集 | `Admin/ContentsQuestions/edit.php` | `/admin/contents-questions/edit/...` | staff | `VR-QUST-009`〜`015` | ◎ | VR-UIS-020 |
| SCR-A-13 | アンケート問題一覧 | `Admin/EnquetesQuestions/index.php` | `/admin/enquetes-questions/index/{content_id}` | staff | `VR-EQST-001`〜`003` | ◎ | VR-UIS-021 |
| SCR-A-14 | アンケート問題追加/編集 | `Admin/EnquetesQuestions/edit.php` | `/admin/enquetes-questions/edit/...` | staff | `VR-EQST-004`〜`006` | ◎ | VR-UIS-021 |
| SCR-A-15 | グループ一覧 | `Admin/Groups/index.php` | `/admin/groups` | staff | `VR-GRUP-001`〜`005/014` | ◎ | VR-UIS-022 |
| SCR-A-16 | グループ追加/編集 | `Admin/Groups/edit.php` | `/admin/groups/add`, `/edit` | staff | `VR-GRUP-006`〜`013` | ◎ | VR-UIS-023 |
| SCR-A-17 | お知らせ一覧 | `Admin/Infos/index.php` | `/admin/infos` | staff | `VR-INFO-001`〜`003/011` | ○ | VR-UIS-024 |
| SCR-A-18 | お知らせ追加/編集 | `Admin/Infos/edit.php` | `/admin/infos/add`, `/edit` | staff | `VR-INFO-004`〜`010` | ◎ | VR-UIS-024 |
| SCR-A-19 | 学習履歴一覧/CSV | `Admin/Records/index.php` | `/admin/records`（`?cmd=csv[_detail]`） | staff | `VR-AREC-001`〜`010` | ◎ | VR-UIS-025 |
| SCR-A-20 | システム設定 | `Admin/Settings/index.php` | `/admin/settings` | staff | `VR-SETT-001`〜`011` | ◎ | VR-UIS-026 |

> 備考: 管理の「学習履歴（内容別）」「テスト結果詳細」「アンケート結果詳細」は `Admin/Contents/record`・`Admin/ContentsQuestions/record`・`Admin/EnquetesQuestions/record` が既存 index テンプレートを共用する。

---

## 4. システム/エラー画面

`error400.php` / `error500.php` は共通レイアウトで表示される。専用の 403 ページは無く、権限拒否はリダイレクト/ログアウトで処理される（`VR-ADMN-002/003`）。404 は `Api/Errors::notFound`（API）と `ErrorController`（Web）で経路が異なる点に注意。

`templates/Error/`（大文字）と `templates/error/`（小文字）が内容違いで併存する。CakePHP 5 の規約上は `Error/`（大文字）が描画される見込みで、小文字側は旧版の重複（デッドコード）の可能性が高い。描画実体を実機で確認する。

---

## 5. 全画面網羅セルフチェック

- [ ] 受講者/共通テンプレート **18 ファイル** が全て SCR-F-01〜SCR-F-19（共用 1 を含む 19 行）に対応している。
- [ ] 管理テンプレート **20 ファイル** が全て SCR-A-01〜SCR-A-20 に対応している。
- [ ] 対応記号が `×` の画面が無い。ある場合は追加項目か「対象外の根拠」を `06` に記録する。
- [ ] 各画面に最低 1 件の `◎`/`○` 項目がある。
- [ ] 各画面に対応する `VR-UIS-*`（`08`）が存在する。
- [ ] 本表の全画面ID が `08-ui-operation.md` §4 の画面別操作シナリオに**範囲表記を含めて**対応している（例: SCR-A-06/07 → VR-UIS-016、SCR-F-12〜15 → VR-UIS-010）。

### 既知の弱い画面（△ — 追加候補）

| 画面ID | 状況 | 推奨対応 |
|---|---|---|
| SCR-F-11 | 静的ページは既存 `NON-*` のみ | 公開/非公開、パストラバーサル、レイアウト崩れの項目を `04` に追加 |
| SCR-F-15/17 | インストール/更新エラー画面の表示確認が薄い | `VR-OPS-004/008` に画面表示の期待を明記 |
| SCR-F-18/19 | 400/500 ページの復帰動線・情報漏洩（スタック）確認が薄い | `RK-13` と接続し `VR-NFR-013` を強化 |
| SCR-A-05 | 管理者パスワード変更は `VR-AUTH-016` の現行パスワード不要問題に従属 | `VR-AUTH-016` の是正結果に連動 |

---

## 6. 画面遷移マップ（主要動線）

```
[SCR-F-01 ログイン]
   └─> [SCR-F-03 受講者トップ]
          ├─> [SCR-F-04 コンテンツ一覧] ─> [SCR-F-05 表示]
          │        ├─ kind=test   ─> [SCR-F-06 テスト受験] ─> [SCR-F-07 結果]
          │        └─ kind=enquete─> [SCR-F-08 アンケート]
          ├─> [SCR-F-09/10 お知らせ]
          └─> [SCR-F-02 ユーザー設定]

[SCR-A-01 管理者ログイン]
   └─> [SCR-A-02〜20 管理各画面]
          ├─ コース [A-06/07] ─> コンテンツ [A-08/09/10] ─> 問題 [A-11/12] / アンケート問題 [A-13/14]
          ├─ ユーザー [A-02/03/04/05]／グループ [A-15/16]
          ├─ お知らせ [A-17/18]／履歴 [A-19]／設定 [A-20]
```

`05-e2e-exploratory.md` の VR-E2E-001〜012 は、このマップ上の実動線を横断して検証する。

---

## 7. 相互参照

| 参照先 | 関係 |
|---|---|
| `01-verification-perspective-matrix.md` §8 | 検証レベル「System（画面）」の対象を本表が具体化 |
| `02`〜`04` | 本表の「主担当検証項目」が実体 |
| `05` | 画面上の業務動線（ジャーニー/チャーター） |
| `08-ui-operation.md` | 各画面の**操作**検証（VR-UIS-*） |

---

## 8. セルフチェック結果

| 観点 | 結果 |
|---|---|
| Front 18 + Admin 20 テンプレートを全列挙 | ✅ §2/§3 |
| 全画面に `◎`/`○` の接続 | ✅（△ 4 画面は追加候補を明記） |
| `×`（項目ゼロ）画面 | ✅ なし |
| 操作シナリオ `VR-UIS-*` との接続 | ✅ `08` §4 |
| 画面遷移と E2E の接続 | ✅ §6 |
