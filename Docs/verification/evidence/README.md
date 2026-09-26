# 検証実施記録（evidence）

`Docs/verification/` の検証計画を実際に実施した結果と一次証拠を、ウェーブ（W）ごとに記録する場所。
**アプリケーションの修整は行わない**（検査係立场）。実施者はこのディレクトリに記録のみを追加する。

---

## 1. ウェーブ一覧

| ウェーブ | 対象 | 記録 | 判定 | 発見不備 |
|----------|------|------|------|----------|
| W0 | 環境準備・スモーク・ユニットテスト | [W0-execution-record.md](W0/W0-execution-record.md) | ✅ 合格 | — |
| W1 | P0 セキュリティ／権限 | [W1-execution-record.md](W1/W1-execution-record.md)<br>[W1-cause-analysis.md](W1/W1-cause-analysis.md) | ❌ 不合格 | D-01〜D-07 |
| W2 | P0 API／MCP／データ整合 | [W2-execution-record.md](W2/W2-execution-record.md)<br>[W2-retest-record.md](W2/W2-retest-record.md)<br>[W2-cause-analysis.md](W2/W2-cause-analysis.md)<br>[W2-fix-record.md](W2/W2-fix-record.md) | ❌ 不合格（→ 修正済） | D-08〜D-12 |
| W3 | P0 運用／移行（install・update・復旧） | [W3-execution-record.md](W3/W3-execution-record.md)<br>[W3-cause-analysis.md](W3/W3-cause-analysis.md)<br>[W3-fix-record.md](W3/W3-fix-record.md) | ❌ 不合格（→ 修正済） | D-23〜D-26<br>＋D-34／D-35（新規発見） |
| W4 | 核心ジャーニー探索 | [W4-execution-record.md](W4/W4-execution-record.md)<br>[W4-cause-analysis.md](W4/W4-cause-analysis.md)<br>[W4-fix-record.md](W4/W4-fix-record.md) | ❌ 不合格（→ 修正済） | D-27〜D-30 |
| W5 | P1 機能／非機能（a11y・静的解析） | [W5-a11y-static.md](W5/W5-a11y-static.md)<br>[W5-cause-analysis.md](W5/W5-cause-analysis.md) | ⚠️ 一部可 | D-13〜D-22 |
| W6 | P2 網羅＋探索拡張＋負荷・並行 | [W6-execution-record.md](W6/W6-execution-record.md)<br>[W6-cause-analysis.md](W6/W6-cause-analysis.md) | ⚠️ 一部可 | D-31〜D-33 |
| W7 | 回帰＋復元＋報告 | [W7-execution-record.md](W7/W7-execution-record.md) | ⚠️ 一部可 | — |

> **訂正記録**: [\_orchestrator-corrections.md](_orchestrator-corrections.md)（W4/W6/W7 の主張に対する独立再実測と、W7 の「修正済み」判定の訂正）

---

## 2. 不備一覧（全ウェーブ・2026-09-26 時点）

