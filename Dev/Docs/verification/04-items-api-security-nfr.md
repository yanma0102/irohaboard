# 検証項目: REST API・MCP・セキュリティ・入力検証・非機能・運用編

> 上位: `README.md` / 観点: `01-verification-perspective-matrix.md` / 製品: `02` / 管理: `03` / E2E: `05`
> 記法: `手順` は `<br>` 区切り。`機械`: 済=既存自動化 / 可=自動化可能 / 難=人間判断・探索。`結果/証跡` 列は実施時に記入。

---

## 1. REST API v1 — 認証（VR-API-001〜039）

対象: `Api/AuthController`、`Api/BaseController::authenticateApiRequest/respond/fail/paginatedList`。

| ID | 分類 | 観点 | 前提/データ | 手順 | 期待結果 | 優先 | 機械 | 既存 |
|---|---|---|---|---|---|---|---|---|
| VR-API-001 | 正常系 | I | DS-8 | 1. `POST /api/v1/auth/token` に正しい資格情報 | 201、Bearer トークン、契約どおりの JSON | P0 | 済 | API-001 |
| VR-API-002 | 正常系 | I/A | DS-8 | 1. `DELETE /api/v1/auth/token`（Bearer） | 200、トークン失効 | P0 | 済 | API-002 |
| VR-API-003 | 異常系 | E | DS-8 | 1. 誤資格情報でトークン発行 | 401、汎用エラー、ユーザー存在を漏らさない | P0 | 済 | — |
| VR-API-004 | 正常系 | A | DS-8 admin | 1. `permanent` 指定でトークン発行 | 9999-12-31 期限（admin のみ） | P1 | 可 | — |
| VR-API-005 | 権限 | Z | DS-8 user | 1. 一般ユーザーが permanent 発行 | 拒否 or 非永久 | P0 | 可 | — |
| VR-API-006 | 正常系 | A | DS-6 | 1. 旧 SHA1 ユーザーで `auth/token` | 成功（**自動昇格なし**を確認） | P1 | 済 | — |
| VR-API-007 | セキュリティ | S | DS-8 | 1. Bearer 無し/不正形式で保護 API | 401 JSON（HTML でない） | P0 | 済 | — |
| VR-API-008 | セキュリティ | S | DS-8 | 1. 期限切れ/失効済トークン | 401 | P0 | 済 | — |
| VR-API-009 | セキュリティ | I | DS-8 | 1. エラー形式が `{error:{code,message,...}}` で統一 | 統一 | P1 | 済 | — |
| VR-API-010 | 境界値 | V | DS-10 | 1. token 発行 body の型不正/必須欠落 | 400 契約どおり | P1 | 済 | — |
| VR-API-011 | セキュリティ | S | DS-10 | 1. 資格情報に SQL/巨大入力 | 注入不成立、DoS しない | P0 | 済 | — |
| VR-API-012 | 並行 | R | DS-8 | 1. 同時に複数トークン発行 | 各々独立、既存を壊さない | P2 | 可 | — |
| VR-API-013 | 正常系 | I | DS-8 | 1. ページネーション形式（list 系） | `{data, pagination}` 契約どおり | P1 | 済 | — |

### 1.1 ユーザー API

| ID | 分類 | 観点 | 前提/データ | 手順 | 期待結果 | 優先 | 機械 | 既存 |
|---|---|---|---|---|---|---|---|---|
| VR-API-014 | 正常系 | I | DS-8 staff | 1. `GET /api/v1/users` | 200、一覧、フィルタ | P0 | 済 | API-003 |
| VR-API-015 | 権限 | Z | DS-8 user | 1. `GET /api/v1/users` | 自分のみ（staff 以外） | P0 | 済 | — |
| VR-API-016 | 正常系 | I | DS-8 | 1. `POST /api/v1/users` | 201、作成、一意検証 | P0 | 済 | API-004 |
| VR-API-017 | 権限 | Z | DS-8 user | 1. `POST /api/v1/users` | 403（manager 以上） | P0 | 済 | — |
| VR-API-018 | 正常系 | I | DS-8 | 1. `GET /api/v1/users/{id}` | 200、詳細 | P0 | 済 | API-005 |
| VR-API-019 | 権限 | Z | DS-8 user | 1. 他人の `GET /users/{id}` | 403（self のみ可） | P0 | 済 | — |
| VR-API-020 | 正常系 | I | DS-8 | 1. `PUT/PATCH /users/{id}` | 200、更新 | P0 | 済 | API-006/007 |
| VR-API-021 | 権限 | Z | DS-8 manager | 1. admin アカウントを編集 | 拒否（admin のみ） | P0 | 可 | — |
| VR-API-022 | 正常系 | I | DS-8 | 1. `DELETE /users/{id}` | 200、削除 | P0 | 済 | API-008 |
| VR-API-023 | 権限 | Z | DS-8 | 1. 自分自身を DELETE | 拒否 | P0 | 可 | — |
| VR-API-024 | 正常系 | I | DS-8 | 1. `PUT/PATCH /users/{id}/password` | 200、変更、全トークン失効 | P0 | 済 | API-009/010 |
| VR-API-025 | 正常系 | I | DS-8 | 1. `GET /users/{id}/courses` | 200、受講コース | P0 | 済 | API-011 |
| VR-API-026 | 正常系 | I | DS-8 | 1. `POST /users/{id}/courses` | 201、受講登録 | P0 | 済 | API-012 |
| VR-API-027 | 正常系 | I | DS-8 | 1. `DELETE /users/{id}/courses/{course_id}` | 200、解除 | P0 | 済 | API-013 |
| VR-API-028 | 異常系 | E | DS-8 | 1. 存在しない ID / 重複登録 | 404/409 契約どおり | P1 | 済 | — |
| VR-API-029 | セキュリティ | S | DS-10 | 1. 更新 body で `role` を不正昇格 | 拒否（mass assignment 防止） | P0 | 可 | — |
| VR-API-030 | セキュリティ | S | DS-8 | 1. レスポンスにパスワード/ハッシュが含まれない | 非公開 | P0 | 済 | — |

