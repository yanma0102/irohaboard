# 03-api.md — REST API v1 試験項目マトリクス

| 項目 | 内容 |
|---|---|
| 対象 | REST API v1（`/api/v1` スコープ内 全28ルート + catch-all 2件） |
| 根拠 | `config/routes.php`、`src/Controller/Api/*.php`、`Docs/API.md`、`Docs/design/08-rest-api.md`、`Docs/design/12-implementation-test-plan.md`（A1〜A24） |
| 前提データ | DS-0〜DS-6（`Docs/test/README.md` §3 参照）。認証テストは DS-1（admin/user 含む）、旧SHA1テストは DS-6 |
| 実行方法 | curl。トークン取得: `curl -s -X POST http://localhost:8082/api/v1/auth/token -H 'Content-Type: application/json' -d '{"username":"admin","password":"admin"}'`。認証済: `curl -s http://localhost:8082/api/v1/users -H 'Authorization: Bearer <TOKEN>'` |

## 1. 認証（POST / DELETE /api/v1/auth/token）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| API-001 | 正常系 | POST /auth/token（admin） | DS-1（admin 存在） | 1. admin 資格情報で POST /api/v1/auth/token | 201。`{"data":{"token":"...","token_type":"Bearer","permanent":false,"expires":"...","user":{"id":1,"username":"admin","name":"...","role":"admin"}}}` | A1、Docs/API.md §認証 | 可 | P0 | □ | |
| API-002 | 正常系 | POST /auth/token（user） | DS-1（user 存在） | 1. user 資格情報で POST | 201。`token_type:"Bearer"`、`user.role:"user"` | A1 | 可 | P0 | □ | |
| API-003 | 異常系 | POST /auth/token（誤パスワード） | DS-1 | 1. 存在ユーザー名＋誤パスワードで POST | 401。`{"error":{"code":401,"message":"..."}}`。`ib_logs` に `api_login_error` 追加 | A1、08-rest-api.md §エラー | 可 | P0 | □ | |
| API-004 | 異常系 | POST /auth/token（存在しないユーザー） | DS-0 | 1. 存在しないユーザー名で POST | 401 | A1 | 可 | P0 | □ | |
| API-005 | 異常系 | POST /auth/token（パラメータ異常） | — | 1. ボディ空で POST<br>2. `{"username":"admin"}` のみ<br>3. `{"password":"admin"}` のみ<br>4. `{"username":"user@example.com","password":"pass"}`（email 使用） | 1〜3: 400。4: 401（username のみ検索、email 非対応） | A1 | 可 | P0 | □ | |
| API-006 | 異常系 | POST /auth/token（レート制限） | DS-1 | 1. 誤パスワード10回 POST で `api_login_error` 10件生成<br>2. 11回目で正しい資格情報 POST | 11回目: 429。`{"error":{"code":429,"message":"..."}}` | A1、AuthController `isRateLimited()` | 可 | P0 | □ | |
| API-007 | 正常系 | POST /auth/token（permanent: admin） | DS-1（admin） | 1. `{"username":"admin","password":"admin","permanent":true}` で POST | 201。`expires:"9999-12-31T23:59:59"` | A1、AuthController `PERMANENT_EXPIRED` | 可 | P1 | □ | |
| API-008 | 異常系 | POST /auth/token（permanent: user） | DS-1（user） | 1. user で `permanent:true` POST | 403。permanent は admin のみ | A1 | 可 | P1 | □ | |
| API-009 | 正常系 | DELETE /auth/token（正常失効） | DS-1 + 発行済トークン | 1. 発行済トークンで DELETE<br>2. 失効後に同一トークンで GET /users | 1: 200。`{"data":{"revoked":true}}`。2: 401 | A2 | 可 | P0 | □ | |
| API-010 | 異常系 | DELETE /auth/token（未認証） | — | 1. Authorization ヘッダなしで DELETE | 401 | A2 | 可 | P0 | □ | |
| API-011 | 異常系 | DELETE /auth/token（失効済トークン） | API-009 実施後 | 1. 失効済トークンで DELETE | 401 | A2 | 可 | P1 | □ | |

