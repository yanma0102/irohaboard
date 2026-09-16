# iroha Board REST API v1 仕様書

## 1. 概要

iroha Board のデータを外部システムから参照・一部操作するための REST API です。

- ベース URL: `http(s)://<ホスト>/api/v1`
- データ形式: JSON（UTF-8）
- 認証方式: Bearer トークン
- バージョニング: URL パスに含む（`/api/v1`）

### 対応リソースと操作

| リソース | 参照 | 追加 | 削除 |
|---|:---:|:---:|:---:|
| users（ユーザ / 受講者） | ○ | ○ | ○ |
| courses（コース） | ○ | ○ | ○ |
| contents（コンテンツ） | ○ | - | - |
| records（学習履歴） | ○ | - | - |
| groups（グループ） | ○ | - | - |

> 更新（PUT/PATCH）は v1 では未対応です。

---

## 2. 認証

すべてのエンドポイント（トークン発行を除く）は、リクエストヘッダの Bearer トークンによる認証が必要です。

```
Authorization: Bearer <token>
```

トークンは `POST /api/v1/auth/token` で発行します。形式は `selector:validator`（32桁hex `:` 64桁hex）の不透明文字列で、サーバ側では validator をハッシュ化して `ib_user_tokens`（`token_type = 'api'`）に保存します。

- 有効期限: 既定 30 日（`api_token_expired_days` で変更可能）
- 失効: `DELETE /api/v1/auth/token` で使用中トークンを無効化
- 1ユーザが複数のトークンを持てます（端末・連携先ごとに発行可能）

### 2.1 トークンの発行

**`POST /api/v1/auth/token`**（認証不要）

リクエスト（`Content-Type: application/json`）:

```json
{
  "username": "admin",
  "password": "admin1234"
}
```

レスポンス `201 Created`:

```json
{
  "data": {
    "token": "d8066ad6a3d71327262068f1132fa1e4:73f10ff5cfab72ef1ea175374e58562c18f9895d36d554cb3e7df071bf8251f4",
    "token_type": "Bearer",
    "expires": "2026-10-16 22:28:15",
    "user": {
      "id": 1,
      "username": "admin",
      "name": "admin",
      "role": "admin"
    }
  }
}
```

| ステータス | 条件 |
|---|---|
| 201 | 発行成功 |
| 400 | `username` または `password` が未指定 |
| 401 | ユーザが存在しない / パスワード不一致 |
| 500 | トークンの保存に失敗 |

### 2.2 トークンの失効

**`DELETE /api/v1/auth/token`**（要認証）

レスポンス `200 OK`:

```json
{ "data": { "revoked": true } }
```

---

## 3. 共通仕様

### 3.1 リクエスト

- ボディは JSON（`Content-Type: application/json`）またはフォーム形式。
- クエリパラメータでフィルタ・ページングを指定します。
- 文字列フィルタ（`username`, `name`, `title`）は部分一致です。

### 3.2 レスポンス形式

**単一データ**

```json
{ "data": { "...": "..." } }
```

**一覧**

```json
{
  "data": [ { "...": "..." } ],
  "meta": {
    "page": 1,
    "limit": 50,
    "total": 120,
    "count": 50
  }
}
```

| meta | 説明 |
|---|---|
| `page` | 現在のページ番号 |
| `limit` | 1ページあたりの件数 |
| `total` | 条件に一致する総件数 |
| `count` | 今回返した件数 |

**エラー**

```json
{
  "error": {
    "code": 404,
    "message": "User not found"
  }
}
```

バリデーションエラー時は `errors` が付与されます。

```json
{
  "error": {
    "code": 400,
    "message": "Validation failed",
    "errors": {
      "username": ["ログインIDは4文字以上32文字以内で入力して下さい"],
      "name": ["氏名が入力されていません"]
    }
  }
}
```

### 3.3 ステータスコード

| コード | 意味 |
|---|---|
| 200 | 成功（参照・削除） |
| 201 | 成功（作成） |
| 400 | パラメータ不正 / バリデーション失敗 |
| 401 | 未認証（ヘッダなし・トークン不正・期限切れ） |
| 403 | 権限不足 |
| 404 | リソースが存在しない / ルート未定義 |
| 500 | サーバ内部エラー |

> 注: v1 では HTTP 422 は使用しません（実行基盤の制約により、バリデーション失敗も 400 で返します）。

### 3.4 ページング

| パラメータ | 既定 | 範囲 |
|---|---|---|
| `page` | 1 | 1 以上 |
| `limit` | 50 | 1〜200（超過分は 200 に丸め） |

### 3.5 認可