### 1.2 コース API

| ID | 分類 | 観点 | 前提/データ | 手順 | 期待結果 | 優先 | 機械 | 既存 |
|---|---|---|---|---|---|---|---|---|
| VR-API-031 | 正常系 | I | DS-8 | 1. `GET /api/v1/courses` | 200（staff=全 / user=アクセス可のみ） | P0 | 済 | API-014 |
| VR-API-032 | 正常系 | I | DS-8 | 1. `POST /api/v1/courses` | 201 | P0 | 済 | API-015 |
| VR-API-033 | 権限 | Z | DS-8 user | 1. `POST /courses` | 403 | P0 | 済 | — |
| VR-API-034 | 正常系 | I | DS-8 | 1. `GET /courses/{id}` | 200 | P0 | 済 | API-016 |
| VR-API-035 | 権限 | Z | DS-8 user | 1. アクセス不可コースの `GET` | 403/404 | P0 | 可 | — |
| VR-API-036 | 正常系 | I | DS-8 | 1. `PUT/PATCH /courses/{id}` | 200 | P1 | 済 | — |
| VR-API-037 | 正常系 | I/D | DS-8 | 1. `DELETE /courses/{id}` | 200、コンテンツ/問題も連鎖削除 | P0 | 済 | API-017 |
| VR-API-038 | 権限 | Z | DS-8 user | 1. `DELETE /courses/{id}` | 403 | P0 | 済 | — |

### 1.3 コンテンツ API（Read + Write）

| ID | 分類 | 観点 | 前提/データ | 手順 | 期待結果 | 優先 | 機械 | 既存 |
|---|---|---|---|---|---|---|---|---|
| VR-API-039 | 正常系 | I | DS-8 staff | 1. `GET /api/v1/contents` | 200、フィルタ | P0 | 済 | API-018 |
| VR-API-040 | 権限 | Z | DS-8 user | 1. `GET /contents` | 公開かつアクセス可コースのみ | P0 | 済 | — |
| VR-API-041 | 正常系 | I | DS-8 | 1. `GET /contents/{id}` | 200 | P0 | 済 | API-019 |
| VR-API-042 | 正常系 | I | DS-8 staff | 1. `POST /api/v1/contents` | 201、`user_id`/`sort_no`/既定 status 設定 | P0 | 済 | 追加機能 |
| VR-API-043 | 境界値 | V | DS-8 | 1. `course_id` 欠落/不正で POST | 400/422 契約どおり | P0 | 済 | — |
| VR-API-044 | 権限 | Z | DS-8 user | 1. `POST /contents` | 403（staff のみ） | P0 | 済 | — |
| VR-API-045 | 権限 | Z | DS-8 staff(teacher) | 1. アクセス不可コースへ POST | 403/422（コースアクセス検証） | P0 | 済 | — |
| VR-API-046 | 正常系 | I | DS-8 staff | 1. `PUT/PATCH /contents/{id}` | 200、更新 | P0 | 済 | 追加機能 |
| VR-API-047 | 権限 | Z | DS-8 staff | 1. 現在/移動先コースのアクセス検証 | 両方の検証が効く | P0 | 済 | — |
| VR-API-048 | 正常系 | I/D | DS-8 staff | 1. `DELETE /contents/{id}` | 200、問題も連鎖削除 | P0 | 済 | 追加機能 |
| VR-API-049 | 境界値 | V | DS-8 | 1. `{id}` に非数字（`abc`） | ルート制約 `\d+` で 404 | P0 | 済 | — |
| VR-API-050 | 正常系 | I | DS-8 | 1. kind 全種（markdown 含む）で Write | 保存、契約どおり返却 | P0 | 済 | — |
| VR-API-051 | セキュリティ | S | DS-10 | 1. body に XSS/SQL を投入して GET | 保存/返却が安全、実行されない | P0 | 可 | — |

