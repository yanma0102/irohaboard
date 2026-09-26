# iroha Board

iroha Board は日本で生まれたオープンソースのeラーニングシステム（LMS）です。
シンプルでフラットな構造と、使いやすいユーザインターフェイスが特徴で、手軽に独自のeラーニングシステムが構築できます。

## 公式サイト
https://irohaboard.irohasoft.jp/

## 動作環境
* PHP : 8.2以上
* MySQL / MariaDB : 10.6以上
* CakePHP : 5.4

## セットアップ

```bash
git clone https://github.com/yanma0102/irohaboard.git
cd irohaboard
composer install
```

Config 目配下の `app_local.php` を作成し、DB接続情報を設定してください。
初期セットアップは `/install` にアクセスして実行します。

> **注**: `/mcp`（MCP サーバ）は `mcp/sdk` を使用します。既存環境を更新する際は `git pull` 後に必ず `composer install` を実行してください。

## テスト

```bash
vendor/bin/phpunit
```

## 本番デプロイ

本番環境では **`APP_FULL_BASE_URL`** 環境変数の設定が必須です。
未設定の場合、Host header 検証（Host Header Injection 防御）は無効化され、ログに警告が記録されます。
値はアプリケーションの公開 URL を指定してください（例: `https://example.com`）。

Docker を使用する場合は `docker-compose.yml` / `docker-compose.cakephp5.yml` で事前設定済みです。
手動デプロイの場合は `.env` ファイルに `export APP_FULL_BASE_URL="https://your-domain.com"` を追加してください。

## サブディレクトリでの配信

ドキュメントルート直下（`http://example.com/`）ではなく、サブディレクトリ
（`http://example.com/irohaboard/`）に配置する場合の Apache 設定です。

**`webroot/.htaccess` はそのままでは使えません。** `.htaccess` の
`RewriteRule ^ index.php` は `RewriteBase` 未設定のため DocumentRoot 相対パスへ
解決され、`Alias` で付けたプレフィックス（`/irohaboard`）が失われます。
`<Directory>` 内の `RewriteBase` も `.htaccess` の `RewriteEngine On` に
上書きされて無効化されます（`RewriteOptions InheritBefore` では不十分）。

したがって **vhost 側で `AllowOverride None` にしてルールを複製**してください。
`RewriteBase /irohaboard/` のところだけ公開スコープに合わせて変更します。

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/html/webroot

    Alias /irohaboard /var/www/html/webroot
    RedirectMatch 301 ^/irohaboard$ /irohaboard/

    <Directory /var/www/html/webroot>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php

        RewriteEngine On
        RewriteBase /irohaboard/          # ← 公開パスに合わせて変更

        # webroot/.htaccess のセキュリティルールを複製
        RewriteCond %{REQUEST_URI} (\.env|vendor|phpunit|\.git|\.sql|\.bak|\.ini|\.cgi|\.py) [NC]
        RewriteRule ^ - [F,L]
        RewriteCond %{REQUEST_URI} (wp-admin|wp-includes|wp-content) [NC]
        RewriteRule ^ - [F,L]
        RewriteCond %{REQUEST_URI} \.php$ [NC]
        RewriteCond %{REQUEST_URI} !/index\.php$ [NC]
        RewriteRule ^ - [F,L]
        RewriteRule ^\.well-known/ - [R=404,L]

        # CakePHP フロントコントローラ
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteRule ^ index.php [L]
    </Directory>
</VirtualHost>
```

`APP_FULL_BASE_URL` には公開 URL（パスを含む）を設定して構いません。
アプリケーション内部では `Router::fullBaseUrl()` に**パスを含めない**形で
`：scheme://host[:port]` だけを引き継ぎ、ベースパスはCakePHP が
`PHP_SELF` から検出した値（`$request->getAttribute('base')`）.Inject
使うため、パスを二重に連結することはありません。

動作確認:

```bash
# 未認証アクセスがベースパス付きログインへ飛ぶこと
curl -s -D - -o /dev/null http://example.com/irohaboard/ | grep -i '^location'

# ログイン後のリダイレクトが /irohaboard/irohaboard に二重化しないこと
# （CSRF トークンは GET した HTML から name="_csrfToken" ... value="..." を抽出）
curl -s -D - -o /dev/null \
  --data-urlencode "_csrfToken=$TOKEN" \
  --data-urlencode "username=<管理者ID>" --data-urlencode "password=<パスワード>" \
  http://example.com/irohaboard/users/login | grep -i '^location'

# API が 401（404 や 302 ではない）であること
curl -s -o /dev/null -w '%{http_code}\n' http://example.com/irohaboard/api/v1/users
```

## ライセンス

GPLv3

