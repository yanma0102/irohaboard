<?php
declare(strict_types=1);

use Migrations\BaseSeed;

/**
 * DS-2 Contents Seed
 *
 * Creates 2 courses and contents covering ALL kinds:
 * label, html, markdown, movie, url, file, test, enquete.
 * Mixed published / unpublished. Assigns course to user1 via ib_users_courses.
 *
 * Expects Ds0 (admin user id=1) and Ds1 (user1 id=5) to have run first.
 */
class Ds2ContentsSeed extends BaseSeed
{
    public function isIdempotent(): bool
    {
        return true;
    }

    public function run(): void
    {
        $now     = date('Y-m-d H:i:s');
        $opened  = date('Y-m-d 00:00:00');

        // ── Clean ──
        $this->execute('DELETE FROM `ib_contents`');
        $this->execute('DELETE FROM `ib_users_courses`');
        $this->execute('DELETE FROM `ib_courses`');

        // Reset AUTO_INCREMENT for stable IDs
        $this->execute('ALTER TABLE `ib_courses` AUTO_INCREMENT = 1');
        $this->execute('ALTER TABLE `ib_contents` AUTO_INCREMENT = 1');

        // ── Courses ──
        // course 1: 受講可能コース (opened, sort 1)
        // course 2: 非公開コース (no opened, sort 2)
        $this->table('ib_courses')->insert([
            [
                'title'        => '受講可能コース',
                'introduction' => 'テスト用の受講可能コースです。',
                'opened'       => $opened,
                'created'      => $now,
                'modified'     => $now,
                'deleted'      => null,
                'sort_no'      => 1,
                'comment'      => null,
                'user_id'      => 1,
            ],
            [
                'title'        => '非公開コース',
                'introduction' => 'テスト用の非公開コースです。',
                'opened'       => null,
                'created'      => $now,
                'modified'     => $now,
                'deleted'      => null,
                'sort_no'      => 2,
                'comment'      => null,
                'user_id'      => 1,
            ],
        ])->save();

        // ── Contents (course_id = 1: 受講可能コース) ──
        // Contents cover every `kind` value.
        // id 1  = label   (published)
        // id 2  = html    (published)
        // id 3  = markdown (published)
        // id 4  = movie   (published)
        // id 5  = url     (published)
        // id 6  = file    (published)
        // id 7  = test    (published) — question_count / pass_rate set
        // id 8  = enquete (published)
        // id 9  = label   (unpublished, status 0)
        // id 10 = html    (unpublished, status 0)

        $contents = [
            // 1 – label
            [
                'course_id'       => 1,
                'user_id'         => 1,
                'title'           => 'ラベルコンテンツ',
                'url'             => null,
                'file_name'       => null,
                'kind'            => 'label',
                'body'            => 'ラベルの説明文です。',
                'timelimit'       => null,
                'pass_rate'       => null,
                'question_count'  => null,
                'wrong_mode'      => 1,
                'status'          => 1,
                'opened'          => $opened,
                'created'         => $now,
                'modified'        => $now,
                'deleted'         => null,
                'sort_no'         => 1,
                'comment'         => null,
            ],
            // 2 – html
            [
                'course_id'       => 1,
                'user_id'         => 1,
                'title'           => 'HTMLコンテンツ',
                'url'             => null,
                'file_name'       => null,
                'kind'            => 'html',
                'body'            => '<h2>HTMLタイトル</h2><p>HTML本文</p>',
                'timelimit'       => null,
                'pass_rate'       => null,
                'question_count'  => null,
                'wrong_mode'      => 1,
                'status'          => 1,
                'opened'          => $opened,
                'created'         => $now,
                'modified'        => $now,
                'deleted'         => null,
                'sort_no'         => 2,
                'comment'         => null,
            ],
            // 3 – markdown
            [
                'course_id'       => 1,
                'user_id'         => 1,
                'title'           => 'Markdownコンテンツ',
                'url'             => null,
                'file_name'       => null,
                'kind'            => 'markdown',
                'body'            => "## マークダウン\n\n本文です。",
                'timelimit'       => null,
                'pass_rate'       => null,
                'question_count'  => null,
                'wrong_mode'      => 1,
                'status'          => 1,
                'opened'          => $opened,
                'created'         => $now,
                'modified'        => $now,
                'deleted'         => null,
                'sort_no'         => 3,
                'comment'         => null,
            ],
            // 4 – movie
            [
                'course_id'       => 1,
                'user_id'         => 1,
                'title'           => '動画コンテンツ',
                'url'             => 'https://example.com/movie.mp4',
                'file_name'       => null,
                'kind'            => 'movie',
                'body'            => null,
                'timelimit'       => null,
                'pass_rate'       => null,
                'question_count'  => null,
                'wrong_mode'      => 1,
                'status'          => 1,
                'opened'          => $opened,
                'created'         => $now,
                'modified'        => $now,
                'deleted'         => null,
                'sort_no'         => 4,
                'comment'         => null,
            ],
            // 5 – url
            [
                'course_id'       => 1,
                'user_id'         => 1,
                'title'           => 'URLコンテンツ',
                'url'             => 'https://example.com',
                'file_name'       => null,
                'kind'            => 'url',
                'body'            => null,
                'timelimit'       => null,
                'pass_rate'       => null,
                'question_count'  => null,
                'wrong_mode'      => 1,
                'status'          => 1,
                'opened'          => $opened,
                'created'         => $now,
                'modified'        => $now,
                'deleted'         => null,
                'sort_no'         => 5,
                'comment'         => null,
            ],
            // 6 – file
            [
                'course_id'       => 1,
                'user_id'         => 1,
                'title'           => 'ファイルコンテンツ',
                'url'             => null,
                'file_name'       => 'sample.pdf',
                'kind'            => 'file',
                'body'            => null,
                'timelimit'       => null,
                'pass_rate'       => null,
                'question_count'  => null,
                'wrong_mode'      => 1,
                'status'          => 1,
                'opened'          => $opened,
                'created'         => $now,
                'modified'        => $now,
                'deleted'         => null,
                'sort_no'         => 6,
                'comment'         => null,
            ],
            // 7 – test  (pass_rate=60, question_count set by DS-3)
            [
                'course_id'       => 1,
                'user_id'         => 1,
                'title'           => 'テストコンテンツ',
                'url'             => null,
                'file_name'       => null,
                'kind'            => 'test',
                'body'            => null,
                'timelimit'       => 30,
                'pass_rate'       => 60,
                'question_count'  => 3,
                'wrong_mode'      => 1,
                'status'          => 1,
                'opened'          => $opened,
                'created'         => $now,
                'modified'        => $now,
                'deleted'         => null,
                'sort_no'         => 7,
                'comment'         => null,
            ],
            // 8 – enquete
            [
                'course_id'       => 1,
                'user_id'         => 1,
                'title'           => 'アンケートコンテンツ',
                'url'             => null,
                'file_name'       => null,
                'kind'            => 'enquete',
                'body'            => null,
                'timelimit'       => null,
                'pass_rate'       => null,
                'question_count'  => 2,
                'wrong_mode'      => 1,
                'status'          => 1,
                'opened'          => $opened,
                'created'         => $now,
                'modified'        => $now,
                'deleted'         => null,
                'sort_no'         => 8,
                'comment'         => null,
            ],
            // 9 – label (unpublished)
            [
                'course_id'       => 1,
                'user_id'         => 1,
                'title'           => '非公開ラベル',
                'url'             => null,
                'file_name'       => null,
                'kind'            => 'label',
                'body'            => '非公開のラベルです。',
                'timelimit'       => null,
                'pass_rate'       => null,
                'question_count'  => null,
                'wrong_mode'      => 1,
                'status'          => 0,
                'opened'          => $opened,
                'created'         => $now,
                'modified'        => $now,
                'deleted'         => null,
                'sort_no'         => 9,
                'comment'         => null,
            ],
            // 10 – html (unpublished)
            [
                'course_id'       => 1,
                'user_id'         => 1,
                'title'           => '非公開HTML',
                'url'             => null,
                'file_name'       => null,
                'kind'            => 'html',
                'body'            => '<p>非公開のHTML</p>',
                'timelimit'       => null,
                'pass_rate'       => null,
                'question_count'  => null,
                'wrong_mode'      => 1,
                'status'          => 0,
                'opened'          => $opened,
                'created'         => $now,
                'modified'        => $now,
                'deleted'         => null,
                'sort_no'         => 10,
                'comment'         => null,
            ],
        ];

        $this->table('ib_contents')->insert($contents)->save();

        // ── User ↔ Course assignment ──
        // user1 (id 5) → course 1 (受講可能コース)
        $this->table('ib_users_courses')->insert([
            [
                'user_id'   => 5,
                'course_id' => 1,
                'started'   => null,
                'ended'     => null,
                'created'   => $now,
                'modified'  => $now,
                'comment'   => null,
            ],
        ])->save();
    }
}