トークンに紐づくユーザの `role` によりアクセス範囲が変わります。

- **staff**（`admin` / `manager` / `editor` / `teacher`）: 全データを参照可能
- **user**: 自分に関連するデータのみ参照可能
- **書き込み**（users / courses の追加・削除）: `admin` / `manager` のみ

---

## 4. エンドポイント一覧

| メソッド | パス | 概要 | ロール |
|---|---|---|---|
| POST | `/api/v1/auth/token` | トークン発行 | 不要 |
| DELETE | `/api/v1/auth/token` | トークン失効 | 認証済 |
| GET | `/api/v1/users` | ユーザ一覧 | staff: 全件 / user: 自分のみ |
| GET | `/api/v1/users/{id}` | ユーザ詳細 | staff: 全件 / user: 自分のみ |
| POST | `/api/v1/users` | ユーザ追加 | admin / manager |
| DELETE | `/api/v1/users/{id}` | ユーザ削除 | admin / manager |
| GET | `/api/v1/courses` | コース一覧 | staff: 全件 / user: 受講可能のみ |
| GET | `/api/v1/courses/{id}` | コース詳細 | staff: 全件 / user: 受講可能のみ |
| POST | `/api/v1/courses` | コース追加 | admin / manager |
| DELETE | `/api/v1/courses/{id}` | コース削除 | admin / manager |
| GET | `/api/v1/contents` | コンテンツ一覧 | staff: 全件 / user: 受講可能かつ公開のみ |
| GET | `/api/v1/contents/{id}` | コンテンツ詳細 | staff: 全件 / user: 受講可能かつ公開のみ |
| GET | `/api/v1/records` | 学習履歴一覧 | staff: 任意 / user: 自分のみ |
| GET | `/api/v1/records/{id}` | 学習履歴詳細 | staff: 任意 / user: 自分のみ |
| GET | `/api/v1/groups` | グループ一覧 | staff: 全件 / user: 所属のみ |
| GET | `/api/v1/groups/{id}` | グループ詳細 | staff: 全件 / user: 所属のみ |

`{id}` は整数（`[0-9]+`）です。

---

## 5. ユーザ API（users）

### 5.1 一覧

**`GET /api/v1/users`**

クエリパラメータ:

| 名前 | 型 | 説明 |
|---|---|---|
| `username` | string | ログインID 部分一致 |
| `name` | string | 氏名 部分一致 |
| `role` | string | 権限 完全一致（admin / manager / editor / teacher / user） |
| `page` | int | ページ番号 |
| `limit` | int | 件数 |

`user` ロールの場合、`username` / `name` / `role` は無視され、常に自分のレコード 1 件を返します。

レスポンス `200 OK`:

```json
{
  "data": [
    {
      "id": 1,
      "username": "admin",
      "name": "admin",
      "role": "admin",
      "email": "",
      "comment": null,
      "last_logined": "2026-09-16 22:17:36",
      "started": null,
      "ended": null,
      "created": "2026-09-16 21:54:31",
      "modified": "2026-09-16 22:17:36"
    }
  ],
  "meta": { "page": 1, "limit": 50, "total": 1, "count": 1 }
}
```

> `password` は常に返しません。論理削除済み（`deleted` が非 NULL）のユーザは返しません。

### 5.2 詳細

**`GET /api/v1/users/{id}`**

- `user` ロールは自分自身以外を指定すると `403`。
- 存在しない場合は `404`。

レスポンス `200 OK`: `data` にユーザオブジェクト（全カラム。`password` を除く）。

```json
{
  "data": {
    "id": 1,
    "username": "admin",
    "name": "admin",
    "role": "admin",
    "email": "",
    "comment": null,
    "last_logined": "2026-09-16 22:17:36",
    "started": null,
    "ended": null,
    "created": "2026-09-16 21:54:31",
    "modified": "2026-09-16 22:17:36",
    "deleted": null
  }
}
```

### 5.3 追加

**`POST /api/v1/users`**（admin / manager）

リクエストボディ:

| 名前 | 必須 | 説明 |
|---|---|---|
| `username` | ○ | ログインID（英数字 4〜32文字・重複不可） |
| `password` | ○ | パスワード（英数字 4〜32文字） |
| `name` | ○ | 氏名 |
| `role` | ○ | 権限（admin / manager / editor / teacher / user） |
| `email` | - | メールアドレス |
| `comment` | - | 備考 |
| `started` | - | 受講開始日 |
| `ended` | - | 受講終了日 |

```json
{
  "username": "apiuser1",
  "password": "apipass1",
  "name": "API User 1",
  "role": "user",
  "email": "apiuser1@example.com"
}
```

