# iroha Board

iroha Board は PHP / CakePHP で作られたオープンソースの eラーニング（LMS）システムです。
本イメージは、iroha Board を Docker で動かすための **Web ランタイム** です。

- ソースコード: https://github.com/yanma0102/irohaboard
- 対応バージョン: CakePHP 5.4 / PHP 8.4

## このイメージに含まれるもの

- PHP 8.4（Apache `php:8.4-apache` ベース）
- PHP 拡張: `pdo_mysql` `mbstring` `gd` `intl` `opcache` `zip` `bcmath` `ldap`
- Composer 2（コンテナ内で `composer install` を実行可能）
- Apache 設定（`mod_rewrite` / `mod_headers` 有効、`DocumentRoot=/var/www/html/webroot`）
- `php.ini` / MPM prefork 設定

## 含まれないもの（重要）

- **iroha Board のアプリ本体（CakePHP のコード）は含まれません。**
  このイメージはアプリを動かすための実行環境のみを提供します。
- 利用時に GitHub リポジトリを clone し、`app/` ディレクトリを `/var/www/html` に
  マウントしてください（マウント方式）。
- 依存パッケージ（`app/vendor/`）と `app/config/app_local.php` もリポジトリ側で用意します。

## 使い方

### 1. リポジトリの取得

```bash
git clone https://github.com/yanma0102/irohaboard.git
cd irohaboard
```

### 2. 起動（推奨: docker compose）

```bash
docker compose -f Docker/docker-compose.hub.yml pull
docker compose -f Docker/docker-compose.hub.yml up -d
```

Web: http://localhost:8082

初回起動時に `app/vendor/autoload.php` が無ければ、コンテナ内の composer が
自動的に `composer install` を実行します（`app/config/app_local.php` も生成されます）。
手動で実行する場合:

```bash
docker compose -f Docker/docker-compose.hub.yml exec web \
  composer install --no-interaction --prefer-dist --optimize-autoloader
```

初回は `http://localhost:8082/install` にアクセスしてインストールを実行します。
（MariaDB は同時に起動し、ホスト側 `13307` に公開されます。）

### タグ

| タグ | 説明 |
| --- | --- |
| `latest` | 最新の Web ランタイム |

## 環境変数

`app/config/app_local.php` を通して次の環境変数を読み取ります。compose ファイルで設定してください。

| 変数 | 既定値 | 説明 |
| --- | --- | --- |
| `DB_HOST` | `127.0.0.1` | DB ホスト（compose では `db`） |
| `DB_PORT` | `3306` | DB ポート |
| `DB_NAME` | `irohaboard` | データベース名 |
| `DB_USER` | `root` | DB ユーザー |
| `DB_PASS` | `rootpass` | DB パスワード |
| `APP_FULL_BASE_URL` | （未設定） | 公開 URL。本番では必須（Host Header Injection 対策） |
| `DEBUG` | `true` | 本番では `false` を推奨 |
| `SECURITY_SALT` | `__SALT__` | `config/app_local.php` に設定済みのものと一致させる |

## 永続化

| ボリューム | コンテナ内パス | 用途 |
| --- | --- | --- |
| `cakephp5-tmp` | `/var/www/html/tmp` | キャッシュ・セッション |
| `cakephp5-files` | `/var/www/html/files` | コンテンツ添付ファイル |
| `cakephp5-mysql-data` | `/var/lib/mysql` | DB データ |

## ライセンス

リポジトリの LICENSE を参照してください。
