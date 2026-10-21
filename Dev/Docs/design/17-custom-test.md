# 17 カスタム受験（ユーザ設定による反復テスト）設計書

## 1. 概要

### 1.1 背景

現行のテスト機能は、出題数・制限時間・合格率・不正解時の表示を**管理者がコンテンツ単位で固定**し、
受講者はその設定で受験する。再受験自体は可能だが、次の課題がある。

- 苦手な章だけを繰り返し解く、といった**学習目的の反復**ができない
- 本番と同じ問題数・制限時間で毎回受ける必要があり、練習には過剰
- 出題範囲を絞るための**問題の分類（章・カテゴリ）**が存在しない

本設計では、既存のテスト機能をベースに、受講者が設定を変えて何度でも受けられる
**カスタム受験（練習）**を追加する。

### 1.2 目的

| # | 目的 |
|---|---|
| P1 | 受講者がテストごとに「出題範囲・出題数・制限時間・出題順・不正解時の表示」を選んで受験できる |
| P2 | 回数制限なく反復でき、毎回異なる出題で学習できる |
| P3 | 練習は**成績・学習回数・理解度・コース完了に影響させない** |
| P4 | 管理者がコンテンツごとに練習の可否と制限時間の上限を制御できる |
| P5 | 正式受験の既存挙動（記録・合否・完了判定・セッション切れ復元）を変えない |

### 1.3 スコープ（ユーザ確認済み）

- 受講者が変更できる設定: **出題数・制限時間・不正解時の表示・出題順・出題範囲（カテゴリ）**
- 練習の履歴: **記録しない**（`ib_records` / `ib_records_questions` に書き込まない）
- 管理側の制御: **コンテンツごとの許可＋制限時間の上限**（スキーマ変更あり）
- 出題範囲の分類: 問題ごとの**カテゴリ（章）**を追加（スキーマ変更あり）

### 1.4 非目標

| 項目 | 理由 |
|---|---|
| 練習履歴の保存・管理画面での集計 | ユーザが「記録しない」を選択（P3 を最優先） |
| 受講者ごとの設定プリセット保存（DB） | v1 はセッション内のみ。端末をまたぐ保存は将来案（§10） |
| 合格率の変更・練習での合否判定 | 練習は記録されないため合否の意味がない。合格率は管理者設定のまま |
| 問題のタグ（多対多）・横断検索 | v1 はコンテンツ内のカテゴリ（1 問題 1 カテゴリ）。将来案（§10） |
| REST API / MCP での練習実行 | v1 は Web UI のみ。問題カテゴリの入出力のみ MCP に追加（§6.6） |
| 練習中のセッション切れからの完全復帰 | 練習は記録しないため、セッション切れ時は設定画面からやり直す（§4.6、§8） |

## 2. 用語

| 用語 | 定義 |
|---|---|
| 正式受験 | 現行の受験。`ib_records` に履歴を保存し、合格率で合否を判定する |
| カスタム受験（練習） | 受講者が設定を選んで受ける受験。履歴を保存せず、合否判定もしない |
| カテゴリ | コンテンツ内の問題分類（章・単元）。`ib_contents_questions.category` |
| 出題範囲 | 練習で出題対象にするカテゴリの集合 |
| 出題数 | 出題範囲から選ぶ問題数（全問の場合は範囲内すべて） |

## 3. 現状

### 3.1 データ構成

| テーブル | 役割 | 本機能での変更 |
|---|---|---|
| `ib_contents` | テスト本体。`timelimit` / `pass_rate` / `question_count` / `wrong_mode` | 練習用の 2 列を追加 |
| `ib_contents_questions` | 問題。`options` / `correct` / `score` / `explain` / `sort_no` | `category` を追加 |
| `ib_records` | 受験結果（1 受験 = 1 行） | **変更なし**（練習では書き込まない） |
| `ib_records_questions` | 受験結果の設問別明細 | **変更なし**（練習では書き込まない） |

### 3.2 出題と採点の流れ（現行）

1. コンテンツ一覧のテスト名 → `GET /contents-questions/index/{content_id}`
2. `ContentsQuestionsController::index()` が出題リストを決定
   - リクエスト指定 → Cookie → セッション → ランダム抽選 → 全問（`16-test-answer-resume.md`）
3. 採点フォームの POST → 採点 → `ib_records` / `ib_records_questions` 保存 → 結果画面へリダイレクト
4. 結果画面（`/contents-questions/record/{content_id}/{record_id}`）で合否・得点・解説を表示

### 3.3 記録と完了判定

- コンテンツ一覧の「学習回数」「理解度」は `ib_records` の集計
- コース完了は、テストの場合 `ib_records.is_passed = 1` が 1 件以上あること
  （`ContentsTable::getContentRecord()` / `UsersCoursesTable::getCourseRecord()`）