レスポンス `201 Created`:

```json
{
  "data": {
    "username": "apiuser1",
    "name": "API User 1",
    "role": "user",
    "email": "apiuser1@example.com",
    "modified": "2026-09-16 22:30:34",
    "created": "2026-09-16 22:30:34",
    "id": "2"
  }
}
```

パスワードはモデル側で bcrypt ハッシュ化されます。バリデーション失敗時は `400`（`errors` 付き）。

### 5.4 削除

**`DELETE /api/v1/users/{id}`**（admin / manager）

- 存在しない場合は `404`。
- 自分自身のアカウントは削除不可（`400`）。

レスポンス `200 OK`:

```json
{ "data": { "id": 3, "deleted": true } }
```

> 物理削除です（`deleted` フラグによる論理削除ではありません）。学習履歴は削除されません。

---

## 6. コース API（courses）

### 6.1 一覧

**`GET /api/v1/courses`**

| 名前 | 型 | 説明 |
|---|---|---|
| `title` | string | タイトル 部分一致 |
| `page` / `limit` | int | ページング |

`user` ロールは受講可能なコース（`ib_users_courses` に登録、または所属グループに紐づくコース）のみ返します。該当が無い場合は空配列を返します。

レスポンス `200 OK`:

```json
{
  "data": [
    {
      "id": 1,
      "title": "API連携テストコース",
      "introduction": "created via API",
      "opened": null,
      "sort_no": 1,
      "comment": null,
      "user_id": 1,
      "created": "2026-09-16 22:30:35",
      "modified": "2026-09-16 22:30:35"
    }
  ],
  "meta": { "page": 1, "limit": 50, "total": 1, "count": 1 }
}
```

### 6.2 詳細

**`GET /api/v1/courses/{id}`**

- 存在しない場合、または `user` ロールで受講権限が無い場合は `404`。

レスポンス `200 OK`: `data` にコースオブジェクト（全カラム）。

### 6.3 追加

**`POST /api/v1/courses`**（admin / manager）

| 名前 | 必須 | 説明 |
|---|---|---|
| `title` | ○ | コース名 |
| `introduction` | - | コース概要 |
| `opened` | - | 公開日時 |
| `comment` | - | 備考 |
| `sort_no` | - | 並び順（未指定時は最大値+1） |

`user_id`（作成者）は認証ユーザが自動設定されます。

レスポンス `201 Created`:

```json
{
  "data": {
    "sort_no": 1,
    "title": "API連携テストコース",
    "introduction": "created via API",
    "user_id": 1,
    "modified": "2026-09-16 22:30:35",
    "created": "2026-09-16 22:30:35",
    "id": "1"
  }
}
```

### 6.4 削除

**`DELETE /api/v1/courses/{id}`**（admin / manager）

コースに紐づくコンテンツ（`ib_contents`）およびテスト問題（`ib_contents_questions`）も同時に削除されます。

レスポンス `200 OK`:

```json
{ "data": { "id": 2, "deleted": true } }
```

---

## 7. コンテンツ API（contents）

### 7.1 一覧

**`GET /api/v1/contents`**

| 名前 | 型 | 説明 |
|---|---|---|
| `course_id` | int | コースで絞り込み |
| `kind` | string | 種別で絞り込み（例: `test`） |
| `status` | int | 公開状態（1: 公開）※ `user` ロールでは常に 1 固定 |
| `page` / `limit` | int | ページング |

`user` ロールは受講可能コースかつ公開（`status = 1`）のコンテンツのみ返します。

レスポンス `200 OK` の `data` 要素:

| フィールド | 型 | 説明 |
|---|---|---|
| `id` | int | コンテンツID |
| `course_id` | int | コースID |
| `user_id` | int | 作成者ID |
| `title` | string | タイトル |
| `url` | string\|null | URL |
| `file_name` | string\|null | ファイル名 |
| `kind` | string | 種別 |
| `body` | string\|null | 本文 |
| `timelimit` | int\|null | 制限時間 |
| `pass_rate` | int\|null | 合格率 |
| `question_count` | int\|null | 出題数 |
| `wrong_mode` | int | 誤答モード |
| `status` | int | 公開状態 |
| `opened` | datetime\|null | 公開日時 |
| `sort_no` | int | 並び順 |
| `created` / `modified` | datetime | 作成／更新日時 |

### 7.2 詳細

**`GET /api/v1/contents/{id}`**

- 存在しない場合、または `user` ロールで受講権限が無い／非公開の場合は `404`。

レスポンス `200 OK`: `data` にコンテンツオブジェクト。

---