## 2. ユーザー管理（/api/v1/users）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| API-012 | 正常系 | GET /users（admin + フィルタ + ページネーション） | DS-1 + admin トークン | 1. admin で GET /api/v1/users<br>2. `?username=admin` でフィルタ<br>3. `?page=1&limit=2` でページネーション | 200。`{"data":[...],"meta":{"page":1,"limit":50,"total":N,"count":N}}`。password 不在 | A3、08-rest-api.md | 可 | P0 | □ | |
| API-013 | 正常系 | GET /users（user → 自分のみ） | DS-1 + user トークン | 1. user で GET /api/v1/users | 200。data が自分1件のみ | A3 | 可 | P0 | □ | |
| API-014 | 異常系 | GET /users（未認証） | DS-1 | 1. Authorization ヘッダなしで GET | 401 | A3 | 可 | P0 | □ | |
| API-015 | 正常系 | GET /users/:id（admin → 任意） | DS-1 + admin トークン | 1. GET /api/v1/users/1 | 200。`{"data":{"id":1,"username":"admin",...}}` | A5 | 可 | P0 | □ | |
| API-016 | 正常系 | GET /users/:id（user → 自分） | DS-1 + user トークン | 1. GET /api/v1/users/{self_id} | 200 | A5 | 可 | P0 | □ | |
| API-017 | 権限 | GET /users/:id（user → 他人） | DS-1 + user トークン | 1. GET /api/v1/users/{admin_id} | 403 | A5 | 可 | P0 | □ | |
| API-018 | 異常系 | GET /users/:id（存在しない） | DS-0 + admin トークン | 1. GET /api/v1/users/99999 | 404 | A5 | 可 | P1 | □ | |
| API-019 | 正常系 | POST /users（追加） | DS-0 + admin トークン | 1. `{"username":"new01","name":"新規","role":"user"}` で POST | 201。`{"data":{"id":N,"username":"new01",...}}` | A4 | 可 | P0 | □ | |
| API-020 | 権限 | POST /users（user → 追加不可） | DS-1 + user トークン | 1. user で POST | 403 | A4 | 可 | P0 | □ | |
| API-021 | 異常系 | POST /users（必須パラメータ欠落） | DS-0 + admin トークン | 1. `{"name":"テスト","role":"user"}` で POST（username 欠落）<br>2. `{"username":"test","name":"テスト"}` で POST（role 欠落） | 各ケースで 400 | A4 | 可 | P1 | □ | |
| API-022 | 正常系 | PUT /users/:id（編集） | DS-1 + admin トークン | 1. PUT /api/v1/users/{id} に `{"name":"変更後"}` | 200。DB の name が更新 | A6 | 可 | P0 | □ | |
| API-023 | 権限 | PUT /users/:id（user → 編集不可） | DS-1 + user トークン | 1. user で PUT /api/v1/users/{self_id} | 403 | A6 | 可 | P0 | □ | |
| API-024 | 権限 | PUT /users/:id（管理者アカウントを非管理者が編集） | DS-1 + manager トークン | 1. manager で PUT /api/v1/users/{admin_id} | 403。管理者アカウントは admin のみ | A6 | 可 | P1 | □ | |
| API-025 | 権限 | PUT /users/:id（自分ロール変更禁止） | DS-1 + admin トークン | 1. admin で自分 PUT に `{"role":"user"}` | 403 | A6 | 可 | P1 | □ | |
| API-026 | 異常系 | PUT /users/:id（存在しない） | DS-0 + admin トークン | 1. PUT /api/v1/users/99999 | 404 | A6 | 可 | P1 | □ | |
| API-027 | 正常系 | DELETE /users/:id（削除） | DS-1 + admin トークン + 別ユーザー | 1. admin で DELETE /api/v1/users/{id} | 200。`{"data":{"id":N,"deleted":true}}` | A7 | 可 | P0 | □ | |
| API-028 | 権限 | DELETE /users/:id（user → 削除不可） | DS-1 + user トークン | 1. user で DELETE | 403 | A7 | 可 | P0 | □ | |
| API-029 | 異常系 | DELETE /users/:id（自分自身） | DS-1 + admin トークン | 1. admin で DELETE /api/v1/users/{self_id} | 400。自己削除禁止 | A7 | 可 | P1 | □ | |
| API-030 | 正常系 | PUT /users/:id/password（パスワード変更） | DS-1 + admin トークン | 1. PUT に `{"password":"new123"}` | 200。`{"data":{"id":N,"password_changed":true}}`。旧トークン全て失効 | — | 可 | P0 | □ | A1-A24 未対応。設計書要追記 |
| API-031 | 権限 | PUT /users/:id/password（user → 変更不可） | DS-1 + user トークン | 1. user で PUT /api/v1/users/{self_id}/password | 403 | — | 可 | P0 | □ | 同上 |

