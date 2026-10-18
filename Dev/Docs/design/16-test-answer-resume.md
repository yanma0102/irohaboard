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
| e | 復元のための再読込（2 回目のリクエスト） | 退避した出題で開き直すため再読込していた。セッション切れがこの再読込と重なるとログイン画面へ飛ばされ、学習者からは「復元の読み込み中に回答が消えた」ように見える（実機で報告） |

> セッションのアイドル期限は CakePHP の `Session.timeout = 1440`（分）に基づき、実行時に
> `session.gc_maxlifetime = 86400`（24 時間）へ上書きされる。`Docker/php.ini` の
> `session.gc_maxlifetime = 1440` は実行時には使われない（コード・実測で確認済み）。
> つまり 30〜60 分の受験中にアイドル期限で切れることは無く、**現実的な引き金は (c)(d) と
> タブ/ブラウザの再起動**である。キープアライブでは防げない。

### 1.3 スコープ（ユーザ承認済み）

- **回答と出題内容（問題ID・出題順）をブラウザへ退避し、復元する**（B 案）
  - 回答: `localStorage`
  - 出題内容: `localStorage` と **Cookie**（e の再読込を不要にするため）
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

受験ページで次のタイミングで `localStorage` と Cookie に保存する。

- 回答の `change` 時（ラジオ・チェックボックス）
- フォーム `submit` 時（制限時間による自動採点を含む）
- ページ離脱時（`pagehide`）
- 10 秒間隔の定期保存

保存キー: `iroha.test.<user_id>.<content_id>`
（同一ブラウザを複数ユーザで使う場合に他ユーザの回答を復元しないため、ユーザID を含める）

`localStorage` の保存内容:

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

出題リストの Cookie:

| 項目 | 値 |
|---|---|
| 名前 | `IrohaTestState_<content_id>`（コンテンツ単位） |
| 値 | `<user_id>-<問題ID>-<問題ID>-...`（例 `1-3-1-2`） |
| 属性 | `path=/; max-age=604800（7日）; samesite=lax`（HTTPS 時のみ `secure`） |

- カンマは Cookie 値に使えない文字（RFC 6265 の cookie-octet 外）のため、区切りに `-` を使う
- 値が 3500 文字を超える場合は保存しない（1 Cookie 約 4KB の上限に対する安全余裕。
  この場合は §2.2 の部分復元にフォールバックする）
- ユーザID を値に含め、サーバ側でログイン中のユーザと照合する（他ユーザの出題内容を復元しない）

### 2.2 復元（テスト画面を開いた時）

サーバ側（`ContentsQuestionsController`）が、**最初のリクエスト**で出題リストを次の優先順で決める。

| 優先 | 条件 | 挙動 |
|---|---|---|
| 1 | リクエストの `question_id_list` が妥当 | その出題順を採用し、セッションにも保存（採点時の隠し項目） |
| 2 | Cookie の出題リストが妥当 | その出題順を採用し、セッションにも保存 |
| 3 | セッションに `id_list` がある | 従来どおり採用 |
| 4 | `question_count > 0` | ランダム抽選し、セッションへ保存（従来どおり） |
| 5 | その他 | 全問を `sort_no` 順で出題（従来どおり） |

ブラウザ側（`contents_questions.js`）は、退避した回答を**表示中の設問と一致する分だけ**復元する。

```
localStorage に受験中データがある
  → 表示中の設問IDと一致する回答を復元（一致しない設問の回答は採点対象外）
     - 退避した回答がすべて復元できた       → 「前回の回答を復元しました」
     - 一部しか復元できなかった（出題変更） → 「前回の回答を復元しました（出題内容が変更されたため、一部の回答は復元されていません）」
     - 退避した回答が 0 件                  → 通知なし
```

**再読込は行わない。** 出題内容は Cookie により最初のリクエストから揃うため、
セッション切れと重なる 2 回目のリクエスト（1.2-e）が発生しない。
Cookie が無い・受け付けられない場合でも、回答は一致分だけ復元され、**破棄しない**。

### 2.3 採点（POST）