- **練習で `ib_records` に書き込まなければ、これらの判定は一切変化しない**

### 3.4 現行の制約

- 設定は管理者のみ変更可能（`Admin/Contents/edit.php`）
- 問題に分類情報がない（`title` / `body` / `comment` のみ）
- 再受験は可能だが、毎回同じ設定・同じ出題数

## 4. 仕様

### 4.1 利用フロー

```
コンテンツ一覧
  └─ [練習] リンク（allow_custom_test = 1 のテストのみ）
       └─ 練習設定画面  GET  /contents-questions/practice/{content_id}
            └─ 開始     POST /contents-questions/practice/{content_id}
                 └─ 受験画面  GET  /contents-questions/practice-run/{content_id}
                      └─ 採点 POST /contents-questions/practice-grade/{content_id}
                           └─ 結果画面 GET /contents-questions/practice-result/{content_id}
                                ├─ もう一度練習（同じ設定で設定画面へ）
                                └─ コースに戻る
```

- 練習の結果は **DB に保存しない**。結果画面はセッションに保持する
- 練習中に「設定を変更」で設定画面へ戻れる（回答は破棄される旨を確認）

### 4.2 練習の設定項目

| 設定 | 選択肢・範囲 | 既定値 | 備考 |
|---|---|---|---|
| 出題範囲 | コンテンツ内のカテゴリ（複数選択）。カテゴリ未設定の問題は「未分類」 | すべて | カテゴリが 1 種類以下の場合は選択 UI を出さない |
| 出題数 | 1〜出題範囲の問題数。空欄または 0 は全問 | 全問 | 範囲の変更に合わせて上限を更新（JS） |
| 制限時間 | 0（なし）または 1〜`practice_timelimit_max` 分。上限未設定時は 1〜100 分 | コンテンツの `timelimit` を範囲内に丸めた値。未設定なら 0（0 を選べない場合は上限値） | 上限が設定されている場合は 0（なし）を選べない |
| 出題順 | ランダム / 登録順 | ランダム | **出題する問題は常にランダムに選び、登録順は表示順のみ**（§4.4） |
| 不正解時の表示 | 正解と解説 / 解説のみ / 表示しない | コンテンツの `wrong_mode` | 練習では正解・解説が見える（§8） |
| 合格率 | 変更不可 | ― | 練習では合否判定を行わない |

補足:

- 制限時間の上限（`practice_timelimit_max`）は「練習で設定できる最大値」。値が入っている
  場合は 1〜上限の範囲で必須とし、無制限は許可しない。空欄の場合は 0（なし）も許可する
- 出題数の上限は「選択した出題範囲の問題数」。管理者が別途上限を設ける必要はない
  （問題数以上の値は範囲内の問題数に丸める）
- 正式受験の `question_count`（管理側の出題数）は練習には適用しない。練習では範囲内の
  全問から選べる（本番より広い範囲を練習できるようにするため）
- 「未分類」（`category` が NULL / 空文字）はフォーム上 `__none__` として送信し、
  サーバ側で NULL / 空文字に正規化する（空配列＝すべて、と区別するため）
- 設定値はセッションに保持し、`localStorage` には保存しない（端末・セッションをまたがない）

### 4.3 管理側の設定

`Admin/Contents/edit.php` のテスト設定に次を追加する。

| 項目 | 列 | 既定 | 説明 |
|---|---|---|---|
| カスタム受験を許可 | `allow_custom_test` | 0（不許可） | 0 の場合は練習リンクを出さず、URL 直叩きも 404 |
| 練習の制限時間の上限（分） | `practice_timelimit_max` | NULL（上限なし） | 1〜100。空欄は上限なし（受講者は 0 = 制限なしも選択可） |

問題編集（`Admin/ContentsQuestions/edit.php`）に次を追加する。

| 項目 | 列 | 説明 |
|---|---|---|
| カテゴリ（章） | `category` | 100 文字以内。同一コンテンツ内の既存カテゴリを `datalist` で候補表示 |

- 管理画面には「練習では正解・解説が表示されます。認定試験等では許可しないでください」
  という注意書きを表示する
- `practice_timelimit_max` は空欄または 0 を NULL（上限なし）として保存する
- 問題一覧（`Admin/ContentsQuestions/index.php`）に「カテゴリ」列を追加する

### 4.4 出題と採点

#### 出題リストの決定（開始時）

```
available = 選択したカテゴリに属する問題（未分類は category が NULL/''）
if 出題数 == 0 or 出題数 >= count(available):
    対象 = available すべて
else:
    対象 = available からランダムに「出題数」件
表示順:
    ランダム → 対象をシャッフル
    登録順   → 対象を sort_no ASC で並べる
```