## 3. ユーザー-コース紐付け（/api/v1/users/:id/courses）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| API-032 | 正常系 | GET /users/:id/courses（admin） | DS-1, DS-2 + admin トークン | 1. admin で GET /api/v1/users/{id}/courses | 200。`{"data":[...],"meta":{...}}` | — | 可 | P0 | □ | A1-A24 未対応。設計書要追記 |
| API-033 | 正常系 | GET /users/:id/courses（user → 自分） | DS-1, DS-2 + user トークン | 1. user で GET /api/v1/users/{self_id}/courses | 200。自分のコースのみ | — | 可 | P0 | □ | 同上 |
| API-034 | 権限 | GET /users/:id/courses（user → 他人） | DS-1 + user トークン | 1. user で GET /api/v1/users/{other_id}/courses | 403 | — | 可 | P0 | □ | 同上 |
| API-035 | 正常系 | POST /users/:id/courses（追加＋冪等性） | DS-1, DS-2 + admin トークン | 1. `{"course_id":1}` で POST<br>2. 同 course_id で再度 POST | 1: 201。`{"data":{"user_id":N,"course_id":1,"created":true}}`。2: 200。created=false | A8 | 可 | P0 | □ | |
| API-036 | 異常系 | POST /users/:id/courses（パラメータ不正） | DS-1 + admin トークン | 1. `{"wrong_key":1}` で POST<br>2. `{"course_id":99999}` で POST<br>3. POST /users/99999/courses | 1: 400。2: 404。3: 404 | A8 | 可 | P1 | □ | |
| API-037 | 権限 | POST /users/:id/courses（user → 追加不可） | DS-1 + user トークン | 1. user で POST | 403 | A8 | 可 | P0 | □ | |
| API-038 | 正常系 | DELETE /users/:id/courses/:course_id | DS-1, DS-2 + admin トークン + 紐付け済 | 1. DELETE /api/v1/users/{id}/courses/{course_id} | 200。`{"data":{"user_id":N,"course_id":M,"deleted":true}}` | — | 可 | P0 | □ | A1-A24 未対応。設計書要追記 |
| API-039 | 権限 | DELETE /users/:id/courses/:course_id（user → 不可） | DS-1 + user トークン | 1. user で DELETE | 403 | — | 可 | P0 | □ | 同上 |

