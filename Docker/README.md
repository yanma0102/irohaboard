# Docker（CakePHP 5 版）

iroha Board を Docker で動かすための構成です。旧 CakePHP 2.10 時代の
`Dockerfile` / `docker-compose.yml` は廃止し、こちらの一式に置き換えました。

## 構成

| ファイル | 役割 |
| --- | --- |
| `Dockerfile.cakephp5` | php:8.4-apache ベースの Web イメージ（PHP 拡張・Apache 設定・php.ini を同梱） |
| `docker-compose.cakephp5.yml` | Web + MariaDB の標準構成 |
| `docker-compose.cakephp5-ldap.yml` | Web + MariaDB + OpenLDAP の構成 |
| `apache-vhost.cakephp5.conf` | Apache vhost（DocumentRoot を `/var/www/html/webroot` に設定） |
| `apache-app.conf` | 追加の Apache 設定（conf-available に登録） |
| `mpm-prefork.conf` | prefork のワーカー数上限と定期再生成 |
| `php.ini` | PHP 設定（`conf.d/app.ini` として配置） |
| `ldap-init/01-users.ldif` | LDAP 初期ユーザ |
| `docker-compose.hub.yml` | Docker Hub の公開イメージを使って起動する構成（マウント方式） |
| `DOCKERHUB.md` | Docker Hub の Overview に貼る説明文 |

アプリ本体はイメージに**焼き込まず**、`../app` を `/var/www/html` に
bind マウントして供給します（開発時のホットリロード用）。リポジトリ直下が

```
irohaboard/
├── app/       # CakePHP アプリ本体（bind マウントされる）
├── Docker/    # このディレクトリ
└── Dev/       # 開発・検証用（テスト、ドキュメント、スクリプト）
```

という構成であることが前提です。

## 使い方

```bash
# ビルドして起動（Web: http://localhost:8082, DB: 13307）
docker compose -f Docker/docker-compose.cakephp5.yml up -d --build

# 停止
docker compose -f Docker/docker-compose.cakephp5.yml down

# ログ
docker compose -f Docker/docker-compose.cakephp5.yml logs -f web
```

初回は `http://localhost:8082/install` にアクセスしてインストールを実行します。
DB 接続情報は compose の環境変数（`DB_HOST=db` など）で渡されます。

LDAP を併用する場合:

```bash
docker compose -f Docker/docker-compose.cakephp5-ldap.yml up -d --build
```

Web は `http://localhost:8083`、LDAP は `localhost:13389`（389）です。

## Docker Hub の公開イメージを使う（マウント方式）

公開イメージ: `yanma0102/irohaboard:latest`
（https://hub.docker.com/r/yanma0102/irohaboard）

このイメージは **PHP 8.4 + Apache + 必要拡張 + Apache/PHP 設定のみ** を同梱した
Web ランタイムです。アプリ本体（CakePHP コード）は含まないため、リポジトリを
clone し、`app/` を `/var/www/html` にマウントして使います。

```bash
git clone https://github.com/yanma0102/irohaboard.git
cd irohaboard

# 依存パッケージの取得（初回は config/app_local.php も生成される）
composer install --working-dir=app
# ホストに composer が無い場合は composer イメージを使う:
# docker run --rm -v "$PWD/app:/app" -w /app composer:2 install

# 公開イメージを取得して起動（アプリ本体はマウント供給）
docker compose -f Docker/docker-compose.hub.yml pull
docker compose -f Docker/docker-compose.hub.yml up -d
```

- `docker-compose.hub.yml` は `build` を持たず、`image:` と `../app` のマウントだけを
  定義した構成です。
- 標準の `docker-compose.cakephp5.yml` / `docker-compose.cakephp5-ldap.yml` も
  `image:` を持つため、`docker compose pull web` で公開イメージを取得できます
  （`--build` を付ければローカルビルド）。

### イメージの公開（メンテナ向け）

```bash
docker login -u yanma0102
docker build -f Docker/Dockerfile.cakephp5 -t yanma0102/irohaboard:latest .
docker push yanma0102/irohaboard:latest
```

> Docker Hub の Overview（説明文）は `DOCKERHUB.md` の内容を貼り付けて更新します。

## 永続化（named volume）

| volume | マウント先 | 用途 |
| --- | --- | --- |
| `cakephp5-tmp` | `/var/www/html/tmp` | CakePHP のキャッシュ・セッション等 |
| `cakephp5-files` | `/var/www/html/files` | コンテンツ添付ファイル |
| `cakephp5-mysql-data` | `/var/lib/mysql` | DB データ |
| `cakephp5-ldap-data` / `cakephp5-ldap-config` | LDAP データ / 設定 | LDAP 使用時のみ |

`webroot/uploads`（画像）はホスト bind のため、起動時に `www-data` へ
chown されます。

## 注意

- 本番では `APP_FULL_BASE_URL` の設定が必須です（Host Header Injection 対策）。
- アプリ本体は bind マウントのため、`composer install` は `app/` で実行してください。
- 公開イメージはアプリ本体を含まない Web ランタイムです。利用者側でリポジトリを clone し、
  依存パッケージ（`vendor/`）と `config/app_local.php` を用意してください。