- 「登録順」でも**選択はランダム**。同じ範囲を繰り返すと毎回違う問題に当たる
- 出題リストはセッションに保存し、採点時に照合する（§4.8）

#### 採点

- 正式受験と同じ判定ロジック（単一選択・複数選択・記述式、`score` の合計）を使う
- 表示する値: 得点（`my_score / full_score`）、正解数（`correct_count / 出題数`）
- **合否判定は行わない**（`is_passed` を計算しない）
- `ib_records` / `ib_records_questions` への書き込みは行わない
- 結果画面の設問別表示（正解・解説）は、練習で選んだ `wrong_mode` に従う

#### 結果画面

| 要素 | 内容 |
|---|---|
| 見出し | 「練習結果（記録されません）」 |
| 得点 | `得点 X / Y` |
| 正解数 | `正解 N / M 問` |
| 設問別 | 正誤、選択した回答、正解・解説（`wrong_mode` に従う） |
| 操作 | 「もう一度練習」（結果セッションに保持した同じ設定で設定画面へ）、「設定を変更」、「コースに戻る」 |

### 4.5 記録しないことによる扱い

| 対象 | 挙動 |
|---|---|
| `ib_records` / `ib_records_questions` | 追加・更新しない |
| コンテンツ一覧の学習回数・学習時間・理解度 | 変化しない（正式受験の履歴のみ） |
| コース完了・未完了件数 | 変化しない |
| 管理画面の学習履歴・CSV | 練習は表示されない |
| REST API / MCP の履歴 | 練習は返らない |
| デモモード | 書き込みがないため練習は利用可能（正式受験は従来どおり保存不可） |

> 練習の利用状況を把握したい場合は、`ib_records` ではなく軽量な集計テーブルを
> 別途追加する（§10）。本設計では追加しない。

### 4.6 セッション切れ・再読込

- 練習の状態はセッションに保持する
  - `Iroha.Practice.<user_id>.<content_id>.state` … 設定・出題リスト・開始時刻
  - `Iroha.Practice.<user_id>.<content_id>.result` … 採点結果・設定（結果画面用）
- ユーザ ID をキーに含め、同一セッションで別ユーザがログインし直しても
  前のユーザの練習状態・結果を参照できないようにする
- 正式受験のキー（`Iroha.RondomQuestions.<content_id>.id_list`）とは**別名前空間**にし、
  相互に影響しない
- 受験画面の再読込: セッションが生きていれば同じ出題・回答で再開できる。
  回答は `localStorage`（キー `iroha.practice.<user_id>.<content_id>`）から復元する
- **セッションが切れた場合**: 状態が失われるため、設定画面からやり直す。
  練習は記録対象ではないため、正式受験のような復元機構（Cookie 退避）は設けない
- 状態の有効期限: 開始から 24 時間。超えた状態は破棄して設定画面へ戻す
- 結果は次の練習開始時に破棄する

### 4.7 権限・アクセス制御

| 操作 | 条件 |
|---|---|
| 設定画面・開始・受験・採点・結果 | ログイン済み、かつ対象コースの受講権限（`CoursesTable::hasRight()`） |
| 非公開コンテンツ | 管理者以外は 404（正式受験と同じ） |
| `allow_custom_test = 0` | 404（管理者含む） |
| 管理者による他人の履歴表示 | 練習リンクを出さない。練習画面への遷移もしない |

- 練習は本人のセッション内でのみ完結し、他ユーザの状態を参照する経路はない
- 練習の結果画面は `record_id` を持たないため、他人の結果を推測する経路はない

### 4.8 入力検証（改ざん対策）

| 入力 | ルール |
|---|---|
| `content_id` | 実在・`kind = test`・受講権限・（管理者以外）`status = 1`・`allow_custom_test = 1`・問題が 1 件以上 |
| `categories[]` | 配列。各要素は 100 文字以内。コンテンツ内に実在するカテゴリのみ。重複排除。最大 100 件。空 = すべて |
| `question_count` | 0〜出題範囲の問題数。0 = 全問。負数・非数値はエラー |
| `timelimit` | 0〜`practice_timelimit_max`（上限設定時は 1〜上限、未設定時は 0〜100）。非数値はエラー |
| `order` | `random` / `sort` のみ |
| `wrong_mode` | 0〜2 のみ |
| `question_id_list`（採点） | **セッションの状態と完全一致**（件数・順序）。不一致は採点せず設定画面へ |
| `study_sec` | 0〜86400 に丸める |

- 出題リストをクライアントから受け取るのは採点時のみ。状態との完全一致を要求するため、
  問題の差し替え・件数の水増しはできない
