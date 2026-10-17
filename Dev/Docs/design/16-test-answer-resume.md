# 16 試験のセッション切れ対策（回答の退避・復元）設計書

## 1. 概要

### 1.1 背景

受講者がテストを受験している最中にセッションが切れると、採点 POST が未認証として
`/users/login` へリダイレクトされ、**受験中の回答がすべて失われ、試験がリセットされる**。

実機（Docker 開発環境）で再現済み。コンテンツ 7（course 1・ランダム出題 3 問）で

1. ログイン → `GET /contents-questions/index/7`（出題リストがセッションに保存される）
2. セッションファイルを削除（セッション切れを模擬）
3. 回答を POST → `302 /users/login`、`ib_records` は増えない（回答は完全に失われる）

### 1.2 原因

| # | 要因 | 詳細 |
|---|---|---|
| a | 回答がブラウザの DOM 上にしか無い | 送信は 1 回限りの POST。送信前にセッションが切れると復元不能 |
| b | 出題順（ランダム出題）がセッションのみ | `Iroha.RondomQuestions.<content_id>.id_list`。セッションが切れると再抽選され、再送信しても**別の問題で採点される** |
| c | セッション Cookie がブラウザセッション限り | `session.cookie_lifetime = 0`（ブラウザ・タブ終了で消える） |
| d | セッション実体がコンテナ `/tmp` | named volume 外のためコンテナ再起動で全セッション消失 |

> セッションのアイドル期限は CakePHP の `Session.timeout = 1440`（分）に基づき、実行時に
> `session.gc_maxlifetime = 86400`（24 時間）へ上書きされる。`Docker/php.ini` の
> `session.gc_maxlifetime = 1440` は実行時には使われない（コード・実測で確認済み）。
> つまり 30〜60 分の受験中にアイドル期限で切れることは無く、**現実的な引き金は (c)(d) と
> タブ/ブラウザの再起動**である。キープアライブでは防げない。

### 1.3 スコープ（ユーザ承認済み）

- **回答と出題内容（問題ID・出題順）をブラウザの `localStorage` へ退避し、復元する**（B 案）
- セッションの保存先（`/tmp`）は**現状維持**（ユーザ選択）
- DB スキーマ変更は行わない

### 1.4 非目標

| 項目 | 理由 |
|---|---|
| DB（サーバサイド）での受験中データ永続化・中断再開 | ユーザが B 案を選択（スキーマ変更を伴うため） |
| `session.save_path` の永続ボリューム移動 | ユーザが「現状維持」を選択 |
| 受験中のキープアライブ | 上記 1.2 のとおり引き金（Cookie 消失・コンテナ再起動）には無効 |
| ログイン後のリダイレクト復帰（`?redirect=` の活用） | 認証フロー全体に影響するため見送り（§7 に将来案） |

## 2. 仕様

### 2.1 退避（受験中）

受験ページで次のタイミングで `localStorage` に保存する。

- 回答の `change` 時（ラジオ・チェックボックス）
- フォーム `submit` 時（制限時間による自動採点を含む）
- ページ離脱時（`pagehide`）
- 10 秒間隔の定期保存

保存キー: `iroha.test.<user_id>.<content_id>`
（同一ブラウザを複数ユーザで使う場合に他ユーザの回答を復元しないため、ユーザID を含める）

保存内容:

```json
{
  "question_id_list": [3, 1, 2],
  "answers": { "answer_3": "1", "answer_2[]": ["1"], "answer_1": "1" },
  "study_sec": 120,
  "saved_at": 1792214088
}
```

- ラジオは単一値、チェックボックスは配列（サーバ側の `answer` 形式に合わせる）
- `study_sec` も復元し、経過時間を継続する
- `saved_at` から 7 日を超えたものは破棄（陳腐化した出題内容の復元を防ぐ）

### 2.2 復元（テスト画面を開いた時）

```
localStorage に受験中データがあり、出題リストが表示中のものと一致
  → 回答・経過時間を復元し、「前回の回答を復元しました」を表示
不一致（セッション切れで再抽選された等）で、URL に question_id_list が無い
  → ?question_id_list=<退避した出題リスト> を付けて 1 回だけ開き直す
    （再読込後の URL には出題リストが付くため無限ループしない）
不一致で、URL の question_id_list が退避内容と同一（＝再現できない）
  → 退避データを破棄
```

### 2.3 採点（POST）

出題内容（出題順）をセッションに依存させないため、フォームに隠し項目
`question_id_list`（出題順の問題IDのカンマ区切り）を出力し、採点時にサーバが
**リクエスト指定 → セッション → ランダム抽選** の順で出題リストを決定する。

これにより「再ログイン後に回答を再送信すると、再抽選された別の問題で誤採点される」
問題（1.2-b）も同時に解消する。

### 2.4 破棄

- 本人の採点結果画面（`is_record` かつ管理者による他人の履歴表示でない）を開いた時に削除
- 上記 2.2 の「再現できない」判定時、および期限切れ時に削除

失敗した POST（セッション切れでログイン画面へ遷移）では削除しない。

## 3. 実装

### 3.1 `ContentsQuestionsController::index()`

出題リストの決定を `resolveQuestionIdList()` に集約し、リクエスト指定を最優先する。

| 優先 | 条件 | 挙動 |
|---|---|---|
| 1 | `question_id_list` が妥当 | その出題順を採用し、セッションにも保存 |
| 2 | セッションに `id_list` がある | 従来どおり採用 |
| 3 | `question_count > 0` | ランダム抽選し、セッションへ保存（従来どおり） |
| 4 | その他 | 全問を `sort_no` 順で出題（従来どおり） |

