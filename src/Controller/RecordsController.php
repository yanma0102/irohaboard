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
 * Records Controller
 *
 * CakePHP 5 版
 */
class RecordsController extends AppController
{
    /**
     * 学習履歴一覧を表示
     *
     * @return \Cake\Http\Response|null
     */
    public function admin_index(): ?\Cake\Http\Response
    {
        $recordsTable = $this->fetchTable('Records');

        // 検索条件の構築（Search.Prg に代わり、直接クエリパラメータから構築）
        $conditions = [];

        $group_id = $this->getQuery('group_id');
        $content_category = $this->getQuery('content_category');

        // グループが指定されている場合、指定したグループに所属するユーザの履歴を抽出
        if ($group_id != '') {
            $conditions['User.id'] = $this->fetchTable('Groups')->getUserIdByGroupID($group_id);
        }

        // コンテンツ種別による絞り込み
        if ($content_category == 'study') {
            $conditions['Content.kind'] = ['text', 'html', 'movie', 'url'];
        } elseif ($content_category != '') {
            $conditions['Content.kind'] = $content_category;
        }

        // 対象日時による絞り込み
        $from_date = ($this->hasQuery('from_date'))
            ? implode('-', (array)$this->getQuery('from_date'))
            : date('Y-m-d', strtotime('-1 month'));
        $to_date = ($this->hasQuery('to_date'))
            ? implode('-', (array)$this->getQuery('to_date'))
            : date('Y-m-d');

        $conditions[$recordsTable->aliasField('created BETWEEN ? AND ?')] = [$from_date, $from_date . ' 23:59:59'];

        // CSV出力モード
        if ($this->getQuery('cmd') == 'csv') {
            return $this->_exportCsv($conditions);
        }
        // テスト結果／アンケート回答CSV出力
        if ($this->getQuery('cmd') == 'csv_detail') {
            return $this->_exportCsvDetail($conditions);
        }

        // 一覧表示
        $this->paginate = [
            'conditions' => $conditions,
            'order' => [$recordsTable->aliasField('created') => 'DESC'],
            'limit' => 20,
        ];

        try {
            $records = $this->paginate($recordsTable->find());
        } catch (\Exception $e) {
            $this->request = $this->request->withParam('page', 1);
            $records = $this->paginate($recordsTable->find());
        }

        $groups = $this->fetchTable('Groups')->find('list');
        $courses = $this->fetchTable('Courses')->find('list');

        $this->set(compact('records', 'groups', 'group_id', 'courses', 'content_category', 'from_date', 'to_date'));

        return null;
    }

    /**
     * 学習履歴を追加
     *
     * @param int|string $content_id コンテンツID
     * @return void
     */
    public function add($content_id): void
    {
        $this->autoRender = false;
        $this->request->allowMethod(['post']);

        $content_id = (int)$content_id;

        $contentsTable = $this->fetchTable('Contents');

        if (!$contentsTable->exists(['id' => $content_id])) {
            throw new NotFoundException(__('Invalid content'));
        }

        $content = $contentsTable->get($content_id);

        if (!$this->fetchTable('Courses')->hasRight($this->readAuthUser('id'), $content->course_id)) {
            throw new NotFoundException(__('Invalid access'));
        }

        if ($this->readAuthUser('role') !== 'admin' && $content->status != 1) {
            throw new NotFoundException(__('Invalid access'));
        }

        $data = $this->request->getData();
        $recordsTable = $this->fetchTable('Records');

        $record = $recordsTable->newEmptyEntity();
        $record = $recordsTable->patchEntity($record, [
            'user_id' => $this->readAuthUser('id'),
            'course_id' => $content->course_id,
            'content_id' => $content_id,
            'study_sec' => $data['study_sec'] ?? 0,
            'understanding' => $data['understanding'] ?? 0,
            'is_passed' => -1,
            'is_complete' => $data['is_complete'] ?? 0,
        ]);

        if ($recordsTable->save($record)) {
            $this->Flash->success(__('学習履歴を保存しました'));

            $this->redirect(['controller' => 'Contents', 'action' => 'index', $content->course_id]);
        } else {
            $this->Flash->error(__('The record could not be saved. Please, try again.'));
        }
    }