## 4. コース管理（/api/v1/courses）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| API-040 | 正常系 | GET /courses（admin） | DS-2 + admin トークン | 1. admin で GET /api/v1/courses | 200。全コース。`{"data":[...],"meta":{...}}` | A15 | 可 | P0 | □ | |
| API-041 | 正常系 | GET /courses（user → 紐付けコースのみ） | DS-2 + user トークン | 1. user で GET /api/v1/courses | 200。紐付けコースのみ | A15 | 可 | P0 | □ | |
| API-042 | 異常系 | GET /courses（未認証） | DS-2 | 1. Authorization ヘッダなしで GET | 401 | A15 | 可 | P0 | □ | |
| API-043 | 正常系 | GET /courses/:id（admin） | DS-2 + admin トークン | 1. GET /api/v1/courses/1 | 200 | A17 | 可 | P0 | □ | |
| API-044 | 正常系 | GET /courses/:id（user → 紐付け済） | DS-2 + user トークン | 1. GET /api/v1/courses/{assigned_id} | 200 | A17 | 可 | P0 | □ | |
| API-045 | 異常系 | GET /courses/:id（user → 未紐付け / 存在しない） | DS-2 + user / DS-0 + admin | 1. user で GET /courses/{not_assigned_id}<br>2. admin で GET /courses/99999 | 1: 404。2: 404 | A17 | 可 | P0 | □ | |
| API-046 | 正常系 | POST /courses（追加） | DS-1 + manager トークン | 1. `{"title":"新コース"}` で POST | 201。`{"data":{"id":N,"title":"新コース","sort_no":N,...}}` | A16 | 可 | P0 | □ | |
| API-047 | 権限 | POST /courses（user → 追加不可） | DS-1 + user トークン | 1. user で POST | 403 | A16 | 可 | P0 | □ | |
| API-048 | 異常系 | POST /courses（title 欠落） | DS-1 + admin トークン | 1. `{}` で POST | 400 | A16 | 可 | P1 | □ | |
| API-049 | 正常系 | DELETE /courses/:id（削除） | DS-2 + manager トークン | 1. DELETE /api/v1/courses/{id} | 200 | A19 | 可 | P0 | □ | |
| API-050 | 権限 | DELETE /courses/:id（user → 削除不可） | DS-1 + user トークン | 1. user で DELETE | 403 | A19 | 可 | P0 | □ | |
| API-051 | 異常系 | DELETE /courses/:id（存在しない） | DS-0 + admin トークン | 1. DELETE /api/v1/courses/99999 | 404 | A19 | 可 | P1 | □ | |
| API-052 | 異常系 | PUT /courses/:id（未定義ルート） | DS-1 + admin トークン | 1. PUT /api/v1/courses/1 | 404（catch-all 到達） | A18（※実装に存在しない） | 可 | P1 | □ | A18 は設計書に存在するが routes.php に PUT なし |

## 5. コンテンツ（/api/v1/contents）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| API-053 | 正常系 | GET /contents（admin + フィルタ） | DS-2 + admin トークン | 1. admin で GET /api/v1/contents<br>2. `?course_id=1` でフィルタ<br>3. `?kind=html` でフィルタ | 200。フィルタで該当のみ | A20 | 可 | P0 | □ | |
| API-054 | 正常系 | GET /contents（user → 紐付け+公開のみ） | DS-2 + user トークン | 1. user で GET /api/v1/contents | 200。紐付けコースのコンテンツ＋公開(status=1) のみ | A20 | 可 | P0 | □ | |
| API-055 | 正常系 | GET /contents/:id（admin） | DS-2 + admin トークン | 1. GET /api/v1/contents/1 | 200 | A21 | 可 | P0 | □ | |
| API-056 | 正常系 | GET /contents/:id（user → 紐付け+公開） | DS-2 + user トークン | 1. GET /api/v1/contents/{accessible_id} | 200 | A21 | 可 | P0 | □ | |
| API-057 | 異常系 | GET /contents/:id（user → アクセス不可 / 存在しない） | DS-2 + user / DS-0 + admin | 1. user で GET /contents/{inaccessible_id}<br>2. admin で GET /contents/99999 | 1: 404。2: 404 | A21 | 可 | P0 | □ | |