結果表示モード（`$record_id` 指定）は `ib_records_questions` の出題順を使用（従来どおり）。

### 3.2 `question_id_list` の検証（改ざん対策）

`getRequestedQuestionIdList()` で次をすべて満たす場合のみ受け付ける。

- カンマ区切りの半角数字のみ・正の整数・重複なし
- 要素数が `min(question_count, コンテンツの問題数)` と一致（**問題数の改ざん防止**）
- すべて `content_id` に属する問題であること

残余リスク: 同一コンテンツ内で出題される問題の**組み合わせ・順序**はクライアントが
指定できる。これは従来から「Cookie を消して再抽選させる」ことで可能であり、試験の
完全性は元々クライアント側の制御に依存していない（サーバでの受験セッション管理は
本設計の対象外）。

### 3.3 変更ファイル

| ファイル | 変更 |
|---|---|
| `app/src/Controller/ContentsQuestionsController.php` | `resolveQuestionIdList()` / `getRequestedQuestionIdList()` / `findQuestionsByIdList()` 追加、`question_id_list` をビューへ受け渡し |
| `app/templates/ContentsQuestions/index.php` | 隠し項目 `question_id_list` と JS 変数（`CONTENT_ID` / `USER_ID` / `IS_ADMIN_RECORD` / `MSG_RESTORED`）を追加、`contents_questions.js` のバージョンを更新 |
| `app/webroot/js/contents_questions.js` | 退避・復元・破棄処理を追加 |
| `app/phpstan-baseline.neon` | 既存パターン（`EntityInterface::$id`）の発生回数 3 → 4 |
| `Dev/tests/TestCase/Controller/ContentsQuestionsControllerTest.php` | 出題リスト復元・改ざん・リクエスト指定採点のテストを追加 |
| `Dev/scripts/gui-test-resume.py` | 実ブラウザ GUI 操作テストを追加 |

スキーマ変更・マイグレーションは無し。

## 4. テスト計画

### 4.1 PHPUnit（`ContentsQuestionsControllerTest`）

| テスト | 検証内容 |
|---|---|
| `testIndexRestoresRequestedQuestionSet` | セッション無しでも指定した出題内容・順序で表示される |
| `testIndexUsesSessionQuestionIdList` | セッションの出題リストは従来どおり使用される（回帰） |
| `testIndexIgnoresInvalidRequestedQuestionSet` | 問題数不一致・他コンテンツの問題・重複は無視される |
| `testPostGradesUsingSubmittedQuestionIdList` | セッションに出題リストが無くても、送信された出題リストで採点される |

### 4.2 GUI 操作テスト（Playwright・必須）

`Dev/scripts/gui-test-resume.py`（18 チェック）。PHPUnit では検出できない
JS / `localStorage` の挙動を実ブラウザで検証する。

1. REST API で受講登録（テスト前に未登録だった場合のみ、終了時に解除）
2. ログイン → テスト画面表示 → 全設問に回答
3. 回答が出題内容とともに `localStorage` へ退避されること
4. `context.clear_cookies()` でセッション切れを模擬 → ログイン画面へ遷移すること
5. 再ログイン → テスト画面を開き直すと、同じ出題内容で**回答が復元される**こと
6. 採点結果画面へ遷移し、**元と同じ出題内容が採点される**こと
7. 採点後に退避データが削除されること／JS コンソールエラーが無いこと

### 4.3 検証結果（2026-10-17）

| 検証 | 結果 |
|---|---|
| PHPUnit 全体 | 964 tests / 4748 assertions OK |
| `ContentsQuestionsControllerTest` | 10 tests / 78 assertions OK |
| GUI 操作テスト | 18/18 PASS（コンテンツ 7・得点 10/20・出題順 `3,1,2` でも復元） |
| `composer stan`（phpstan level 8） | No errors |
| `composer cs-check`（変更ファイル） | 0 errors（既存の 120 文字超過 warning 1 件のみ） |

## 5. リスクと対策

| リスク | 対策 |
|---|---|
| 再読込の無限ループ | 再読込後の URL に `question_id_list` が付くことを判定条件にする |
| 陳腐化した出題内容の復元 | `saved_at` から 7 日超は破棄。サーバ側でも妥当性を検証し、不正なら無視 |
| 別ユーザの回答を復元 | 保存キーにユーザID を含める。管理者の他人履歴表示時は削除しない |
| `localStorage` 不可（プライベートモード等） | 例外を握りつぶし、従来どおりの挙動（復元なし）にフォールバック |
| 経過時間を復元すると制限時間超過で即時採点 | 従来の自動採点条件と同一。クライアント側タイマーは元々強制力が無い |

## 6. 既知の制限

- 復元できるのは「同じブラウザ」のみ（別端末・別ブラウザでは復元できない）
- ブラウザの `localStorage` を消去した場合は復元できない
- 受験中データのサーバサイド永続化（中断・再開・別端末復帰）は未対応（§1.4）

## 7. 将来の改善案

- ログイン後のリダイレクト復帰: 認証ミドルウェアは GET に対して
  `?redirect=<元URL>` を付与する（実測確認済み）が、`UserLoginTrait::performLogin()` は
  これを使わずコース一覧へ遷移する。活用すればセッション切れ後、再ログインで
  テスト画面へ自動復帰できる（ログイン URL 自身を除く検証が必要）
- サーバサイドでの受験中データ保持（`ib_records` を `is_complete = 0` で先行作成し、
  出題順・回答を `ib_records_questions` に保存）による中断・再開