### 1.4 履歴 API

| ID | 分類 | 観点 | 前提/データ | 手順 | 期待結果 | 優先 | 機械 | 既存 |
|---|---|---|---|---|---|---|---|---|
| VR-API-052 | 正常系 | I | DS-8 staff | 1. `GET /api/v1/records` | 200、フィルタ（user/course/期間） | P0 | 済 | API-020 |
| VR-API-053 | 権限 | Z | DS-8 user | 1. `GET /records` | 自分のみ | P0 | 済 | — |
| VR-API-054 | 正常系 | I | DS-8 | 1. `GET /records/{id}` | 200 | P0 | 済 | API-021 |
| VR-API-055 | 権限 | Z | DS-8 user | 1. 他人の `GET /records/{id}` | 403 | P0 | 済 | — |

### 1.5 グループ API

| ID | 分類 | 観点 | 前提/データ | 手順 | 期待結果 | 優先 | 機械 | 既存 |
|---|---|---|---|---|---|---|---|---|
| VR-API-056 | 正常系 | I | DS-8 | 1. `GET /api/v1/groups` | 200（staff=全 / user=所属） | P0 | 済 | API-022 |
| VR-API-057 | 正常系 | I | DS-8 | 1. `POST /groups` | 201 | P0 | 済 | 追加機能 |
| VR-API-058 | 権限 | Z | DS-8 user | 1. `POST /groups` | 403（manager 以上） | P0 | 済 | — |
| VR-API-059 | 正常系 | I | DS-8 | 1. `GET /groups/{id}` | 200 | P0 | 済 | API-023 |
| VR-API-060 | 正常系 | I | DS-8 | 1. `PUT/PATCH /groups/{id}` | 200 | P1 | 済 | — |
| VR-API-061 | 正常系 | I/D | DS-8 | 1. `DELETE /groups/{id}` | 200、junction 連鎖削除 | P0 | 済 | — |
| VR-API-062 | 正常系 | I | DS-8 | 1. `GET /groups/{id}/users` | 200 | P0 | 済 | API-024 |
| VR-API-063 | 正常系 | I | DS-8 | 1. `POST /groups/{id}/users` | 201 | P0 | 済 | API-025 |
| VR-API-064 | 正常系 | I | DS-8 | 1. `DELETE /groups/{id}/users/{user_id}` | 200 | P0 | 済 | API-026 |
| VR-API-065 | 権限 | Z | DS-8 manager | 1. admin 操作相当/権限外 | 契約どおり拒否 | P0 | 可 | — |

### 1.6 API 横断（契約・エラー・回帰）

| ID | 分類 | 観点 | 前提/データ | 手順 | 期待結果 | 優先 | 機械 | 既存 |
|---|---|---|---|---|---|---|---|---|
| VR-API-066 | 異常系 | I | DS-8 | 1. 未定義 `/api/*`, `/api` | 404 JSON `{error:{code:404}}` | P0 | 済 | NON-016 |
| VR-API-067 | 異常系 | E | DS-8 | 1. 不正 JSON body | 400、HTML にならない | P0 | 済 | — |
| VR-API-068 | セキュリティ | S | DS-8 | 1. CSRF 無しで Write | 成功（API は CSRF 免除）+ Bearer 必須 | P0 | 済 | — |
| VR-API-069 | 正常系 | I | DS-8 | 1. 全 `config/routes.php` の全 `/api/v1` ルートのメソッド不許可 | 405 相当（契約確認） | P1 | 可 | NON-015 |
| VR-API-070 | セキュリティ | S | DS-8 | 1. API を連続呼び出ししレート制限を確認 | **API 全体のレート制限は無い（ログインの `isRateLimited` のみ、MCP のみ 60 req/min）**。DoS/濫用リスクとして記録し対策要否を判定 | P1 | 難 | — |
| VR-API-071 | 整合性 | D | DS-8 | 1. Write 後の DB/GET 反映 | 一致 | P0 | 済 | — |
| VR-API-072 | 回帰 | I | DS-8 | 1. 旧 API 契約（移行前）との差分 | 破壊的変更がない/意図どおり | P1 | 難 | NON-007 |
| VR-API-073 | 性能 | P | DS-11 | 1. 一覧 API 大量データ | ページング必須、肥大応答なし | P1 | 可 | — |
| VR-API-074 | セキュリティ | S | DS-10 | 1. 各エンドポイントに OWASP 系入力（SQLi/XSS/パス） | 全件安全 | P0 | 済 | — |

---

## 2. MCP サーバ（VR-MCP）

対象: `McpController`、`McpServerFactory`、`IrohaAuthMiddleware`、`IrohaTokenValidator`、`McpRateLimitMiddleware`、9 ツール（読み取り 7 + 書き込み 2）。

