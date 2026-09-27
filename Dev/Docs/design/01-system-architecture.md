# 01 — システム構成設計

## 1. 概要

iroha Board の CakePHP 2.10 → 5.x 移行後のシステム全体構成を定義する。

| 項目 | 現行 | 移行先 |
|---|---|---|
| CakePHP | 2.10.24 | **5.4.x** |
| PHP | 8.1 | **8.4** |
| MySQL | 5.7 | **MariaDB 11.4 LTS** |
| Apache | 2.4（mod_php） | 2.4（変更なし） |
| Composer | 未使用 | **2**（依存管理を導入） |
| Debian | Bullseye | Bookworm / Trixie（`php:8.4-apache` ベース） |

根拠: `Docs/design/README.md:28-35`, `Docs/cakephp5-migration-spec.md:79-85`

---

## 2. ディレクトリ構成

CakePHP 5 の標準構成に沿ったファイル配置。現行からの主な変更点を明記する。

```
app/                              # ルート（プロジェクトルート）
├── config/
│   ├── app.php                   # core.php の全設定を移行（新規作成）
│   ├── bootstrap.php             # bootstrap.php の処理を移行（新規作成）
│   ├── routes.php                # routes.php をスコープ付きルートに変更（新規作成）
│   └── ib_config.php             # アプリ固有設定（既存 Config/ib_config.php をそのまま移行）
├── src/
│   ├── Application.php           # Application クラス（新規作成、bootstrap/middleware）
│   ├── Controller/
│   │   ├── AppController.php     # AppController（移行）
│   │   ├── Component/
│   │   │   └── （AppSecurityComponent は削除、FormProtectionMiddleware に置換）
│   │   ├── Admin/                # admin プレフィクス（$routes->prefix('Admin')）
│   │   │   ├── UsersController.php
│   │   │   ├── CoursesController.php
│   │   │   ├── ContentsController.php
│   │   │   ├── GroupsController.php
│   │   │   ├── RecordsController.php
│   │   │   ├── SettingsController.php
│   │   │   ├── InfosController.php
│   │   │   ├── UsersCoursesController.php
│   │   │   ├── ContentsQuestionsController.php
│   │   │   ├── EnquetesQuestionsController.php
│   │   │   ├── InstallController.php
│   │   │   └── UpdateController.php
│   │   ├── UsersCoursesController.php  # 受講者画面
│   │   └── Api/                  # API コントローラ（サブディレクトリ化）
│   │       ├── AppBaseController.php
│   │       ├── AuthController.php
│   │       ├── UsersController.php
│   │       ├── GroupsController.php
│   │       ├── CoursesController.php
│   │       ├── ContentsController.php
│   │       ├── RecordsController.php
│   │       └── ErrorsController.php
│   ├── Model/
│   │   ├── Table/                # Table クラス
│   │   │   ├── AppTable.php      # 基底 Table（プレフィックスロジック）
│   │   │   ├── UsersTable.php
│   │   │   ├── GroupsTable.php
│   │   │   ├── CoursesTable.php
│   │   │   ├── ContentsTable.php
│   │   │   ├── RecordsTable.php
│   │   │   ├── SettingsTable.php
│   │   │   ├── InfosTable.php
│   │   │   ├── UsersCoursesTable.php
│   │   │   ├── UsersGroupsTable.php
│   │   │   ├── GroupsCoursesTable.php
│   │   │   ├── InfosGroupsTable.php
│   │   │   ├── ContentsQuestionsTable.php
│   │   │   ├── RecordsQuestionsTable.php
│   │   │   ├── LogsTable.php
│   │   │   └── UserTokensTable.php
│   │   ├── Entity/               # Entity クラス（必要に応じて）
│   │   └── Behavior/             # カスタムビヘイビア
│   ├── View/
│   │   ├── AppView.php
│   │   └── Helper/               # カスタムヘルパー
│   └── Custom/                   # PSR-4 autoload で App\Custom\ 名前空間
│       ├── Config/
│       ├── Controller/
│       ├── Model/
│       ├── Vendor/
│       └── View/
├── templates/                    # テンプレート（.php）
│   ├── layout/
│   ├── element/
│   ├── Admin/                    # admin プレフィクス用テンプレート
│   ├── Courses/
│   ├── Contents/
│   ├── Groups/
│   ├── Infos/
│   ├── Records/
│   ├── Settings/
│   ├── Users/
│   └── UsersCourses/
├── webroot/                      # ドキュメントルート
│   ├── index.php                 # CakePHP 5 エントリポイント
│   ├── css/
│   ├── js/
│   ├── img/
│   └── .htaccess
├── tests/                        # PHPUnit テスト
├── composer.json
├── .env
├── .htaccess
└── ...
```