出題内容（出題順）をセッションに依存させないため、フォームに隠し項目
`question_id_list`（出題順の問題IDのカンマ区切り）を出力し、採点時にサーバが
**リクエスト指定 → Cookie → セッション → ランダム抽選** の順で出題リストを決定する。

これにより「再ログイン後に回答を再送信すると、再抽選された別の問題で誤採点される」
問題（1.2-b）も同時に解消する。

### 2.4 破棄

- 本人の採点結果画面（`is_record` かつ管理者による他人の履歴表示でない）を開いた時に
  `localStorage` と Cookie を削除
- 採点成功時にサーバ側でも Cookie を失効させる（JS 無効時の保険）
- 期限切れ（`saved_at` から 7 日超）でも削除

失敗した POST（セッション切れでログイン画面へ遷移）では削除しない。

## 3. 実装

### 3.1 `ContentsQuestionsController::index()`

出題リストの決定を `resolveQuestionIdList()` に集約し、リクエスト指定と Cookie を優先する（§2.2 の表）。
結果表示モード（`$record_id` 指定）は `ib_records_questions` の出題順を使用（従来どおり）。

Cookie の読み取り・検証は `getTestStateCookieIdList()`、失効は `deleteTestStateCookie()` が担当する。

### 3.2 出題リストの検証（改ざん対策）

`getRequestedQuestionIdList()` で、リクエスト指定・Cookie のどちらも次をすべて満たす場合のみ受け付ける。

- カンマ区切りの半角数字のみ・正の整数・重複なし
- 要素数が `min(question_count, コンテンツの問題数)` と一致（**問題数の改ざん防止**）
- すべて `content_id` に属する問題であること（Cookie はさらにユーザID の一致）

残余リスク: 同一コンテンツ内で出題される問題の**組み合わせ・順序**はクライアントが
指定できる。これは従来から「Cookie を消して再抽選させる」ことで可能であり、試験の
完全性は元々クライアント側の制御に依存していない（サーバでの受験セッション管理は
本設計の対象外）。

### 3.3 変更ファイル

| ファイル | 変更 |
|---|---|
| `app/src/Controller/ContentsQuestionsController.php` | `resolveQuestionIdList()` / `getRequestedQuestionIdList()` / `parseQuestionIdList()` / `getTestStateCookieIdList()` / `findQuestionsByIdList()` 追加、Cookie 失効、`question_id_list` をビューへ受け渡し |
| `app/templates/ContentsQuestions/index.php` | 隠し項目 `question_id_list` と JS 変数（`CONTENT_ID` / `USER_ID` / `IS_ADMIN_RECORD` / `MSG_RESTORED` / `MSG_RESTORED_PARTIAL`）を追加、`contents_questions.js` のバージョンを更新 |
| `app/webroot/js/contents_questions.js` | 退避（`localStorage` + Cookie）・復元（再読込なし）・破棄処理を追加 |
| `app/phpstan-baseline.neon` | 既存パターン（`EntityInterface::$id`）の発生回数 3 → 4 |
| `Dev/tests/TestCase/Controller/ContentsQuestionsControllerTest.php` | 出題リスト復元（リクエスト・Cookie）・改ざん・リクエスト指定採点・Cookie 失効のテストを追加 |
| `Dev/scripts/gui-test-resume.py` | 実ブラウザ GUI 操作テストを追加 |

スキーマ変更・マイグレーションは無し。

## 4. テスト計画

### 4.1 PHPUnit（`ContentsQuestionsControllerTest`）

| テスト | 検証内容 |
|---|---|
| `testIndexRestoresRequestedQuestionSet` | セッション無しでも指定した出題内容・順序で表示される |
| `testIndexRestoresQuestionSetFromCookie` | Cookie に退避した出題リストで、最初のリクエストから同じ出題内容・順序で表示される |
| `testIndexIgnoresOtherUserCookie` | 他ユーザの Cookie（ユーザID 不一致）は採用されない |
| `testIndexUsesSessionQuestionIdList` | セッションの出題リストは従来どおり使用される（回帰） |
| `testIndexIgnoresInvalidRequestedQuestionSet` | 問題数不一致・他コンテンツの問題・重複は無視される |
| `testPostGradesUsingSubmittedQuestionIdList` | セッションに出題リストが無くても、送信された出題リストで採点される |
| `testPostDeletesTestStateCookie` | 採点完了時に Cookie が失効する |