| ID | 分類 | 観点 | 前提/データ | 手順 | 期待結果 | 優先 | 機械 | 既存 |
|---|---|---|---|---|---|---|---|---|
| VR-MCP-001 | 正常系 | I | DS-9 | 1. `POST /mcp` で `initialize` | JSON-RPC 応答、セッション確立 | P0 | 済 | MCP-001 |
| VR-MCP-002 | 正常系 | I | DS-9 | 1. `tools/list` | 9 ツールのスキーマ返却（読み取り 7 + 書き込み 2） | P0 | 済 | MCP-002 |
| VR-MCP-003 | セキュリティ | A | DS-9 | 1. Bearer 無し/不正で `POST /mcp` | 401、処理されない | P0 | 済 | MCP-* |
| VR-MCP-004 | セキュリティ | A | DS-9 | 1. `lookupApiToken` が失効/期限切れを拒否 | 401 | P0 | 済 | — |
| VR-MCP-005 | 正常系 | I | DS-9 | 1. `DELETE /mcp` でセッション破棄 | セッション終了、以降の呼び出し不可 | P1 | 済 | — |
| VR-MCP-006 | 正常系 | I | DS-9 | 1. `OPTIONS /mcp` | CORS preflight 応答 | P2 | 済 | — |
| VR-MCP-007 | 異常系 | E | DS-9 | 1. `GET /mcp` | 405（SSE 非対応） | P1 | 済 | — |
| VR-MCP-008 | セキュリティ | S | DS-9 | 1. CSRF 無しで `POST /mcp` | 成功（CSRF 免除）+ Bearer 必須 | P0 | 済 | — |
| VR-MCP-009 | 正常系 | I | DS-9 | 1. セッション TTL 経過後の呼び出し | 期限切れ処理（FileSessionStore 3600s） | P2 | 難 | — |
| VR-MCP-010 | 正常系 | I | DS-9 | 1. 不正 JSON-RPC / 未知メソッド | 契約どおりのエラー | P1 | 済 | — |
| VR-MCP-011 | 権限 | Z | DS-9 user | 1. `list_courses` | アクセス可コースのみ | P0 | 済 | MCP-* |
| VR-MCP-012 | 権限 | Z | DS-9 user | 1. `get_course`（アクセス不可） | 拒否/空 | P0 | 済 | — |
| VR-MCP-013 | 正常系 | I | DS-9 | 1. `list_contents`（kind フィルタ） | 該当のみ、user は公開のみ | P0 | 済 | — |
| VR-MCP-014 | 正常系 | I | DS-9 | 1. `get_content` | メタ + 生 body（markdown=ソース / html=生） | P0 | 済 | — |
| VR-MCP-015 | 正常系 | I/S | DS-7 | 1. `get_content_html`（kind=markdown） | Markdown→変換+HTMLPurifier でサニタイズ済 HTML | P0 | 済 | — |
| VR-MCP-016 | セキュリティ | S | DS-7 | 1. `get_content_html` に悪意ある Markdown | Markdown 経路は XSS 不成立。html kind は別項目 VR-MCP-031 で確認 | P0 | 済 | — |
| VR-MCP-031 | セキュリティ | S | DS-7 | 1. kind=html のコンテンツ（`<script>` 等）を `get_content_html` で取得 | **html kind はサニタイズされず生 HTML を返す（コード G-9: Phase 3 未対応）**。クライアント側 XSS/漏洩リスクとして P0 記録 | P0 | 済 | — |
| VR-MCP-017 | 権限 | Z | DS-9 user | 1. `get_content`（非公開/不可コース） | 拒否 or 非表示 | P0 | 済 | — |
| VR-MCP-018 | 正常系 | I | DS-9 | 1. `list_records` | staff=全/フィルタ、user=自分のみ | P0 | 済 | — |
| VR-MCP-019 | 権限 | Z | DS-9 user | 1. `list_records` で他人 `user_id` 指定 | 無視/403 | P0 | 済 | — |
| VR-MCP-020 | 正常系 | I | DS-9 | 1. `get_user_profile`（自己） | パスワード除外で返却 | P0 | 済 | — |
| VR-MCP-021 | 権限 | Z | DS-9 user | 1. `get_user_profile` で他人 `user_id` | staff のみ可、user は拒否 | P0 | 済 | — |
| VR-MCP-022 | セキュリティ | S | DS-9 | 1. 60 req/min 超過 | 429 + Retry-After | P0 | 済 | MCP-* |
| VR-MCP-023 | 整合性 | D | DS-9 | 1. レート制限カウンタ（`ib_logs` log_type=mcp_request） | 正しく集計、他ログと混ざらない | P1 | 可 | — |
| VR-MCP-024 | セキュリティ | S | DS-9 | 1. 他ユーザーのセッション ID 流用 | 拒否（セッション束縛） | P0 | 可 | — |
| VR-MCP-025 | 性能 | P | DS-9 | 1. 複数セッション同時ツール呼び出し | 安定、混線なし | P1 | 難 | — |
| VR-MCP-026 | 正常系 | I | DS-9 | 1. ページネーション（courses/contents/records） | 契約どおり | P1 | 済 | — |
| VR-MCP-027 | セキュリティ | S | DS-9 | 1. ツール引数の型不正/注入 | 安全、500 なし | P0 | 済 | — |
| VR-MCP-028 | 回帰 | I | DS-9 | 1. 公式 Inspector E2E（本番相当） | 正常（MCP-012 検証済の再現） | P1 | 難 | MCP-012 |
| VR-MCP-029 | 異常系 | E | DS-9 | 1. `_meta.oauth` 欠落コンテキスト | 安全に拒否 | P1 | 可 | — |
| VR-MCP-030 | セキュリティ | S | DS-9 | 1. ツール多用による情報列挙 | 権限外データ非返却 | P0 | 難 | — |
| VR-MCP-032 | 権限 | Z | DS-9 user | 1. `create_content` を一般ユーザーで呼び出し | 拒否（staff のみ）。403 or MCP エラー | P0 | 可 | — |
| VR-MCP-033 | 権限 | Z | DS-9 staff | 1. `create_content` をアクセス不可コースで呼び出し | 拒否（コース membership 検証）。403 or MCP エラー | P0 | 可 | — |
| VR-MCP-034 | 正常系 | I/V | DS-9 staff | 1. `create_content` で必須フィールド（course_id）欠落 / 不正値 | 400/エラー（API Write と同形式のバリデーション） | P0 | 可 | — |
| VR-MCP-035 | 正常系 | I/D | DS-9 staff | 1. `create_content` で kind=markdown / html のコンテンツ作成 → `get_content` で確認 | Markdown はソース保存、HTML は生保存。MCP 経路で API Write と同一挙動 | P0 | 可 | — |
| VR-MCP-036 | 権限 | Z | DS-9 user | 1. `update_content` を一般ユーザーで呼び出し | 拒否（staff のみ） | P0 | 可 | — |
| VR-MCP-037 | 正常系 | I/V | DS-9 staff | 1. `update_content` で存在しない content_id / 不正フィールド | 404/400 エラー | P1 | 可 | — |
| VR-MCP-038 | 正常系 | I/D | DS-9 staff | 1. `update_content` で部分更新（title のみ等） → `get_content` で確認 | 指定フィールドのみ更新、他は維持（部分更新）。kind 切替時の Markdown/HTML 取扱が API Write と一致 | P0 | 可 | — |
| VR-MCP-039 | セキュリティ | S | DS-9 staff | 1. `create_content` / `update_content` の body に XSS/Markdown スクリプト混入 | Markdown はサニタイズ、HTML は生保存（API Write と同等のリスク管理） | P0 | 可 | — |

