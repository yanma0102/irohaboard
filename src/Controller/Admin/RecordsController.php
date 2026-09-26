<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Utility\Utils;
use Cake\Core\Configure;
use Cake\Database\Expression\BetweenExpression;
use Cake\Database\Expression\QueryExpression;
use Cake\Http\Response;
use Exception;

/**
 * Records Controller (Admin)
 *
 * CakePHP 5 版
 */
class RecordsController extends AppController
{
    /**
     * 初期化
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->FormProtection->unlockActions([]);
    }

    /**
     * 学習履歴一覧を表示
     *
     * @return \Cake\Http\Response|null
     */
    public function index(): ?Response
    {
        $recordsTable = $this->fetchTable('Records');

        // 検索条件の構築（Search.Prg に代わり、直接クエリパラメータから構築）
        $conditions = [];

        $group_id = $this->getQuery('group_id');
        $content_category = $this->getQuery('content_category');

        // グループが指定されている場合、指定したグループに所属するユーザの履歴を抽出
        if ($group_id != '') {
            $userIds = $this->fetchTable('Groups')->getUserIdByGroupID((int)$group_id);
            $conditions['Users.id IN'] = $userIds ?: [-1];
        }

        // コンテンツ種別による絞り込み
        if ($content_category == 'study') {
            $conditions[] = function (QueryExpression $exp) {
                return $exp->in('Contents.kind', ['text', 'html', 'movie', 'url']);
            };
        } elseif ($content_category != '') {
            $conditions['Contents.kind'] = $content_category;
        }

        // 対象日時による絞り込み
        $from_date = $this->hasQuery('from_date')
            ? implode('-', (array)$this->getQuery('from_date'))
            : date('Y-m-d', strtotime('-1 month'));
        $to_date = $this->hasQuery('to_date')
            ? implode('-', (array)$this->getQuery('to_date'))
            : date('Y-m-d');

        $conditions[] = new BetweenExpression(
            $recordsTable->aliasField('created'),
            $from_date,
            $to_date . ' 23:59:59',
        );

        // CSV出力モード
        if ($this->getQuery('cmd') == 'csv') {
            return $this->_exportCsv($conditions);
        }
        // テスト結果／アンケート回答CSV出力
        if ($this->getQuery('cmd') == 'csv_detail') {
            return $this->_exportCsvDetail($conditions);
        }

        // 一覧表示
        $query = $recordsTable->find()
            ->contain(['Users', 'Courses', 'Contents'])
            ->where($conditions)
            ->orderBy([$recordsTable->aliasField('created') => 'DESC']);

        $this->paginate = [
            'limit' => 20,
        ];

        try {
            $records = $this->paginate($query);
        } catch (Exception $e) {
            // 不正な page パラメータ（範囲外・非数値等）が指定された場合は 1 ページ目にリセットする。
            // CakePHP 5 の Paginator はクエリパラメータ page を参照するため、
            // ルートパラメータ（withParam）ではなくクエリパラメータを上書きする必要がある。
            $this->request = $this->request->withQueryParams(
                array_merge($this->request->getQueryParams(), ['page' => 1]),
            );
            $records = $this->paginate($query);
        }

        $groups = $this->fetchTable('Groups')->find('list');
        $courses = $this->fetchTable('Courses')->find('list');

        $this->set(compact('records', 'groups', 'group_id', 'courses', 'content_category', 'from_date', 'to_date'));

        return null;
    }

    /**
     * 学習履歴CSV出力
     *
     * @param array $conditions 検索条件
     * @return \Cake\Http\Response
     */
    protected function _exportCsv(array $conditions): Response
    {
        $this->autoRender = false;

        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', (string)(60 * 10));

        $recordsTable = $this->fetchTable('Records');

        $rows = $recordsTable->find()
            ->contain(['Users', 'Courses', 'Contents'])
            ->where($conditions)
            ->orderBy([$recordsTable->aliasField('created') => 'DESC'])
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

        // CSV内容をバッファリング（CakePHP 5 では headers 送信後に php://output へ直接書き込めない）
        $csvContent = '';

        mb_convert_variables('SJIS-WIN', 'UTF-8', $header);
        $csvContent .= $this->_csvFputcsv($header);

        foreach ($rows as $row) {
            $line = [
                $row->user->username ?? '',
                $this->sanitizeCsvValue($row->user->name ?? ''),
                $row->course->title ?? '',
                $row->content->title ?? '',
                $row->score,
                $row->pass_score,
                Configure::read('record_result.' . $row->is_passed),
                Configure::read('record_understanding.' . $row->understanding),
                Utils::getHNSBySec($row->study_sec),
                Utils::getYMDHN($row->created),
            ];

            mb_convert_variables('SJIS-WIN', 'UTF-8', $line);
            $csvContent .= $this->_csvFputcsv($line);
        }

        return $this->response
            ->withType('csv')
            ->withHeader('Content-Type', 'text/csv; charset=SJIS-WIN')
            ->withHeader('Content-Disposition', 'attachment; filename="user_records.csv"')
            ->withStringBody($csvContent);
    }

    /**
     * テスト結果詳細CSV出力
     *
     * @param array $conditions 検索条件
     * @return \Cake\Http\Response
     */
    protected function _exportCsvDetail(array $conditions): Response
    {
        $this->autoRender = false;

        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', (string)(60 * 10));

        $conditions['ContentsQuestions.question_type !='] = 'label';

        $recordsQuestionsTable = $this->fetchTable('RecordsQuestions');

        $query = $recordsQuestionsTable->find()
            ->contain(['Records.Users', 'Records.Courses', 'Records.Contents', 'ContentsQuestions'])
            ->where($conditions)
            ->orderBy([
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

        // CSV内容をバッファリング（CakePHP 5 では headers 送信後に php://output へ直接書き込めない）
        $csvContent = '';

        mb_convert_variables('SJIS-WIN', 'UTF-8', $header);
        $csvContent .= $this->_csvFputcsv($header);

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
                $this->sanitizeCsvValue($row->record->user->name ?? ''),
                $row->record->course->title ?? '',
                $row->record->content->title ?? '',
                $question_no,
                $this->sanitizeCsvValue($row->contents_question->title ?? ''),
                $this->sanitizeCsvValue(strip_tags($row->contents_question->body ?? '')),
                $answer,
                $result,
                Utils::getYMDHN($row->record->created ?? ''),
            ];

            mb_convert_variables('SJIS-WIN', 'UTF-8', $line);
            $csvContent .= $this->_csvFputcsv($line);
        }

        return $this->response
            ->withType('csv')
            ->withHeader('Content-Type', 'text/csv; charset=SJIS-WIN')
            ->withHeader('Content-Disposition', 'attachment; filename="record_details.csv"')
            ->withStringBody($csvContent);
    }

    /**
     * fputcsv のバッファリング版（配列をCSV行文字列に変換）
     *
     * @param array $fields CSV出力するフィールド配列
     * @return string CSV行文字列
     */
    protected function _csvFputcsv(array $fields): string
    {
        $fp = fopen('php://memory', 'r+');
        fputcsv($fp, $fields, ',', '"', '\\');
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $csv;
    }
}
