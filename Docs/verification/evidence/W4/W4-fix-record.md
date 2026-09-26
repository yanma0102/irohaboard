# W4 修正記録（Fix Record）

> 対象: `Docs/verification/evidence/W4/W4-execution-record.md` および `W4-cause-analysis.md` の D-27〜D-30
> 実施日: 2026-09-26 / 対象アプリ: irohaboard（CakePHP 5.4.2 / PHP 8.4.25 / MariaDB 11.4.13）
> 前提: 記録規約に従い、**既存記録は上書きせず**本ファイルを追加する。

---

## 1. 修正サマリ

| ID | 重大度 | 問題 | 修正方針 | 状態 |
|----|--------|------|----------|------|
| D-27 | S2 / P0 | テスト結果表示ページが 500（`TypeError: string - int`） | 正解番号リストを型安全にフィルタし、添字を範囲チェック | ✅ 修正済 |
| D-28 | S2 | 非公開のお知らせが受講者画面で閲覧可能 | `getInfoIdList()` に公開状態フィルタを追加 | ✅ 修正済 |
| D-29 | S2 / P0 | 学習記録保存が FormProtection で 302 拒否 | `RecordsController` に `unlockActions(['add'])` | ✅ 修正済 |
| D-30 | S3 | アンケート回答の詳細が `ib_records_questions` に未保存 | `score` を保存データに追加し `save()` 戻り値を検査 | ✅ 修正済 |

**副次的な修正（別 causa）**

| 項目 | 内容 | 状態 |
|------|------|------|
| `getExplain()` のグローバル関数 | 同一 PHP プロセスでの複数回レンダリングで fatal／条件付き宣言だと undefined function | ✅ クロージャ化 |
| Deprecation 5 件 | `Query::order()`／`str_replace`・`explode` への NULL 引き渡し | ✅ 2 件へ削減 |
| 既存テスト 8 件 | D-28 の新セマンティクスで公開情報を装载osseしていた | ✅ フィクスチャを新セマンティクスへ追従 |

---

## 2. 各修正の詳細

### D-27: テスト結果表示ページの 500

**根本原因**（`W4-cause-analysis.md` §D-27）
`templates/ContentsQuestions/index.php:164` で `explode(',', $question['correct'])` の結果をループし、`$option_list[$correct_no - 1]` を参照していた。記述式設問は正解番号を持たないため `correct` が空文字で、要素は `['']` となる。`'' - 1` は PHP 8 で `TypeError` になり、結果ページが 500 になっていた。

**修正**（`templates/ContentsQuestions/index.php`）

1. 結果表示ループで `array_filter` と `ctype_digit` により**数値として有効な正解番号のみ**をループ対象にする
2. `$option_list` への添字アクセスを `isset()` で範囲チェック
3. `$idx = (int)$correct_no - 1` として整数化してから参照
4. 記述式設問では正解表示を空のまま出力（`$correct_label` は空）

**補正（追加対応）**
`explode()` 自体の引数も非 NULL 前提だったため、文字列へ正規化した。

```php
// 選択肢（options）と正解（correct）は未入力の設問では NULL になり得るため、
// explode() に渡す前に文字列へ正規化する
$option_list  = explode('|', (string)$question['options']);   // 選択肢リスト
$correct_list = explode(',', (string)$question['correct']);   // 正解リスト
```

**追加テスト**: `tests/TestCase/Controller/ContentsQuestionsResultViewTest.php`（6 メソッド）
記述式のみ／単一正解／複数正解／3種混在／記述式の不正解／選択式の不正解 の6ケース。

---

### D-28: 非公開のお知らせが受講者画面で閲覧可能

**根本原因**（`W4-cause-analysis.md` §D-28）
`src/Model/Table/InfosTable.php` の `getInfoIdList()` が可視性をグループ権限のみで判定し、スキーマに存在する `opened`（公開日時）／`closed`（閉鎖日時）カラムによるフィルタが一切なかった。そのため `opened IS NULL`（下書き・非公開）のお知らせも受講者に露出していた。