---

## 3. セキュリティ横断（VR-SEC）

| ID | 分類 | 観点 | 前提/データ | 手順 | 期待結果 | 優先 | 機械 | 既存 |
|---|---|---|---|---|---|---|---|---|
| VR-SEC-001 | セキュリティ | S | DS-10 | 1. 全入力画面/API に XSS ベクタ（`<script>`, `on*`, `javascript:`, SVG） | 実行されない（文脈別エスケープ） | P0 | 済 | AUTH-* |
| VR-SEC-002 | セキュリティ | S | DS-10 | 1. 全検索/ID に SQLi（UNION/時間ベース） | 注入不成立 | P0 | 済 | AUTH-* |
| VR-SEC-003 | セキュリティ | S | DS-1 | 1. 全書込フォームで CSRF トークン欠如/改ざん | 拒否 | P0 | 済 | NON-010 |
| VR-SEC-004 | セキュリティ | S | DS-10 | 1. `file-image`/`file-download` にトラバーサル | 拒否 | P0 | 済 | AUTH-* |
| VR-SEC-005 | セキュリティ | S | DS-10 | 1. アップロードに `.php/.phtml/.htaccess` | 拒否、配信のみ（nosniff） | P0 | 可 | VAL-086 |
| VR-SEC-006 | セキュリティ | S/A | DS-1 | 1. 応答ヘッダ・セッション固定・CSRF を確認 | **2026-09-25 実測: X-Frame-Options: SAMEORIGIN / X-Content-Type-Options: nosniff は付与済、セッション Cookie は HttpOnly + SameSite=Lax。CSP / HSTS / Referrer-Policy は未確認（不在）。セッション ID 再生成は無し（`session_regenerate` 未使用）**。CSRF 有効 | P0 | 可 | — |
| VR-SEC-007 | セキュリティ | S | DS-10 | 1. Host ヘッダ注入 | 400（本番設定時） | P0 | 可 | NON-037 |
| VR-SEC-008 | セキュリティ | S | DS-10 | 1. HTTP メソッド不許可（GET で削除等） | 拒否 | P0 | 可 | — |
| VR-SEC-009 | セキュリティ | S | DS-1 | 1. 直接オブジェクト参照（IDOR）全リソース | 他人データ不可視 | P0 | 可 | — |
| VR-SEC-010 | セキュリティ | S/Z | DS-8 | 1. 権限昇格（API/CSV/MCP 経由で role 変更） | 不可 | P0 | 可 | — |
| VR-SEC-011 | セキュリティ | S | DS-1 | 1. パスワード/トークンがログ・レスポンス・エラー画面に露出しない | 非露出 | P0 | 可 | — |
| VR-SEC-012 | セキュリティ | S | DS-1 | 1. デバッグ情報露出（本番 debug=false） | スタック/設定を出さない | P0 | 可 | NON-* |
| VR-SEC-013 | セキュリティ | S | DS-10 | 1. `.htaccess` で保護すべきパス（files/config/vendor） | 直接取得不可 | P0 | 済 | AUTH-076〜081 |
| VR-SEC-014 | セキュリティ | S | DS-10 | 1. CSV 数式インジェクション全出力 | `'` 前置 | P0 | 済 | VAL-* |
| VR-SEC-015 | セキュリティ | S | DS-10 | 1. 大量リクエスト/巨大 body（DoS 簡易） | API 全体のレート制限は無い（ログインのみ・MCP のみ）。大量リクエストが無制限に処理され得るため DoS リスクとして記録 | P1 | 難 | — |
| VR-SEC-016 | セキュリティ | S | DS-10 | 1. SSRF（url kind / MCP 経由の外部取得） | 外部取得しない | P1 | 可 | — |
| VR-SEC-017 | セキュリティ | S | DS-1 | 1. エラー時の情報漏洩（ユーザー列挙/DB 構造） | 汎用メッセージ | P0 | 可 | — |
| VR-SEC-018 | セキュリティ | S | DS-10 | 1. 安全でないデシリアライズ/テンプレート注入 | 不成立 | P1 | 難 | — |
| VR-SEC-019 | セキュリティ | S | DS-1 | 1. 応答ヘッダ（CSP/HSTS/Referrer-Policy）の有無 | **2026-09-25 実測: X-Frame-Options: SAMEORIGIN により他サイトからの iframe 埋め込みは不可。CSP / HSTS / Referrer-Policy は未確認のため、導入要否を判定** | P0 | 可 | — |
| VR-SEC-021 | セキュリティ | S | DS-10 | 1. 攻撃ページで管理画面を iframe 埋め込み 2. 管理者に操作させる | **2026-09-25 実測: X-Frame-Options: SAMEORIGIN のため他オリジンからの iframe 表示はブロックされる見込み。実機で確認し、CSP frame-ancestors の追加要否を判定** | P0 | 可 | — |
| VR-SEC-020 | セキュリティ | S | DS-8 | 1. API/MCP の認可漏れ一覧（全エンドポイント棚卸し） | 全件で認可必須 | P0 | 可 | — |

