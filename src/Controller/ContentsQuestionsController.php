<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Controller;

use Cake\Core\Configure;
use Cake\Http\Exception\NotFoundException;

/**
 * ContentsQuestions Controller
 *
 * CakePHP 5 版
 */
class ContentsQuestionsController extends AppController
{
    /**
     * 初期化
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->FormProtection->unlockActions(['index']);
    }

    /**
     * 問題を出題
     *
     * @param int|string $content_id 表示するコンテンツ(テスト)のID
     * @param int|string|null $record_id 履歴ID (テスト結果表示の場合、指定)
     * @return void
     */
    public function index($content_id, $record_id = null): void
    {
        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');
        $contentsTable = $this->fetchTable('Contents');
        $recordsTable = $this->fetchTable('Records');

        $content_id = (int)$content_id;
        $record_id = ($record_id !== null) ? (int)$record_id : null;

        // コンテンツ情報を取得
        $content = $contentsTable->get($content_id, contain: ['Courses']);

        // 権限チェック
        if (!$this->isAdminPage()) {
            if (!$this->fetchTable('Courses')->hasRight($this->readAuthUser('id'), $content->course_id)) {
                throw new NotFoundException(__('Invalid access'));
            }
        }

        if ($this->readAuthUser('role') !== 'admin' && $content->status != 1) {
            throw new NotFoundException(__('Invalid access'));
        }

        // 問題情報を取得
        $record = null;

        if ($record_id !== null) {
            // テスト結果表示モード
            $record = $recordsTable->get($record_id, contain: ['RecordsQuestions']);

            if (!$this->isAdminPage() && $this->isRecordPage() && ($record->user_id != $this->readAuthUser('id'))) {
                throw new NotFoundException(__('Invalid access'));
            }

            // テスト結果に紐づく問題ID一覧（出題順）を作成
            $question_id_list = [0];
            $recordQuestions = $this->fetchTable('RecordsQuestions')
                ->find()
                ->where(['record_id' => $record_id])
                ->all();

            foreach ($recordQuestions as $rq) {
                $question_id_list[] = $rq->question_id;
            }

            $contentsQuestions = $contentsQuestionsTable->find()
                ->where([
                    'content_id' => $content_id,
                    $contentsQuestionsTable->aliasField('id') . ' IN' => $question_id_list,
                ])
                ->orderBy($contentsQuestionsTable->find()->expr()->add('FIELD(' . $contentsQuestionsTable->aliasField('id') . ',' . implode(',', $question_id_list) . ')'))
                ->all();
        } elseif ($this->readSession('Iroha.RondomQuestions.' . $content_id . '.id_list') !== '') {
            // セッションにランダム出題情報がある場合
            $question_id_list = $this->readSession('Iroha.RondomQuestions.' . $content_id . '.id_list');

            $contentsQuestions = $contentsQuestionsTable->find()
                ->where([
                    'content_id' => $content_id,
                    $contentsQuestionsTable->aliasField('id') . ' IN' => $question_id_list,
                ])
                ->orderBy($contentsQuestionsTable->find()->expr()->add('FIELD(' . $contentsQuestionsTable->aliasField('id') . ',' . implode(',', $question_id_list) . ')'))
                ->all();
        } elseif ($content->question_count > 0) {
            // ランダム出題の場合
            $contentsQuestions = $contentsQuestionsTable->find()
                ->where(['content_id' => $content_id])
                ->limit((int)$content->question_count)
                ->orderBy($contentsQuestionsTable->find()->expr()->add('RAND()'))
                ->all();

            $question_id_list = [];
            foreach ($contentsQuestions as $cq) {
                $question_id_list[] = $cq->id;
            }

            $this->writeSession('Iroha.RondomQuestions.' . $content_id . '.id_list', $question_id_list);
        } else {
            // 通常の出題
            $contentsQuestions = $contentsQuestionsTable->find()
                ->where(['content_id' => $content_id])
                ->orderBy([$contentsQuestionsTable->aliasField('sort_no') => 'ASC'])
                ->all();
        }

        // 採点処理
        if ($this->request->is('post')) {
            $details = [];
            $full_score = 0;
            $pass_score = 0;
            $my_score = 0;
            $pass_rate = $content->pass_rate;

            foreach ($contentsQuestions as $cq) {
                $question_id = $cq->id;
                $answer = $this->getData('answer_' . $question_id);

                $correct = $cq->correct;
                $corrects = explode(',', $correct);
                $score = $cq->score;

                if (count($corrects) > 1) {
                    $is_correct = $this->isMultiCorrect($answer, $corrects) ? 1 : 0;
                    $answer = is_array($answer) ? implode(',', $answer) : null;
                } else {
                    $is_correct = ($answer == $correct) ? 1 : 0;
                }

                $full_score += $score;
                if ($is_correct == 1) {
                    $my_score += $score;
                }

                $details[] = [
                    'question_id' => $question_id,
                    'answer' => $answer,
                    'correct' => $correct,
                    'is_correct' => $is_correct,
                    'score' => $score,
                ];
            }

            $pass_score = ($full_score * $pass_rate) / 100;
            $is_passed = ($my_score >= $pass_score) ? 1 : 0;
            $study_sec = $this->getData('ContentsQuestion')['study_sec'] ?? 0;

            $recordEntity = $recordsTable->newEmptyEntity();
            $recordEntity = $recordsTable->patchEntity($recordEntity, [
                'user_id' => $this->readAuthUser('id'),
                'course_id' => $content->course_id,
                'content_id' => $content_id,
                'full_score' => $full_score,
                'pass_score' => $pass_score,
                'score' => $my_score,
                'is_passed' => $is_passed,
                'study_sec' => $study_sec,
                'is_complete' => 1,
            ]);

            if ($recordsTable->save($recordEntity)) {
                $newRecordId = $recordEntity->id;
                $recordsQuestionsTable = $this->fetchTable('RecordsQuestions');

                foreach ($details as $detail) {
                    $rq = $recordsQuestionsTable->newEmptyEntity();
                    $rq = $recordsQuestionsTable->patchEntity($rq, $detail + ['record_id' => $newRecordId]);
                    $recordsQuestionsTable->save($rq);
                }

                $this->deleteSession('Iroha.RondomQuestions.' . $content_id . '.id_list');

                $this->redirect(['action' => 'record', $content_id, $newRecordId]);
            }
        }

        $is_record = $this->isRecordPage();
        $is_admin_record = $this->isAdminPage() && $this->isRecordPage();

        $this->set(compact('content', 'contentsQuestions', 'record', 'is_record', 'is_admin_record'));
    }

    /**
     * テスト結果を表示
     *
     * @param int|string $content_id コンテンツID
     * @param int|string $record_id 履歴ID
     * @return void
     */
    public function record($content_id, $record_id): void
    {
        $content_id = (int)$content_id;
        $record_id = (int)$record_id;

        $this->index($content_id, $record_id);
        $this->viewBuilder()->setTemplate('index');
    }

    /**
     * 複数選択問題の正誤判定
     *
     * @param array|null $answers 解答
     * @param array $corrects 正解
     * @return bool
     */
    private function isMultiCorrect(?array $answers, array $corrects): bool
    {
        if (!isset($answers) || $answers === null) {
            return false;
        }

        if (count($answers) !== count($corrects)) {
            return false;
        }

        foreach ($answers as $answer) {
            if (!in_array($answer, $corrects)) {
                return false;
            }
        }

        return true;
    }
}