### 現行からの主な変更点

| 現行パス | 移行先パス | 変更内容 |
|---|---|---|
| `Config/` | `config/` | ディレクトリ名変更。`core.php` → `app.php` に統合 |
| `Controller/` | `src/Controller/` | 名前空間 `App\Controller` に変更。admin 系は `Admin/` サブディレクトリ |
| `Controller/ApiBaseController.php` | `src/Controller/Api/AppBaseController.php` | API コントローラは `Api/` サブディレクトリ |
| `Model/` | `src/Model/Table/` + `src/Model/Entity/` | Table/Entity に分離。AppModel は AppTable に変更 |
| `View/` | `templates/` | テンプレートディレクトリ。`.ctp` → `.php` |
| `View/AppView.php` | `src/View/AppView.php` | 名前空間 `App\View` に変更 |
| `Plugin/DebugKit/` | Composer で `cakephp/debug_kit` | 同梱を廃止、Composer 管理に変更 |
| `Plugin/BoostCake/` | Composer で `friendsofcake/bootstrap-ui` | 同梱を廃止、Composer 管理に変更 |
| `Plugin/Search/` | 自作検索ロジック | プラグイン削除、QueryBuilder で置換 |
| `Plugin/Acl/` | 削除 | 未使用（`Config/bootstrap.php:93-95` に Acl ロード無し） |
| `Vendor/Utils.php`, `Vendor/FileUpload.php` | `src/Custom/Vendor/` or `src/Utility/` | PSR-4 autoload 対応 |
| `Lib/FormToken.php` | 削除 | `FormProtectionMiddleware` に置換 |
| `Custom/` | `src/Custom/` | PSR-4 autoload で `App\Custom\` 名前空間に変更 |
| `Config/ib_config.php` | `config/ib_config.php` | Configure::load() で継続ロード |
| `index.php` | `webroot/index.php` | CakePHP 5 標準のエントリポイント構成に変更 |
| `files/` | `files/` | アップロードファイル格納先（変更なし） |

---

## 3. Composer 依存

`composer.json` に含めるパッケージ一覧とバージョン。

```json
{
  "name": "irohasoft/irohaboard",
  "description": "iroha Board - e-Learning System",
  "type": "cakephp-app",
  "license": "GPL-3.0",
  "require": {
    "php": ">=8.2",
    "cakephp/cakephp": "^5.4",
    "cakephp/authentication": "^4.2",
    "cakephp/debug_kit": "^5.2",
    "friendsofcake/bootstrap-ui": "^5.2"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0"
  },
  "autoload": {
    "psr-4": {
      "App\\": "src/",
      "App\\Custom\\": "src/Custom/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "App\\Test\\": "tests/"
    }
  },
  "config": {
    "sort-packages": true,
    "allow-plugins": {
      "cakephp/authentication": true
    }
  }
}
```

### パッケージ一覧

| パッケージ | バージョン | 用途 |
|---|---|---|
| `cakephp/cakephp` | ^5.4 | CakePHP 本体（最新 5.4.2: 2026-09-05） |
| `cakephp/authentication` | ^4.2 | 認証（AuthComponent の代替） |
| `cakephp/debug_kit` | ^5.2 | デバッグツール（環境別ロード） |
| `friendsofcake/bootstrap-ui` | ^5.2 | Bootstrap UI ヘルパー（BoostCake の代替） |
| `php` | ^8.2 | PHP 8.2 以上（CakePHP 5 の要件） |

根拠: `Docs/design/README.md:85-88`, `Docs/cakephp5-migration-spec.md:287-300`

### 削除対象プラグイン

| プラグイン | 状態 | 削除理由 |
|---|---|---|
| `Plugin/Acl/` | 未使用 | `Config/bootstrap.php:93-95` に Acl ロード無し、AppController に Acl コンポーネント無し |
| `Plugin/Search/` | 非アクティブ | アーカイブ済み。User / Record の2モデルのみ利用、自作検索で置換 |
| `Plugin/BoostCake/` | 非アクティブ | 2014年頃で更新停止。bootstrap-ui で置換 |
| `Plugin/DebugKit/` | 同梱版（2.2.6） | Composer で `cakephp/debug_kit` ^5.2 をインストール |

根拠: `Docs/cakephp5-migration-spec.md:68-72`, `Config/bootstrap.php:93-95`

---

## 4. PSR-4 autoload

### autoload 設定

```json
{
  "autoload": {
    "psr-4": {
      "App\\": "src/",
      "App\\Custom\\": "src/Custom/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "App\\Test\\": "tests/"
    }
  }
}
```

### 名前空間マッピング

| 名前空間 | ディレクトリ | 用途 |
|---|---|---|
| `App\` | `src/` | アプリケーション本体 |
| `App\Controller\` | `src/Controller/` | コントローラ |
| `App\Controller\Api\` | `src/Controller/Api/` | API コントローラ |
| `App\Controller\Admin\` | `src/Controller/Admin/` | admin プレフィクス コントローラ |
| `App\Model\Table\` | `src/Model/Table/` | Table クラス |
| `App\Model\Entity\` | `src/Model/Entity/` | Entity クラス |
| `App\Model\Behavior\` | `src/Model/Behavior/` | カスタムビヘイビア |
| `App\View\Helper\` | `src/View/Helper/` | カスタムヘルパー |
| `App\Middleware\` | `src/Middleware/` | カスタムミドルウェア |
| `App\Custom\` | `src/Custom/` | カスタマイズ用（Plugin のオーバーライド） |

### 現行 `App::build()` の17パスと PSR-4 での代替

現行の `Config/bootstrap.php:55-75` で定義されている17パスを PSR-4 autoload で代替する。

| # | 現行パス（`App::build()`） | PSR-4 での対応 |
|---|---|---|
| 1 | `Custom/Model/` | `App\Model\` → `src/Model/Table/` にマージ（プレフィックスで区別） |
| 2 | `Custom/Model/Behavior/` | `App\Model\Behavior\` → `src/Model/Behavior/` |
| 3 | `Custom/Model/Datasource/` | `App\Model\Datasource\` → `src/Model/Datasource/` |
| 4 | `Custom/Model/Datasource/Database/` | `App\Model\Datasource\Database\` |
| 5 | `Custom/Model/Datasource/Session/` | `App\Model\Datasource\Session\` |
| 6 | `Custom/Controller/` | `App\Controller\` → `src/Controller/` にマージ |
| 7 | `Custom/Controller/Component/` | `App\Controller\Component\` |
| 8 | `Custom/Controller/Component/Auth/` | `App\Controller\Component\Auth\` |
| 9 | `Custom/Controller/Component/Acl/` | `App\Controller\Component\Acl\`（未使用のため削除） |
| 10 | `Custom/View/` | `App\View\` → `src/View/` |
| 11 | `Custom/View/Helper/` | `App\View\Helper\` |
| 12 | `Custom/Console/` | `App\Shell\` → `src/Command/`（Console は CakePHP 5 で Shell → Command に変更） |
| 13 | `Custom/Console/Command/` | `App\Command\` |
| 14 | `Custom/Lib/` | `App\Lib\` → `src/Utility/` または `src/Lib/` |
| 15 | `Custom/Locale/` | CakePHP 5 標準の Locale 機構で対応 |
| 16 | `Custom/Vendor/` | Composer autoload で対応（PSR-4 クラスは `App\Custom\Vendor\`） |
| 17 | `Custom/Plugin/` | CakePHP 5 ではプラグインのオーバーライドは autoload で対応 |

根拠: `Config/bootstrap.php:55-75`, `Docs/design/README.md:126-128`

---

## 5. 環境変数

`.env` で管理する変数一覧。

```env
# データベース設定
DB_HOST=localhost
DB_PORT=3306
DB_NAME=irohaboard
DB_USER=root
DB_PASS=