---

## 4. 入力検証・境界値 横断（VR-VAL）

| ID | 分類 | 観点 | 前提/データ | 手順 | 期待結果 | 優先 | 機械 | 既存 |
|---|---|---|---|---|---|---|---|---|
| VR-VAL-001 | 境界値 | V | DS-10 | 1. 全必須項目を空で送信 | 各フォーム固有のエラー | P0 | 済 | VAL-* |
| VR-VAL-002 | 境界値 | V | DS-10 | 1. 文字列長 0/1/最大/最大+1 全項目 | 仕様どおり | P0 | 済 | VAL-* |
| VR-VAL-003 | 境界値 | V | DS-10 | 1. 数値 0/負値/最大/最大+1/型不正 | 仕様どおり | P1 | 済 | VAL-* |
| VR-VAL-004 | 境界値 | V | DS-10 | 1. 日付/時刻 不正・境界 | パース拒否 or 正規化 | P1 | 可 | — |
| VR-VAL-005 | 境界値 | V | DS-10 | 1. 真偽値/列挙外（kind/role/status） | 拒否 | P0 | 済 | VAL-* |
| VR-VAL-006 | 境界値 | V | DS-10 | 1. マルチバイト/絵文字/結合文字/結合絵文字 | 長さ定義どおり（`alphaNumericMB`） | P1 | 済 | — |
| VR-VAL-007 | 境界値 | V | DS-10 | 1. 前後空白/全角空白/制御文字/NULL バイト | 正規化 or 拒否 | P1 | 可 | — |
| VR-VAL-008 | 境界値 | V | DS-10 | 1. 一意制約（ユーザー名等）の重複 | 拒否、DB エラーを出さない | P0 | 済 | VAL-002 |
| VR-VAL-009 | 境界値 | V | DS-10 | 1. 配列/オブジェクトを文字列項目へ | 拒否（型検証） | P1 | 済 | — |
| VR-VAL-010 | 境界値 | V | DS-10 | 1. アップロード拡張子/サイズ境界（全種） | 仕様どおり | P0 | 可 | VAL-* |
| VR-VAL-011 | 境界値 | V | DS-10 | 1. options/correct の区切り文字エッジ | パース破綻なし | P1 | 可 | — |
| VR-VAL-012 | 境界値 | V | DS-10 | 1. パスワード確認・形式（4〜32 英数） | 仕様どおり | P0 | 済 | — |
| VR-VAL-013 | 整合性 | D | DS-10 | 1. バリデーション失敗時の DB 不変 | 部分保存なし | P0 | 済 | — |
| VR-VAL-014 | UI | U | DS-10 | 1. エラー表示の位置・文言・保持 | 該当項目に表示、入力保持 | P1 | 難 | — |
| VR-VAL-015 | 境界値 | V | DS-10 | 1. クライアント側検証のバイパス（直接 POST） | サーバ側でも検証 | P0 | 済 | — |
| VR-VAL-016 | セキュリティ | S/V | DS-10 | 1. `file-image/{file_name}` に `%2e%2e%2f`・`%00`・Unicode 正規化・二重エンコードを送信 | パストラバーサル/不正参照が拒否され、既存ファイルを取得できない | P0 | 可 | — |

