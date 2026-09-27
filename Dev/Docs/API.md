# iroha Board REST API v1 仕様書

## 1. 概要

iroha Board のデータを外部システムから参照・一部操作するための REST API です。

- ベース URL: `http(s)://<ホスト>/api/v1`
- データ形式: JSON（UTF-8）
- 認証方式: Bearer トークン
- バージョニング: URL パスに含む（`/api/v1`）

### 対応リソースと操作

| リソース | 参照 | 追加 | 更新 | 削除 |
|---|:---:|:---:|:---:|:---:|
| users（ユーザ / 受講者） | ○ | ○ | ○ | ○ |
| courses（コース） | ○ | ○ | - | ○ |
| contents（コンテンツ） | ○ | ○ | ○ | ○ |
| records（学習履歴） | ○ | - | - | - |
| groups（グループ） | ○ | - | - | - |

関連操作:

- ユーザのパスワード変更: `PUT /api/v1/users/{id}/password`
- ユーザのコース割当 / 解除: `POST /api/v1/users/{id}/courses` / `DELETE /api/v1/users/{id}/courses/{course_id}`
- グループのユーザ割当 / 解除: `POST /api/v1/groups/{id}/users` / `DELETE /api/v1/groups/{id}/users/{user_id}`
- MCP エンドポイント（エージェント連携）: `POST /mcp`（§12）

---

## 2. 認証

すべてのエンドポイント（トークン発行を除く）は、リクエストヘッダの Bearer トークンによる認証が必要です。

```
Authorization: Bearer <token>
```

トークンは `POST /api/v1/auth/token` で発行します。形式は `selector:validator`（32桁hex `:` 64桁hex）の不透明文字列で、サーバ側では validator をハッシュ化して `ib_user_tokens`（`token_type = 'api'`）に保存します。

- 有効期限: 既定 30 日（`api_token_expired_days` で変更可能）
- 永続トークン: `permanent: true` を指定すると無期限（期限 `9999-12-31 23:59:59`）で発行できます（**admin のみ**）
- 失効: `DELETE /api/v1/auth/token` で使用中トークンを無効化
- 1ユーザが複数のトークンを持てます（端末・連携先ごとに発行可能）
- パスワード変更時は、そのユーザの API トークンと Remember Me トークンは自動的に失効します

### 2.1 トークンの発行

**`POST /api/v1/auth/token`**（認証不要）

リクエスト（`Content-Type: application/json`）:

```json
{
  "username": "admin",
  "password": "admin1234",
  "permanent": false
}
```

| 名前 | 必須 | 説明 |
|---|---|---|
| `username` | ○ | ログインID |
| `password` | ○ | パスワード |
| `permanent` | - | `true` で無期限トークンを発行（admin のみ。省略時 false） |

レスポンス `201 Created`:

```json
{
  "data": {
    "token": "d8066ad6a3d71327262068f1132fa1e4:73f10ff5cfab72ef1ea175374e58562c18f9895d36d554cb3e7df071bf8251f4",
    "token_type": "Bearer",
    "permanent": false,
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

永続トークンの場合:

```json
{
  "data": {
    "token": "...",
    "token_type": "Bearer",
    "permanent": true,
    "expires": "9999-12-31 23:59:59",
    "user": { "id": 1, "username": "admin", "name": "admin", "role": "admin" }
  }
}
```

| ステータス | 条件 |
|---|---|
| 201 | 発行成功 |
| 400 | `username` または `password` が未指定 |
| 401 | ユーザが存在しない / パスワード不一致 |
| 403 | `permanent: true` を admin 以外が要求 |
| 429 | ログイン失敗が上限（1時間に10回）に達した |
| 500 | トークンの保存に失敗 |

> ログイン失敗は `ib_logs`（`log_type = 'api_login_error'`）に記録され、同一ログインIDで1時間に10回失敗すると以降は `429` を返します。

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
- 文字列フィルタ（`username`, `name`, `title`）は既定で部分一致です。
- `exact=1` を付けると、文字列フィルタを完全一致に変更できます（部分一致による意図しない一致の回避）。

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
| 200 | 成功（参照・更新・削除・割当済みの再登録） |
| 201 | 成功（作成・新規割当） |
| 400 | パラメータ不正 / バリデーション失敗 |
| 401 | 未認証（ヘッダなし・トークン不正・期限切れ） |
| 403 | 権限不足 |
| 404 | リソースが存在しない / 未定義エンドポイント / メソッド不一致 |
| 429 | ログイン試行回数の上限超過 |
| 500 | サーバ内部エラー |

> 注:
> - v1 では HTTP 422 は使用しません（実行基盤の制約により、バリデーション失敗も 400 で返します）。
> - 未定義の `/api/v1/...` や HTTP メソッドが一致しないリクエストも JSON の 404（`"message": "Endpoint not found"`）を返します（405 は使用しません）。

### 3.4 ページング

| パラメータ | 既定 | 範囲 |
|---|---|---|
| `page` | 1 | 1 以上 |
| `limit` | 50 | 1〜200（超過分は 200 に丸め） |

### 3.5 認可

トークンに紐づくユーザの `role` によりアクセス範囲が変わります。

- **staff**（`admin` / `manager` / `editor` / `teacher`）: 全データを参照可能
- **user**: 自分に関連するデータのみ参照可能（自分自身の情報、自分の学習履歴、受講可能なコース・コンテンツ、所属グループ、自分に割り当てられたコース）
- **書き込み**（users / courses の追加・更新・削除、パスワード変更、コース／グループの割当・解除）: `admin` / `manager` のみ
- **コンテンツ書き込み**（contents の追加・更新・削除）: `admin` / `manager` / `editor` / `teacher`（staff）+ 対象コースへのアクセス権

追加の保護:

- `manager` は `admin` アカウントの更新・パスワード変更はできません（`admin` のみ可能）。
- `admin` へのロール変更は `admin` のみ可能です。
- 自分自身の `role` は変更できません（ロックアウト防止）。

---

## 4. エンドポイント一覧

| メソッド | パス | 概要 | ロール |
|---|---|---|---|
| POST | `/api/v1/auth/token` | トークン発行 | 不要 |
| DELETE | `/api/v1/auth/token` | トークン失効 | 認証済 |
| GET | `/api/v1/users` | ユーザ一覧 | staff: 全件 / user: 自分のみ |
| GET | `/api/v1/users/{id}` | ユーザ詳細 | staff: 全件 / user: 自分のみ |
| POST | `/api/v1/users` | ユーザ追加 | admin / manager |
| PUT / PATCH | `/api/v1/users/{id}` | ユーザ更新 | admin / manager |
| PUT / PATCH | `/api/v1/users/{id}/password` | パスワード変更 | admin / manager |
| DELETE | `/api/v1/users/{id}` | ユーザ削除 | admin / manager |
| GET | `/api/v1/users/{id}/courses` | 割当済みコース一覧 | staff: 全件 / user: 自分のみ |
| POST | `/api/v1/users/{id}/courses` | コース割当 | admin / manager |
| DELETE | `/api/v1/users/{id}/courses/{course_id}` | コース割当解除 | admin / manager |
| GET | `/api/v1/courses` | コース一覧 | staff: 全件 / user: 受講可能のみ |
| GET | `/api/v1/courses/{id}` | コース詳細 | staff: 全件 / user: 受講可能のみ |
| POST | `/api/v1/courses` | コース追加 | admin / manager |
| DELETE | `/api/v1/courses/{id}` | コース削除 | admin / manager |
| GET | `/api/v1/contents` | コンテンツ一覧 | staff: 全件 / user: 受講可能かつ公開のみ |
| GET | `/api/v1/contents/{id}` | コンテンツ詳細 | staff: 全件 / user: 受講可能かつ公開のみ |
| POST | `/api/v1/contents` | コンテンツ追加 | staff |
| PUT / PATCH | `/api/v1/contents/{id}` | コンテンツ更新 | staff + コース権限 |
| DELETE | `/api/v1/contents/{id}` | コンテンツ削除 | staff + コース権限 |
| GET | `/api/v1/records` | 学習履歴一覧 | staff: 任意 / user: 自分のみ |
| GET | `/api/v1/records/{id}` | 学習履歴詳細 | staff: 任意 / user: 自分のみ |
| GET | `/api/v1/groups` | グループ一覧 | staff: 全件 / user: 所属のみ |
| GET | `/api/v1/groups/{id}` | グループ詳細 | staff: 全件 / user: 所属のみ |
| GET | `/api/v1/groups/{id}/users` | グループ所属ユーザ一覧 | staff: 全件 / user: 所属のみ |
| POST | `/api/v1/groups/{id}/users` | グループへユーザ割当 | admin / manager |
| DELETE | `/api/v1/groups/{id}/users/{user_id}` | グループのユーザ割当解除 | admin / manager |

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

### 5.5 更新

**`PUT /api/v1/users/{id}`** / **`PATCH /api/v1/users/{id}`**（admin / manager）

| 名前 | 必須 | 説明 |
|---|---|---|
| `role` | - | 権限（admin / manager / editor / teacher / user）。`admin` への変更は admin のみ |
| `name` | - | 氏名 |
| `email` | - | メールアドレス |
| `comment` | - | 備考 |
| `started` | - | 受講開始日 |
| `ended` | - | 受講終了日 |

- 指定した項目のみ更新します（部分更新）。
- 更新可能な項目が1つも無い場合は `400`。
- 自分自身の `role` は変更できません（`403`）。
- `manager` は `admin` アカウントを変更できません（`403`）。

例:

```json
{ "role": "manager", "name": "更新後の名前" }
```

レスポンス `200 OK`: `data` に更新後のユーザオブジェクト（`password` を除く）。

```json
{ "data": { "name": "更新後の名前", "email": "u3@example.com", "id": 5, "modified": "2026-09-16 22:52:02" } }
```

### 5.6 パスワード変更

**`PUT /api/v1/users/{id}/password`** / **`PATCH /api/v1/users/{id}/password`**（admin / manager）

| 名前 | 必須 | 説明 |
|---|---|---|
| `password` | ○ | 新しいパスワード（英数字 4〜32文字） |
| `new_password` | - | `password` の別名（ポータル互換。`password` が無い場合に使用） |

例:

```json
{ "password": "newpass3" }
```

レスポンス `200 OK`:

```json
{ "data": { "id": 5, "password_changed": true } }
```

- 変更対象ユーザの API トークン・Remember Me トークンは失効します。
- `manager` は `admin` のパスワードを変更できません（`403`）。

### 5.7 コース割当 / 解除 / 一覧

**`GET /api/v1/users/{id}/courses`**
そのユーザに割り当てられたコース一覧を返します。`user` ロールは自分のみ参照可能。

**`POST /api/v1/users/{id}/courses`**（admin / manager）

| 名前 | 必須 | 説明 |
|---|---|---|
| `course_id` | ○ | 割り当てるコースID |

- 新規割当は `201`、すでに割当済みなら `200`（冪等）。
- ユーザ / コースが存在しない場合は `404`。

**`DELETE /api/v1/users/{id}/courses/{course_id}`**（admin / manager）
割当を解除します。未割当でも `200`（`"deleted": false`）。

レスポンス例:

```json
{ "data": { "user_id": 5, "course_id": 3, "assigned": true, "created": true } }
{ "data": { "user_id": 5, "course_id": 3, "deleted": true } }
```

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

### 7.3 追加

**`POST /api/v1/contents`**（staff: admin / manager / editor / teacher）

| 名前 | 必須 | 説明 |
|---|---|---|
| `course_id` | ○ | コースID（アクセス可能コースであること） |
| `title` | ○ | タイトル（最大200文字） |
| `kind` | ○ | 種別（`content_kind` の許可値） |
| `body` | ○※ | 本文（`kind` が `text`/`html`/`markdown` の場合に必須） |
| `status` | - | 公開状態（未指定時は 0: 非公開） |
| `sort_no` | - | 並び順（未指定時は自動採番） |
| `url` | - | URL |
| `file_name` | - | ファイル名 |
| `comment` | - | 備考 |

`user_id`（作成者）は認証ユーザが自動設定されます（クライアント指定は無視）。

レスポンス `201 Created`:

```json
{
  "data": {
    "id": 42,
    "course_id": 1,
    "user_id": 3,
    "title": "第1章 イントロダクション",
    "kind": "markdown",
    "body": "# イントロ\n\nこれは **Markdown** 教材です。",
    "status": 0,
    "sort_no": 5,
    "created": "2026-09-24T10:00:00+09:00",
    "modified": "2026-09-24T10:00:00+09:00"
  }
}
```

| ステータス | 条件 |
|---|---|
| 201 | 作成成功 |
| 400 | パラメータ不正 / バリデーション失敗（`course_id` 未指定、`title` 未指定、無効な `kind` 等） |
| 401 | 未認証 |
| 403 | スタッフ以外 / アクセス権外コース |

### 7.4 更新

**`PUT /api/v1/contents/{id}`** / **`PATCH /api/v1/contents/{id}`**（staff + コース権限）

| 名前 | 必須 | 説明 |
|---|---|---|
| `course_id` | - | コースID（変更先コースへのアクセス権も確認） |
| `title` | - | タイトル |
| `kind` | - | 種別 |
| `body` | - | 本文 |
| `status` | - | 公開状態 |
| `sort_no` | - | 並び順 |
| `url` | - | URL |
| `file_name` | - | ファイル名 |
| `comment` | - | 備考 |

- PUT は全置換、PATCH は部分更新（指定フィールドのみ）。
- 指定したフィールドが1つもない場合は `400`。
- `user_id` は更新不可（クライアント指定は無視）。

レスポンス `200 OK`: `data` に更新後のコンテンツオブジェクト。

### 7.5 削除

**`DELETE /api/v1/contents/{id}`**（staff + コース権限）

- 物理削除です。紐づくテスト問題（`ib_contents_questions`）も同時に削除されます。
- 存在しない場合は `404`。

レスポンス `200 OK`:

```json
{ "data": { "id": 42, "deleted": true } }
```

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

### 9.3 ユーザ割当 / 解除 / 一覧

**`GET /api/v1/groups/{id}/users`**
グループに所属するユーザ一覧を返します。`user` ロールは所属グループのみ参照可能。

**`POST /api/v1/groups/{id}/users`**（admin / manager）

| 名前 | 必須 | 説明 |
|---|---|---|
| `user_id` | ○ | 所属させるユーザID |

- 新規割当は `201`、すでに所属している場合は `200`（冪等）。
- グループ / ユーザが存在しない場合は `404`。

**`DELETE /api/v1/groups/{id}/users/{user_id}`**（admin / manager）
所属を解除します。未所属でも `200`（`"deleted": false`）。

レスポンス例:

```json
{ "data": { "group_id": 1, "user_id": 5, "assigned": true, "created": true } }
{ "data": { "group_id": 1, "user_id": 5, "deleted": true } }
```

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

# ユーザ更新（role / name 変更）
curl -s -X PUT "$BASE/users/2" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"role":"manager","name":"更新後の名前"}'

# パスワード変更
curl -s -X PUT "$BASE/users/2/password" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"password":"newpass1"}'

# コース割当 / 一覧 / 解除
curl -s -X POST "$BASE/users/2/courses" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"course_id":1}'
curl -s "$BASE/users/2/courses" -H "Authorization: Bearer $TOKEN"
curl -s -X DELETE "$BASE/users/2/courses/1" -H "Authorization: Bearer $TOKEN"

# グループへユーザ割当 / 解除
curl -s -X POST "$BASE/groups/1/users" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"user_id":2}'
curl -s -X DELETE "$BASE/groups/1/users/2" -H "Authorization: Bearer $TOKEN"

# 完全一致検索（部分一致の衝突回避）
curl -s "$BASE/users?username=admin&exact=1" -H "Authorization: Bearer $TOKEN"

# 無期限トークン発行（admin のみ）
curl -s -X POST "$BASE/auth/token" \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin1234","permanent":true}'

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
| 更新操作 | users / contents が対応。courses / records / groups の更新は未対応 |
| 422 | 使用しない（バリデーション失敗は 400） |
| 監査ログ | 参照・更新・削除などの操作は `ib_logs` に記録されません（ログイン失敗のみ記録） |
| レート制限 | `POST /auth/token` は同一ログインIDで1時間に10回失敗すると `429` |
| パスワード変更 | 変更対象ユーザの API / Remember Me トークンは自動失効します |
| 権限 | `manager` は `admin` アカウントの更新・パスワード変更は不可（作成・削除は可能） |
| 本人操作 | 自分自身のロール変更・自分のアカウント削除はできません |
| 永続トークン | `permanent: true` は admin のみ。失効は `DELETE /auth/token` または管理者によるパスワード変更 |
| 詳細レスポンス | 一部の詳細 API は `deleted` などの内部カラムを含みます |
| ID | 作成・割当レスポンスの ID は数値（int）です |
| 通信 | 本番環境では HTTPS を使用し、トークンを保護してください |
| 最終ログイン | API 利用は `last_logined` を更新しません（トークンの `last_used` のみ更新） |

---

## 12. MCP エンドポイント（`/mcp`）

design 13（Markdown / MCP）により、エージェント連携用の MCP（Model Context Protocol）エンドポイントを追加しました。これは従来の REST API とは別プロトコル（JSON-RPC 2.0 over Streamable HTTP）ですが、同一サーバ・同一トークンで利用できます。

- **パス**: `POST /mcp`
- **認証**: `Authorization: Bearer <APIトークン>`（`/api/v1` と同じトークンを流用）
- **トランスポート**: MCP Streamable HTTP（JSON-RPC 2.0 メッセージを `POST` で送信）。`GET /mcp` は `405`（`Allow: POST, DELETE, OPTIONS`）
- **セッション**: サーバ側で MCP セッションを管理（`tmp/mcp-sessions`、TTL 3600 秒）
- **CSRF**: `/mcp` はフォームの CSRF トークン検証対象外（Bearer 認証のため）

### 12.1 ツール一覧（9 種）

| ツール名 | 種別 | 概要 | 認可 |
|---|---|---|---|
| `list_courses` | 読み取り | コース一覧 | 所属（直接 / グループ） |
| `get_course` | 読み取り | コース詳細 | 所属 |
| `list_contents` | 読み取り | コンテンツ一覧 | 所属 |
| `get_content` | 読み取り | コンテンツ取得（Markdown は生のまま） | 所属 |
| `get_content_html` | 読み取り | コンテンツ取得（HTML 出力、サニタイズ済み） | 所属 |
| `list_records` | 読み取り | 学習履歴一覧 | 所属 |
| `get_user_profile` | 読み取り | 自ユーザプロフィール | 認証済 |
| `create_content` | 書き込み | コンテンツ追加 | staff: admin / manager / editor / teacher |
| `update_content` | 書き込み | コンテンツ更新 | staff + コース権限 |

### 12.2 レート制限

| 区分 | 制限 | `ib_logs` の log_type |
|---|---|---|
| 読み取り | 60 リクエスト / 分 / ユーザ | `mcp_request` |
| 書き込み | 20 リクエスト / 分 / ユーザ | `mcp_write` |

- 読み取りと書き込みは別カウンター。超過時は `429` + `Retry-After: 60`
- 詳細は `Docs/design/05-security.md` §7、試験項目は `Docs/test/03-api.md` §11（MCP-001〜015）

---

## 13. 変更履歴

| バージョン | 日付 | 内容 |
|---|---|---|
| v1 | 2026-09-16 | 初版（auth / users / courses / contents / records / groups の参照・一部追加削除） |
| v1.1 | 2026-09-16 | ユーザ更新、パスワード変更、コース割当/解除、グループのユーザ割当/解除、完全一致検索（`exact=1`）、永続トークン、ログイン試行制限、ID の数値統一、未定義パスの JSON 404 |
| v1.2 | 2026-09-24 | コンテンツの追加・更新・削除（POST / PUT / PATCH / DELETE）、staff 権限でのみ操作可能 |
| v1.3 | 2026-09-25 | MCP エンドポイント追加（POST /mcp、MCP Streamable HTTP、ツール 9 種、レート制限 read 60/min・write 20/min） |
