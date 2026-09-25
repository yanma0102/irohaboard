# W1 実施記録（P0 セキュリティ/権限 — 自動化可能項目）

| 項目 | 内容 |
|---|---|
| 実施日 | 2026-09-25 |
| 対象 | 稼働中アプリ `http://localhost:8082`（プロダクションDBは Seed 済） |
| 参照 | `06-risk-coverage-automation.md` §4 W1、`04-items-api-security-nfr.md`（VR-SEC） |

## 1. 結果サマリ

| # | 検証項目 | 手順（要約） | 結果 | 判定 |
|---|---|---|---|---|
| W1-1 | VR-SEC-006 / 019 / 021 | 応答ヘッダ確認（`/users/login`, `/mcp`） | `X-Content-Type-Options: nosniff`、`X-Frame-Options: SAMEORIGIN`、`Set-Cookie: AppSession=...; HttpOnly; SameSite=Lax`。CSP / HSTS / Referrer-Policy は不在 | ✅ 一部合格（計画の「皆無」想定は誤り、実測に更新済） |
| W1-2 | VR-SEC-009（IDOR/API） | user1 トークンで他ユーザー参照 | `GET /users/1`(admin)=**403** / `GET /users/5`(self)=**200** / 未認証=**401** / admin=**200** | ✅ OK |
| W1-3 | VR-API-053（records スコープ） | user1 が `GET /records?user_id=1` | **200** で `data:[]`（他ユーザーのデータは返らず、フィルタは無視） | ✅ 漏洩なし（観察記録） |
| W1-4 | VR-API 権限マトリクス | `vendor/bin/phpunit --filter ApiContractTest`（46 テスト） | 全緑（`ApiContractTest.php`） | ✅ OK |

### 実測ヘッダ（証跡）

```
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Set-Cookie: AppSession=<id>; path=/; HttpOnly; SameSite=Lax
```
- `/mcp` でも `X-Content-Type-Options` / `X-Frame-Options` を確認。
- 未確認/不在: `Content-Security-Policy`, `Strict-Transport-Security`, `Referrer-Policy`。

## 2. 計画への反映（実施済み）

- `04-items-api-security-nfr.md` の `VR-SEC-006` / `VR-SEC-019` / `VR-SEC-021` を実測に合わせて更新（「ヘッダ皆無」→「X-Frame-Options: SAMEORIGIN・X-Content-Type-Options: nosniff あり、CSP/HSTS/Referrer-Policy は要判定」）。
- MCP 契約の追記: `IrohaAuthMiddleware` は **initialize を含む全 `/mcp` リクエストに Bearer 認証を要求**（認証付き initialize は 200）。

## 3. 未実施（手動・探索が必要）

W1 の以下は非機械的/環境依存のため未実施（`05-e2e-exploratory.md` のチャーターで実施予定）:
- VR-SEC-001/002（XSS/SQLi の網羅的投入）、VR-SEC-004（パストラバーサル）、VR-SEC-005（アップロード実行）、VR-SEC-008（HTTP メソッド）、VR-SEC-010（権限昇格）、VR-SEC-011/012（情報露出）、VR-SEC-013（.htaccess 保護）、VR-SEC-014（CSV 数式）、VR-SEC-017（ユーザー列挙）。
- AB-01〜AB-15（攻撃者シナリオ）。
- VR-AUTH-022/043（セッション固定の実機確認）、VR-AUTH-021/044（ロックアウト DoS）。

## 4. 判定

- W1 の自動化可能な主要 P0（IDOR・API 権限・セキュリティヘッダ）は**不合格なし**。
- 残る W1 項目は手動/探索で継続。W1 全体の完了条件（`06` §5）は未達。
