# 検証実施記録（evidence）

`Docs/verification/` の検証計画を実際に実施した結果と一次証拠を、ウェーブ（W）ごとに記録する場所。
**アプリケーションの修整は行わない**（検査係立场）。実施者はこのディレクトリに記録のみを追加する。

---

## 1. ウェーブ一覧

| ウェーブ | 対象 | 記録 | 判定 | 発見不備 |
|----------|------|------|------|----------|
| W0 | 環境準備・スモーク・ユニットテスト | [W0-execution-record.md](W0/W0-execution-record.md) | ✅ 合格 | — |
| W1 | P0 セキュリティ／権限 | [W1-execution-record.md](W1/W1-execution-record.md)<br>[W1-cause-analysis.md](W1/W1-cause-analysis.md) | ❌ 不合格 | D-01〜D-07 |
| W2 | P0 API／MCP／データ整合 | [W2-execution-record.md](W2/W2-execution-record.md) | ❌ 不合格 | D-08〜D-11 |

---

## 2. 不備一覧（全ウェーブ）

| ID | 重大度 | 内容 | 発見ウェーブ |
|----|--------|------|--------------|
| D-01 | S2 / P0 | API 全体にレート制限が存在しない（ログイン試行のみ） | W1 |
| D-02 | S2 / P0 | `file` 種別のアップロードが常時拒否（設定キー名不一致） | W1 |
| D-03 | S2 / P0 | demo_mode 判定が管理 4 画面（Groups/ContentsQuestions/EnquetesQuestions/Records）で欠落 | W1 |
| D-04 | S3 | CSP / HSTS / Referrer-Policy / Permissions-Policy 未設定 | W1 |
| D-05 | S3 | セキュリティヘッダがアプリ層でなく Apache 層のみ | W1 |
| D-06 | S3 | ログインブロック（Prelock）がユーザー名単位＝ロックアウト DoS | W1 |
| D-07 | 要判定 | セッション Cookie の `Secure` 属性不在（HTTPS 環境での再計測が必要） | W1 |
| D-08 | S2 / P0 | `accessibleCourseIds()` に staff バイパスなし → API/MCP の教材 Write が受講登録済み課程に限定（読み取り系と非対称） | W2 |
| D-09 | S2 / P0 | MCP ツールのエラーが `isError: false` のまま JSON テキストで返る（契約逸脱） | W2 |
| D-10 | S2 / P0 | ユーザー CSV インポートが機能しない（`getData('csvfile')` が `$_FILES` を読めない／テストも不在） | W2 |
| D-11 | S3 | CSV レスポンスが `charset=UTF-8` 宣言だが実体 CP932 | W2 |

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