| ID | 重大度 | 内容 | 発見 | 現状 |
|----|--------|------|------|------|
| D-01 | S2 / P0 | API 全体にレート制限が存在しない | W1 | ✅ 修正済（120/min） |
| D-02 | S2 / P0 | `file` 種別の許可拡張子が 0 件で常時拒否 | W1 | ⚠️ 部分（設定キー解決。実操作は D-12 により不可） |
| D-03 | S2 / P0 | demo_mode 判定が管理 4 画面で欠落 | W1 | ⚠️ 部分（**Records 未**） |
| D-04 | S3 | CSP／HSTS／Referrer-Policy／Permissions-Policy 未設定 | W1 | ✅ 修正済 |
| D-05 | S3 | セキュリティヘッダがアプリ層でなく Apache 層のみ | W1 | ✅ 修正済 |
| D-06 | S3 | Prelock がユーザー名単位＝ロックアウト DoS | W1 | ✅ 修正済（IP 併用） |
| D-07 | 要判定 | セッション Cookie の `Secure` 不在 | W1 | 要判定（HTTPS 環境が必要） |
| D-08 | S2 / P0 | `accessibleCourseIds()` に staff バイパスなし → Write が受講登録済み課程に限定 | W2 | ✅ 修正済（Write 側にも `isStaff()` バイパス） |
| D-09 | S2 / P0 | MCP ツールのエラーが `isError: false` のまま JSON テキストで返る | W2 | ✅ 修正済（`ToolCallException` 送出 → `isError: true`） |
| D-10 | S2 / P0 | ユーザー CSV インポートが機能しない（`getData('csvfile')` が `$_FILES` を読めない／テスト不在） | W2 | ✅ 修正済（`getUploadedFile` ＋ `import` の FormProtection 解除） |
| D-11 | S3 | CSV が `charset=UTF-8` 宣言だが実体 CP932 | W2 | ✅ 修正済（SJIS-WIN） |
| D-12 | S2 / P0 | `upload` が FormProtection で 302 拒否 → **file／movie アップロードが利用不可** | 再試験 | ✅ 修正済（`unlockActions` に `upload`） |
| D-13 | S3 | `<html lang>` 属性が全テンプレートで不在 | W5 | ❌ 未修正 |
| D-14 | S2 | テスト／アンケート画面のラジオ・チェックボックスに `<label>` なし | W5 | ❌ 未修正 |
| D-15 | S2 | モーダルに `role="dialog"`／`aria-modal`／フォーカストラップなし | W5 | ❌ 未修正 |
| D-16 | S3 | インストール画面の `<label for>` と input id が不一致 | W5 | ❌ 未修正 |
| D-17 | S3 | `error400.php` で URL がエスケープなしに出力 | W5 | ❌ 未修正 |
| D-18 | S3 | `date()` の直接使用 31 箇所 | W5 | ❌ 未修正 |
| D-19 | S4 | flash の `<div onclick>` がキーボード操作不可 | W5 | ❌ 未修正 |
| D-20 | S4 | 13 テーブル中 12 に `<caption>` なし | W5 | ❌ 未修正 |
| D-21 | S4 | 見出しレベルの飛び | W5 | ❌ 未修正 |
| D-22 | S4 | phpcs 違反 997 件 | W5 | ❌ 未修正 |
| D-23 | S2 | `InstallController` の DB 名検出が `Configure::consume` で常に既定へフォールバック | W3 | ✅ 修正済（`ConnectionManager::getConfig('default')`） |
| D-24 | S3 | `_executeSQLScript()` が 23000（Duplicate entry）を握り潰さない | W3 | ✅ 修正済（`UpdateController` と挙動統一） |
| D-25 | S4 | `App.fullBaseUrl` 未設定時に debug=false では全リクエストが 500 | W3 | ✅ 修正済（警告ログ＋通過。`APP_FULL_BASE_URL` 必須を README／compose に明記） |
| D-26 | S4 | D-23 により install バリデーションを動的検証できない | W3 | ✅ 解消（D-23 修正により到達可能。スクラッチDBで実証） |
| D-27 | S2 / P0 | テスト結果表示ページが 500（`TypeError: string - int`）。記述式設問を含むテストで結果が見られない | W4 | ✅ 修正済（型安全な添字参照＋範囲チェック） |
| D-28 | S2 | 非公開のお知らせが受講者画面で閲覧可能（`opened` フィルタ欠落） | W4 | ✅ 修正済（`opened`/`closed` フィルタ追加） |
| D-29 | S2 / P0 | 学習記録保存（`/records/add`）が FormProtection で拒否されレコードが生成されない | W4 | ✅ 修正済（`unlockActions(['add'])`。CSRF は別レイヤで担保） |
| D-30 | S3 | アンケート回答の詳細が `ib_records_questions` に保存されない | W4 | ✅ 修正済（`score` 追加＋`save()` 戻り値検査） |
| D-31 | S4 | API の一部が JSON ではなく HTML 404 を返す | W6 | ❌ 未修正（当初の 400／DebugKit 泄露は反転） |
| D-32 | S4 | NULL byte パスで Apache 既定の HTML 404 | W6 | ❌ 未修正 |
| D-33 | S4 | MCP の `OPTIONS` preflight が 401 を返し CORS ヘッダを伴わない | W6 | ❌ 未修正（当初の 403 は反転） |
| **D-34** | **S2 / P0** | `/install` のフォームに CSRF トークンが無く POST が 403（インストーラー使用不能） | W3 修正中 | ✅ 修正済（hidden input 追加） |
| **D-35** | **S2 / P0** | install のフォーム送信値が常に空でバリデーションが必ず失敗（インストール永久に完了しない） | W3 修正中 | ✅ 修正済（`getData('data.User')`） |