# CakePHP セキュリティ
SECURITY_SALT=397110e45242a23e5802e78f4eec95a7bd39e0f0

# CakePHP デバッグモード（true: 開発 / false: 本番）
CAKEPHP_DEBUG=true

# タイムゾーン
DEFAULT_TIMEZONE=Asia/Tokyo

# MySQL 設定
MYSQL_ROOT_PASSWORD=rootpass
MYSQL_DATABASE=irohaboard
MYSQL_USER=ib_user
MYSQL_PASSWORD=ib_password
```

### 環境変数の利用箇所

| 変数名 | 設定先 | 使用箇所 | 現行の設定元 |
|---|---|---|---|
| `DB_HOST` | `config/app.php` → `Datasources.default.host` | データベース接続 | `Config/database.php:25` |
| `DB_NAME` | `config/app.php` → `Datasources.default.database` | データベース名 | `Config/database.php:28` |
| `DB_USER` | `config/app.php` → `Datasources.default.username` | DB ユーザ名 | `Config/database.php:26` |
| `DB_PASS` | `config/app.php` → `Datasources.default.password` | DB パスワード | `Config/database.php:27` |
| `SECURITY_SALT` | `config/app.php` → `Security.salt` | パスワードハッシュ生成 | `Config/core.php:243` |
| `CAKEPHP_DEBUG` | `config/app.php` → `debug` | デバッグモード | 現行はコメントアウト（`Config/core.php:37-38`） |
| `DEFAULT_TIMEZONE` | `config/app.php` → `App.defaultTimezone` | タイムゾーン | `Config/core.php:297` |

> **注**: MariaDB Docker イメージでも `MYSQL_*` 環境変数（`MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE` 等）はそのまま動作する。CakePHP の `Datasources` 設定も MySQL と同一の `Mysql` ドライバを使用するため変更不要。

---

## 6. Docker 構成

### docker/Dockerfile

```dockerfile
FROM php:8.4-apache

