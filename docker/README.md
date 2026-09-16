# iroha Board Docker環境

iroha Board（CakePHP 2.10 ベース）を Docker / Docker Compose で起動するための環境です。
`docker/` ディレクトリ配下で完結します。

## 構成

| サービス | イメージ / ベース | 役割 | ポート |
|---------|------------------|------|--------|
| web | `php:8.1-apache`（CakePHP 2.10.24 同梱） | Apache + PHP でアプリを配信 | 8081 → 80 |
| db  | `mysql:5.7` | データベース | 3306 → 3306 |

- CakePHP 2.10 のコア（`lib/Cake`）はビルド時に GitHub から取得します。
- アプリ本体は `/var/www/html/app` に配置し、DocumentRoot は `/var/www/html/app/webroot` です。
- 開発しやすいよう、プロジェクトディレクトリとアプリディレクトリをバインドマウントしています。

## 必要なもの

- Docker Engine 20.10 以上
- Docker Compose V2（`docker compose` コマンド）

## 起動方法

```bash
cd docker
docker compose up -d --build
```

起動後、ブラウザで以下にアクセスします。

- アプリ: http://localhost:8081

## 初期インストール（必須）

初回はデータベースのテーブルが未作成のため、**インストーラー**を実行します。

1. http://localhost:8081/install にアクセス
2. 管理者のログインIDとパスワードを入力（英数字・4〜32文字）して「インストール」をクリック
3. テーブル作成と管理者アカウント作成が自動で行われます
4. 完了後、http://localhost:8081/ からログインできます

> トップページ（`/`）はインストール前だとエラーになりますが、`/install` は実行可能です。

### データベース接続情報

`Config/database.php` は環境変数を参照します（未設定時は従来の値を使用）。

| 項目 | 値 |
|------|----|
| ホスト | `db` |
| データベース名 | `irohaboard` |
| ユーザー | `root` |
| パスワード | `rootpass` |

環境変数 `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` で上書きできます。

## 停止・削除

```bash
# 停止
docker compose down

# 停止 + データ（DB・アップロード・tmp）も削除
docker compose down -v
```

## 開発時の操作

```bash
# ログ確認
docker compose logs -f web
docker compose logs -f db

# コンテナに入る
docker compose exec web bash
docker compose exec db mysql -uroot -prootpass irohaboard

# コード変更はバインドマウントに反映されます（PHPは即時反映）
```

## Docker関連ファイル

| ファイル | 説明 |
|---------|------|
| `Dockerfile` | PHP 8.1 + Apache + CakePHP 2.10.24 のイメージ定義 |
| `docker-compose.yml` | web / db サービスの定義 |
| `apache-vhost.conf` | DocumentRoot と `AllowOverride All` の設定 |
| `apache-app.conf` | アプリディレクトリのアクセス許可設定 |
| `php.ini` | PHP設定（文字コード・アップロード上限・タイムゾーン等） |

## トラブルシューティング

### ポートが使用中と言われる
`docker-compose.yml` の `ports`（`8081:80` / `3306:3306`）を空いているポートに変更してください。

### 「CakePHP core could not be found」
イメージビルドに失敗している可能性があります。再ビルドしてください。

```bash
docker compose down -v
docker compose up -d --build
```

### 「データベースへの接続に失敗しました」
db コンテナが healthy になるまで待ってから再アクセスしてください。

```bash
docker compose ps
docker compose logs db
```

### パーミッションエラー（アップロード・ログ）
tmp と files は名前付きボリュームで管理しています。作り直す場合:

```bash
docker compose down -v
docker compose up -d --build
```