**修正**（`src/Model/Table/InfosTable.php:140-194`）
グループ条件（OR）へ公開状態条件（AND）を追加した。

```sql
Infos.opened IS NOT NULL          -- 下書き（非公開）を除外
AND Infos.opened <= :now          -- 未来日時の公開予定を除外
AND (Infos.closed IS NULL OR Infos.closed > :now)  -- 閉鎖済みを除外
```

- 現在時刻は `date('Y-m-d H:i:s')` で取得（`src/Model/Table/UserTokensTable.php` と同じ既存パターンに統一）
- グループ条件（`IbInfosGroups.group_id IS NULL` または `IN 所属グループ`）は**変更なし**。公開状態条件は OR 条件と AND で結合のみ

**セマンティクスの根拠**

| カラム | NULL | 値あり | 根拠 |
|--------|------|--------|------|
| `opened` | 非公開（下書き） | 公開日時 | `config/Seeds/Ds5InfosSettingsSeed.php:34` に `// info 3: 非公開 (opened = null, closed = null)` と明記 |
| `closed` | 期限なし | 閉鎖日時 | `config/schema/app.sql:109` の `closed datetime DEFAULT NULL` |

**既存テストの追従（意図は弱めていない）**
D-28 の実装により、`opened` を設定せず作成していた既存テストのフィクスチャが「下書き」として除外され 5 件が失敗した。`saveInfo()` / `createInfo()` ヘルパーに `'opened' => date('Y-m-d H:i:s')` を追加し、**アサーションは一切変更していない**（検証ロジックは不変、投入データのみ公開状態に揃えた）。

**追加テスト**: `tests/TestCase/Model/Table/InfosVisibilityTest.php`（16 メソッド）
下書き除外（`getInfos` / `getInfoOption` / `hasRight`）、過去公開・未来公開・閉鎖済み・グループ限定の組合せ、フロント側フィルタの一貫性、複数件一括、`limit` との組合せ。

---

### D-29: 学習記録保存が FormProtection で 302 拒否

**根本原因**（`W4-cause-analysis.md` §D-29）
`src/Controller/RecordsController.php` が `FormProtection->unlockActions()` を呼んでいなかった。`webroot/js/contents_view.js:50-86` は動的フォームを `document.createElement` で組み立て、`_csrfToken` のみを送信する（`_Token[fields]` / `_Token[unlocked]` / `_Token[debug]` は送信しない）。FormProtector のフィールド集合が恒常的に不一致となり `blackHole()` → 302 で `/users/login` へ転送されていた。

**修正**（`src/Controller/RecordsController.php:29-38`）

```php
public function initialize(): void
{
    parent::initialize();
    $this->FormProtection->unlockActions(['add']);
}
```

**採用理由**
既存の `Admin/ContentsController::upload` と同じパターン（案 A）を採った。案 B（JS から `_Token` フィールドも送る）は複雑かつ脆弱で、実質的なセキュリティ向上がない。

**CSRF 防御が弱まらないことの確認**
`CsrfProtectionMiddleware`（`src/Application.php:124-142`）はミドルウェアレイヤーで独立に動作し、`/records/add/*` はスキップ対象外（スキップするのは `/api/*`, `/mcp`, `/users/login`, `/users/logout` のみ）。したがって `add` でも POST には有効な `_csrfToken` が必須であり、FormProtection の解除は CSRF 防御に影響しない。この前提はテストで担保している（トークンなし／不正トークンで 403 かつレコード未作成）。

**追加テスト**: `tests/TestCase/Controller/RecordsSaveTest.php`（8 メソッド）
正常系（リダイレクト先・各フィールド値・`is_complete=0`）、CSRF 拒否 2 ケース、存在しないコンテンツ 404、未受講ユーザ 404、GET 405。

**テストの期待値修正（別 causa）**
CSRF 拒否の実ステータスは 400 ではなく **403**。`vendor/cakephp/cakephp/src/Http/Exception/InvalidCsrfTokenException.php:27` の `protected int $_defaultCode = 403;` が根拠。修正前の 400 期待は誤りだった。**拒否であることは 3 点で担保**している（403 であること／login リダイレクトでないこと／`ib_records` にレコードが 0 件であること）。

