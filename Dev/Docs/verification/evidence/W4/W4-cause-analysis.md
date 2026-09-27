# W4 検出項目 原因解析（Root Cause Analysis）

> 対象: `Docs/verification/evidence/W4/W4-execution-record.md` の D-27〜D-30
> 実施日: 2026-09-26 / 方法: 静的コード点検（file:line）＋ W4実測記録・訂正記録の参照
> 前提: コード修正は未実施。原因の特定と影響評価のみ。

---

## 総括

| ID | 事象 | 根本原因（要約） | 分類 | 重要度 |
|----|------|------------------|------|--------|
| D-27 | テスト結果表示ページ 500 (`TypeError: string - int`) | 記述式設問の `correct` が空文字 → `explode(',', '')` が `['']` → 配列要素 `'' - 1` で PHP 8 型エラー | 実装バグ | S2 / P0 |
| D-28 | 非公開のお知らせが受講者画面で閲覧可能 | `getInfoIdList()` に `opened`（公開日時）フィルタが存在しない | 設計欠落 | S2 |
| D-29 | 学習記録保存が FormProtection で拒否 | `RecordsController` に `FormProtection->unlockActions()` の呼び出しがなく、JS が `_Token` フィールドを送信しない設計と不整合 | 実装バグ | S2 / P0 |
| D-30 | アンケート回答詳細が `ib_records_questions` に未保存 | 保存データで `score` フィールドが欠落し、バリデーション失敗 → `save()` の戻り値未チェックで静的失敗 | 実装バグ | S3 |

---

## D-27: テスト結果表示ページが 500（`TypeError: string - int`）

### 根本原因

**テンプレート `templates/ContentsQuestions/index.php:164` で、記述式設問の `correct` フィールドが空文字の場合に型安全な減算が行われず、PHP 8.0+ の厳格型により TypeError が発生する。**

### 発生メカニズム（データフロー）

1. **DB スキーマ**: `ib_contents_questions.correct` は `varchar(200) NOT NULL DEFAULT ''`（`config/schema/app.sql`）。記述式設問では管理者が正解番号を入力しないため、`correct` は空文字 `''` で格納される。

2. **テンプレート:104** — `$correct_list = explode(',', $question['correct']);`:
   - 選択式設問（`correct='1,3'`）→ `['1', '3']` ✅
   - 記述式設問（`correct=''`）→ `['']`（要素 1 つの空文字列）⚠️

3. **テンプレート:164**（結果表示 `$is_record` ブロック内）:
   ```php
   $correct_label .= ($correct_label == '') ? $option_list[$correct_no - 1] : ', '.$option_list[$correct_no - 1];
   ```
   - `$correct_no` = `''`（文字列）
   - `$correct_no - 1` → PHP 8.0 で `TypeError: Unsupported operand types: string - int`

4. **テンプレート:164** が直接のエラー発生行（実測で grep 確認済み: `templates/ContentsQuestions/index.php:164`）。

### なぜ選択式設問では発生しないか

選択式（`correct='1'` または `'1,3'`）では `explode` の結果が数値文字列の配列となり、PHP 8 でも暗黙の型変換で `int - int` が成立する。問題は**記述式（text）設問のみ**。

### なぜコントローラ側ではクラッシュしないか

`ContentsQuestionsController` の採点ロジックでは `count($corrects)` が 1 のため `else` 分岐に入り、`==` 比較のみで減算がないためクラッシュしない（比較の型ヒントの違いによる、テンプレート側だけが壊れる非対称）。

### 影響

- 記述式設問を含むテストの「結果を見る」(`/contents-questions/record/{content_id}/{record_id}`) が 500 となり、**受講者が合格/不合格・得点・正解を一切確認できない**。
- W4 実測・訂正記録で再現確認済み。死にリンクが発生（一覧から結果への導線が断絶）。

### 分類: **実装バグ** — 空文字配列のガード欠落

---

## D-28: 非公開のお知らせが受講者画面で閲覧可能

### 根本原因

**`src/Model/Table/InfosTable.php` の `getInfoIdList()` メソッドが、お知らせの可視性をグループ権限のみで判定し、`opened`（公開日時）カラムによるフィルタリングが一切行われていない。**

### 発生メカニズム

1. **DB スキーマ**: `ib_infos.opened` は `datetime DEFAULT NULL`。管理者が公開日時を未設定（NULL）にすると、そのお知らせは「非公開」状態になる。

2. **Seed データの意図**（`config/Seeds/Ds5InfosSettingsSeed.php`）:
   - info 1・2: `opened = $now`（公開）
   - info 3: **`opened = null`**（非公開）← 明示的に「非公開」を表すデータ

3. **`getInfoIdList()` のクエリ**は `IbInfosGroups.group_id` の OR 条件のみで、**`opened` カラムの条件を含まない**。グループ制御のみでフィルタしているため `opened = NULL` の非公開お知らせも結果に含まれる。
   - **grep 実測: `src/Model/Table/InfosTable.php` に `opened` の出現は 0 件**（＝フィルタ実装が存在しないことの直接証拠）。

