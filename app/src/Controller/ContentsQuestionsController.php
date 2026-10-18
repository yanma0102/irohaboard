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
use Cake\Datasource\ResultSetInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\ORM\Table;

/**
 * ContentsQuestions Controller
 *
 * CakePHP 5 版
 */
class ContentsQuestionsController extends AppController
{
    /**
     * 出題リストを退避する Cookie 名の接頭辞（末尾にコンテンツIDを付与）
     *
     * ブラウザ側（contents_questions.js の TEST_STATE_COOKIE_PREFIX）と揃える。
     */
    private const TEST_STATE_COOKIE_PREFIX = 'IrohaTestState_';

    /** Cookie へ退避できる出題リストの最大長（1 Cookie 約4KB に対する安全余裕） */
    private const TEST_STATE_COOKIE_MAX_LENGTH = 3500;

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
     * @param string|int $content_id 表示するコンテンツ(テスト)のID
     * @param string|int|null $record_id 履歴ID (テスト結果表示の場合、指定)
     * @return void
     */
    public function index($content_id, $record_id = null): void
    {
        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');
        $contentsTable = $this->fetchTable('Contents');
        $recordsTable = $this->fetchTable('Records');

        $content_id = (int)$content_id;
        $record_id = $record_id !== null ? (int)$record_id : null;

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
        $question_id_list = [];

        if ($record_id !== null) {
            // テスト結果表示モード
            $record = $recordsTable->get($record_id, contain: ['RecordsQuestions']);

            if (!$this->isAdminPage() && $this->isRecordPage() && ($record->user_id != $this->readAuthUser('id'))) {
                throw new NotFoundException(__('Invalid access'));
            }

            // テスト結果に紐づく問題ID一覧（出題順）を作成
            $recordQuestions = $this->fetchTable('RecordsQuestions')
                ->find()
                ->where(['record_id' => $record_id])
                ->all();

            foreach ($recordQuestions as $rq) {
                $question_id_list[] = (int)$rq->question_id;
            }

            $contentsQuestions = $this->findQuestionsByIdList(
                $contentsQuestionsTable,
                $content_id,
                $question_id_list,
            );
        } else {
            // 出題する問題ID一覧を決定（セッション切れ時の復元用にリクエスト指定を優先）
            $question_id_list = $this->resolveQuestionIdList($content_id, (int)$content->question_count);

            if ($question_id_list !== []) {
                $contentsQuestions = $this->findQuestionsByIdList(
                    $contentsQuestionsTable,
                    $content_id,
                    $question_id_list,
                );
            } else {
                // 通常の出題（全問を sort_no 順で出題）
                $contentsQuestions = $contentsQuestionsTable->find()
                    ->where(['content_id' => $content_id])
                    ->orderBy([$contentsQuestionsTable->aliasField('sort_no') => 'ASC'])
                    ->all();

                foreach ($contentsQuestions as $cq) {
                    $question_id_list[] = (int)$cq->id;
                }
            }
        }

        // 採点処理
        if ($this->request->is('post')) {
            if (Configure::read('demo_mode')) {
                $this->Flash->error(__('デモモードでは保存できません'));
                $this->redirect(['action' => 'index', $content_id]);

                return;
            }

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
                    $is_correct = $answer == $correct ? 1 : 0;
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

            $pass_score = $full_score * $pass_rate / 100;
            $is_passed = $my_score >= $pass_score ? 1 : 0;
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
                $this->deleteTestStateCookie($content_id);

                $this->redirect(['action' => 'record', $content_id, $newRecordId]);
            }
        }

        $is_record = $this->isRecordPage();
        $is_admin_record = $this->isAdminPage() && $this->isRecordPage();