# PHP拡張モジュールインストール
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mbstring \
    gd \
    intl \
    opcache \
    zip \
    bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Apache mod_rewrite / mod_headers 有効化
RUN a2enmod rewrite headers

# Apache 設定ファイルをコピー
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache-app.conf /etc/apache2/conf-available/irohaboard.conf
RUN a2enconf irohaboard \
    && echo 'ServerName localhost' >> /etc/apache2/apache2.conf

# Composer インストール
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# アプリケーションファイルをコピー
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY --chown=www-data:www-data src/ /var/www/html/src/
COPY --chown=www-data:www-data config/ /var/www/html/config/
COPY --chown=www-data:www-data templates/ /var/www/html/templates/
COPY --chown=www-data:www-data webroot/ /var/www/html/webroot/
COPY --chown=www-data:www-data files/ /var/www/html/files/
COPY --chown=www-data:www-data .env .env

# tmp ディレクトリの作成と権限設定
RUN mkdir -p /var/www/html/tmp/cache/models \
    && mkdir -p /var/www/html/tmp/cache/persistent \
    && mkdir -p /var/www/html/tmp/cache/views \
    && mkdir -p /var/www/html/tmp/logs \
    && mkdir -p /var/www/html/tmp/sessions \
    && chown -R www-data:www-data /var/www/html/tmp \
    && chmod -R 777 /var/www/html/tmp

# files ディレクトリの権限設定
RUN chown -R www-data:www-data /var/www/html/files \
    && chmod -R 777 /var/www/html/files

# ログファイルを作成
RUN touch /var/www/html/tmp/logs/debug.log \
    && touch /var/www/html/tmp/logs/error.log \
    && chown www-data:www-data /var/www/html/tmp/logs/debug.log \
    && chown www-data:www-data /var/www/html/tmp/logs/error.log

# PHP 設定ファイルをコピー
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

EXPOSE 80

CMD ["apache2-foreground"]
```

### 現行からの変更点（Dockerfile）

| 現行 | 移行先 | 変更理由 |
|---|---|---|
| `FROM php:8.1-apache` | `FROM php:8.4-apache` | PHP 8.4 対応 |
| `git clone --branch 2.10.24` | `composer install` | CakePHP 5 を Composer で管理（`docker/Dockerfile:39`） |
| `COPY Controller/` 等 | `COPY src/`, `COPY config/`, `COPY templates/` | CakePHP 5 のディレクトリ構成に変更 |
| なし | `COPY .env .env` | 環境変数の導入 |
| `mysqli` 拡張 | なし | CakePHP 5 では `pdo_mysql` のみ使用 |
| なし | `bcmath` 拡張 | 追加 |

根拠: `docker/Dockerfile:1-82`

### docker/docker-compose.yml

```yaml
services:
  web:
    build:
      context: ../
      dockerfile: docker/Dockerfile
    ports:
      - "8081:80"
    volumes:
      - ../:/var/www/html/
      - cakephp-tmp:/var/www/html/tmp
      - cakephp-files:/var/www/html/files
    env_file:
      - ../.env
    environment:
      - DB_HOST=db
      - DB_PORT=3306
      - DB_NAME=${DB_NAME:-irohaboard}
      - DB_USER=${DB_USER:-root}
      - DB_PASS=${DB_PASS:-}
    depends_on:
      db:
        condition: service_healthy
    restart: unless-stopped

  db:
    image: mariadb:11.4
    ports:
      - "3306:3306"
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:-rootpass}
      MYSQL_DATABASE: ${MYSQL_DATABASE:-irohaboard}
      MYSQL_USER: ${MYSQL_USER:-ib_user}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD:-ib_password}
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped

