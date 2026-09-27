#!/bin/sh
# iroha Board Web コンテナの起動エントリポイント
# - named volume をマウントした場合に必要ディレクトリを用意
# - uploads/.htaccess が volume で隠れた場合に復元
# - Apache 実行ユーザ www-data に所有権を合わせる
# - 引数（CMD）をそのまま実行
set -e

# 必要なディレクトリが無ければ作成（volume マウント時に空になるため）
mkdir -p /var/www/html/tmp \
         /var/www/html/logs \
         /var/www/html/files \
         /var/www/html/webroot/uploads

# uploads/.htaccess はディレクトリを volume で上書きすると失われるため復元する
if [ ! -f /var/www/html/webroot/uploads/.htaccess ] \
   && [ -f /usr/local/share/irohaboard/uploads.htaccess ]; then
    cp /usr/local/share/irohaboard/uploads.htaccess /var/www/html/webroot/uploads/.htaccess
fi

# 書き込みが必要なディレクトリの所有権を Apache 実行ユーザへ合わせる
chown -R www-data:www-data \
    /var/www/html/tmp \
    /var/www/html/logs \
    /var/www/html/files \
    /var/www/html/webroot/uploads

exec "$@"
