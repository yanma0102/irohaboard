<?php
declare(strict_types=1);

use Migrations\BaseSeed;

/**
 * DS-7 Markdown Seed
 *
 * Inserts a markdown content that exercises every rendering feature:
 * headings, table, fenced code block, link, image, raw HTML,
 * a <script> XSS probe, and a GFM task list.
 */
class Ds7MarkdownSeed extends BaseSeed
{
    public function isIdempotent(): bool
    {
        return true;
    }

    public function run(): void
    {
        $now    = date('Y-m-d H:i:s');
        $opened = date('Y-m-d 00:00:00');

        $body = <<<'MD'
# 見出し1

## 見出し2

本文のテキストです。

### 表

| 名前 | 値 |
|------|-----|
| A | 100 |
| B | 200 |

### コードブロック

```php
<?php
echo "Hello, world!";
```

### リンク

[CakePHP公式サイト](https://cakephp.org)

### 画像

![サンプル画像](https://example.com/sample.png)

### 生HTML

<div style="background-color: yellow;">HTMLコンテンツ</div>

### XSS攻撃プローブ

<script>alert('XSS');</script>

### タスクリスト

- [x] アイテム1（完了）
- [ ] アイテム2（未完了）
- [ ] アイテム3（未完了）
MD;

        // content id 11 – markdown (published)
        $this->table('ib_contents')->insert([
            [
                'course_id'       => 1,
                'user_id'         => 1,
                'title'           => 'Markdown全方位テスト',
                'url'             => null,
                'file_name'       => null,
                'kind'            => 'markdown',
                'body'            => $body,
                'timelimit'       => null,
                'pass_rate'       => null,
                'question_count'  => null,
                'wrong_mode'      => 1,
                'status'          => 1,
                'opened'          => $opened,
                'created'         => $now,
                'modified'        => $now,
                'deleted'         => null,
                'sort_no'         => 11,
                'comment'         => null,
            ],
        ])->save();
    }
}