4. **影響範囲**: `getInfoIdList()` は `getInfos()`（ホーム）／`getInfoOption()`（`/infos` 一覧）／`hasRight()`（`/infos/view/{id}`）の 3 箇所から呼ばれる。ホームは `$limit` による件数制限で非公開が落ちる可能性があるが、`/infos` 一覧と `/infos/view/{id}` では非公開情報が漏洩する。

### 影響

- **情報漏洩**: 管理者が「下書き」状態で保存したお知らせが、全受講者に閲覧可能。
- W4 実測: `/infos` 一覧・個別閲覧で非公開お知らせの内容が表示される。

### 分類: **設計欠落** — 公開日時フィルタの実装がない

---

## D-29: 学習記録保存（`/records/add`）が FormProtection で拒否される

### 根本原因

**`src/Controller/RecordsController.php` が `FormProtection->unlockActions()` を呼んでおらず、JS が動的フォームで `_csrfToken` のみ送信する設計と不整合が生じ、CakePHP 5 の FormProtection が POST を blackHole で拒否する。**

### 発生メカニズム

1. **`unlockActions` の実装状況（grep 実測）**:
   - `src/Controller/ContentsQuestionsController.php:32` — `unlockActions(['index'])` ✅
   - `src/Controller/EnquetesQuestionsController.php:31` — `unlockActions(['index'])` ✅
   - その他多数のコントローラが `unlockActions` を呼んでいる
   - **`src/Controller/RecordsController.php` — 該当なし** ✗

2. **JS の送信設計**（`webroot/js/contents_view.js`）: 動的フォームを `createElement` で組み立て、ページ内の `input[name="_csrfToken"]` の値のみを複製して POST する。`_Token[fields]` / `_Token[unlocked]` / `_Token[debug]` は**一切送信しない**。

3. **FormProtection の動作**: 必須フィールドの不整合を検知 → blackHole → リダイレクト（`/users/login` へ 302）。**レコードは INSERT されない。**

4. **Orchestrator の指摘**（`_orchestrator-corrections.md`）: D-29 と D-12（`upload` の FormProtection 拒否）は**同一原因クラス**。JS が「描画されたページと異なる URL へ POST する」パターンは、FormProtection のトークン binding が発行元 URL に固定されるため恒久的に失敗する。

### 影響

- `label` / `html` / `markdown` / `movie` / `url` / `file` 種別で、学習終了時の「理解度」ボタン押下で**学習記録が一切保存されない**。
- `ib_records` にレコードが生成されない → 学習進捗が反映されない。
- W4 実測: `POST /records/add/… → 302 → /users/login`（blackHole）。既存レコードのみで新規追加なし。

### 分類: **実装バグ** — `unlockActions` 欠落 + JS とサーバの設計不整合

---

## D-30: アンケート回答の詳細が `ib_records_questions` に保存されない

### 根本原因

**アンケート保存処理で `RecordsQuestions` エンティティに `score` フィールドが含まれておらず、`RecordsQuestionsTable` のバリデーション（`requirePresence('score', 'create')`）により `save()` が失敗する。さらに `save()` の戻り値が未チェックのため、静的に失敗する。**

### 発生メカニズム

1. **アンケート回答の保存データ構築**（`EnquetesQuestionsController`）: `$details[]` に `question_id` / `answer` / `is_correct` のみを格納。**`score` は含まれていない**（grep 実測: 同コントローラに `'score'` の記述なし）。

2. **エンティティ保存**: `newEmptyEntity()` → `patchEntity($rq, $detail + ['record_id' => ...])` → `save($rq)`。**戻り値をチェックしていない。**

3. **バリデーション失敗**（実測）:
   - `src/Model/Table/RecordsQuestionsTable.php:71` — `->requirePresence('score', 'create')`
   - `score` が `patchEntity` に含まれていないため必須チェックが失敗 → `save()` が `false` を返す → **INSERT されない**。

4. **対照**: `ContentsQuestionsController` のテスト採点ロジックでは `score` と `correct` が正しく含まれるため保存が成功する（アンケート側だけ欠落）。

5. **`ib_records` は保存されるが `ib_records_questions` は保存されない**: `recordsTable->save()` は成功し（`score` 不要）、その後の `recordsQuestionsTable->save()` が失敗。戻り値未検査のためコントローラは正常に振る舞い、リダイレクトまで行われる。

### 影響

- アンケート送信は「回答を送信しました」と表示されるが、**回答詳細（各設問の回答値）が DB に記録されない**。
- アンケート結果の一覧・分析機能が動作しない。
- W4 実測: `ib_records` は作成されるが `ib_records_questions` は 0 件。

### 分類: **実装バグ** — 保存データからの `score` フィールド欠落 + `save()` 戻り値の未検査

---

## 付録: 共通する構造的問題

| 問題 | 該当 D-ID | 構造 |
|------|-----------|------|
| FormProtection と JS 動的フォームの不整合 | D-12, D-29 | `unlockActions` なし + JS が `_Token` フィールドを送信しない |
| `save()` 戻り値の未チェック | D-30 | エンティティ保存の結果を無視している |
| テンプレートでの型安全性の欠落 | D-27 | 空文字の `explode` 結果を数値として扱う |
| モデル層での可視性フィルタの欠落 | D-28 | `opened` カラムがスキーマに存在するがクエリで使用されない |

> 本解析は検査記録であり、コード修正は行っていない。