---

### D-30: アンケート回答の詳細が保存されない

**根本原因**（`W4-cause-analysis.md` §D-30）
`src/Controller/EnquetesQuestionsController.php` が `RecordsQuestions` エンティティを組み立てる際に `score` フィールドを含めていなかった。`RecordsQuestionsTable` のバリデーション `->integer('score')->requirePresence('score', 'create')`（`src/Model/Table/RecordsQuestionsTable.php:69-72`）が失敗して `save()` が false を返していたが、**戻り値が検査されていなかった**ため、コントローラは正常終了し「回答内容を送信しました」と表示していた。結果 `ib_records` だけが作成され `ib_records_questions` は 0 件になっていた。

**修正**（`src/Controller/EnquetesQuestionsController.php`）
- `:102` に `$score = $cq->score;` を追加
- `:108` で `'score' => $score` を `$details[]` に追加（`ContentsQuestionsController.php:141,160` の同一パターンを踏襲。`score` の妥当性は `ContentsQuestionsTable.php:74-77` の `range(-1, 101)` を満たす）
- `:128-145` で `save()` の戻り値を `$savedAll` フラグで検査し、`false` の場合は `Flash::error('アンケート回答の保存に失敗しました')` ＋ `log()` ＋ 入力画面へリダイレクト＋ `return`（成功メッセージは出さない）

**副次的な効果**
静かに失敗していた状態（`ib_records` だけ作成され詳細だけ欠落）が解消され、失敗時は必ず利用者に通知される。

**追加テスト**: `tests/TestCase/Controller/EnquetesQuestionsRecordSaveTest.php`（5 メソッド）
`ib_records` と `ib_records_questions` の両方に保存されること、保存行数が設問数と一致すること、各フィールド値、サクセスメッセージ、送信後の結果ページ表示。

---

## 3. `getExplain()` のグローバル関数廃止（副次修正）

本フェーズで別途顕在化した問題と、その修正経緯を記録する。

### 顕在化した2つの問題

1. **`Cannot redeclare function getExplain()` で fatal**
   `templates/ContentsQuestions/index.php` が同一 PHP プロセス内で複数回レンダリングされると（テストで連続リクエストする場合等）、テンプレート内のグローバル関数宣言が衝突する。W4 の新規テストがこれを顕在化させた。

2. **`function_exists()` ガードが逆効果**
   最初に `if (!function_exists('getExplain')) { function getExplain(...) {...} }` とガードしたが、**条件付き関数宣言は実行時評価される**ため、呼び出し位置（205／218／221 行）より後にある宣言に到達する前に呼ばれ、`Call to undefined function getExplain()` → 500 となった。ライブ実測で `GET /contents-questions/record/7/6 → 500` として検出した。

### 根本対応: クロージャ化

グローバル関数を廃止し、テンプレートの先頭にクロージャとして定義する。呼び出し3箇所は `$getExplain($question['explain'])` に変更した（旧関数宣言ブロックは削除）。

- 同一プロセスで複数回レンダリングされても、**変数の再代入は安全**（関数宣言の衝突が発生しない）
- クロージャは先頭で定義されるため、**宣言位置より前の呼び出しでも動作する**
- 結果として PHP の global 関数名前空間への汚染も解消

---

## 4. 検証結果

### 4.1 全スイート

```
$ bash scripts/test-fresh.sh
Tests: 726, Assertions: 3622, Deprecations: 2, PHPUnit Notices: 8, Time: 04:01
Errors: 0, Failures: 0
```

| ウェーブ | Tests | Assertions | Errors | Failures | Deprecations |
|----------|-------|------------|--------|----------|--------------|
| W1 | 645 | 3173 | 0 | 0 | 2 |
| W2 | 675 | 3324 | 0 | 0 | 2 |
| W3 | 690 | 3356 | 0 | 0 | 2 |
| **W4** | **726** | **3622** | **0** | **0** | **2** |

