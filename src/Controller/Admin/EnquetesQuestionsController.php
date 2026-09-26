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
use Cake\Core\Configure;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;

/**
 * EnquetesQuestions Controller (Admin)
 */
class EnquetesQuestionsController extends AppController
{
    /**
     * 初期化
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->FormProtection->unlockActions(['order']);
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
        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');
        $contentsTable = $this->fetchTable('Contents');
        $recordsTable = $this->fetchTable('Records');

        $content_id = (int)$content_id;
        $record_id = (int)$record_id;

        // コンテンツ情報を取得
        $content = $contentsTable->get($content_id, contain: ['Courses']);

        // テスト結果を取得
        $record = $recordsTable->get($record_id);

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

        $is_record = true;
        $is_admin_record = true;

        $this->set(compact('content', 'contentsQuestions', 'record', 'is_record', 'is_admin_record'));
        $this->viewBuilder()->setTemplate('index');
    }

    /**
     * 問題一覧を表示
     *
     * @param string|int $content_id コンテンツID
     * @return void
     */
    public function index($content_id): void
    {
        $content_id = (int)$content_id;

        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');

        $contentsQuestions = $contentsQuestionsTable->find()
            ->where([$contentsQuestionsTable->aliasField('content_id') => $content_id])
            ->orderBy([$contentsQuestionsTable->aliasField('sort_no') => 'ASC'])
            ->all();

        $content = $this->fetchTable('Contents')->get($content_id, contain: ['Courses']);

        $this->set(compact('content', 'contentsQuestions'));
    }

    /**
     * 問題を追加
     *
     * @param string|int $content_id コンテンツID
     * @return \Cake\Http\Response|null
     */
    public function add($content_id): ?Response
    {
        $result = $this->edit($content_id);
        if ($result !== null) {
            return $result;
        }
        $this->viewBuilder()->setTemplate('edit');

        return null;
    }

    /**
     * 問題を編集
     *
     * @param string|int $content_id コンテンツID
     * @param string|int|null $question_id 問題ID
     * @return \Cake\Http\Response|null
     */
    public function edit($content_id, $question_id = null): ?Response
    {
        $content_id = (int)$content_id;
        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');

        if ($this->isEditPage() && $question_id !== null && !$contentsQuestionsTable->exists(['id' => $question_id])) {
            throw new NotFoundException(__('Invalid contents question'));
        }

        $content = $this->fetchTable('Contents')->get($content_id, contain: ['Courses']);

        if ($this->request->is(['post', 'put'])) {
            if (Configure::read('demo_mode')) {
                return null;
            }

            if ($question_id === null) {
                $data = $this->request->getData();
                $data['user_id'] = $this->readAuthUser('id');
                $data['content_id'] = $content_id;
                $data['sort_no'] = $contentsQuestionsTable->getNextSortNo($content_id);
                $this->request = $this->request->withData('ContentsQuestions', $data);
            }

            if ($question_id !== null) {
                $question = $contentsQuestionsTable->get((int)$question_id);
            } else {
                $question = $contentsQuestionsTable->newEmptyEntity();
            }

            $question = $contentsQuestionsTable->patchEntity($question, $this->request->getData());

            if (!$contentsQuestionsTable->save($question)) {
                $this->Flash->error(__('The contents question could not be saved. Please, try again.'));
            } else {
                $this->Flash->success(__('質問が保存されました'));

                return $this->redirect([
                    'controller' => 'EnquetesQuestions',
                    'action' => 'index',
                    $content_id,
                ]);
            }
        } else {
            $question = $question_id !== null ? $contentsQuestionsTable->get((int)$question_id) : $contentsQuestionsTable->newEmptyEntity();
        }

        $this->set(compact('content', 'question'));

        return null;
    }

    /**
     * 問題を削除
     *
     * @param string|int|null $question_id 問題ID
     * @return \Cake\Http\Response|null
     */
    public function delete($question_id = null): ?Response
    {
        if (Configure::read('demo_mode')) {
            return null;
        }

        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');

        if (!$contentsQuestionsTable->exists(['id' => $question_id])) {
            throw new NotFoundException(__('Invalid contents question'));
        }

        $this->request->allowMethod(['post', 'delete']);

        $question = $contentsQuestionsTable->get((int)$question_id);

        if ($contentsQuestionsTable->delete($question)) {
            $this->Flash->success(__('質問が削除されました'));

            return $this->redirect([
                'controller' => 'EnquetesQuestions',
                'action' => 'index',
                $question->content_id,
            ]);
        } else {
            $this->Flash->error(__('The contents question could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Ajax によるコンテンツの並び替え
     *
     * @return string
     */
    public function order(): Response
    {
        if (Configure::read('demo_mode')) {
            return $this->response->withStringBody('');
        }

        if ($this->request->is('ajax')) {
            $this->fetchTable('ContentsQuestions')->setOrder($this->request->getData('id_list'));

            return $this->response->withStringBody('OK');
        }

        return $this->response->withStringBody('');
    }
}