## 8. 学習履歴 API（records）

### 8.1 一覧

**`GET /api/v1/records`**

| 名前 | 型 | 説明 |
|---|---|---|
| `user_id` | int | 受講者で絞り込み（`user` ロールでは無視され自分固定） |
| `course_id` | int | コースで絞り込み |
| `content_id` | int | コンテンツで絞り込み |
| `from` | datetime | `created >= from` |
| `to` | datetime | `created <= to` |
| `page` / `limit` | int | ページング |

`user` ロールは常に自分のレコードのみ返します（`user_id` を指定しても無視）。

レスポンス `200 OK` の `data` 要素:

| フィールド | 型 | 説明 |
|---|---|---|
| `id` | int | 履歴ID |
| `course_id` | int | コースID |
| `user_id` | int | 受講者ID |
| `content_id` | int | コンテンツID |
| `full_score` | int | 満点 |
| `pass_score` | int\|null | 合格点 |
| `score` | int\|null | 得点 |
| `is_passed` | int | 合格フラグ |
| `is_complete` | int\|null | 完了フラグ |
| `progress` | int | 進捗 |
| `understanding` | int\|null | 理解度 |
| `study_sec` | int\|null | 学習時間（秒） |
| `created` | datetime | 受講日時 |

### 8.2 詳細

**`GET /api/v1/records/{id}`**

- 存在しない場合は `404`。
- `user` ロールで他人のレコードを指定した場合は `403`。

レスポンス `200 OK`: `data` に履歴オブジェクト。

---

## 9. グループ API（groups）

### 9.1 一覧

**`GET /api/v1/groups`**

| 名前 | 型 | 説明 |
|---|---|---|
| `title` | string | タイトル 部分一致 |
| `status` | int | ステータス 完全一致 |
| `page` / `limit` | int | ページング |

`user` ロールは所属グループのみ返します。

レスポンス `200 OK` の `data` 要素:

| フィールド | 型 | 説明 |
|---|---|---|
| `id` | int | グループID |
| `title` | string | グループ名 |
| `comment` | string\|null | 備考 |
| `status` | int | ステータス |
| `logo` | string\|null | ロゴ |
| `copyright` | string\|null | コピーライト |
| `module` | string | モジュール |
| `created` / `modified` | datetime | 作成／更新日時 |

### 9.2 詳細

**`GET /api/v1/groups/{id}`**

- 存在しない場合、または `user` ロールで未所属の場合は `404`。

レスポンス `200 OK`: `data` にグループオブジェクト。

---

## 10. 使用例

```bash
BASE=http://localhost:8081/api/v1

# トークン発行
TOKEN=$(curl -s -X POST $BASE/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin1234"}' \
  | grep -oP '"token":"\K[^"]+')

# ユーザ一覧（ページング）
curl -s "$BASE/users?limit=20&page=1" -H "Authorization: Bearer $TOKEN"

# ユーザ追加
curl -s -X POST "$BASE/users" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"username":"apiuser1","password":"apipass1","name":"API User 1","role":"user"}'

# ユーザ削除
curl -s -X DELETE "$BASE/users/2" -H "Authorization: Bearer $TOKEN"

# コース一覧（タイトル部分一致）
curl -s "$BASE/courses?title=テスト" -H "Authorization: Bearer $TOKEN"

# 学習履歴（期間指定）
curl -s "$BASE/records?from=2026-09-01%2000:00:00&to=2026-09-30%2023:59:59" \
  -H "Authorization: Bearer $TOKEN"

# トークン失効
curl -s -X DELETE "$BASE/auth/token" -H "Authorization: Bearer $TOKEN"
```

---

## 11. 制限・注意事項

| 項目 | 内容 |
|---|---|
| 更新操作 | v1 では未対応（参照と追加・削除のみ） |
| 422 | 使用しない（バリデーション失敗は 400） |
| 監査ログ | API 経由の操作は `ib_logs` に記録されません |
| レート制限 | `POST /auth/token` に試行回数制限はありません（Webログインにはあり） |
| パスワード変更 | API トークンは自動失効しません |
| 権限 | `manager` でも `admin` ユーザの作成・削除が可能です |
| 詳細レスポンス | 一部の詳細 API は `deleted` などの内部カラムを含みます |
| 通信 | 本番環境では HTTPS を使用し、トークンを保護してください |
| 最終ログイン | API 利用は `last_logined` を更新しません（トークンの `last_used` のみ更新） |

---

## 12. 変更履歴

| バージョン | 日付 | 内容 |
|---|---|---|
| v1 | 2026-09-16 | 初版（auth / users / courses / contents / records / groups） |