- 採点成功時に状態を削除するため、二重送信しても 2 回目は設定画面へ戻る（二重採点されない）
- 制限時間はクライアント側タイマーで制御する（正式受験と同じ）。サーバ側では
  `started_at` を記録するが、時間超過を理由に採点を拒否はしない（回答を失わないため）

### 4.9 画面仕様

#### コンテンツ一覧（`templates/Contents/index.php`）

- `kind = test` かつ `allow_custom_test = 1` の行に、タイトル横のバッジとして
  「練習」リンクを追加する（正式受験のタイトルリンクは現状維持）
- 管理者による他人の履歴表示（`is_admin_record`）では表示しない
- 非公開コンテンツの「(非公開)」表示は現状維持

#### 練習設定画面（`templates/ContentsQuestions/practice.php`）

- パンくず: コース一覧 / コース名 / コンテンツ名 / 練習
- 見出しに「練習（記録されません）」を明示
- 設定フォーム（`FormProtection` / CSRF 有効）
  - 出題範囲: カテゴリごとのチェックボックス（問題数を併記）
  - 出題数: 数値入力。範囲変更時に JS で上限を更新
  - 制限時間: 選択肢（なし / 1〜上限）
  - 出題順: ラジオ（ランダム / 登録順）
  - 不正解時の表示: ラジオ（正解と解説 / 解説のみ / 表示しない）
- エラー時は同画面にメッセージを表示し、入力値を保持する
- 直前の練習結果（`Iroha.Practice.<user_id>.<content_id>.result`）がある場合は、
  その設定を初期値にする（「もう一度練習」「設定を変更」用）
- 「練習を開始」「コースに戻る」

#### 受験画面（`templates/ContentsQuestions/index.php` を共用）

- `$is_practice = true` のとき
  - 見出しに「練習」バッジ、「記録されません」の注記
  - 合否・合格基準得点は表示しない
  - フォームの送信先を `practiceGrade` にする
  - タイマーは `timelimit` に従う（0 の場合は経過時間のみ）
  - 既存の回答退避・復元 JS を練習用キーで利用する
- 正式受験（`$is_practice = false`）の表示・挙動は変更しない

#### 結果画面（`templates/ContentsQuestions/practice_result.php`）

- 得点・正解数・設問別の正誤と解説
- 「もう一度練習」: 直前の設定を引き継いで設定画面へ
- セッションに結果がない場合は設定画面へリダイレクトし、フラッシュメッセージを表示

### 4.10 ルーティング

`config/routes.php` の ContentsQuestions ブロックに追加する。

| メソッド | パス | アクション |
|---|---|---|
| GET / POST | `/contents-questions/practice/{content_id}` | `practice` |
| GET | `/contents-questions/practice-run/{content_id}` | `practiceRun` |
| POST | `/contents-questions/practice-grade/{content_id}` | `practiceGrade` |
| GET | `/contents-questions/practice-result/{content_id}` | `practiceResult` |

- 既存の `/contents-questions/index/...` / `record/...` より前に定義する
- `practiceGrade` は動的な `answer_*` を POST するため、`FormProtection->unlockActions()` に追加する

## 5. データ設計

### 5.1 スキーマ変更

#### `ib_contents`

| 列 | 型 | NULL | 既定 | 説明 |
|---|---|---|---|---|
| `allow_custom_test` | tinyint(1) | NO | 0 | カスタム受験（練習）を許可する |
| `practice_timelimit_max` | int(8) | YES | NULL | 練習で設定できる制限時間の上限（分）。NULL = 上限なし |

#### `ib_contents_questions`

| 列 | 型 | NULL | 既定 | 説明 |
|---|---|---|---|---|
| `category` | varchar(100) | YES | NULL | カテゴリ（章）。NULL / 空文字は「未分類」 |

インデックス: `idx_content_category (content_id, category)`

### 5.2 マイグレーション

`app/config/Migrations/20261020120000_AddCustomTestSupport.php`

```php
use Migrations\BaseMigration;

class AddCustomTestSupport extends BaseMigration
{
    public function up(): void
    {
        $this->table('ib_contents')
            ->addColumn('allow_custom_test', 'boolean', [
                'default' => false, 'null' => false, 'after' => 'question_count',
            ])
            ->addColumn('practice_timelimit_max', 'integer', [
                'null' => true, 'default' => null, 'after' => 'allow_custom_test',
            ])
            ->update();

        $this->table('ib_contents_questions')
            ->addColumn('category', 'string', [
                'limit' => 100, 'null' => true, 'default' => null, 'after' => 'title',
            ])
            ->addIndex(['content_id', 'category'], ['name' => 'idx_content_category'])
            ->update();
    }

    public function down(): void
    {
        $this->table('ib_contents_questions')
            ->removeIndexByName('idx_content_category')
            ->removeColumn('category')
            ->update();

        $this->table('ib_contents')
            ->removeColumn('practice_timelimit_max')
            ->removeColumn('allow_custom_test')
            ->update();
    }
}
```