**Deprecations 2 件の内訳**（いずれも W4 以前からの既存項目で、本フェーズで増やしていない）

| 場所 | 内容 |
|------|------|
| `tests/TestCase/Controller/Admin/ContentsControllerTest.php:529` | `Query::order()`（→ `orderBy()`） |
| `vendor/cakephp/cakephp/src/Event/EventManager.php:316` 経由 | `AppController::beforeFilter()` のイベントリスナー戻り値 |

**本フェーズで削減した Deprecations（5 → 2）**

| 場所 | 内容 | 対応 |
|------|------|------|
| `tests/TestCase/Controller/EnquetesQuestionsRecordSaveTest.php:298` | `Query::order()` | `orderBy()` へ変更 |
| `templates/ContentsQuestions/index.php:250`（旧 `getExplain` 内） | `str_replace()` に NULL 引き渡し | `(string)` キャスト |
| `templates/ContentsQuestions/index.php:103` | `explode()` に NULL 引き渡し | `(string)` キャスト |

### 4.2 ライブ実測（稼働中アプリ・本番 DB `irohaboard`）

前提: root で実行した phpunit が root 所有の `tmp/cache/*` を残すと Web（www-data）が壊れるため、ライブ実測前にホスト／コンテナ両方でキャッシュを削除した。CSRF トークンは base64 で `+` を含むため `curl --data-urlencode` を使用する。

#### D-27: テスト結果表示ページ

| 検証 | 修正前 | 修正後 |
|------|--------|--------|
| `GET /contents-questions/record/7/6` | **500**（`Call to undefined function getExplain()`） | **200** |
| `GET /contents-questions/record/8/7` | — | **200** |

#### D-28: 非公開のお知らせ

DB 状態（`SELECT id, opened, closed FROM ib_infos`）

| id | opened | closed | 意味 |
|----|--------|--------|------|
| 1 | 2026-09-25 22:28:52 | NULL | 公開 |
| 2 | 2026-09-25 22:28:52 | NULL | 公開 |
| 3 | **NULL** | NULL | **非公開** |

`user1` / `password` でログイン（`GET /users/login` → `_csrfToken` 抽出 → POST、302）したうえでの実測。

| 検証 | 修正前 | 修正後 |
|------|--------|--------|
| `GET /infos` に含まれる `view` リンク | 1, 2, **3** を含む | **1, 2 のみ** |
| `GET /infos/view/3`（非公開） | 200（閲覧可） | **404** |
| `GET /infos/view/1` / `view/2`（公開） | 200 | 200 |

#### D-29: 学習記録保存

`POST /records/add/7`（`_csrfToken` あり、`understanding=3` `study_sec=60` `is_complete=1`）

| 検証 | 修正前 | 修正後 |
|------|--------|--------|
| ステータス | 302 | 302 |
| 遷移先 | **`/users/login`**（blackHole による強制ログイン） | **`/contents/index/1`**（正常なリダイレクト） |
| `ib_records` の生成 | 0 件 | **1 件** |

生成されたレコード（`SELECT ... ORDER BY id DESC LIMIT 1`）

| id | user_id | course_id | content_id | study_sec | is_complete | is_passed |
|----|---------|-----------|------------|-----------|-------------|------------|
| 12 | 5 | 1 | 7 | 60 | 1 | -1 |

（`is_passed = -1` は未採点。`ContentsQuestionsController` と同じ既存セマンティクス）

#### D-30: アンケート回答の保存

`GET /enquetes-questions/index/8` で設問を確認（id=4: 選択式、`answer_4`）／id=5: 記述式、`answer_5` の textarea）。`answer_4=1`、`answer_5=，回答テスト本文です` を送信。