## 6. 学習履歴（/api/v1/records）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| API-058 | 正常系 | GET /records（admin + フィルタ） | DS-4 + admin トークン | 1. admin で GET /api/v1/records<br>2. `?user_id=1`<br>3. `?from=2026-01-01&to=2026-12-31` | 200。フィルタ正常 | A22 | 可 | P0 | □ | |
| API-059 | 正常系 | GET /records（user → 自分のみ） | DS-4 + user トークン | 1. user で GET /api/v1/records | 200。自分の記録のみ | A22 | 可 | P0 | □ | |
| API-060 | 正常系 | GET /records/:id（admin） | DS-4 + admin トークン | 1. GET /api/v1/records/1 | 200 | A23 | 可 | P0 | □ | |
| API-061 | 正常系 | GET /records/:id（user → 自分） | DS-4 + user トークン | 1. GET /api/v1/records/{self_record_id} | 200 | A23 | 可 | P0 | □ | |
| API-062 | 権限 | GET /records/:id（user → 他人） | DS-4 + user トークン | 1. GET /api/v1/records/{other_record_id} | 403 | A23 | 可 | P0 | □ | |
| API-063 | 異常系 | GET /records/:id（存在しない） | DS-0 + admin トークン | 1. GET /api/v1/records/99999 | 404 | A23 | 可 | P1 | □ | |

## 7. グループ管理（/api/v1/groups）

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| API-064 | 正常系 | GET /groups（admin + フィルタ） | DS-1 + admin トークン | 1. admin で GET /api/v1/groups<br>2. `?title=グループ` | 200。フィルタ正常 | A9 | 可 | P0 | □ | |
| API-065 | 正常系 | GET /groups（user → 所属グループのみ） | DS-1 + user トークン | 1. user で GET /api/v1/groups | 200。所属グループのみ | A9 | 可 | P0 | □ | |
| API-066 | 正常系 | GET /groups/:id（admin） | DS-1 + admin トークン | 1. GET /api/v1/groups/1 | 200 | A11 | 可 | P0 | □ | |
| API-067 | 正常系 | GET /groups/:id（user → 所属グループ） | DS-1 + user トークン | 1. GET /api/v1/groups/{member_group_id} | 200 | A11 | 可 | P0 | □ | |
| API-068 | 異常系 | GET /groups/:id（user → 非所属 / 存在しない） | DS-1 + user / DS-0 + admin | 1. user で GET /groups/{non_member_id}<br>2. admin で GET /groups/99999 | 1: 404。2: 404 | A11 | 可 | P0 | □ | |
| API-069 | 正常系 | GET /groups/:id/users（admin） | DS-1 + admin トークン | 1. GET /api/v1/groups/1/users | 200。ユーザー一覧 | — | 可 | P0 | □ | A1-A24 未対応。設計書要追記 |
| API-070 | 正常系 | GET /groups/:id/users（user → 所属） | DS-1 + user トークン | 1. GET /api/v1/groups/{member_id}/users | 200 | — | 可 | P0 | □ | 同上 |
| API-071 | 異常系 | GET /groups/:id/users（user → 非所属） | DS-1 + user トークン | 1. GET /api/v1/groups/{non_member_id}/users | 404 | — | 可 | P0 | □ | 同上 |
| API-072 | 正常系 | POST /groups/:id/users（追加＋冪等性） | DS-1 + admin トークン | 1. `{"user_id":N}` で POST<br>2. 同 user_id で再度 POST | 1: 201。2: 200。created=false | A14 | 可 | P0 | □ | |
| API-073 | 異常系 | POST /groups/:id/users（パラメータ不正） | DS-1 + admin トークン | 1. `{}` で POST<br>2. `{"user_id":99999}`<br>3. POST /groups/99999/users | 1: 400。2: 404。3: 404 | A14 | 可 | P1 | □ | |
| API-074 | 権限 | POST /groups/:id/users（user → 追加不可） | DS-1 + user トークン | 1. user で POST | 403 | A14 | 可 | P0 | □ | |
| API-075 | 正常系 | DELETE /groups/:id/users/:user_id | DS-1 + admin トークン + 所属済 | 1. DELETE /api/v1/groups/{id}/users/{user_id} | 200。`{"data":{"group_id":M,"user_id":N,"deleted":true}}` | — | 可 | P0 | □ | A1-A24 未対応。設計書要追記 |
| API-076 | 権限 | DELETE /groups/:id/users/:user_id（user → 不可） | DS-1 + user トークン | 1. user で DELETE | 403 | — | 可 | P0 | □ | 同上 |
| API-077 | 異常系 | DELETE /groups/:id/users/:user_id（存在しない） | DS-0 + admin トークン | 1. DELETE /api/v1/groups/99999/users/99999 | 404 | — | 可 | P1 | □ | 同上 |
| API-078 | 異常系 | POST /groups（未定義ルート） | DS-1 + admin トークン | 1. POST /api/v1/groups | 404（catch-all） | A10（※実装に存在しない） | 可 | P1 | □ | A10 は設計書に存在するが routes.php に POST なし |
| API-079 | 異常系 | PUT /groups/:id（未定義ルート） | DS-1 + admin トークン | 1. PUT /api/v1/groups/1 | 404（catch-all） | A12（※同上） | 可 | P1 | □ | A12 は設計書に存在するが routes.php に PUT なし |
| API-080 | 異常系 | DELETE /groups/:id（未定義ルート） | DS-1 + admin トークン | 1. DELETE /api/v1/groups/1 | 404（catch-all） | A13（※同上） | 可 | P1 | □ | A13 は設計書に存在するが routes.php に DELETE なし |