`down()` は追加した列とインデックスを削除する（既存データの削除は行わない）。

適用:

```bash
bin/cake migrations migrate
```

- 本番適用前に DB バックアップを取得する
- `app/config/schema/app.sql` と `Dev/tests/schema.sql` にも同じ定義を反映する
- 既存データへの影響:
  - `allow_custom_test = 0` → 練習リンクは出ず、従来どおり
  - `practice_timelimit_max = NULL` → 上限なし
  - `category = NULL` → すべて「未分類」。出題範囲の選択 UI は出さない

### 5.3 代替案（不採用）

| 案 | 不採用理由 |
|---|---|
| 練習も `ib_records` に保存し `is_practice` 列で区別 | 学習回数・理解度・完了判定・CSV・API の全経路で除外条件が必要。ユーザが「記録しない」を選択 |
| 練習専用テーブル（`ib_practice_records`） | 履歴を残さない方針のため不要。将来必要になったら追加（§10） |
| 問題のタグ（多対多） | v1 の「章・カテゴリ」には過剰。カテゴリ 1 列で足りる |
| カテゴリのマスタテーブル | 表記ゆれは管理画面の `datalist` と一覧表示で運用回避。必要になったら移行（§10） |
| 練習設定を `localStorage` に保存 | 端末をまたげない。v1 はセッションのみとし、プリセットは将来案 |

## 6. 実装

### 6.1 変更ファイル

| ファイル | 変更 |
|---|---|
| `app/config/Migrations/20261020120000_AddCustomTestSupport.php` | 新規。スキーマ変更 |
| `app/config/schema/app.sql` / `Dev/tests/schema.sql` | 新規列の反映 |
| `app/src/Model/Table/ContentsTable.php` | `allow_custom_test` / `practice_timelimit_max` のバリデーション |
| `app/src/Model/Table/ContentsQuestionsTable.php` | `category` のバリデーション |
| `app/src/Controller/ContentsQuestionsController.php` | `practice` / `practiceRun` / `practiceGrade` / `practiceResult`、設定検証・出題・採点の共通化 |
| `app/templates/ContentsQuestions/practice.php` | 新規。設定画面 |
| `app/templates/ContentsQuestions/practice_result.php` | 新規。結果画面 |
| `app/templates/ContentsQuestions/index.php` | 練習モードの表示・送信先切替 |
| `app/templates/Contents/index.php` | 練習リンク |
| `app/webroot/js/contents_questions.js` | 練習用の状態キー・Cookie 無効化・結果遷移 |
| `app/templates/Admin/Contents/edit.php` | 練習許可・制限時間上限の入力 |
| `app/templates/Admin/ContentsQuestions/edit.php` | カテゴリ入力（`datalist`） |
| `app/templates/Admin/ContentsQuestions/index.php` | カテゴリ列 |
| `app/config/routes.php` | 練習用ルート |
| `app/src/Mcp/Tool/CreateTestQuestionTool.php` / `UpdateTestQuestionTool.php` / `CopyContentTool.php` | `category` の入出力（v1 で対応） |
| `Dev/tests/TestCase/Controller/ContentsQuestionsPracticeTest.php` | 新規。PHPUnit |
| `Dev/scripts/gui-test-practice.py` | 新規。実ブラウザ GUI テスト |
| `Dev/scripts/README.md` | GUI テストの説明を追加 |

### 6.2 `ContentsQuestionsController`

#### アクション

| アクション | 処理 |
|---|---|
| `practice($content_id)` | GET: 設定画面。POST: 設定検証 → 出題リスト決定 → セッション保存 → `practiceRun` へリダイレクト |
| `practiceRun($content_id)` | セッション状態を読み、受験画面を表示。状態がない/期限切れは設定画面へ |
| `practiceGrade($content_id)` | POST のみ。状態と送信された出題リストを照合 → 採点 → 結果をセッションへ → `practiceResult` へ |
| `practiceResult($content_id)` | セッションの結果を表示。なければ設定画面へ |

#### 共通ヘルパー

| メソッド | 役割 |
|---|---|
| `loadPracticeContent(int $contentId): EntityInterface` | コンテンツ取得・権限・`kind` / `status` / `allow_custom_test` の検証 |
| `getPracticeCategories(int $contentId): array` | カテゴリと問題数の一覧（`GROUP BY category`） |
| `validatePracticeSettings(...): array` | 入力の正規化と検証（§4.8）。エラー配列を返す |
| `selectPracticeQuestionIds(...): array` | 範囲・件数・順序から出題リストを作る（§4.4） |
| `isValidPracticeQuestionIdList(...): bool` | 出題リストが範囲・件数・実在に合うか検証 |
| `gradeQuestions(ResultSetInterface $questions): array` | 採点の共通化。`details` / `full_score` / `my_score` / `correct_count` を返す |