        $this->set(compact('content', 'contentsQuestions', 'record', 'is_record', 'is_admin_record'));
        $this->set('question_id_list', $question_id_list);
    }

    /**
     * テスト結果を表示
     *
     * @param string|int $content_id コンテンツID
     * @param string|int $record_id 履歴ID
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
     * 出題する問題ID一覧（出題順）を決定する
     *
     * セッションが切れた場合でも試験を復元できるよう、リクエストで指定された
     * 出題リストを最優先で受け付ける。指定が無い場合はセッション →
     * ランダム抽選の順に決定する。
     *
     * @param int $content_id コンテンツID
     * @param int $question_count 出題数（0 の場合は全問出題）
     * @return array<int> 出題する問題ID一覧（空の場合は全問出題）
     */
    private function resolveQuestionIdList(int $content_id, int $question_count): array
    {
        // 1. リクエストで指定された出題リスト（セッション切れ後の復元用）
        $requested = $this->getRequestedQuestionIdList($content_id, $question_count);

        if ($requested !== []) {
            $this->writeSession('Iroha.RondomQuestions.' . $content_id . '.id_list', $requested);

            return $requested;
        }

        // 2. セッションに保持している出題リスト
        $sessionList = $this->readSession('Iroha.RondomQuestions.' . $content_id . '.id_list');

        if (is_array($sessionList) && $sessionList !== []) {
            return array_map('intval', $sessionList);
        }

        // 3. ランダム出題
        if ($question_count > 0) {
            $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');

            $contentsQuestions = $contentsQuestionsTable->find()
                ->where(['content_id' => $content_id])
                ->limit($question_count)
                ->orderBy($contentsQuestionsTable->find()->expr()->add('RAND()'))
                ->all();

            $question_id_list = [];

            foreach ($contentsQuestions as $cq) {
                $question_id_list[] = (int)$cq->id;
            }

            if ($question_id_list !== []) {
                $this->writeSession('Iroha.RondomQuestions.' . $content_id . '.id_list', $question_id_list);
            }

            return $question_id_list;
        }

        // 4. 全問出題（問題ID一覧は呼び出し元で全問から作成する）
        return [];
    }

    /**
     * リクエストで指定された出題リストを取得する
     *
     * セッション切れでログイン画面へ遷移した後に、ブラウザへ退避した回答を
     * 同じ出題内容で復元するために使用する。改ざん防止のため、対象コンテンツの
     * 問題であること・出題数と一致することを検証する。
     *
     * @param int $content_id コンテンツID
     * @param int $question_count 出題数（0 の場合は全問出題）
     * @return array<int> 妥当な出題リスト（不正な場合は空配列）
     */
    private function getRequestedQuestionIdList(int $content_id, int $question_count): array
    {
        // 全問出題の場合は出題内容が固定のため、リクエスト指定は受け付けない
        if ($question_count <= 0) {
            return [];
        }

        // 1. リクエストで指定された出題リスト（採点時の隠し項目、URL 指定）
        $question_id_list = $this->parseQuestionIdList(
            $this->request->getData('question_id_list') ?? $this->request->getQuery('question_id_list'),
        );

        // 2. ブラウザへ退避した出題リスト（Cookie）
        //    セッションが切れた後でも、テスト画面を開いた最初のリクエストで
        //    同じ出題内容を返せるようにする（復元のための再読込を不要にする）。
        if ($question_id_list === []) {
            $question_id_list = $this->parseQuestionIdList($this->getTestStateCookieIdList($content_id));
        }

        if ($question_id_list === []) {
            return [];
        }

        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');
        $total = $contentsQuestionsTable->find()->where(['content_id' => $content_id])->count();

        // 出題数と一致しない出題リストは受け付けない（問題数の改ざん防止）
        if (count($question_id_list) !== min($question_count, $total)) {
            return [];
        }

        // 対象コンテンツの問題であることを確認
        $found = $contentsQuestionsTable->find()
            ->where([
                'content_id' => $content_id,
                $contentsQuestionsTable->aliasField('id') . ' IN' => $question_id_list,
            ])
            ->count();

        if ($found !== count($question_id_list)) {
            return [];
        }

        return $question_id_list;
    }

    /**
     * カンマ区切りの出題リスト文字列を検証して整数配列にする
     *
     * @param mixed $raw 出題リスト文字列（不正な型は空配列）
     * @return array<int> 妥当な出題リスト（不正な場合は空配列）
     */
    private function parseQuestionIdList(mixed $raw): array
    {
        if (!is_string($raw) && !is_int($raw)) {
            return [];
        }

        $question_id_list = [];

        foreach (explode(',', (string)$raw) as $value) {
            $value = trim($value);

            if ($value === '' || !ctype_digit($value)) {
                return [];
            }

            $question_id = (int)$value;

            if ($question_id <= 0 || in_array($question_id, $question_id_list, true)) {
                return [];
            }

            $question_id_list[] = $question_id;
        }

        return $question_id_list;
    }

    /**
     * Cookie に退避された出題リスト文字列を取得する
     *
     * 形式は `<ユーザID>-<問題ID>-<問題ID>-...`。他ユーザの出題内容を復元しない
     * よう、ログイン中のユーザID と一致する場合のみ返す。
     *
     * @param int $content_id コンテンツID
     * @return string|null 出題リスト文字列（退避されていない場合は null）
     */
    private function getTestStateCookieIdList(int $content_id): ?string
    {
        $cookie = (string)$this->readCookie($this->getTestStateCookieName($content_id));

        // 長すぎる値（Cookie 上限超過・改ざん）は受け付けない
        if ($cookie === '' || strlen($cookie) > self::TEST_STATE_COOKIE_MAX_LENGTH) {
            return null;
        }

        $parts = explode('-', $cookie);
        $user_id = array_shift($parts);

        if ($user_id === null || !ctype_digit($user_id) || (int)$user_id !== (int)$this->readAuthUser('id')) {
            return null;
        }

        return $parts === [] ? null : implode(',', $parts);
    }

    /**
     * 出題リストを退避する Cookie 名を取得する
     *
     * @param int $content_id コンテンツID
     * @return string Cookie 名
     */
    private function getTestStateCookieName(int $content_id): string
    {
        return self::TEST_STATE_COOKIE_PREFIX . $content_id;
    }

    /**
     * 出題リストを退避した Cookie を削除する（採点完了時）
     *
     * @param int $content_id コンテンツID
     * @return void
     */
    private function deleteTestStateCookie(int $content_id): void
    {
        $this->deleteCookie($this->getTestStateCookieName($content_id));
    }

    /**
     * 指定された問題ID一覧の問題を出題順に取得する
     *
     * @param \Cake\ORM\Table $contentsQuestionsTable 問題テーブル
     * @param int $content_id コンテンツID
     * @param array<int> $question_id_list 問題ID一覧（出題順）
     * @return \Cake\Datasource\ResultSetInterface<int, \Cake\Datasource\EntityInterface>
     */
    private function findQuestionsByIdList(
        Table $contentsQuestionsTable,
        int $content_id,
        array $question_id_list,
    ): ResultSetInterface {
        $question_id_list = array_map('intval', $question_id_list);

        // IN 句に空配列を渡すと例外になるため、該当無しの条件を返す
        if ($question_id_list === []) {
            return $contentsQuestionsTable->find()
                ->where(['content_id' => $content_id, $contentsQuestionsTable->aliasField('id') . ' <' => 0])
                ->all();
        }

        return $contentsQuestionsTable->find()
            ->where([
                'content_id' => $content_id,
                $contentsQuestionsTable->aliasField('id') . ' IN' => $question_id_list,
            ])
            ->orderBy($contentsQuestionsTable->find()->expr()->add('FIELD(' . $contentsQuestionsTable->aliasField('id') . ',' . implode(',', $question_id_list) . ')'))
            ->all();
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