    /**
     * 学習履歴CSV出力
     *
     * @param array $conditions 検索条件
     * @return \Cake\Http\Response
     */
    protected function _exportCsv(array $conditions): \Cake\Http\Response
    {
        $this->autoRender = false;

        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', (string)(60 * 10));

        $recordsTable = $this->fetchTable('Records');

        $rows = $recordsTable->find()
            ->contain(['Users', 'Courses', 'Contents'])
            ->where($conditions)
            ->order([$recordsTable->aliasField('created') => 'DESC'])
            ->all();

        $header = [
            __('ログインID'),
            __('氏名'),
            __('コース'),
            __('コンテンツ'),
            __('得点'),
            __('合格点'),
            __('結果'),
            __('理解度'),
            __('学習時間'),
            __('学習日時'),
        ];

        $output = fopen('php://output', 'w');

        mb_convert_variables('SJIS-WIN', 'UTF-8', $header);
        fputcsv($output, $header);

        foreach ($rows as $row) {
            $line = [
                $row->user->username ?? '',
                $row->user->name ?? '',
                $row->course->title ?? '',
                $row->content->title ?? '',
                $row->score,
                $row->pass_score,
                Configure::read('record_result.' . $row->is_passed),
                Configure::read('record_understanding.' . $row->understanding),
                \Utils::getHNSBySec($row->study_sec),
                \Utils::getYMDHN($row->created),
            ];

            mb_convert_variables('SJIS-WIN', 'UTF-8', $line);
            fputcsv($output, $line);
        }

        fclose($output);

        return $this->response
            ->withType('csv')
            ->withHeader('Content-Disposition', 'attachment; filename="user_records.csv"');
    }

    /**
     * テスト結果詳細CSV出力
     *
     * @param array $conditions 検索条件
     * @return \Cake\Http\Response
     */
    protected function _exportCsvDetail(array $conditions): \Cake\Http\Response
    {
        $this->autoRender = false;

        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', (string)(60 * 10));

        $conditions['ContentsQuestion.question_type !='] = 'label';

        $recordsQuestionsTable = $this->fetchTable('RecordsQuestions');

        $query = $recordsQuestionsTable->find()
            ->contain(['Records.Users', 'Records.Courses', 'Records.Contents', 'ContentsQuestions'])
            ->where($conditions)
            ->order([
                'Users.name' => 'ASC',
                'Courses.sort_no' => 'ASC',
                'Contents.sort_no' => 'ASC',
                'Records.id' => 'ASC',
                'ContentsQuestions.sort_no' => 'ASC',
            ]);

        $header = [
            __('ログインID'),
            __('氏名'),
            __('コース'),
            __('コンテンツ'),
            __('番号'),
            __('タイトル'),
            __('問題/質問'),
            __('解答/回答'),
            __('正誤'),
            __('学習日時'),
        ];

        $output = fopen('php://output', 'w');

        mb_convert_variables('SJIS-WIN', 'UTF-8', $header);
        fputcsv($output, $header);

        $record_id = '';
        $question_no = 0;

        foreach ($query as $row) {
            if ($record_id !== $row->record->id) {
                $question_no = 1;
            } else {
                $question_no++;
            }

            $record_id = $row->record->id;
            $answer = '';
            $result = '';

            if ($row->contents_question->question_type == 'text') {
                $answer = $row->answer;
                if (preg_match('/^\s*[=\+\-@]/', $answer)) {
                    $answer = "'" . $answer;
                }
            } else {
                $option_list = explode('|', $row->contents_question->options ?? '');
                $answer_list = explode(',', $row->answer ?? '');
                $answer_str_list = [];

                foreach ($answer_list as $a) {
                    $index = (int)$a - 1;
                    $answer_str_list[] = $option_list[$index] ?? '';
                }

                $answer = implode('|', $answer_str_list);

                if (($row->record->content->kind ?? '') == 'test') {
                    $result = Configure::read('is_correct.' . $row->is_correct);
                }
            }

            $line = [
                $row->record->user->username ?? '',
                $row->record->user->name ?? '',
                $row->record->course->title ?? '',
                $row->record->content->title ?? '',
                $question_no,
                $row->contents_question->title ?? '',
                strip_tags($row->contents_question->body ?? ''),
                $answer,
                $result,
                \Utils::getYMDHN($row->record->created ?? ''),
            ];

            mb_convert_variables('SJIS-WIN', 'UTF-8', $line);
            fputcsv($output, $line);
        }

        fclose($output);

        return $this->response
            ->withType('csv')
            ->withHeader('Content-Disposition', 'attachment; filename="record_details.csv"');
    }
}