volumes:
  mysql-data:
  cakephp-tmp:
  cakephp-files:
```

### 現行からの変更点（docker-compose.yml）

| 現行 | 移行先 | 変更理由 |
|---|---|---|
| `image: mysql:5.7` | `image: mariadb:11.4` | MariaDB 11.4 LTS 対応（MySQL と同一の `Mysql` ドライバを使用） |
| `environment:` で直接設定 | `env_file:` + `environment:` | `.env` ファイルで環境変数を管理 |
| なし | `env_file: ../.env` | 環境変数の外部化 |

根拠: `docker/docker-compose.yml:1-47`

### docker/php.ini

```ini
; iroha Board用PHP設定
[PHP]
; エラー表示設定（開発環境用）
display_errors = On
error_reporting = E_ALL & ~E_DEPRECATED

; 文字エンコーディング
default_charset = "UTF-8"

; 日本時間
date.timezone = Asia/Tokyo

; ファイルアップロード設定
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300
max_input_time = 300
memory_limit = 256M

; セッション設定
session.gc_maxlifetime = 1440

; OPcache設定（開発環境では無効化推奨）
opcache.enable = 0

; mbstring設定
mbstring.language = Japanese

; PHP 8.4 固有の設定
session.cookie_samesite = Lax
```

---

## 7. PHP 拡張

### 必須

| 拡張 | 用途 | 現行 Dockerfile | 移行先 Dockerfile |
|---|---|---|---|
| `pdo_mysql` | CakePHP 5 の DB ドライバ（MariaDB でも同一） | `docker/Dockerfile:15` | 継続 |
| `mbstring` | 多バイト文字列処理 | `docker/Dockerfile:18` | 継続 |
| `gd` | 画像処理 | `docker/Dockerfile:17` | 継続 |
| `intl` | 国際化（i18n） | `docker/Dockerfile:20` | 継続 |
| `opcache` | PHP パフォーマンス | `docker/Dockerfile:21` | 継続 |

### 追加

| 拡張 | 用途 | 根拠 |
|---|---|---|
| `zip` | ZIP アーカイブ処理 | `docker/Dockerfile:19`（現行はインストール済み） |
| `bcmath` | 任意精度数値計算 | CakePHP 5 の推奨拡張 |

### 削除

| 拡張 | 理由 |
|---|---|
| `mysqli` | CakePHP 5 では `pdo_mysql` のみ使用（`docker/Dockerfile:16`） |

---

## 8. Apache 設定

### 現行設定

| 設定 | 現行ファイル | 現行値 |
|---|---|---|
| mod_rewrite | `docker/Dockerfile:26` | `a2enmod rewrite headers` |
| DocumentRoot | `docker/apache-vhost.conf:3` | `/var/www/html/app/webroot` |
| AllowOverride | `docker/apache-app.conf:3` | `All` |
| X-Content-Type-Options | `.htaccess:9` | `nosniff` |

### 移行先設定

| 設定 | 移行先ファイル | 移行先値 | 変更内容 |
|---|---|---|---|
| mod_rewrite | `docker/Dockerfile` | `a2enmod rewrite headers` | 変更なし |
| DocumentRoot | `docker/apache-vhost.conf` | `/var/www/html/webroot` | `app/webroot` → `webroot`（CakePHP 5 標準） |
| AllowOverride | `docker/apache-app.conf` | `All` | 変更なし |
| X-Content-Type-Options | `.htaccess` | `nosniff` | 変更なし |

### 移行先 apache-vhost.conf

```apache
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/webroot

    <Directory /var/www/html/webroot>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # セキュリティヘッダー
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
```

### 移行先 .htaccess（ルート）

```apache
<IfModule mod_rewrite.c>
    RewriteEngine on
    RewriteRule ^$ webroot/ [L]
    RewriteRule (.*) webroot/$1 [L]
</IfModule>

# セキュリティヘッダー
Header always set X-Content-Type-Options nosniff
```

根拠: `.htaccess:1-11`, `docker/apache-vhost.conf:1-13`, `docker/apache-app.conf:1-5`