**未修正の P0 / S2**: D-02（実質）、D-03（Records）、**D-14 / D-15** の 4 件。

> D-34 / D-35 は W3 の修正（D-23）で「インストール済み」判定の誤りが外れたことで初めて到達可能になった installer の実経路に、更なる独立原因として顕在化した。**修正前は D-23 が installer 全体を無効化していたため不可視だった。** 詳細は [W3-fix-record.md](W3/W3-fix-record.md) §1。

---

## 3. 記録規約

- ファイル名は `<ウェーブ>-execution-record.md`（実施記録）、`<ウェーブ>-cause-analysis.md`（原因解析、任意）。
- 各記録の冒頭に結果サマリ（ID / 検証項目 / 判定 / 根拠）を表形式で置く。
- 判定は `✅ 適正` / `⚠️ 一部可` / `❌ 不合格` / `N/A（再現せず）` を用いる。
- **実測値（HTTP 応答ヘッダ、CSV 実体、SQL 集計値、DB のレコード数）を必ず一次証拠として残す**。推定と実測を区別する。
- 実施で生成したテストデータは**後始末して残さない**（残す場合は記録に明記）。
- 既存記録を上書き・削除しない（上書きで証拠が失われることを避ける）。

---

## 4. 実施済みウェーブの教訓

- **自動テストが緑でも契約適合を意味しない**（W2: `Api/` 191 件が緑でも staff の教材 Write は 403、CSV インポートは反映 0 件）。
- **実測の記録を保存する**（W1: 記録が上書きされ IDOR 実測が失われた事例あり。一次証拠を取り直して再記載済み）。
- **静的推論と実挙動の乖離を疑う**（W1: ルート `.htaccess` の実効性、`session_regenerate` 不在でもセッション ID は Framework 依存で再生成される 等）。
- **「遮蔽されているバグ」を遮蔽の修正後に必ず再検証する**（W3: D-23 の修正で installer に到達できるようになったところ、CSRF トークン欠落（D-34）と送信値読み出しパスの誤り（D-35）が preexisting の 2 つの P0 として顕在化。1 つの障害が複数原因を隠していた）。
- **自分の修正が新たな 500 を作ったら、原因を Frames の知識で判断せず実測で特定する**（W4: `function_exists()` ガードで `Cannot redeclare` を回避しようとしたところ、条件付き関数宣言は実行時評価されるため、呼び出し位置より前の定義で `Call to undefined function` に変わり、ライブ実測で 500 として検出した。静的推論では「ガードしたから安全」と誤判断していた）。
- **テストが緑でも、そのテストが通っていない画面は必ず実測する**（W4: スイートは 726 件すべて緑だったが、632 行目の結果ページは 500 のままであった。複数レンダリング涉及するテンプレートは、JSP 的なホイスティング前提が成り立たない）。
- **`save()` 等の戻り値を検査していないコードは「成功したふり」をする**（W4: D-30 は `save()` が false を返していてもコントローラは正常終了し、ユーザには成功メッセージが出ていた。保存処理には必ず戻り値の検査と失敗時の明示的な通知を入れる）。
