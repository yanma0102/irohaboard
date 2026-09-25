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

## ライセンス

GPLv3