---

## 5. 非機能（VR-NFR）

| ID | 分類 | 観点 | 前提/データ | 手順 | 期待結果 | 優先 | 機械 | 既存 |
|---|---|---|---|---|---|---|---|---|
| VR-NFR-001 | 性能 | P | DS-11 | 1. 主要画面の応答時間計測 | 目標内（例 3 秒以内） | P1 | 可 | NON-049 |
| VR-NFR-002 | 性能 | P | DS-11 | 1. N+1 クエリ検出（一覧/トップ/履歴） | クエリ数が件数比例で増えない | P1 | 可 | — |
| VR-NFR-003 | 性能 | P | DS-11 | 1. 同時 50〜100 ユーザー負荷 | エラー率/応答が許容内 | P1 | 難 | — |
| VR-NFR-004 | 性能 | P | DS-11 | 1. 履歴 10 万行の集計/CSV | タイムアウト/メモリ超過なし | P1 | 難 | — |
| VR-NFR-005 | 性能 | P | DS-11 | 1. 大容量ファイル配信 | 安定（ストリーミング） | P2 | 難 | — |
| VR-NFR-006 | 信頼性 | R | DS-1 | 1. DB 切断中の操作 | 安全なエラー、復旧後正常 | P1 | 難 | — |
| VR-NFR-007 | 信頼性 | R | DS-2 | 1. 二重送信/戻る/再読込 | 破綻・重複なし | P1 | 難 | — |
| VR-NFR-008 | 信頼性 | R | DS-3 | 1. 同時採点/同時完了 | ロスト更新なし | P1 | 難 | — |
| VR-NFR-009 | 可用性 | E | DS-1 | 1. PHP 致命エラー/例外時の挙動 | 500 ページ、ログ記録、データ保全 | P0 | 可 | — |
| VR-NFR-010 | UI | Y | DS-2 | 1. WCAG 2.2 AA 主要基準（コントラスト/代替/ラベル/フォーカス/見出し） | 重大違反なし | P1 | 難 | — |
| VR-NFR-011 | UI | Y | DS-2 | 1. キーボードのみで主要動線 | 到達・操作可能 | P1 | 難 | — |
| VR-NFR-012 | UI | Y | DS-2 | 1. スクリーンリーダー（NVDA/VoiceOver）主要画面 | 意味が伝わる | P2 | 難 | — |
| VR-NFR-013 | UI | U | DS-2 | 1. レスポンシブ（PC/タブレット/スマホ） | 崩れなし、操作可能 | P1 | 難 | NON-043〜046 |
| VR-NFR-014 | UI | U | DS-2 | 1. クロスブラウザ（Chrome/Firefox/Safari/Edge） | 機能差で破綻なし | P1 | 難 | NON-043〜045 |
| VR-NFR-015 | UI | U | DS-2 | 1. JS 無効環境 | 主要閲覧が可能 or 明示 | P2 | 難 | NON-047 |
| VR-NFR-016 | 相互運用 | I | DS-10 | 1. i18n `__()` のフォールバック表示 | 日本語が正しく表示 | P2 | 可 | NON-051 |
| VR-NFR-017 | 保守性 | M | — | 1. `phpstan analyse`（level 8） | エラー 0（または許容） | P1 | 済 | — |
| VR-NFR-018 | 保守性 | M | — | 1. `psalm`（level 2） | エラー 0（または許容） | P1 | 済 | — |
| VR-NFR-019 | 保守性 | M | — | 1. `phpcs`（CakePHP） | 違反 0 | P1 | 済 | — |
| VR-NFR-020 | 保守性 | M | — | 1. テストスイート全実行 | 全て成功、回帰なし | P0 | 済 | — |
| VR-NFR-021 | 保守性 | M | — | 1. ルーティング全件解決（`bin/cake routes`） | 未解決/重複なし | P1 | 可 | — |
| VR-NFR-022 | 使用性 | U/Y | DS-10 | 1. 長い翻訳文/多言語表示でレイアウトを確認 | 崩れ・重なりなし（`resources/` 空の現状はフォールバック動作を確認） | P2 | 難 | — |
| VR-NFR-023 | 整合性 | D | DS-1 | 1. 学習日時/記録日時を保存・表示 | `Asia/Tokyo` で一貫、UTC 混在/オフセットずれがない | P1 | 可 | — |
| VR-NFR-024 | 整合性 | D/V | DS-1/DS-10 | 1. DB/テーブル/カラムの文字コード・照合順序を確認 2. 絵文字(4byte)を入出力 | utf8mb4 で一貫、4byte 文字が保存・表示・CSV 出力できる | P1 | 可 | — |
| VR-NFR-025 | 異常系 | E/V | DS-2 | 1. `?page=0`/`-1`/`abc`/最終ページ/最終行削除後 | 例外・500 なく正規化、最終ページの端数表示が正しい | P1 | 可 | — |