### 4.2 GUI 操作テスト（Playwright・必須）

`Dev/scripts/gui-test-resume.py`（21 チェック）。PHPUnit では検出できない
JS / `localStorage` / Cookie の挙動を実ブラウザで検証する。

1. REST API で受講登録（テスト前に未登録だった場合のみ、終了時に解除）
2. ログイン → テスト画面表示 → 全設問に回答
3. 回答が出題内容とともに `localStorage` へ、出題リストが Cookie へ退避されること
4. `context.clear_cookies()` でセッション切れを模擬 → ログイン画面へ遷移すること
5. 再ログイン → テスト画面を開き直すと、同じ出題内容で**回答が復元される**こと
6. 復元が**再読込なしの 1 リクエスト**で完了すること（`/contents-questions/index/` への
   リクエスト数が 1、URL に `question_id_list` が付かない）
7. 採点結果画面へ遷移し、**元と同じ出題内容が採点される**こと
8. 採点後に退避データ（`localStorage`・Cookie）が削除されること／JS コンソールエラーが無いこと

### 4.3 検証結果（2026-10-18）

| 検証 | 結果 |
|---|---|
| PHPUnit 全体 | 967 tests / 4778 assertions OK |
| `ContentsQuestionsControllerTest` | 13 tests / 108 assertions OK |
| GUI 操作テスト | 21/21 PASS（コンテンツ 7・得点 10/20・復元は 1 リクエスト） |
| GUI 追加確認（コンテンツ 62・60 問） | セッションのみ喪失＋Cookie 保持で 60/60 復元・1 リクエスト・出題順も一致 |
| GUI 追加確認（Cookie も喪失・部分復元） | 再抽選された出題に対し一致分のみ復元（0〜1/10）・通知あり・回答は破棄されない |
| `composer stan`（phpstan level 8） | No errors |
| `composer cs-check`（変更ファイル） | 0 errors（既存の 120 文字超過 warning 1 件のみ） |

## 5. リスクと対策

| リスク | 対策 |
|---|---|
| 陳腐化した出題内容の復元 | `saved_at` から 7 日超は破棄。Cookie も `max-age` 7 日。サーバ側でも妥当性を検証し、不正なら無視 |
| 別ユーザの回答・出題内容を復元 | 保存キーにユーザID を含める。Cookie は値のユーザID をサーバ側で照合。管理者の他人履歴表示時は削除しない |
| Cookie の容量超過（設問が多いテスト） | 3500 文字を超える値は保存せず、一致する設問のみ復元する（回答は破棄しない） |
| `localStorage` / Cookie 不可（プライベートモード等） | 例外を握りつぶし、従来どおりの挙動（復元なし）にフォールバック。片方のみ使える場合は使える方だけで復元する |
| 出題が再抽選された場合の回答 | 表示中の設問と一致する回答のみ復元し、回答を破棄しない。採点は表示中の出題（隠し項目）で行う |
| 経過時間を復元すると制限時間超過で即時採点 | 従来の自動採点条件と同一。クライアント側タイマーは元々強制力が無い |

## 6. 既知の制限

- 復元できるのは「同じブラウザ」のみ（別端末・別ブラウザでは復元できない）
- ブラウザの `localStorage` / Cookie を消去した場合は、出題が再抽選され一致する回答のみ復元される
- 受験中データのサーバサイド永続化（中断・再開・別端末復帰）は未対応（§1.4）

## 7. 将来の改善案

- ログイン後のリダイレクト復帰: 認証ミドルウェアは GET に対して
  `?redirect=<元URL>` を付与する（実測確認済み）が、`UserLoginTrait::performLogin()` は
  これを使わずコース一覧へ遷移する。活用すればセッション切れ後、再ログインで
  テスト画面へ自動復帰できる（ログイン URL 自身を除く検証が必要）
- サーバサイドでの受験中データ保持（`ib_records` を `is_complete = 0` で先行作成し、
  出題順・回答を `ib_records_questions` に保存）による中断・再開