## 8. catch-all / 未定義ルート

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| API-081 | 異常系 | GET /api/v1/does-not-exist | — | 1. GET /api/v1/does-not-exist | 404。`{"error":{"code":404,"message":"Endpoint not found"}}` | A24 | 可 | P0 | □ | |
| API-082 | 異常系 | API スコープ外 URL / メソッド不正 | — | 1. GET /api/v1<br>2. GET /api<br>3. GET /api/v1/auth/token（POST/DELETE のみルートに GET→catch-all 到達）<br>4. POST /api/v1/contents（GET のみルートに POST）<br>5. PUT /api/v1/auth/token（未定義メソッド） | 1,2: 404（catch-all）。3,4,5: 404（catch-all 到達）または 405（CakePHP ルーティング） | A24 | 可 | P1 | □ | |

## 9. トークン仕様 / ヘッダ形式

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| API-085 | 正常系 | Bearer（大文字小文字不区別） | DS-1 + 発行済トークン | 1. `Authorization: bearer <TOKEN>`<br>2. `Authorization: BEARER <TOKEN>` | 各ケースで 200。`/^Bearer\s+(.+)$/i` | 08-rest-api.md §認証 | 可 | P1 | □ | |
| API-086 | 異常系 | Bearer 認証エラー（接頭辞なし/空/クエリパラメータ） | DS-1 + 発行済トークン | 1. `Authorization: <TOKEN>`（Bearer なし）<br>2. `Authorization: Bearer `（空）<br>3. GET /api/v1/users?token=TOKEN（クエリ指定） | 各ケースで 401 | 08-rest-api.md | 可 | P1 | □ | |
| API-089 | 正常系 | 不正トークン | DS-1 | 1. `Authorization: Bearer invalid-token` | 401 | 08-rest-api.md §エラー | 可 | P0 | □ | |
| API-090 | 正常系 | 失効済トークン | API-009 実施済 | 1. 失効済トークンでリクエスト | 401 | — | 可 | P0 | □ | |

## 10. レスポンス形式 / エラー形式