---

## 6. 運用・インストール・更新・移行（VR-OPS）

| ID | 分類 | 観点 | 前提/データ | 手順 | 期待結果 | 優先 | 機械 | 既存 |
|---|---|---|---|---|---|---|---|---|
| VR-OPS-001 | 正常系 | O | 新規 DB | 1. `/install` で初期化 | 全テーブル作成 + admin 作成、完了画面 | P0 | 難 | NON-018 |
| VR-OPS-002 | 正常系 | O/D | 新規 DB | 1. インストール後のログイン | admin でログイン可 | P0 | 難 | — |
| VR-OPS-003 | 異常系 | E | 既存 DB | 1. 再インストール試行 | 「インストール済」表示、既存データ保持 | P0 | 可 | — |
| VR-OPS-004 | 境界値 | V | 新規 DB | 1. admin 名/パスワード 境界・不正 | 検証どおり | P1 | 可 | — |
| VR-OPS-005 | セキュリティ | S | 本番想定 | 1. `deny_install_update_access=true` | `/install`,`/update` 遮断 | P0 | 可 | NON-018 |
| VR-OPS-006 | セキュリティ | S | 新規 DB | 1. インストール画面の CSRF/再入 | 適切 | P1 | 可 | — |
| VR-OPS-007 | 正常系 | O | 旧 DB | 1. `/update` 実行 | `update.sql`+`custom.sql` 適用、失敗時安全 | P0 | 難 | — |
| VR-OPS-008 | 異常系 | E | 旧 DB | 1. 途中失敗（重複列 42S21 等） | 無視して継続、破綻しない | P1 | 難 | — |
| VR-OPS-009 | 整合性 | D | 移行 DB | 1. 移行後の行数/索引/文字コード照合 | 旧環境と一致（utf8mb4） | P0 | 可 | DB-* |
| VR-OPS-010 | 整合性 | D | 移行 DB | 1. 旧パスワード/トークン/履歴の保全 | 欠落なし | P0 | 可 | DB-* |
| VR-OPS-011 | 運用 | O | 任意 | 1. バックアップ→リストア | 完全復元 | P0 | 難 | — |
| VR-OPS-012 | 運用 | O | 任意 | 1. `Docs/dev/rollback-procedure.md` に沿ったロールバック | 手順どおり復旧 | P0 | 難 | — |
| VR-OPS-013 | 運用 | O | DS-11 | 1. ログ/監視（`logs/error.log`, `ib_logs`） | 障害を検知・追跡可能 | P1 | 可 | — |
| VR-OPS-014 | 信頼性 | R | DS-1 | 1. ディスク満杯/権限不足でアップロード | 安全なエラー、部分ファイルなし | P1 | 難 | — |
| VR-OPS-015 | 運用 | O | DS-1 | 1. 設定切替（demo_mode 等）の反映 | 再起動なしで反映、副作用なし | P1 | 難 | — |
| VR-OPS-016 | 移行 | O | 旧環境 | 1. CakePHP2 との画面/挙動比較（主要フロー） | 同等 or 意図的差分 | P1 | 難 | manual-checklist |
| VR-OPS-017 | 運用 | O | — | 1. スモークテスト（デプロイ直後） | 主要動線 5 分以内に確認可 | P1 | 可 | SM |
| VR-OPS-018 | 整合性 | D | DS-1 | 1. 本番相当データでの B-3（設定値照合） | 一致 or 差分記録 | P2 | 難 | B-3 |
| VR-OPS-019 | 運用 | O | — | 1. 依存関係更新（`composer install`）後の起動 | 正常起動、テスト成功 | P1 | 可 | — |
| VR-OPS-020 | 移行 | O | 旧環境 | 1. DB セッション/文字コード設定 | 設計どおり | P2 | 難 | — |
| VR-OPS-021 | 異常系 | R/O | 新規 DB | 1. `/install` をスキーマ途中で中断 2. 再実行 | 部分スキーマ残留でも再実行が安全（冪等/回復）か確認。重複エラー無視の挙動を記録 | P0 | 難 | — |
| VR-OPS-022 | 正常系 | R/O | 更新済 DB | 1. `/update` を 2 回連続実行 | 2 回目が安全でデータ破壊・重複が無いこと | P1 | 難 | — |