| 検証 | 修正前 | 修正后 |
|------|--------|--------|
| ステータス／遷移 | 302（成功メッセージのみ） | 302 → `/enquetes-questions/record/8/13` |
| `ib_records_questions` の件数 | 3 件のまま（**0 件を追加**） | **5 件**（+2） |
| 保存内容 | — | `record_id=13, question_id=4, answer=1, is_correct=-1, score=0`<br>`record_id=13, question_id=5, answer=，回答テスト本文です, is_correct=-1, score=0` |
| 結果ページ | — | `GET /enquetes-questions/record/8/13` → **200**、本文を表示 |

---

## 5. 変更ファイル一覧

### 製品コード

| ファイル | 不備 | 変更内容 |
|----------|------|----------|
| `templates/ContentsQuestions/index.php` | D-27 | 正解リストの型安全フィルタ＋添字範囲チェック、`explode` の NULL キャスト、`getExplain` のクロージャ化 |
| `src/Model/Table/InfosTable.php` | D-28 | `getInfoIdList()` に `opened` / `closed` による公開状態フィルタを追加 |
| `src/Controller/RecordsController.php` | D-29 | `initialize()` を追加し `FormProtection->unlockActions(['add'])` |
| `src/Controller/EnquetesQuestionsController.php` | D-30 | `score` を保存データに追加、`save()` 戻り値の検査と失敗時のエラー処理 |

### テスト

| ファイル | 種別 | 内容 |
|----------|------|------|
| `tests/TestCase/Controller/ContentsQuestionsResultViewTest.php` | 新規 | D-27：記述式／単一正解／複数正解／混在／不正解の 6 ケース |
| `tests/TestCase/Model/Table/InfosVisibilityTest.php` | 新規 | D-28：公開状態フィルタの 16 ケース |
| `tests/TestCase/Controller/RecordsSaveTest.php` | 新規 | D-29：正常系・CSRF 拒否・404・405 の 8 ケース |
| `tests/TestCase/Controller/EnquetesQuestionsRecordSaveTest.php` | 新規 | D-30：保存の成否とフィールド値の 5 ケース |
| `tests/TestCase/Model/Table/InfosTableTest.php` | 修正 | `saveInfo()` ヘルパーに `opened` を追加（4 テストが対象） |
| `tests/TestCase/Controller/InfosControllerTest.php` | 修正 | `createInfo()` ヘルパーに `opened` を追加（1 テストが対象） |

### 記録

| ファイル | 種別 | 内容 |
|----------|------|------|
| `Docs/verification/evidence/W4/W4-fix-record.md` | 新規 | 本ファイル |
| `Docs/verification/evidence/README.md` | 編集 | W4 行に fix-record リンク追加、D-27〜D-30 を ✅ 修正済へ、未修正 P0/S2 一覧を更新 |

---

## 6. 残課題

1. **D-14**（S2）: テスト／アンケート画面のラジオ・チェックボックスに `<label>` がない（`templates/ContentsQuestions/index.php:124,134`、`templates/EnquetesQuestions/index.php:105`）。D-27 の修正で同テンプレートの結果表示は直したが、解答フォーム側の a11y 問題は未着手。
2. **D-15**（S2）: モーダルに `role="dialog"` / `aria-modal` / フォーカストラップがない（`templates/ContentsQuestions/index.php`、`templates/EnquetesQuestions/index.php`、`templates/Admin/Contents/edit.php` の 3 箇所）。
3. **Deprecations 2 件**: 既存項目。`Query::order()` は `orderBy()` へ、`AppController::beforeFilter()` の戻り値は `$event->setResult()` へ対応可能。
4. **phpcs 違反 997 件**（`vendor/bin/phpcbf` 導入済み・未実行）。
5. **Playwright / axe / k6 未導入**: ブラウザ E2E・アクセシビリティ実測・負荷試験は未実施。
6. **D-07** は HTTPS 環境での Cookie `Secure` 再計測が必要。
7. 本フェーズのライブ実測で作成したデータ（`ib_records` id=12、`ib_records` id=13 と `ib_records_questions` id=21,22）は**本番 DB に残置**している。検証で生成したテストデータは後始末するか、残置する場合は本記録で明記する規約に従い、本項に明記した。
