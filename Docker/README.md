# Docker（CakePHP 5 版）

iroha Board を Docker で動かすための構成です。旧 CakePHP 2.10 時代の
`Dockerfile` / `docker-compose.yml` は廃止し、こちらの一式に置き換えました。

## 構成

| ファイル | 役割 |
| --- | --- |
| `Dockerfile.cakephp5` | php:8.4-apache ベースの Web イメージ（PHP 拡張・Apache 設定・php.ini・composer・アプリ本体＋vendor を同梱） |
| `entrypoint.sh` | 起動時に必要ディレクトリ作成・`uploads/.htaccess` 復元・所有権調整を行う |
| `docker-compose.cakephp5.yml` | Web + MariaDB の標準構成（開発用。`../app` を bind マウント） |
| `docker-compose.cakephp5-ldap.yml` | Web + MariaDB + OpenLDAP の構成（開発用。`../app` を bind マウント） |
| `docker-compose.hub.yml` | Docker Hub の公開イメージをそのまま起動する構成（同梱方式・clone 不要） |
| `apache-vhost.cakephp5.conf` | Apache vhost（DocumentRoot を `/var/www/html/webroot` に設定） |
| `apache-app.conf` | 追加の Apache 設定（conf-available に登録） |
| `mpm-prefork.conf` | prefork のワーカー数上限と定期再生成 |
| `php.ini` | PHP 設定（`conf.d/app.ini` として配置） |
| `ldap-init/01-users.ldif` | LDAP 初期ユーザ |
| `DOCKERHUB.md` | Docker Hub の Overview に貼る説明文 |

アプリ本体（CakePHP コード＋`vendor/`）は**イメージに焼き込み済み**です。
そのため pull するだけで起動でき、clone や `composer install` は不要です
（ネットワーク制限のある環境向け）。

リポジトリ直下は次の構成です。

```
irohaboard/
├── app/       # CakePHP アプリ本体（イメージに同梱。開発時は bind マウントで上書き）
├── Docker/    # このディレクトリ
└── Dev/       # 開発・検証用（テスト、ドキュメント、スクリプト）
```

## 使い方（ソースからビルドする場合）

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
`config/app_local.php` はイメージビルド時に `app_local.example.php` から生成され、
ランダムな `SECURITY_SALT` が焼き込まれます。

LDAP を併用する場合:

```bash
docker compose -f Docker/docker-compose.cakephp5-ldap.yml up -d --build
```

Web は `http://localhost:8083`、LDAP は `localhost:13389`（389）です。

## Docker Hub の公開イメージを使う（同梱方式）

公開イメージ: `yanma0102/irohaboard:latest`
（https://hub.docker.com/r/yanma0102/irohaboard）

このイメージには **PHP 8.4 + Apache + 必要拡張 + アプリ本体（CakePHP コード）+
`vendor/` + composer** が含まれます。clone も `composer install` も不要で、
`docker-compose.hub.yml` だけで起動できます。

```bash
docker compose -f Docker/docker-compose.hub.yml pull
docker compose -f Docker/docker-compose.hub.yml up -d
```

- `docker-compose.hub.yml` は `build` を持たず、`image:` と named volume だけで
  構成されています。アプリ本体はイメージ内の `/var/www/html` を使います。
- 初回は `http://localhost:8082/install` にアクセスしてインストールしてください。
- ソースを差し替えたい場合は、`docker-compose.cakephp5.yml` のように
  `../app` を `/var/www/html` に bind マウントすればイメージ内のアプリを上書きできます。

> 公開イメージを使う場合も、DB はローカルの `mariadb` コンテナ（`cakephp5-mysql-data`）
> に保存されます。`/install` はすべてコンテナ内で完結するため外部ネットワークは不要です。

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
| `cakephp5-uploads` | `/var/www/html/webroot/uploads` | 画像アップロード（hub 構成のみ） |
| `cakephp5-mysql-data` | `/var/lib/mysql` | DB データ |
| `cakephp5-ldap-data` / `cakephp5-ldap-config` | LDAP データ / 設定 | LDAP 使用時のみ |

`entrypoint.sh` が起動時に `/var/www/html/{tmp,logs,files,webroot/uploads}` を
作成し `www-data` へ chown します。`webroot/uploads` を volume で上書きした場合も
`uploads/.htaccess` を復元します。

## 注意

- 本番では `APP_FULL_BASE_URL` の設定が必須です（Host Header Injection 対策）。
- ソースからビルドする場合、`app/vendor/` と `app/config/app_local.php` は
  それぞれ同梱・生成されるため、事前の `composer install` は不要です。
- `SECURITY_SALT` はビルド時にランダム生成されます。環境変数 `SECURITY_SALT` を
  渡せばそちらが優先されます。
- 開発用 compose（`docker-compose.cakephp5*.yml`）はホットリロードのため
  `../app` を bind マウントし、イメージ内のアプリを上書きします。