| 項目ID | 分類 | 対象 | 前提条件 | 手順 | 期待結果 | 設計参照 | 自動化 | 優先 | 結果 | 証跡 |
|---|---|---|---|---|---|---|---|---|---|---|
| API-091 | 整合性 | 成功レスポンス JSON 構造 | DS-1 + admin トークン | 1. GET /users/1（単一）<br>2. GET /users（一覧） | 1: `{"data":{...}}`。2: `{"data":[...],"meta":{"page":N,"limit":N,"total":N,"count":N}}` | 08-rest-api.md §レスポンス | 可 | P0 | □ | |
| API-092 | 整合性 | エラーレスポンス JSON 構造 | — | 1. 未認証で GET /users | `{"error":{"code":N,"message":"..."}}` | 08-rest-api.md §エラー | 可 | P0 | □ | |
| API-093 | 整合性 | Content-Type レスポンスヘッダ | DS-1 + admin トークン | 1. GET /users のレスポンスヘッダ確認 | `Content-Type: application/json; charset=UTF-8` | 08-rest-api.md | 可 | P1 | □ | |
| API-094 | 整合性 | JSON / form 両ボディ受付 | DS-1 + admin トークン | 1. `Content-Type: application/json` で POST<br>2. `Content-Type: application/x-www-form-urlencoded` で POST | 両方で正常処理 | — | 可 | P2 | □ | |

## 集計表

| 分類 | 項目数 |
|---|---|
| 正常系 | 41 |
| 異常系 | 30 |
| 権限 | 15 |
| 整合性 | 4 |
| **合計** | **90** |

| 優先度 | 項目数 |
|---|---|
| P0 | 66 |
| P1 | 23 |
| P2 | 1 |
| **合計** | **90** |

### 設計書（12-implementation-test-plan.md A1〜A24）対応状況

| 設計参照 | 対応項目 | 状況 |
|---|---|---|
| A1（POST /auth/token） | API-001〜008 | 対応済 |
| A2（DELETE /auth/token） | API-009〜011 | 対応済 |
| A3（GET /users） | API-012〜014 | 対応済 |
| A4（POST /users） | API-019〜021 | 対応済 |
| A5（GET /users/:id） | API-015〜018 | 対応済 |
| A6（PUT /users/:id） | API-022〜026 | 対応済 |
| A7（DELETE /users/:id） | API-027〜029 | 対応済 |
| A8（POST /users/:id/courses） | API-035〜037 | 対応済 |
| A9（GET /groups） | API-064〜065 | 対応済 |
| A10（POST /groups） | API-078 | **ルート未存在**（404 到達確認） |
| A11（GET /groups/:id） | API-066〜068 | 対応済 |
| A12（PUT /groups/:id） | API-079 | **ルート未存在** |
| A13（DELETE /groups/:id） | API-080 | **ルート未存在** |
| A14（POST /groups/:id/users） | API-072〜074 | 対応済 |
| A15（GET /courses） | API-040〜042 | 対応済 |
| A16（POST /courses） | API-046〜048 | 対応済 |
| A17（GET /courses/:id） | API-043〜045 | 対応済 |
| A18（PUT /courses/:id） | API-052 | **ルート未存在** |
| A19（DELETE /courses/:id） | API-049〜051 | 対応済 |
| A20（GET /contents） | API-053〜054 | 対応済 |
| A21（GET /contents/:id） | API-055〜057 | 対応済 |
| A22（GET /records） | API-058〜059 | 対応済 |
| A23（GET /records/:id） | API-060〜063 | 対応済 |
| A24（catch-all 404） | API-081〜082 | 対応済 |

### 設計書 A1〜A24 に存在しないが routes.php に存在するエンドポイント（設計書要追記）

| 実エンドポイント | 対応項目 | 備考 |
|---|---|---|
| PUT/PATCH /users/:id/password | API-030〜031 | パスワード変更 |
| GET /users/:id/courses | API-032〜034 | ユーザー紐付けコース一覧 |
| DELETE /users/:id/courses/:course_id | API-038〜039 | コース紐付け解除 |
| GET /groups/:id/users | API-069〜071 | グループ所属ユーザー一覧 |
| DELETE /groups/:id/users/:user_id | API-075〜077 | グループユーザー解除 |

### routes.php 実装と README.md の相違点

| 項目 | README.md 記載 | routes.php 実装 |
|---|---|---|
| 総ルート数 | 30ルート | 28ルート（26 明示 + 2 catch-all） |
| コースルート数 | 4 | 4（一致） |
| グループルート数 | 5 | 5（一致） |
| ユーザールート数 | 11 | 11（一致） |
