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

use Cake\Http\Exception\NotFoundException;

/**
 * EnquetesQuestions Controller
 *
 * CakePHP 5 版
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
        $this->FormProtection->unlockActions(['admin_order']);
    }

    /**
     * 問題を出題
     *
     * @param int|string $content_id コンテンツID
     * @param int|string|null $record_id 履歴ID
     * @return void
     */
    public function index($content_id, $record_id = null): void
    {
        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');
        $contentsTable = $this->fetchTable('Contents');
        $recordsTable = $this->fetchTable('Records');

        $content_id = (int)$content_id;
        $record_id = ($record_id !== null) ? (int)$record_id : null;

        $content = $contentsTable->get($content_id);

        if (!$this->isAdminPage()) {
            if (!$this->fetchTable('Courses')->hasRight($this->readAuthUser('id'), $content->course_id)) {
                throw new NotFoundException(__('Invalid access'));
            }
        }

        if ($this->readAuthUser('role') !== 'admin' && $content->status != 1) {
            throw new NotFoundException(__('Invalid access'));
        }

        $record = null;

        if ($record_id !== null) {
            $record = $recordsTable->get($record_id);

            if (!$this->isAdminPage() && $this->isRecordPage() && ($record->user_id != $this->readAuthUser('id'))) {
                throw new NotFoundException(__('Invalid access'));
            }

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
                ->order($contentsQuestionsTable->find()->newExpr()->add('FIELD(' . $contentsQuestionsTable->aliasField('id') . ',' . implode(',', $question_id_list) . ')'))
                ->all();
        } else {
            $contentsQuestions = $contentsQuestionsTable->find()
                ->where(['content_id' => $content_id])
                ->order([$contentsQuestionsTable->aliasField('sort_no') => 'ASC'])
                ->all();
        }

        // 保存処理
        if ($this->request->is('post')) {
            $details = [];

            foreach ($contentsQuestions as $cq) {
                $question_id = $cq->id;
                $answer = $this->getData('answer_' . $question_id);

                $details[] = [
                    'question_id' => $question_id,
                    'answer' => $answer,
                    'is_correct' => -1,
                ];
            }

            $study_sec = $this->getData('ContentsQuestion')['study_sec'] ?? 0;

            $recordEntity = $recordsTable->newEmptyEntity();
            $recordEntity = $recordsTable->patchEntity($recordEntity, [
                'user_id' => $this->readAuthUser('id'),
                'course_id' => $content->course_id,
                'content_id' => $content_id,
                'study_sec' => $study_sec,
                'is_passed' => 2,
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

                $this->Flash->success(__('回答内容を送信しました'));

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
        $this->index((int)$content_id, (int)$record_id);
        $this->viewBuilder()->setOption('template', 'index');
    }

    /**
     * テスト結果を表示（管理画面）
     *
     * @param int|string $content_id コンテンツID
     * @param int|string $record_id 履歴ID
     * @return void
     */
    public function admin_record($content_id, $record_id): void
    {
        $this->record($content_id, $record_id);
    }

    /**
     * 問題一覧を表示
     *
     * @param int|string $content_id コンテンツID
     * @return void
     */
    public function admin_index($content_id): void
    {
        $content_id = (int)$content_id;

        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');

        $contentsQuestions = $contentsQuestionsTable->find()
            ->where([$contentsQuestionsTable->aliasField('content_id') => $content_id])
            ->order([$contentsQuestionsTable->aliasField('sort_no') => 'ASC'])
            ->all();

        $content = $this->fetchTable('Contents')->get($content_id);

        $this->set(compact('content', 'contentsQuestions'));
    }

    /**
     * 問題を追加
     *
     * @param int|string $content_id コンテンツID
     * @return \Cake\Http\Response|null
     */
    public function admin_add($content_id): ?\Cake\Http\Response
    {
        $this->admin_edit($content_id);
        $this->viewBuilder()->setOption('template', 'admin_edit');

        return null;
    }

    /**
     * 問題を編集
     *
     * @param int|string $content_id コンテンツID
     * @param int|string|null $question_id 問題ID
     * @return \Cake\Http\Response|null
     */
    public function admin_edit($content_id, $question_id = null): ?\Cake\Http\Response
    {
        $content_id = (int)$content_id;
        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');

        if ($this->isEditPage() && $question_id !== null && !$contentsQuestionsTable->exists(['id' => $question_id])) {
            throw new NotFoundException(__('Invalid contents question'));
        }

        $content = $this->fetchTable('Contents')->get($content_id);

        if ($this->request->is(['post', 'put'])) {
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
            if ($question_id !== null) {
                $question = $contentsQuestionsTable->get((int)$question_id);
                $this->set(compact('question'));
            }
        }

        $this->set(compact('content'));

        return null;
    }

    /**
     * 問題を削除
     *
     * @param int|string|null $question_id 問題ID
     * @return \Cake\Http\Response|null
     */
    public function admin_delete($question_id = null): ?\Cake\Http\Response
    {
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
    public function admin_order(): string
    {
        $this->autoRender = false;

        if ($this->request->is('ajax')) {
            $this->fetchTable('ContentsQuestions')->setOrder($this->request->getData('id_list'));

            return 'OK';
        }

        return '';
    }
}