- 正式受験の採点（`index()`）も `gradeQuestions()` を使うように置き換える。
  判定ロジック・保存内容は変更しない（既存テストで回帰確認）
- 練習用の状態キーは `Iroha.Practice.<user_id>.<content_id>.state` / `.result`（§4.6）
- 開始時に同じキーの古い `.state` / `.result` を削除する
- `practiceGrade` は `FormProtection->unlockActions(['practiceGrade'])` を追加

### 6.3 テンプレート

- `practice.php`: 設定フォーム。カテゴリごとの問題数を JSON で JS に渡し、出題数の上限を更新する
- `index.php`: `$is_practice` で表示とフォーム送信先を切り替える。
  設問カードの描画は結果画面と共通化するため、`templates/element/ContentsQuestions/question.php`
  に切り出す（正式受験の表示は変えない）
  - `TIMELIMIT_SEC` は `$timelimit`（正式＝`content.timelimit` / 練習＝選択値）から出力する
  - 練習の「戻る」は設定画面へ、正式は従来どおりコース一覧へ
  - 練習では合否・合格基準得点を表示しない
- `practice_result.php`: 得点・正解数・設問別結果。「もう一度練習」は結果セッションに
  保持した設定を使って設定画面を再表示する（設定画面側で結果セッションを参照）
- `Contents/index.php`: `$content['allow_custom_test'] == 1` のとき練習リンクを表示
- 練習結果画面でも `contents_questions.js` を読み込み、`IS_RECORD = 1` /
  `TEST_MODE = practice` として練習用の回答退避データを削除する

### 6.4 JavaScript（`contents_questions.js`）

- `TEST_MODE`（`official` / `practice`）を追加
- 状態キーをモード別にする
  - 正式: `iroha.test.<user_id>.<content_id>`
  - 練習: `iroha.practice.<user_id>.<content_id>`
- 練習では Cookie への出題リスト退避を行わない（`TEST_STATE_USE_COOKIE = false`）
- 練習の結果画面では練習用の退避データを削除する
- 既存の復元メッセージ・二重送信防止・制限時間の自動採点はそのまま利用する

### 6.5 管理画面

- `Admin/Contents/edit.php`
  - `.kind-test` 内に「カスタム受験を許可」「練習の制限時間の上限」を追加
  - `unlockField('allow_custom_test')` / `unlockField('practice_timelimit_max')` を追加
  - 注意書き（正解・解説が表示される）を表示
- `Admin/ContentsQuestions/edit.php`
  - `category` 入力。`datalist` に同一コンテンツの既存カテゴリを表示
  - コントローラの `edit()` で既存カテゴリ一覧を取得し、ビューへ渡す
- `Admin/ContentsQuestions/index.php`
  - 「カテゴリ」列を追加（未設定は「未分類」）

### 6.6 MCP / REST API

- REST API: 変更なし（`allow_custom_test` 等は管理画面のみ。API の更新対象外）
- MCP:
  - `CreateTestQuestionTool` / `UpdateTestQuestionTool` に任意の `category` を追加
  - `CopyContentTool` で `category` も複製
  - コンテンツ設定（`allow_custom_test` / `practice_timelimit_max`）の MCP 対応は
    v1 では行わない（必要になったら追加）

### 6.7 設定・ルーティング

- 追加の `config` 項目はなし（コンテンツ単位の許可で制御）
- `config/routes.php` に §4.10 の 4 ルートを追加

## 7. テスト計画

### 7.1 PHPUnit（`Dev/tests/TestCase/Controller/ContentsQuestionsPracticeTest.php`）

