FROM php:8.1-apache

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
    mysqli \
    gd \
    mbstring \
    zip \
    intl \
    opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Apache mod_rewrite / mod_headers有効化
RUN a2enmod rewrite headers

# Apache設定ファイルをコピー（DocumentRootをapp/webrootに設定、AllowOverride All）
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache-app.conf /etc/apache2/conf-available/irohaboard.conf
RUN a2enconf irohaboard \
    && echo 'ServerName localhost' >> /etc/apache2/apache2.conf

# Composerインストール
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# CakePHP 2.10をGitHubからダウンロード
WORKDIR /var/www/html
RUN git clone --depth 1 --branch 2.10.24 https://github.com/cakephp/cakephp.git /tmp/cakephp \
    && mkdir -p /var/www/html/app \
    && cp -a /tmp/cakephp/lib /var/www/html/lib \
    && rm -rf /tmp/cakephp

# アプリケーションファイルをappディレクトリにコピー
COPY --chown=www-data:www-data Controller/ /var/www/html/app/Controller/
COPY --chown=www-data:www-data Model/ /var/www/html/app/Model/
COPY --chown=www-data:www-data View/ /var/www/html/app/View/
COPY --chown=www-data:www-data Config/ /var/www/html/app/Config/
COPY --chown=www-data:www-data Lib/ /var/www/html/app/Lib/
COPY --chown=www-data:www-data Plugin/ /var/www/html/app/Plugin/
COPY --chown=www-data:www-data Vendor/ /var/www/html/app/Vendor/
COPY --chown=www-data:www-data Custom/ /var/www/html/app/Custom/
COPY --chown=www-data:www-data files/ /var/www/html/app/files/
COPY --chown=www-data:www-data index.php /var/www/html/app/index.php
COPY --chown=www-data:www-data .htaccess /var/www/html/app/.htaccess
COPY --chown=www-data:www-data webroot/ /var/www/html/app/webroot/

# tmpディレクトリの作成と権限設定
RUN mkdir -p /var/www/html/app/tmp/cache/models \
    && mkdir -p /var/www/html/app/tmp/cache/persistent \
    && mkdir -p /var/www/html/app/tmp/cache/views \
    && mkdir -p /var/www/html/app/tmp/logs \
    && mkdir -p /var/www/html/app/tmp/sessions \
    && chown -R www-data:www-data /var/www/html/app/tmp \
    && chmod -R 777 /var/www/html/app/tmp

# filesディレクトリの権限設定
RUN chown -R www-data:www-data /var/www/html/app/files \
    && chmod -R 777 /var/www/html/app/files

# ログファイルを作成
RUN touch /var/www/html/app/tmp/logs/debug.log \
    && touch /var/www/html/app/tmp/logs/error.log \
    && chown www-data:www-data /var/www/html/app/tmp/logs/debug.log \
    && chown www-data:www-data /var/www/html/app/tmp/logs/error.log

# PHP設定ファイルをコピー
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

EXPOSE 80

CMD ["apache2-foreground"]
