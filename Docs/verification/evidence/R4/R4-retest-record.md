# R4 再試験記録（D-10／D-36／D-37／D-38 の修正確認）

| 項目 | 内容 |
|------|------|
| 実施日 | 2026-09-26 |
| 対象 | R3-fix で修正した **D-10／D-36／D-37／D-38** の修正確認（実 HTTP ＋ 自動テスト） |
| 参照コミット | HEAD = `7e34d9e`（アプリコードは未コミットの作業ツリーで変更済み） |
| 検証方法 | 実 HTTP（Python）＋ `docker compose --force-recreate` による**起動時 chown の実証** ＋ `scripts/test-fresh.sh` |

---

## 1. 結果サマリ

| ID | 検証項目 | 判定 | 根拠（実測） |
|----|----------|------|--------------|
| **D-10** | ユーザー CSV インポート | ✅ 修正確認 | CP932 の CSV で `POST /admin/users/import` → **302 → /admin**、fatal なし。`ib_users` に `r4imp`（id=9 / role=`user` / email 保持）が**作成された** |
| **D-36** | アップロード先の書込権限 | ✅ 修正確認（**永続化も実証**） | 所有者を `root:root` に戻したうえで `docker compose up -d --force-recreate web` を実行 → 起動時に自動で `www-data:www-data` へ復帰。`upload/file` は `complete` 応答、`uploadImage` は URL を返却し、いずれも `www-data` 所有で生成 |
| **D-37** | 画像・ファイル配信の到達性 | ✅ 修正確認 | `uploadImage` の返却 URL `/contents/file-image/20260926230502mwke.png` → **200 / image/png**。旧アンダースコア形式 `/contents/file_image/…` は 404（回帰なし） |
| **D-38** | 空 URL での耐性 | ✅ 修正確認 | `/contents/file-download/6`（`url=NULL`）→ **404**、`/contents/file-movie/4` → **404**（修正前は 500） |
| — | 自動テスト（回帰） | ✅ 維持 | **744 tests / 3689 assertions / 0 errors / 0 failures / 0 deprecations / PHPUnit Notices 8** |

**判定: ✅ 合格** — 未修正の P0／S2 は **0 件**を維持。

---

## 2. D-36 の永続化の実証（制御実験）

本修正はcompose の `command:` による**起動時**.Spec の変更であるため、コンテナ再作成を伴わないと検証できない。そこで「壊れた状態」を意図的に再現してから再作成した。

```
# 1) 事前に所有者を root へ（= D-36 の未修正状態）
chown -R root:root /var/www/html/webroot/uploads /var/www/html/files
→ drwxr-xr-x root:root  （両ディレクトリ）

# 2) web サービスを再作成
docker compose -f docker/docker-compose.cakephp5.yml up -d --force-recreate web
→ Container irohaboard5-web-1  Recreated / Started

# 3) 再作成直後の実測
→ drwxr-xr-x www-data:www-data /var/www/html/webroot/uploads
→ drwxr-xr-x www-data:www-data /var/www/html/files
→ su www-data -c touch …/uploads/.p  → WRITABLE
→ su www-data -c touch …/files/.p    → WRITABLE
→ GET / → HTTP 302（アプリ正常）
```

- 設定の妥当性は事前に `docker compose config` で検証済み（サービス名は `db` / `web`）。
- 注意事項: Dockerfile は `CMD ["apache2-foreground"]` のみで `ENTRYPOINT` を持たない。compose の `command:` は `sh -c "chown … && exec apache2-foreground"` として最終プロセスに `apache2-foreground` を渡すため、Apache は PID1 の子として conventionally 起動される（実測で `apache2 -DFOREGROUND`）。
- ホスト側の `webroot/uploads` は bind mount のため `www-data` 所有になる。`files` は named volume `cakephp5-files` であり実体はコンテナ内にある（ホスト表示は `root:root` でも実体は `www-data`）。

---

## 3. 実測の詳細

| 操作 | 結果 |
|------|------|
| CP932 CSV → `POST /admin/users/import` | 302 → `/admin`、`r4imp` 作成（`role=user`） |
| `POST /admin/contents/upload/file`（field=`file`） | 200、本文に `complete`。`uploads/20260926230502scgk.txt`（13 バイト）生成、`www-data` 所有 |
| `POST /admin/contents/upload-image`（field=`file`、**`X-Requested-With: XMLHttpRequest`** 必須） | 200、`["http://localhost:8082/contents/file-image/20260926230502mwke.png"]`。PNG 生成、`www-data` 所有 |
| `GET /contents/file-image/<生成名>`（user1） | 200 / `image/png` |
| `GET /contents/file-download/6`（user1、`url=NULL`） | 404（修正前 500） |
| `GET /contents/file-movie/4`（user1、外部 URL コンテンツ） | 404 |
| `GET /contents/file_image/<旧形式>` | 404（リネーム後、想定通り） |

---

## 4. 後片付け

| 対象 | 結果 |
|------|------|
| R4 で作成したユーザー（`r4imp` id=9） | 削除 |
| 検証で発行した `ib_user_tokens` / `ib_logs` | 全削除（0 / 0） |
| 検証で生成したファイル（`R4MARKER` 2 件） | 削除（`grep -rl R4MARKER` で残存なし） |
| dev DB 最終状態 | `users=6 courses=2 contents=11 questions=5 records=2 records_questions=3 tokens=0 logs=0`（基準値一致） |
| 追跡ファイル `webroot/uploads/.htaccess` | 生存確認済み（`git status webroot/` クリーン） |

---

## 5. 判定

**✅ 合格** — D-10／D-36／D-37／D-38 の修正を実 HTTP と自動テストで確認。D-36 については「再作成しても権限が自動で修復される」ことまで実証し、恒久対応であることを確認した。
