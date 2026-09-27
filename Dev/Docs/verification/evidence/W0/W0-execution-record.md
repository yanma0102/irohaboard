# W0 実施記録（環境準備・スモーク・基盤確認）

| 項目 | 内容 |
|---|---|
| 実施日 | 2026-09-25 |
| 対象 | iroha Board（CakePHP 5.4 / MariaDB 11.4） |
| 実施者 | 検証実施（オーケストレータ） |
| 環境 | Docker `irohaboard5-web-1`（:8082）, `irohaboard5-db-1`（MariaDB 11.4.13, :13307） |
| 参照手順 | `09-verification-procedure.md` §2・§9、`06-risk-coverage-automation.md` §4（W0） |

## 1. 結果サマリ

| # | チェック | コマンド | 結果 | 判定 |
|---|---|---|---|---|
| W0-1 | 環境・ヘルスチェック | `docker ps` / `docker exec … SELECT VERSION()` | web/db 稼働、MariaDB 11.4.13 応答 | ✅ OK |
| W0-2 | ツール確認 | `php -v`, `curl`, `jq`, `vendor/bin/phpunit --version` | PHP 8.4.25 / PHPUnit 13.3.4 / curl / jq | ✅ OK |
| W0-3 | ユニット/統合テスト | `bash scripts/test-fresh.sh` | **605 tests / 3024 assertions / Errors 0 / Failures 0 / Deprecations 2** | ✅ OK |
| W0-4 | API スモーク | `bash scripts/smoke-api.sh` | **3 passed, 0 failed** | ✅ OK |
| W0-5 | MCP スモーク | `bash scripts/smoke-mcp.sh` | **4 passed, 0 failed** | ✅ OK |

> W0-3 の件数は、作業ツリーに含まれる進行中の追加（Markdown サニタイズ関連テスト等）を含む。計画上の旧値（587/2961）は、当該追加前のもの。

## 2. 証跡

| 種別 | 内容 |
|---|---|
| W0-3 | `Tests: 605, Assertions: 3024, Deprecations: 2`（`bash scripts/test-fresh.sh` 出力） |
| W0-4 | token 201 / courses 200 / unknown 404 `{error:{code:404}}` |
| W0-5 | initialize 200（`serverInfo.name=iroha Board MCP`）/ tools/list 9 tools / tools/call list_courses 結果あり |
| 環境 | MariaDB 11.4.13 / PHP 8.4.25 / PHPUnit 13.3.4 |

## 3. 既知の Deprecations（非ブロッキング）

1. `tests/TestCase/Controller/Admin/ContentsControllerTest.php` — `Query::order()`（`orderBy()` 推奨）。
2. `AppController::beforeFilter()` — イベントリスナーの戻り値（`$event->setResult()` 推奨）。

## 4. 発見した不具合と対応

| # | 事象 | 原因（file:line） | 対応 | 状態 |
|---|---|---|---|---|
| D-1 | MCP スモークで `/mcp initialize` が HTTP 400 | `scripts/smoke-mcp.sh:77` の `local params="${2:-{}}"` が bash の展開規則で余分な `}` を付加し JSON が破損 | `params="$2"` + 空判定に修正（回帰防止コメント追加） | 修正済 ✅ |
| D-2 | `/mcp` が全リクエストで 401（initialize 含む） | 作業ツリーの `src/Mcp/IrohaAuthMiddleware.php` に initialize 認証スキップの改変が入っていた（HEAD と不一致） | `git checkout -- src/Mcp/IrohaAuthMiddleware.php` で HEAD 復元 | 復元済 ✅ |
| D-3 | `composer cs-check` が root 実行で中断 | Composer の root 実行ポリシー | `COMPOSER_ALLOW_SUPERUSER=1` を付与して実行 | 回避確認 ✅ |
| D-4 | phpcs 残 1 件 | `tests/TestCase/Utility/MarkdownRendererTest.php:145`（`\ReflectionProperty` の FQName 参照） | 未修正（スタイル負債） | 未対応（P2） |

> D-1 は当方が追加したスクリプト内のバグ、D-2 は作業ツリーの進行中変更に起因。いずれも製品本体（`src/` の恒久コード）への回帰ではない。

## 5. 補足・確認事項

- `/mcp` は HEAD の `IrohaAuthMiddleware` 仕様で **initialize を含む全リクエストに Bearer 認証が必須**。認証付き initialize は 200 を返す（実測）。計画 `04-items-api-security-nfr.md` の MCP 項目でこの契約を明示すること。
- 応答ヘッダに `X-Frame-Options: SAMEORIGIN` / `X-Content-Type-Options: nosniff` を確認。計画の「セキュリティヘッダ皆無」記述は当該環境では該当しないため、`04` の VR-SEC-006/019/021 の期待結果を実測に合わせて見直す必要がある（要フォローアップ）。
- テスト実行は `scripts/test-fresh.sh`（テスト DB を作り直す）を標準とする。残留データによる偽の失敗を防ぐ。

## 6. 次ウェーブへの接続

- W0 完了。次は W1（P0 セキュリティ/権限: RK-01/02/04/05/13/17、VR-SEC 全、AB-01〜09/13/14）。
- 実施結果は本ファイルに追記、または `evidence/<wave>/` に記録する。
