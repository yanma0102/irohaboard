# iroha Board

iroha Board は PHP / CakePHP で作られたオープンソースの eラーニング（LMS）システムです。
本イメージは、iroha Board の **アプリ本体を同梱した Web イメージ** です。

- ソースコード: https://github.com/yanma0102/irohaboard
- 対応バージョン: CakePHP 5.4 / PHP 8.4

## このイメージに含まれるもの

- iroha Board のアプリ本体（CakePHP 5.4 のコード）
- 依存パッケージ（`vendor/`、`composer.lock` に基づく）
- 生成済みの `config/app_local.php`（ランダムな `SECURITY_SALT` を焼き込み済み）
- PHP 8.4（Apache `php:8.4-apache` ベース）
- PHP 拡張: `pdo_mysql` `mbstring` `gd` `intl` `opcache` `zip` `bcmath` `ldap`
- Composer 2（保守用。通常は不要）
- Apache 設定（`mod_rewrite` / `mod_headers` 有効、`DocumentRoot=/var/www/html/webroot`）
- `php.ini` / MPM prefork 設定

そのため **clone や `composer install` は不要** で、ネットワーク制限のある環境でも
pull するだけで起動できます。

## 使い方

```bash
docker compose -f Docker/docker-compose.hub.yml pull
docker compose -f Docker/docker-compose.hub.yml up -d
```

Web: http://localhost:8082

初回は `http://localhost:8082/install` にアクセスしてインストールを実行します。
（MariaDB コンテナも同時に起動し、ホスト側 `13307` に公開されます。
インストール処理はコンテナ内で完結するため外部ネットワークは不要です。）

### ソースを差し替えたい場合

リポジトリを clone し、開発用 compose で `app/` を bind マウントすると、
イメージ内のアプリをホスト側のコードで上書きできます。

```bash
git clone https://github.com/yanma0102/irohaboard.git
cd irohaboard
docker compose -f Docker/docker-compose.cakephp5.yml up -d --build
```

### タグ

| タグ | 説明 |
| --- | --- |
| `latest` | アプリ本体（vendor 同梱）を含む最新イメージ |

## 環境変数

`config/app_local.php` を通して次の環境変数を読み取ります。compose ファイルで設定してください。

| 変数 | 既定値 | 説明 |
| --- | --- | --- |
| `DB_HOST` | `127.0.0.1` | DB ホスト（compose では `db`） |
| `DB_PORT` | `3306` | DB ポート |
| `DB_NAME` | `irohaboard` | データベース名 |
| `DB_USER` | `root` | DB ユーザー |
| `DB_PASS` | `rootpass` | DB パスワード |
| `APP_FULL_BASE_URL` | （未設定） | 公開 URL。本番では必須（Host Header Injection 対策） |
| `DEBUG` | `true` | 本番では `false` を推奨 |
| `SECURITY_SALT` | ビルド時に生成したランダム値 | 指定した場合はそちらが優先される |

## 永続化

| ボリューム | コンテナ内パス | 用途 |
| --- | --- | --- |
| `cakephp5-tmp` | `/var/www/html/tmp` | キャッシュ・セッション |
| `cakephp5-files` | `/var/www/html/files` | コンテンツ添付ファイル |
| `cakephp5-uploads` | `/var/www/html/webroot/uploads` | 画像アップロード |
| `cakephp5-mysql-data` | `/var/lib/mysql` | DB データ |

## ライセンス

リポジトリの LICENSE を参照してください。
