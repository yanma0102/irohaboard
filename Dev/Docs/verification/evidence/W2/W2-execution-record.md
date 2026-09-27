# W2 実施記録（P0: REST API / MCP / 採点・集計 / CSV）

- 実施日: 2026-09-26
- 対象: `06-risk-coverage-automation.md` §4 W2（RK-03/06/07/08: API契約・MCP・採点/集計・CSV）
- 方法: 自動テスト（フレッシュ MariaDB `irohaboard_test`）＋稼働中アプリ `http://localhost:8082` への実 HTTP（curl）
- 環境: PHP 8.4.25 / PHPUnit 13.3.4 / MariaDB 11.4.13 / Docker `irohaboard5-web-1`,`irohaboard5-db-1`
- 前提: プロダクション DB `irohaboard` は Seed 済（admin, manager1, editor1, teacher1, user1(id=5), user2(id=6)、コース2、コンテンツ11）

## 1. 自動テスト結果（すべて OK）

| ID | 対象 | コマンド | 結果 |
|---|---|---|---|
| W2-01 | REST API v1（契約・権限・Write） | `vendor/bin/phpunit tests/TestCase/Controller/Api` | **OK 191 tests / 1205 assertions** |
| W2-02 | MCP サーバ（9ツール・認証・レート制限） | `vendor/bin/phpunit tests/TestCase/Controller/McpControllerTest.php` | **OK 25 tests / 212 assertions** |
| W2-03 | 採点（問題 CRUD・正解判定） | `ContentsQuestionsControllerTest` / `ContentsQuestionsTableTest` | **OK 6/27, 4/17** |
| W2-04 | 集計（半完了ロジック） | `UsersCoursesTableTest` / `UsersCoursesControllerTest` | **OK 4/20, 2/3** |
| W2-05a | CSV（数式インジェクション防止） | `CsvSanitizeTest` | **OK 8 tests / 8 assertions** |
| W2-05b | CSV（履歴・明細エクスポート） | `Admin/RecordsControllerTest` | **OK 24 tests / 152 assertions** |
| W2-05c | CSV（ユーザー入出力） | `Admin/UsersControllerTest` | **OK 17 tests / 72 assertions** |
| W2-06 | テーブル/モデル補助 | `RecordsTableTest` | **OK 2 tests / 5 assertions** |

> 注: 個別実行はフレッシュ DB 作成後に実施（`irohaboard_test` を DROP/CREATE してから各ファイル実行）。

## 2. 稼働中アプリへの実 HTTP 検証（読み取り系）

| ID | 操作 | 結果 |
|---|---|---|
| W2-L1 | `GET /api/v1/contents`（admin） | **200**、`data` 11件（`ラベルコンテンツ` 先頭） |
| W2-L2 | `GET /api/v1/records`（user1 自身） | **200**、件数 2 |
| W2-L3 | `GET /api/v1/records?user_id=1`（user1 が他人指定） | **200**、件数 2（**自分のみ**。`user_id` フィルタは非staffでは無視＝漏洩なし） |
| W2-L4 | `GET /api/v1/courses`（user1） | **200**、件数 1（アクセス可能コースのみ） |
| W2-L5 | `GET /api/v1/users`（admin） | **200**、件数 6 |
| W2-L6 | DB 集計照合（user1 の学習 content 数, course1） | SQL: `studied=2`（集計対象が存在し、API/コントローラ集計の前提データが正） |

## 3. CSV 実エクスポート検証（管理者セッション）

手順: `GET /admin/users/login` でセッション Cookie と `_csrfToken`＋`_Token[fields]/[unlocked]/[debug]` を取得 → `POST` ログイン（302→`/admin`）→ 各 CSV を取得。

| ID | 操作 | 結果 |
|---|---|---|
| W2-C1 | `GET /admin/records?cmd=csv` | **200 / text/csv / 256 bytes**。ヘッダ `ログインID,氏名,コース,コンテンツ,得点,合格点,結果,理解度,学習時間,学習日時` |
| W2-C2 | `GET /admin/records?cmd=csv_detail` | **200 / text/csv / 478 bytes** |
| W2-C3 | `GET /admin/users?cmd=export` | **200 / text/csv / 791 bytes**。ヘッダ `ログインID,パスワード,氏名,権限,...`（グループ1-10/コース1-6 以降を含む）。**7行 = ヘッダ+6ユーザ**（DB 6名と一致） |
| W2-C4 | 文字コード | 本文は **CP932(SJIS-WIN)**（CP932→UTF-8 変換で正常表示）。→ 設計どおり |

## 4. 発見事項

| ID | 重大度 | 内容 | 証拠 |
|---|---|---|---|
| W2-F1 | S3（軽微） | CSV 応答の `Content-Type: text/csv; charset=UTF-8` だが本文は CP932(SJIS-WIN)。charset 表記不一致 | `/admin/records?cmd=csv`, `/admin/users?cmd=export` の応答ヘッダ |
| W2-F2 | S4（仕様確認） | 非staff の `GET /api/v1/records?user_id=` はフィルタを無視して自分のみ返す（漏洩なし）。仕様として明記/無視してよいか要確認 | W2-L3 |

> `W2-F1` は計画 `VR-API-*`/CSV 系の期待（SJIS 出力）に対する Content-Type の不整合であり、機能・セキュリティ影響はなし。

## 5. 判定

- W2 の自動化可能 P0 項目は **すべて合格**（Errors 0 / Failures 0 相当）。
- 採点（W2-03）・集計（W2-04）のライブ HTTP 実行はフロントのセッション＋FormProtection トークンが必要なため今回未実施。**コントローラ/モデル自動テストでカバー済**と判断（未実施の旨を明示）。
- オンライン時の未実施・手動残余: 採点の実ブラウザ受験、集計の実画面表示、CSV の Excel 実オープン。

## 6. 次工程

- W3（P0 運用/移行: INSTALL/UPDATE/復旧）へ。
- W2-F1（Content-Type charset 表記）は P2 として修正候補。
