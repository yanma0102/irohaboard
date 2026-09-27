<?php
declare(strict_types=1);

use Migrations\BaseSeed;

/**
 * DS-3 Questions Seed
 *
 * Inserts test questions for content_id 7 (test) and enquete questions
 * for content_id 8 (enquete).
 *
 * Question types for test:
 *   - single  (one correct answer)
 *   - single  (multiple correct via comma-separated `correct`)
 *   - text    (free-text, score 0 – boundary)
 *
 * Enquete questions:
 *   - single  (opinion poll style)
 *   - text    (free comment)
 *
 * options are "|" separated; correct contains comma-separated indices (0-based).
 */
class Ds3QuestionsSeed extends BaseSeed
{
    public function isIdempotent(): bool
    {
        return true;
    }

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->execute('DELETE FROM `ib_contents_questions`');

        // Reset AUTO_INCREMENT for stable IDs
        $this->execute('ALTER TABLE `ib_contents_questions` AUTO_INCREMENT = 1');

        $questions = [
            // ── Test questions (content_id = 7) ──

            // Q1: single choice – one correct (index 1 = "はい")
            [
                'content_id'    => 7,
                'question_type' => 'single',
                'title'         => '単一正解の選択問題',
                'body'          => 'CakePHPはPHPフレームワークですか？',
                'image'         => null,
                'options'       => 'いいえ|はい|わからない',
                'correct'       => '1',
                'score'         => 10,
                'explain'       => 'CakePHPは人気のあるPHPフレームワークです。',
                'comment'       => null,
                'created'       => $now,
                'modified'      => $now,
                'sort_no'       => 1,
            ],

            // Q2: single choice – multiple correct (indices 0,2)
            [
                'content_id'    => 7,
                'question_type' => 'single',
                'title'         => '複数正解の選択問題',
                'body'          => '以下のうちPHP関連はどれですか？',
                'image'         => null,
                'options'       => 'PHP|Python|CakePHP|Java',
                'correct'       => '0,2',
                'score'         => 10,
                'explain'       => 'PHPとCakePHPが正解です。',
                'comment'       => null,
                'created'       => $now,
                'modified'      => $now,
                'sort_no'       => 2,
            ],

            // Q3: text – score 0 (boundary)
            [
                'content_id'    => 7,
                'question_type' => 'text',
                'title'         => '記述式問題（スコア0）',
                'body'          => '自由記述の回答欄です。',
                'image'         => null,
                'options'       => null,
                'correct'       => '',
                'score'         => 0,
                'explain'       => '自動採点対象外の記述式問題です。',
                'comment'       => null,
                'created'       => $now,
                'modified'      => $now,
                'sort_no'       => 3,
            ],

            // ── Enquete questions (content_id = 8) ──

            // EQ1: single – opinion
            [
                'content_id'    => 8,
                'question_type' => 'single',
                'title'         => 'アンケート：満足度',
                'body'          => 'このコースの満足度はいかがですか？',
                'image'         => null,
                'options'       => '非常に満足|満足|やや不満|不満',
                'correct'       => '',
                'score'         => 0,
                'explain'       => null,
                'comment'       => null,
                'created'       => $now,
                'modified'      => $now,
                'sort_no'       => 1,
            ],

            // EQ2: text – free comment
            [
                'content_id'    => 8,
                'question_type' => 'text',
                'title'         => 'アンケート：コメント',
                'body'          => 'ご意見・ご要望がございましたら記入してください。',
                'image'         => null,
                'options'       => null,
                'correct'       => '',
                'score'         => 0,
                'explain'       => null,
                'comment'       => null,
                'created'       => $now,
                'modified'      => $now,
                'sort_no'       => 2,
            ],
        ];

        $this->table('ib_contents_questions')->insert($questions)->save();
    }
}