| テスト | 検証内容 |
|---|---|
| `testPracticeRequiresAllowCustomTest` | `allow_custom_test = 0` は設定画面・開始とも 404 |
| `testPracticeRequiresCourseRight` | 未受講ユーザは 404 |
| `testPracticeRequiresTestContent` | テスト以外のコンテンツは 404 |
| `testPracticeSettingsPageShowsCategories` | カテゴリと問題数が表示される |
| `testPracticeStartStoresState` | 有効な設定で開始すると状態が保存され、受験画面へリダイレクト |
| `testPracticeStartRejectsInvalidCount` | 負数・範囲外の出題数はエラー |
| `testPracticeStartRejectsInvalidTimelimit` | 上限超過・非数値はエラー |
| `testPracticeStartRejectsUnknownCategory` | 他コンテンツ・存在しないカテゴリはエラー |
| `testPracticeStartRejectsInvalidOrderAndWrongMode` | 列挙値以外はエラー |
| `testPracticeRunUsesStoredQuestionList` | 保存した出題リスト・順序で表示される |
| `testPracticeRunRedirectsWithoutState` | 状態がない場合は設定画面へ |
| `testPracticeRunExpiresStaleState` | 24 時間を超えた状態は破棄される |
| `testPracticeStateIsUserScoped` | 別ユーザの状態・結果を参照できない（キーにユーザ ID を含む） |
| `testPracticeGradeSavesNoRecord` | 採点後も `ib_records` / `ib_records_questions` が増えない |
| `testPracticeGradeRejectsTamperedQuestionList` | 状態と異なる出題リストでは採点しない |
| `testPracticeGradeComputesScore` | 単一・複数選択の得点と正解数が正しい |
| `testPracticeResultShowsScoreAndExplanations` | 結果画面に得点・正解数・解説が表示される |
| `testPracticeResultRedirectsWithoutResult` | 結果がない場合は設定画面へ |
| `testPracticeSettingsPrefillsPreviousResult` | 直前の結果の設定が設定画面に引き継がれる |
| `testOfficialTestStillSavesRecord` | 正式受験の記録・合否が従来どおり（回帰） |

### 7.2 GUI 操作テスト（Playwright・必須）

`Dev/scripts/gui-test-practice.py`（13 チェック）

1. REST API で受講登録（未登録の場合のみ。終了時に解除）
2. 管理画面で対象テストの「カスタム受験を許可」を ON、問題にカテゴリを設定
3. 受講者画面のコンテンツ一覧に「練習」リンクが出ること
4. 設定画面でカテゴリ・出題数・制限時間・出題順・表示を変更して開始
5. 指定した問題数・カテゴリ・順序で出題されること
6. 全問回答 → 採点 → 結果画面に得点・正解数・解説が出ること
7. **`ib_records` が増えていないこと**（REST API の履歴件数で確認）
8. コンテンツ一覧の学習回数・理解度が変化しないこと
9. 「もう一度練習」で同じ設定の設定画面に戻ること
10. 設定を変えて再実行すると、異なる出題数で開始できること
11. 正式受験は従来どおり記録されること（回帰）
12. JS コンソールエラーが無いこと
13. 後始末（カテゴリ・許可フラグを元に戻す）

### 7.3 手動確認

- 管理者が許可していないテストでは練習リンクが出ない・URL 直叩きで 404
- 制限時間の上限を設定した場合、設定画面に上限超えの選択肢が出ない
- 制限時間 0（なし）でタイマーが経過時間表示になる
- カテゴリ未設定のコンテンツでは出題範囲の UI が出ない
- セッションを削除して受験画面を開くと設定画面へ戻る（正式受験に影響しない）

### 7.4 回帰確認

- `ContentsQuestionsControllerTest`（既存 15 テスト）がすべて通る
- GUI テスト `gui-test-resume.py` / `gui-test-question-count.py` がすべて通る
- 正式受験の結果画面・管理画面の履歴・CSV が変化しない
- MCP のカテゴリ入出力（`smoke-mcp.sh` またはツール単体テスト）
- phpstan level 8 / phpcs がエラーなし

### 7.5 検証結果（2026-10-21）

| 検証 | 結果 |
|---|---|
| PHPUnit 全件（`bash Dev/scripts/test-fresh.sh`） | **OK（990 tests / 5015 assertions）** |
| PHPStan 全件（level 8） | **エラーなし** |
| phpcs（変更ファイル） | **エラーなし**（既存の長行 warning のみ） |
| GUI 操作テスト（`gui-test-practice.py`） | **13/13 PASS** |
| 正式受験の回帰（GUI） | 記録が 1 件増えることを確認（record_id 79） |
| 管理画面の許可・上限・カテゴリ | GUI で設定・復元を確認 |
| MCP のカテゴリ作成・更新・複製 | `CreateTestQuestionToolTest` / `UpdateTestQuestionToolTest` / `CopyContentToolTest` で確認 |

手動確認の結果:

- `allow_custom_test = 0`: コンテンツ一覧に練習リンクが出ない。URL 直叩きは 404。
- `practice_timelimit_max = 10`: 設定画面の選択肢は 1〜10 分（0 なしは非表示）。
- 上限なし: 選択肢は 0（なし）〜100 分。0 を選ぶと受験画面のタイマーが「経過 : HH:MM:SS」になる。
- 全問題が未分類: 出題範囲 UI は非表示。出題数の上限は全問（60）になる。
- 新しいセッション（別ブラウザコンテキスト）で `practice-run` を開く: 設定画面へリダイレクト。
- 管理画面の問題編集でカテゴリを保存できる。

GUI テストで発見・修正した事項:

1. 問題編集フォーム内に Summernote が挿入する `name="files"` の file input が
   FormProtection の「想定外フィールド」となり、保存がログイン画面へ戻されていた。
   `Form->unlockField('files')` を追加。
2. `Form->control('category', ['list' => ...])` が `list` を select の options と解釈し、
   `<select>` を描画していた。`type => 'text'` を明示。
3. カテゴリ UI が無い（全問題が未分類の）コンテンツで、JS が出題数の `max` を 0 にし、
   フォーム送信できなかった。全問数を `PRACTICE_ALL_COUNT` として渡し、UI 無し／全解除時は
   全問を上限にするよう修正。
4. 制限時間の選択肢が `%d分` と表示されていた。`sprintf(__('%d分'), $minutes)` に修正。
5. 既存 DB ではマイグレーション後にモデルキャッシュ（`tmp/cache/models`）が古く、
   カテゴリが `patchEntity` で無視された。キャッシュ削除で解消（コード変更なし）。
   本番適用時はマイグレーション後にキャッシュをクリアする。


## 8. リスクと対策

| リスク | 対策 |
|---|---|
| 練習で正解・解説が見えるため、認定試験の内容が漏れる | コンテンツ単位の**オプトイン**（`allow_custom_test`）。管理画面に注意書きを表示。認定試験では許可しない運用 |
| 練習の利用状況が管理者に分からない | 記録しない方針（ユーザ選択）。必要になったら軽量な集計テーブルを追加（§10） |
| 練習中のセッション切れで回答が失われる | 練習は記録対象外のため許容。再読込は `localStorage` で復元。セッション切れ時は設定画面からやり直す |
| 制限時間のクライアント改ざん | 正式受験と同じ（元々クライアント制御）。練習は記録しないため影響なし |
| カテゴリ名の表記ゆれ | 管理画面の `datalist` と一覧表示で運用。将来はマスタテーブルへ移行 |
| 大量の問題数によるセッション肥大 | 出題リストは問題 ID の配列（1,000 問でも数 KB）。実用上問題なし |
| 複数タブで同時に練習 | 同一コンテンツの状態は最後の開始で上書き。必要ならタブごとの試行 ID を将来導入 |
| 練習設定の改ざん（出題数・範囲） | サーバ側で範囲・件数・実在を検証。採点はセッション状態と完全一致を要求 |
| 練習中に問題が削除・変更される | 採点は残存する問題のみで行う（正式受験と同じ）。状態の出題リストは開始時のまま |
| 管理者が練習を確認できない（未受講の場合） | 正式受験と同じ制約。確認用の受講登録を行う。必要なら管理者プレビューを将来追加 |

## 9. 運用・移行

1. DB バックアップを取得する
2. `bin/cake migrations migrate` を実行する
3. モデルキャッシュ（`tmp/cache/models`）をクリアする（古いスキーマキャッシュがあると
   新しい `category` 列が `patchEntity` で無視される）
4. 管理画面で対象コンテンツの「カスタム受験を許可」を ON にする
5. 問題編集でカテゴリを設定する（未設定でも「未分類」として動作する）
6. 受講者画面で練習リンク・設定・採点・結果を確認する
7. ロールバックは `bin/cake migrations rollback`（追加列とインデックスの削除のみ。
   練習は履歴を書き込まないため、データ移行は不要）

## 10. 将来の改善案

- **設定プリセットの保存**: 受講者ごと・コンテンツごとの設定を DB に保存し、端末をまたいで再利用
- **練習履歴（軽量）**: `ib_practice_logs`（user_id, content_id, settings, score, created）で
  利用状況・正答率の推移を記録（`ib_records` の完了判定には使わない）
- **セッション非依存の練習**: 出題リストと設定を Cookie / 署名付き URL に退避し、
  セッション切れ後も同じ出題で再開
- **カテゴリのマスタ化**: `ib_question_categories`（コンテンツ単位）＋ `category_id`。
  並び順・リネーム・削除を安全にする
- **タグ（多対多）**: 複数タグ・横断出題
- **誤答のみ復習 / 間隔反復**: 直前の練習結果から誤答問題を優先出題
- **練習の REST API / MCP 公開**: 外部連携・自動化
- **管理者プレビュー**: 未受講でも管理者が練習画面を確認できるようにする

## 11. 決定事項（2026-10-20 ユーザ確認済み）

| 項目 | 決定 |
|---|---|
| 変更できる設定 | 出題数・制限時間・不正解時の表示・出題順・**出題範囲（カテゴリ）** |
| 練習の履歴 | **記録しない**（成績・学習回数・理解度・完了判定に影響させない） |
| 管理側の制御 | **コンテンツごとの許可＋制限時間の上限**（スキーマ変更あり） |
