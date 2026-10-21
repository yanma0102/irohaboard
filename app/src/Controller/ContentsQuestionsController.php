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
use Cake\Datasource\EntityInterface;
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

    /** 練習状態の有効期限（秒） */
    private const PRACTICE_STATE_TTL_SEC = 24 * 60 * 60;

    /** 未分類カテゴリを表すフォーム上の値 */
    private const PRACTICE_NONE_CATEGORY = '__none__';

    /** 制限時間の上限が未設定の場合に選べる最大値（分） */
    private const PRACTICE_TIMELIMIT_MAX = 100;

    /**
     * 初期化
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        // practiceGrade は answer_* を動的に送信するため、FormProtection の対象外にする
        $this->FormProtection->unlockActions(['index', 'practiceGrade']);
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

            // 練習と共通の採点ロジックを使用する（判定・保存内容は従来どおり）
            $graded = $this->gradeQuestions($contentsQuestions);
            $details = $graded['details'];
            $full_score = $graded['full_score'];
            $my_score = $graded['my_score'];
            $pass_rate = $content->pass_rate;

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
     * 練習の設定画面を表示する（POST の場合は設定を検証して練習を開始する）
     *
     * @param string|int $content_id コンテンツID
     * @return void
     */
    public function practice($content_id): void
    {
        $content_id = (int)$content_id;
        $content = $this->loadPracticeContent($content_id);
        $categories = $this->getPracticeCategories($content_id);

        $errors = [];
        $settings = $this->getPracticeDefaultSettings($content, $categories);

        if ($this->request->is('post')) {
            [$settings, $errors] = $this->validatePracticeSettings($content, $categories, [
                'categories' => $this->getData('categories'),
                'question_count' => $this->getData('question_count'),
                'timelimit' => $this->getData('timelimit'),
                'order' => $this->getData('order'),
                'wrong_mode' => $this->getData('wrong_mode'),
            ]);

            if ($errors === []) {
                $question_id_list = $this->selectPracticeQuestionIds($content_id, $settings);

                if ($question_id_list === []) {
                    $errors[] = __('出題できる問題がありません。');
                } else {
                    // 前回の状態・結果は破棄してから開始する
                    $this->deletePracticeState($content_id);
                    $this->deletePracticeResult($content_id);

                    $this->writePracticeState($content_id, [
                        'settings' => $settings,
                        'question_id_list' => $question_id_list,
                        'started_at' => time(),
                    ]);
                    $this->redirect(['action' => 'practiceRun', $content_id]);

                    return;
                }
            }

            foreach ($errors as $error) {
                $this->Flash->error($error);
            }
        } else {
            // 「もう一度練習」「設定を変更」用に直前の結果の設定を引き継ぐ
            $result = $this->readPracticeResult($content_id);

            if (is_array($result) && isset($result['settings']) && is_array($result['settings'])) {
                $settings = $this->getPracticeSettingsForForm($content, $categories, $result['settings']);
            }
        }

        $selected_category_keys = array_map(
            fn(string $value): string => $value === '' ? self::PRACTICE_NONE_CATEGORY : $value,
            $settings['categories'],
        );

        $timelimit_choices = $this->getPracticeTimelimitChoices($content);
        $wrong_mode_options = Configure::read('wrong_mode');

        $this->set(compact(
            'content',
            'categories',
            'settings',
            'selected_category_keys',
            'timelimit_choices',
            'wrong_mode_options',
            'errors',
        ));
    }

    /**
     * 練習の受験画面を表示する
     *
     * @param string|int $content_id コンテンツID
     * @return void
     */
    public function practiceRun($content_id): void
    {
        $content_id = (int)$content_id;
        $content = $this->loadPracticeContent($content_id);

        $state = $this->readPracticeState($content_id);

        if ($state === null) {
            $this->Flash->error(__('練習の状態が見つかりません。設定画面からやり直してください。'));
            $this->redirect(['action' => 'practice', $content_id]);

            return;
        }

        $question_id_list = $state['question_id_list'];
        $contentsQuestions = $this->findQuestionsByIdList(
            $this->fetchTable('ContentsQuestions'),
            $content_id,
            $question_id_list,
        );

        if (count($contentsQuestions) === 0) {
            $this->deletePracticeState($content_id);
            $this->Flash->error(__('出題できる問題がありません。設定画面からやり直してください。'));
            $this->redirect(['action' => 'practice', $content_id]);

            return;
        }

        $is_practice = true;
        $is_record = false;
        $is_admin_record = false;
        $practice_settings = $state['settings'];
        $timelimit = (int)$practice_settings['timelimit'];

        $this->set(compact(
            'content',
            'contentsQuestions',
            'is_practice',
            'is_record',
            'is_admin_record',
            'practice_settings',
            'timelimit',
        ));
        $this->set('question_id_list', $question_id_list);
        $this->viewBuilder()->setTemplate('index');
    }

    /**
     * 練習の採点を行う（記録は保存しない）
     *
     * @param string|int $content_id コンテンツID
     * @return void
     */
    public function practiceGrade($content_id): void
    {
        $this->request->allowMethod(['post']);

        $content_id = (int)$content_id;
        $this->loadPracticeContent($content_id);
        $state = $this->readPracticeState($content_id);

        if ($state === null) {
            $this->Flash->error(__('練習の状態が見つかりません。設定画面からやり直してください。'));
            $this->redirect(['action' => 'practice', $content_id]);

            return;
        }

        // セッションの出題リストと完全一致する場合のみ採点する（改ざん防止）
        $submitted = $this->parseQuestionIdList($this->getData('question_id_list'));

        if (!$this->isValidPracticeQuestionIdList($state['question_id_list'], $submitted)) {
            $this->deletePracticeState($content_id);
            $this->Flash->error(__('出題内容が変更されたため採点できません。設定画面からやり直してください。'));
            $this->redirect(['action' => 'practice', $content_id]);

            return;
        }

        $contentsQuestions = $this->findQuestionsByIdList(
            $this->fetchTable('ContentsQuestions'),
            $content_id,
            $state['question_id_list'],
        );

        if (count($contentsQuestions) === 0) {
            $this->deletePracticeState($content_id);
            $this->Flash->error(__('出題できる問題がありません。設定画面からやり直してください。'));
            $this->redirect(['action' => 'practice', $content_id]);

            return;
        }

        $graded = $this->gradeQuestions($contentsQuestions);

        $studySecData = $this->getData('ContentsQuestion');
        $study_sec = is_array($studySecData) ? (int)($studySecData['study_sec'] ?? 0) : 0;
        $study_sec = max(0, min(86400, $study_sec));

        $result = [
            'settings' => $state['settings'],
            'question_id_list' => $state['question_id_list'],
            'details' => $graded['details'],
            'full_score' => $graded['full_score'],
            'my_score' => $graded['my_score'],
            'correct_count' => $graded['correct_count'],
            'question_count' => count($graded['details']),
            'study_sec' => $study_sec,
            'finished_at' => time(),
        ];

        // 採点成功時に状態を削除する（二重送信時は設定画面へ戻る）
        $this->deletePracticeState($content_id);
        $this->writePracticeResult($content_id, $result);

        $this->redirect(['action' => 'practiceResult', $content_id]);
    }

    /**
     * 練習の結果画面を表示する
     *
     * @param string|int $content_id コンテンツID
     * @return void
     */
    public function practiceResult($content_id): void
    {
        $content_id = (int)$content_id;
        $content = $this->loadPracticeContent($content_id);
        $result = $this->readPracticeResult($content_id);

        if ($result === null) {
            $this->Flash->error(__('練習結果が見つかりません。設定画面からやり直してください。'));
            $this->redirect(['action' => 'practice', $content_id]);

            return;
        }

        $contentsQuestions = $this->findQuestionsByIdList(
            $this->fetchTable('ContentsQuestions'),
            $content_id,
            $result['question_id_list'],
        );

        // 設問IDをキーにした結果（設問カードの共通 element が参照する形式）
        $question_records = [];

        foreach ($result['details'] as $detail) {
            $question_records[(int)$detail['question_id']] = $detail;
        }

        $is_practice = true;
        $is_record = true;
        $is_admin_record = false;
        $practice_settings = $result['settings'];
        $practice_result = $result;
        $timelimit = (int)$practice_settings['timelimit'];

        $this->set(compact(
            'content',
            'contentsQuestions',
            'question_records',
            'is_practice',
            'is_record',
            'is_admin_record',
            'practice_settings',
            'practice_result',
            'timelimit',
        ));
        $this->set('question_id_list', $result['question_id_list']);
    }

    /**
     * 練習の対象コンテンツを取得し、アクセス条件を検証する
     *
     * @param int $content_id コンテンツID
     * @return \Cake\Datasource\EntityInterface
     */
    private function loadPracticeContent(int $content_id): EntityInterface
    {
        $content = $this->fetchTable('Contents')->get($content_id, contain: ['Courses']);

        // 練習はテストコンテンツのみ・許可されたコンテンツのみ
        if ($content->get('kind') !== 'test' || (int)$content->get('allow_custom_test') !== 1) {
            throw new NotFoundException(__('Invalid access'));
        }

        if (!$this->isAdminPage()) {
            $coursesTable = $this->fetchTable('Courses');
            $userId = (int)$this->readAuthUser('id');
            $courseId = (int)$content->get('course_id');

            if (!$coursesTable->hasRight($userId, $courseId)) {
                throw new NotFoundException(__('Invalid access'));
            }
        }

        if ($this->readAuthUser('role') !== 'admin' && (int)$content->get('status') !== 1) {
            throw new NotFoundException(__('Invalid access'));
        }

        $questionCount = $this->fetchTable('ContentsQuestions')
            ->find()
            ->where(['content_id' => $content_id])
            ->count();

        if ($questionCount === 0) {
            throw new NotFoundException(__('Invalid access'));
        }

        return $content;
    }

    /**
     * 練習で選べるカテゴリと問題数の一覧を取得する
     *
     * @param int $content_id コンテンツID
     * @return array<int, array{value: string, key: string, label: string, count: int}>
     */
    private function getPracticeCategories(int $content_id): array
    {
        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');

        $query = $contentsQuestionsTable->find();
        $query
            ->select([
                'category' => $contentsQuestionsTable->aliasField('category'),
                'question_count' => $query->func()->count('*'),
            ])
            ->where([$contentsQuestionsTable->aliasField('content_id') => $content_id])
            ->groupBy([$contentsQuestionsTable->aliasField('category')]);

        $categories = [];

        foreach ($query->all() as $row) {
            $category = $row->get('category');
            $value = $category === null ? '' : (string)$category;
            $key = $value === '' ? self::PRACTICE_NONE_CATEGORY : $value;

            if (!isset($categories[$key])) {
                $categories[$key] = [
                    'value' => $value,
                    'key' => $key,
                    'label' => $value === '' ? __('未分類') : $value,
                    'count' => 0,
                ];
            }

            $categories[$key]['count'] += (int)$row->get('question_count');
        }

        $categories = array_values($categories);

        // 未分類は最後に表示する
        usort($categories, function (array $a, array $b): int {
            if ($a['value'] === '') {
                return 1;
            }

            if ($b['value'] === '') {
                return -1;
            }

            return strcmp($a['label'], $b['label']);
        });

        return $categories;
    }

    /**
     * 練習設定の初期値を取得する
     *
     * @param \Cake\Datasource\EntityInterface $content コンテンツ
     * @param array<int, array{value: string, key: string, label: string, count: int}> $categories カテゴリ一覧
     * @return array<string, mixed> 設定
     */
    private function getPracticeDefaultSettings(EntityInterface $content, array $categories): array
    {
        $timelimitMax = $this->getPracticeTimelimitMax($content);
        $timelimit = (int)($content->get('timelimit') ?? 0);

        if ($timelimitMax > 0) {
            // 上限が設定されている場合は 0（なし）を許可しない
            $timelimit = $timelimit > 0 ? min(max($timelimit, 1), $timelimitMax) : $timelimitMax;
        } else {
            $timelimit = min(max($timelimit, 0), self::PRACTICE_TIMELIMIT_MAX);
        }

        return [
            'categories' => array_map(fn(array $category): string => $category['value'], $categories),
            'question_count' => 0,
            'timelimit' => $timelimit,
            'order' => 'random',
            'wrong_mode' => (int)$content->get('wrong_mode'),
            'available_count' => array_sum(array_column($categories, 'count')),
        ];
    }

    /**
     * 直前の練習結果の設定を、現在のカテゴリ・上限に合わせて設定画面用に整える
     *
     * @param \Cake\Datasource\EntityInterface $content コンテンツ
     * @param array<int, array{value: string, key: string, label: string, count: int}> $categories カテゴリ一覧
     * @param array<string, mixed> $saved 保存済みの設定
     * @return array<string, mixed> 設定
     */
    private function getPracticeSettingsForForm(EntityInterface $content, array $categories, array $saved): array
    {
        $savedCategories = $saved['categories'] ?? [];

        if (!is_array($savedCategories)) {
            $savedCategories = [];
        }

        // 保存時はカテゴリ値を保持しているため、フォームのキーへ戻す
        $categoryKeys = array_map(
            fn($value): string => $value === '' || $value === null
                ? self::PRACTICE_NONE_CATEGORY
                : (string)$value,
            $savedCategories,
        );

        [$settings] = $this->validatePracticeSettings($content, $categories, [
            'categories' => $categoryKeys,
            'question_count' => $saved['question_count'] ?? 0,
            'timelimit' => $saved['timelimit'] ?? 0,
            'order' => $saved['order'] ?? 'random',
            'wrong_mode' => $saved['wrong_mode'] ?? null,
        ]);

        return $settings;
    }

    /**
     * 練習の設定入力を正規化・検証する
     *
     * @param \Cake\Datasource\EntityInterface $content コンテンツ
     * @param array<int, array{value: string, key: string, label: string, count: int}> $categories カテゴリ一覧
     * @param array<string, mixed> $input 入力値
     * @return array{0: array<string, mixed>, 1: array<int, string>} 設定とエラー一覧
     */
    private function validatePracticeSettings(EntityInterface $content, array $categories, array $input): array
    {
        $errors = [];
        $timelimitMax = $this->getPracticeTimelimitMax($content);

        $categoryCounts = [];

        foreach ($categories as $category) {
            $categoryCounts[$category['key']] = (int)$category['count'];
        }

        // 出題範囲（空 = すべて）
        $rawCategories = $input['categories'] ?? null;

        if ($rawCategories === null || $rawCategories === '') {
            $rawCategories = [];
        }

        $selectedKeys = [];

        if (!is_array($rawCategories)) {
            $errors[] = __('出題範囲の指定が不正です。');
        } else {
            if (count($rawCategories) > 100) {
                $errors[] = __('出題範囲は100件以内で指定してください。');
                $rawCategories = array_slice($rawCategories, 0, 100);
            }

            foreach ($rawCategories as $key) {
                if (!is_string($key)) {
                    $errors[] = __('出題範囲の指定が不正です。');
                    continue;
                }

                if (mb_strlen($key) > 100) {
                    $errors[] = __('カテゴリ名は100文字以内で指定してください。');
                    continue;
                }

                if (!array_key_exists($key, $categoryCounts)) {
                    $errors[] = __('存在しないカテゴリが指定されました。');
                    continue;
                }

                $selectedKeys[$key] = true;
            }
        }

        if ($selectedKeys === []) {
            $selectedKeys = array_fill_keys(array_keys($categoryCounts), true);
        }

        $storedCategories = [];
        $availableCount = 0;

        foreach (array_keys($selectedKeys) as $key) {
            $availableCount += $categoryCounts[$key];
            $storedCategories[] = $key === self::PRACTICE_NONE_CATEGORY ? '' : $key;
        }

        sort($storedCategories);

        if ($availableCount <= 0) {
            $errors[] = __('選択した出題範囲に問題がありません。');
        }

        // 出題数（0 = 全問）
        $questionCount = 0;
        $rawCount = $input['question_count'] ?? '';

        if ($rawCount === '') {
            $questionCount = 0;
        } elseif (!is_scalar($rawCount) || !preg_match('/^-?\d+$/', trim((string)$rawCount))) {
            $errors[] = __('出題数は整数で指定してください。');
        } else {
            $questionCount = (int)$rawCount;

            if ($questionCount < 0) {
                $errors[] = __('出題数は0以上で指定してください。');
                $questionCount = 0;
            } elseif ($questionCount > $availableCount) {
                $errors[] = __('出題数は出題範囲の問題数以内で指定してください。');
                $questionCount = $availableCount;
            }
        }

        // 制限時間（上限設定時は 0 を許可しない）
        $timelimit = 0;
        $rawTimelimit = $input['timelimit'] ?? '';

        if (!is_scalar($rawTimelimit) || !preg_match('/^-?\d+$/', trim((string)$rawTimelimit))) {
            $errors[] = __('制限時間は整数で指定してください。');
        } else {
            $timelimit = (int)$rawTimelimit;

            if ($timelimitMax > 0) {
                if ($timelimit < 1 || $timelimit > $timelimitMax) {
                    $errors[] = sprintf(__('制限時間は1〜%d分で指定してください。'), $timelimitMax);
                    $timelimit = min(max($timelimit, 1), $timelimitMax);
                }
            } elseif ($timelimit < 0 || $timelimit > self::PRACTICE_TIMELIMIT_MAX) {
                $errors[] = sprintf(__('制限時間は0〜%d分で指定してください。'), self::PRACTICE_TIMELIMIT_MAX);
                $timelimit = min(max($timelimit, 0), self::PRACTICE_TIMELIMIT_MAX);
            }
        }

        // 出題順
        $order = is_scalar($input['order'] ?? null) ? (string)$input['order'] : '';

        if (!in_array($order, ['random', 'sort'], true)) {
            $errors[] = __('出題順の指定が不正です。');
            $order = 'random';
        }

        // 不正解時の表示
        $rawWrongMode = $input['wrong_mode'] ?? null;

        if (!is_scalar($rawWrongMode) || !in_array((string)$rawWrongMode, ['0', '1', '2'], true)) {
            $errors[] = __('不正解時の表示の指定が不正です。');
            $wrongMode = (int)$content->get('wrong_mode');
        } else {
            $wrongMode = (int)$rawWrongMode;
        }

        return [
            [
                'categories' => $storedCategories,
                'question_count' => $questionCount,
                'timelimit' => $timelimit,
                'order' => $order,
                'wrong_mode' => $wrongMode,
                'available_count' => $availableCount,
            ],
            $errors,
        ];
    }

    /**
     * 練習で選べる制限時間（分）の一覧を取得する
     *
     * @param \Cake\Datasource\EntityInterface $content コンテンツ
     * @return array<int, string> 選択肢
     */
    private function getPracticeTimelimitChoices(EntityInterface $content): array
    {
        $timelimitMax = $this->getPracticeTimelimitMax($content);
        $choices = [];

        if ($timelimitMax > 0) {
            for ($minutes = 1; $minutes <= $timelimitMax; $minutes++) {
                $choices[$minutes] = sprintf(__('%d分'), $minutes);
            }

            return $choices;
        }

        $choices[0] = __('なし');

        for ($minutes = 1; $minutes <= self::PRACTICE_TIMELIMIT_MAX; $minutes++) {
            $choices[$minutes] = sprintf(__('%d分'), $minutes);
        }

        return $choices;
    }

    /**
     * 練習の制限時間の上限（分）を取得する（0 は上限なし）
     *
     * @param \Cake\Datasource\EntityInterface $content コンテンツ
     * @return int 上限
     */
    private function getPracticeTimelimitMax(EntityInterface $content): int
    {
        $timelimitMax = (int)($content->get('practice_timelimit_max') ?? 0);

        return min(max($timelimitMax, 0), self::PRACTICE_TIMELIMIT_MAX);
    }

    /**
     * 出題範囲から練習の出題リストを作成する
     *
     * 出題数は範囲内からランダムに選び、登録順は表示順のみに適用する。
     *
     * @param int $content_id コンテンツID
     * @param array<string, mixed> $settings 設定
     * @return array<int> 出題リスト（出題順）
     */
    private function selectPracticeQuestionIds(int $content_id, array $settings): array
    {
        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');

        $query = $contentsQuestionsTable->find()
            ->select([
                $contentsQuestionsTable->aliasField('id'),
                $contentsQuestionsTable->aliasField('sort_no'),
            ])
            ->where([$contentsQuestionsTable->aliasField('content_id') => $content_id]);

        $categoryValues = [];

        foreach ((array)($settings['categories'] ?? []) as $category) {
            if (is_string($category)) {
                $categoryValues[] = $category;
            }
        }

        $conditions = $this->getPracticeCategoryConditions($categoryValues);

        if ($conditions !== null) {
            $query->where(['OR' => $conditions]);
        }

        $questions = $query->all()->toArray();

        if ($questions === []) {
            return [];
        }

        shuffle($questions);

        $questionCount = (int)$settings['question_count'];

        if ($questionCount > 0 && $questionCount < count($questions)) {
            $questions = array_slice($questions, 0, $questionCount);
        }

        // 登録順は「選んだ問題の表示順」のみを並べ替える
        if ($settings['order'] === 'sort') {
            usort($questions, function (EntityInterface $a, EntityInterface $b): int {
                $sort = (int)$a->get('sort_no') <=> (int)$b->get('sort_no');

                return $sort !== 0 ? $sort : ((int)$a->get('id') <=> (int)$b->get('id'));
            });
        }

        return array_map(fn(EntityInterface $question): int => (int)$question->get('id'), $questions);
    }

    /**
     * 練習の出題範囲に対応するクエリ条件を作成する
     *
     * @param array<int, string> $categories カテゴリ（空文字は未分類）
     * @return array<int, array<string, mixed>>|null 条件（すべての場合は null）
     */
    private function getPracticeCategoryConditions(array $categories): ?array
    {
        if ($categories === []) {
            return null;
        }

        $conditions = [];

        foreach ($categories as $category) {
            if ($category === '') {
                // NULL と空文字の両方を未分類として扱う
                $conditions[] = ['category IS' => null];
                $conditions[] = ['category' => ''];
            } else {
                $conditions[] = ['category' => $category];
            }
        }

        return $conditions;
    }

    /**
     * 送信された出題リストがセッションの状態と完全一致するか検証する
     *
     * @param array<int> $stateQuestionIdList セッションの出題リスト
     * @param array<int> $submittedQuestionIdList 送信された出題リスト
     * @return bool 一致する場合 true
     */
    private function isValidPracticeQuestionIdList(array $stateQuestionIdList, array $submittedQuestionIdList): bool
    {
        return $submittedQuestionIdList !== []
            && $submittedQuestionIdList === array_values(array_map('intval', $stateQuestionIdList));
    }

    /**
     * 設問を採点する（正式受験・練習の共通処理）
     *
     * @param \Cake\Datasource\ResultSetInterface<int, \Cake\Datasource\EntityInterface> $questions 設問一覧
     * @return array{details: array<int, array<string, mixed>>, full_score: int, my_score: int, correct_count: int} 採点結果
     */
    private function gradeQuestions(ResultSetInterface $questions): array
    {
        $details = [];
        $full_score = 0;
        $my_score = 0;
        $correct_count = 0;

        foreach ($questions as $cq) {
            $question_id = (int)$cq->get('id');
            $answer = $this->getData('answer_' . $question_id);

            $correct = (string)$cq->get('correct');
            $corrects = explode(',', $correct);
            $score = (int)$cq->get('score');

            if (count($corrects) > 1) {
                $is_correct = $this->isMultiCorrect($answer, $corrects) ? 1 : 0;
                $answer = is_array($answer) ? implode(',', $answer) : null;
            } else {
                $is_correct = $answer == $correct ? 1 : 0;
            }

            $full_score += $score;

            if ($is_correct == 1) {
                $my_score += $score;
                $correct_count++;
            }

            $details[] = [
                'question_id' => $question_id,
                'answer' => $answer,
                'correct' => $correct,
                'is_correct' => $is_correct,
                'score' => $score,
            ];
        }

        return [
            'details' => $details,
            'full_score' => $full_score,
            'my_score' => $my_score,
            'correct_count' => $correct_count,
        ];
    }

    /**
     * 練習の状態を保存するセッションキーを取得する
     *
     * @param int $content_id コンテンツID
     * @return string セッションキー
     */
    private function getPracticeStateKey(int $content_id): string
    {
        return 'Iroha.Practice.' . (int)$this->readAuthUser('id') . '.' . $content_id . '.state';
    }

    /**
     * 練習の結果を保存するセッションキーを取得する
     *
     * @param int $content_id コンテンツID
     * @return string セッションキー
     */
    private function getPracticeResultKey(int $content_id): string
    {
        return 'Iroha.Practice.' . (int)$this->readAuthUser('id') . '.' . $content_id . '.result';
    }

    /**
     * 練習の状態を取得する（期限切れは破棄）
     *
     * @param int $content_id コンテンツID
     * @return array<string, mixed>|null 状態（無い・期限切れの場合は null）
     */
    private function readPracticeState(int $content_id): ?array
    {
        $state = $this->readSession($this->getPracticeStateKey($content_id));

        if (
            !is_array($state)
            || !isset($state['settings'], $state['question_id_list'], $state['started_at'])
            || !is_array($state['settings'])
            || !is_array($state['question_id_list'])
        ) {
            return null;
        }

        if (time() - (int)$state['started_at'] > self::PRACTICE_STATE_TTL_SEC) {
            $this->deletePracticeState($content_id);

            return null;
        }

        $state['question_id_list'] = array_values(array_map('intval', $state['question_id_list']));

        return $state;
    }

    /**
     * 練習の状態を保存する
     *
     * @param int $content_id コンテンツID
     * @param array<string, mixed> $state 状態
     * @return void
     */
    private function writePracticeState(int $content_id, array $state): void
    {
        $this->writeSession($this->getPracticeStateKey($content_id), $state);
    }

    /**
     * 練習の状態を削除する
     *
     * @param int $content_id コンテンツID
     * @return void
     */
    private function deletePracticeState(int $content_id): void
    {
        $this->deleteSession($this->getPracticeStateKey($content_id));
    }

    /**
     * 練習の結果を取得する
     *
     * @param int $content_id コンテンツID
     * @return array<string, mixed>|null 結果（無い場合は null）
     */
    private function readPracticeResult(int $content_id): ?array
    {
        $result = $this->readSession($this->getPracticeResultKey($content_id));

        if (
            !is_array($result)
            || !isset($result['settings'], $result['question_id_list'], $result['details'])
            || !is_array($result['settings'])
            || !is_array($result['question_id_list'])
            || !is_array($result['details'])
        ) {
            return null;
        }

        $result['question_id_list'] = array_values(array_map('intval', $result['question_id_list']));

        return $result;
    }

    /**
     * 練習の結果を保存する
     *
     * @param int $content_id コンテンツID
     * @param array<string, mixed> $result 結果
     * @return void
     */
    private function writePracticeResult(int $content_id, array $result): void
    {
        $this->writeSession($this->getPracticeResultKey($content_id), $result);
    }

    /**
     * 練習の結果を削除する
     *
     * @param int $content_id コンテンツID
     * @return void
     */
    private function deletePracticeResult(int $content_id): void
    {
        $this->deleteSession($this->getPracticeResultKey($content_id));
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
        //    出題数は管理画面で変更できるため、現在の出題設定と一致する場合のみ使う。
        //    （変更前の出題リストをそのまま使うと、設定した出題数より多く出題される）
        $sessionList = $this->readSession('Iroha.RondomQuestions.' . $content_id . '.id_list');

        if (is_array($sessionList) && $sessionList !== []) {
            $sessionList = array_values(array_map('intval', $sessionList));

            if ($this->isValidQuestionIdList($content_id, $question_count, $sessionList)) {
                return $sessionList;
            }

            // 出題数の変更等で使えない出題リストは破棄し、抽選し直す
            $this->deleteSession('Iroha.RondomQuestions.' . $content_id . '.id_list');
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

        if (!$this->isValidQuestionIdList($content_id, $question_count, $question_id_list)) {
            return [];
        }

        return $question_id_list;
    }

    /**
     * 出題リストが現在の出題設定に対して妥当かを検証する
     *
     * 出題数（question_count）は管理画面で変更できるため、セッションに保持した
     * 過去の出題リストをそのまま使うと、変更後の出題数と食い違う。件数と
     * 対象コンテンツの問題であることを、採用のたびに検証する。
     *
     * @param int $content_id コンテンツID
     * @param int $question_count 出題数（0 の場合は全問出題）
     * @param array<int> $question_id_list 出題リスト
     * @return bool 妥当な場合 true
     */
    private function isValidQuestionIdList(int $content_id, int $question_count, array $question_id_list): bool
    {
        if ($question_id_list === []) {
            return false;
        }

        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');
        $total = $contentsQuestionsTable->find()->where(['content_id' => $content_id])->count();

        // 出題数が未指定（0）の場合は全問出題のため、問題数と一致することを求める
        $expected = $question_count > 0 ? min($question_count, $total) : $total;

        // 出題数と一致しない出題リストは受け付けない（問題数の改ざん防止）
        if (count($question_id_list) !== $expected) {
            return false;
        }

        // 対象コンテンツの問題であることを確認
        $found = $contentsQuestionsTable->find()
            ->where([
                'content_id' => $content_id,
                $contentsQuestionsTable->aliasField('id') . ' IN' => $question_id_list,
            ])
            ->count();

        return $found === count($question_id_list);
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
