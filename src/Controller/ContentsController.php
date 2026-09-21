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
use Cake\I18n\FrozenTime;
use Cake\ORM\TableRegistry;

/**
 * Contents Controller
 *
 * CakePHP 5 版
 */
class ContentsController extends AppController
{
    /**
     * 初期化
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->FormProtection->unlockActions(['admin_order', 'admin_preview', 'admin_upload_image']);
    }

    /**
     * 学習コンテンツ一覧を表示
     *
     * @param int|string $course_id コースID
     * @param int|string|null $user_id 学習履歴を表示するユーザのID
     * @return void
     */
    public function index($course_id, $user_id = null): void
    {
        $course_id = (int)$course_id;
        $user_id = ($user_id !== null) ? (int)$user_id : null;

        $coursesTable = $this->fetchTable('Courses');
        $contentsTable = $this->fetchTable('Contents');

        // コースの情報を取得
        $course = $coursesTable->get($course_id);

        // ロールを取得
        $role = $this->readAuthUser('role');

        // 管理者かつ、学習履歴表示モードの場合
        if ($this->isAdminPage() && $this->isRecordPage()) {
            $contents = $contentsTable->getContentRecord($user_id, $course_id, $role);
        } else {
            // コースの閲覧権限の確認
            if (!$coursesTable->hasRight($this->readAuthUser('id'), $course_id)) {
                throw new NotFoundException(__('Invalid access'));
            }

            $contents = $contentsTable->getContentRecord($this->readAuthUser('id'), $course_id, $role);
        }

        // アップロードファイル参照用
        $this->writeCookie('LoginStatus', 'logined');

        $this->set(compact('course', 'contents'));
    }

    /**
     * コンテンツの表示
     *
     * @param int|string $content_id 表示するコンテンツのID
     * @return void
     */
    public function view($content_id): void
    {
        $content_id = (int)$content_id;

        $contentsTable = $this->fetchTable('Contents');

        if (!$contentsTable->exists(['id' => $content_id])) {
            throw new NotFoundException(__('Invalid content'));
        }

        // ヘッダー、フッターを非表示
        $this->viewBuilder()->setOption('fullPage', false);

        $content = $contentsTable->get($content_id);

        // コンテンツの閲覧権限の確認
        if (!$this->fetchTable('Courses')->hasRight($this->readAuthUser('id'), $content->course_id)) {
            throw new NotFoundException(__('Invalid access'));
        }

        // 管理者以外の場合、非公開コンテンツへのアクセスを禁止
        if ($this->readAuthUser('role') !== 'admin' && $content->status != 1) {
            throw new NotFoundException(__('Invalid access'));
        }

        $this->set(compact('content'));
    }

    /**
     * コンテンツ一覧の表示
     *
     * @param int|string $course_id コースID
     * @return void
     */
    public function admin_index($course_id): void
    {
        $course_id = (int)$course_id;

        $contentsTable = $this->fetchTable('Contents');

        // コースの情報を取得
        $course = $this->fetchTable('Courses')->get($course_id);

        $contents = $contentsTable->find()
            ->where([$contentsTable->aliasField('course_id') => $course_id])
            ->order([$contentsTable->aliasField('sort_no') => 'ASC'])
            ->all();

        $this->set(compact('contents', 'course'));
    }

    /**
     * コンテンツの追加
     *
     * @param int|string $course_id コースID
     * @return \Cake\Http\Response|null
     */
    public function admin_add($course_id): ?\Cake\Http\Response
    {
        $this->admin_edit($course_id);
        $this->viewBuilder()->setOption('template', 'admin_edit');

        return null;
    }

    /**
     * コンテンツの編集
     *
     * @param int|string $course_id 所属するコースのID
     * @param int|string|null $content_id 編集するコンテンツのID (指定しない場合、追加)
     * @return \Cake\Http\Response|null
     */
    public function admin_edit($course_id, $content_id = null): ?\Cake\Http\Response
    {
        $course_id = (int)$course_id;

        $contentsTable = $this->fetchTable('Contents');

        if ($this->isEditPage() && $content_id !== null && !$contentsTable->exists(['id' => $content_id])) {
            throw new NotFoundException(__('Invalid content'));
        }

        if ($this->request->is(['post', 'put'])) {
            if (Configure::read('demo_mode')) {
                return null;
            }

            if (!$this->isEditPage()) {
                // 新規追加の場合、コンテンツの作成者と所属コースを指定
                $data = $this->request->getData();
                $data['user_id'] = $this->readAuthUser('id');
                $data['course_id'] = $course_id;
                $data['sort_no'] = $contentsTable->getNextSortNo($course_id);
                $this->request = $this->request->withData('Contents', $data);
            }

            if ($content_id !== null) {
                $content = $contentsTable->get((int)$content_id);
            } else {
                $content = $contentsTable->newEmptyEntity();
            }

            $content = $contentsTable->patchEntity($content, $this->request->getData());

            if ($contentsTable->save($content)) {
                $this->Flash->success(__('コンテンツが保存されました'));

                return $this->redirect(['action' => 'index', $course_id]);
            } else {
                $this->Flash->error(__('The content could not be saved. Please, try again.'));
            }
        } else {
            if ($content_id !== null) {
                $content = $contentsTable->get((int)$content_id);
                $this->set(compact('content'));
            }
        }

        // コース情報を取得
        $course = $this->fetchTable('Courses')->get($course_id);
        $courses = $this->fetchTable('Courses')->find('list');

        $this->set(compact('course', 'courses'));

        return null;
    }

    /**
     * コンテンツの削除
     *
     * @param int|string $content_id 削除するコンテンツのID
     * @return \Cake\Http\Response|null
     */
    public function admin_delete($content_id): ?\Cake\Http\Response
    {
        if (Configure::read('demo_mode')) {
            return null;
        }

        $content_id = (int)$content_id;

        $contentsTable = $this->fetchTable('Contents');

        if (!$contentsTable->exists(['id' => $content_id])) {
            throw new NotFoundException(__('Invalid content'));
        }

        $content = $contentsTable->get($content_id);

        $this->request->allowMethod(['post', 'delete']);

        if ($contentsTable->delete($content)) {
            // コンテンツに紐づくテスト問題も削除
            $this->fetchTable('ContentsQuestions')->deleteAll(['content_id' => $content_id]);
            $this->Flash->success(__('コンテンツが削除されました'));
        } else {
            $this->Flash->error(__('The content could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index', $content->course_id]);
    }

    /**
     * プレビュー用に入力内容をセッションに保存
     *
     * @return void
     */
    public function admin_preview(): void
    {
        $this->autoRender = false;

        if ($this->request->is('ajax')) {
            $data = [
                'Content' => [
                    'id' => 0,
                    'title' => $this->getData('content_title'),
                    'kind' => $this->getData('content_kind'),
                    'url' => $this->getData('content_url'),
                    'body' => $this->getData('content_body'),
                ],
                'Course' => [
                    'id' => 0,
                ],
            ];

            $this->writeSession('Iroha.preview_content', $data);
        }
    }

    /**
     * 動画ファイルのプレビュー
     *
     * @param string $file_name ファイル名
     * @return \Cake\Http\Response|null
     */
    public function admin_preview_movie($file_name): ?\Cake\Http\Response
    {
        if (!$file_name) {
            throw new NotFoundException(__('Invalid content'));
        }

        $safe_file_name = basename($file_name);
        $file_path = ROOT . DS . 'files' . DS . $safe_file_name;

        $upload_extensions = (array)Configure::read('upload_movie_extensions');
        $extension = '.' . pathinfo($safe_file_name, PATHINFO_EXTENSION);

        if (!in_array($extension, $upload_extensions)) {
            throw new NotFoundException(__('Invalid content'));
        }

        if (!preg_match('/^[a-zA-Z0-9\.\-_]+$/', $safe_file_name)) {
            throw new NotFoundException(__('Invalid content'));
        }

        if (!file_exists($file_path)) {
            $file_path = ROOT . DS . 'webroot' . DS . 'uploads' . DS . $safe_file_name;

            if (!file_exists($file_path)) {
                throw new NotFoundException(__('File not found'));
            }
        }

        $this->response = $this->response->withFile($file_path, ['download' => false, 'name' => $safe_file_name]);

        return $this->response;
    }

    /**
     * セッションに保存された情報を元にプレビュー
     *
     * @return void
     */
    public function preview(): void
    {
        $this->viewBuilder()->setOption('fullPage', false);
        $this->set('content', $this->readSession('Iroha.preview_content'));
        $this->viewBuilder()->setOption('template', 'view');
    }

    /**
     * ファイル（配布資料、動画）のアップロード
     *
     * @param string $file_type ファイルの種類
     * @return void
     */
    public function admin_upload($file_type): void
    {
        $file_path = ROOT . DS . 'files';

        if (!is_dir($file_path)) {
            mkdir($file_path, 0755);
        }

        $mode = '';
        $file_url = '';

        $upload_extensions = (array)Configure::read('upload_' . $file_type . '_extensions');
        $upload_maxsize = Configure::read('upload_' . $file_type . '_maxsize');

        if ($this->request->is(['post', 'put'])) {
            if (Configure::read('demo_mode')) {
                return;
            }

            $file = $this->request->getUploadedFile('file') ?? $this->request->getUploadedFile('Contents.file');
            if ($file && $file->getError() === UPLOAD_ERR_OK) {
                $original_name = $file->getClientFilename();
                $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                if (!in_array('.' . $ext, $upload_extensions)) {
                    $mode = 'error';
                    $this->Flash->error('アップロードされたファイルの形式は許可されていません');
                } else {
                    $str = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 4);
                    $new_name = date('YmdHis') . $str . '.' . $ext;

                    $dest = $file_path . DS . $new_name;
                    $result = $file->moveTo($dest);

                    if ($result) {
                        $mode = 'complete';
                        $file_url = $new_name;
                    } else {
                        $mode = 'error';
                        $this->Flash->error('ファイルのアップロードに失敗しました');
                    }
                }
            } else {
                $mode = 'error';
                $this->Flash->error('ファイルが指定されていません');
            }
        }

        $file_name = $mode === 'complete' ? $file_url : '';
        $upload_extensions_str = implode(', ', $upload_extensions);

        $this->set(compact('mode', 'file_url', 'file_name', 'upload_extensions_str', 'upload_maxsize'));
    }

    /**
     * リッチテキストエディタ(Summernote) から送信された画像を保存
     *
     * @return \Cake\Http\Response
     */
    public function admin_upload_image(): \Cake\Http\Response
    {
        $this->autoRender = false;

        if ($this->request->is('ajax')) {
            $file = $this->request->getUploadedFile('file');
            if ($file && $file->getError() === UPLOAD_ERR_OK) {
                $file_path = ROOT . DS . 'files';
                if (!is_dir($file_path)) {
                    mkdir($file_path, 0755);
                }

                $original_name = $file->getClientFilename();
                $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                $str = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 4);
                $new_name = date('YmdHis') . $str . '.' . $ext;

                $dest = $file_path . DS . $new_name;
                $result = $file->moveTo($dest);

                $file_url = $this->request->getUri()->getScheme() . '://' . $this->request->getUri()->getHost() . '/contents/file_image/' . $new_name;
                $response = $result ? [$file_url] : [false];
            } else {
                $response = [false];
            }

            $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $this->response
                ->withType('json')
                ->withStringBody($json);
        }

        return $this->response;
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
            $this->fetchTable('Contents')->setOrder($this->request->getData('id_list'));

            return 'OK';
        }

        return '';
    }

    /**
     * 学習履歴の表示
     *
     * @param int|string $course_id コースID
     * @param int|string $user_id ユーザID
     * @return void
     */
    public function admin_record($course_id, $user_id): void
    {
        $this->index($course_id, $user_id);
        $this->viewBuilder()->setOption('template', 'index');
    }

    /**
     * コンテンツのコピー
     *
     * @param int|string $course_id コピー先のコースのID
     * @param int|string $content_id コピーするコンテンツのID
     * @return \Cake\Http\Response|null
     */
    public function admin_copy($course_id, $content_id): ?\Cake\Http\Response
    {
        $this->request->allowMethod(['post']);

        $contentsTable = $this->fetchTable('Contents');
        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');

        // コンテンツのコピー
        $content = $contentsTable->get((int)$content_id);
        $maxRow = $contentsTable->find()->select(['max_id' => $contentsTable->find()->func()->max('id')])->first();
        $new_content_id = ($maxRow->max_id ?? 0) + 1;

        $newContent = $contentsTable->newEmptyEntity();
        $newContent = $contentsTable->patchEntity($newContent, [
            'id' => $new_content_id,
            'title' => $content->title . 'の複製',
            'kind' => $content->kind,
            'url' => $content->url,
            'body' => $content->body,
            'file_name' => $content->file_name,
            'status' => 0,
            'course_id' => (int)$course_id,
            'user_id' => $content->user_id,
        ]);

        $contentsTable->saveOrFail($newContent);

        // テスト問題のコピー
        $questions = $contentsQuestionsTable->find()
            ->where(['content_id' => $content_id])
            ->order(['sort_no' => 'ASC'])
            ->all();

        $sort_no = 1;

        foreach ($questions as $question) {
            $maxRow = $contentsQuestionsTable->find()
                ->select(['max_id' => $contentsQuestionsTable->find()->func()->max('id')])
                ->first();

            $new_question_id = ($maxRow->max_id ?? 0) + 1;

            $newQuestion = $contentsQuestionsTable->newEmptyEntity();
            $newQuestion = $contentsQuestionsTable->patchEntity($newQuestion, [
                'id' => $new_question_id,
                'content_id' => $new_content_id,
                'sort_no' => $sort_no,
                'question' => $question->question,
                'kind' => $question->kind,
                'answer' => $question->answer,
                'commentary' => $question->commentary,
                'select_count' => $question->select_count,
            ]);

            $contentsQuestionsTable->saveOrFail($newQuestion);
            $sort_no++;
        }

        return $this->redirect(['action' => 'index', $course_id]);
    }

    /**
     * ファイルのダウンロード
     *
     * @param int|string $content_id コンテンツID
     * @return \Cake\Http\Response|null
     */
    public function file_download($content_id): ?\Cake\Http\Response
    {
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

        if ($content->kind !== 'file') {
            throw new NotFoundException(__('Invalid content'));
        }

        $safe_file_name = basename($content->url);
        $file_path = ROOT . DS . 'files' . DS . $safe_file_name;

        if (!file_exists($file_path)) {
            $file_path = ROOT . DS . 'webroot' . DS . 'uploads' . DS . $safe_file_name;

            if (!file_exists($file_path)) {
                throw new NotFoundException(__('File not found'));
            }
        }

        $this->response = $this->response->withFile($file_path, [
            'download' => true,
            'name' => $content->file_name,
        ]);

        return $this->response;
    }

    /**
     * 動画ファイルの表示
     *
     * @param int|string $content_id コンテンツID
     * @return \Cake\Http\Response|null
     */
    public function file_movie($content_id): ?\Cake\Http\Response
    {
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

        if ($content->kind !== 'movie') {
            throw new NotFoundException(__('Invalid content'));
        }

        $safe_file_name = basename($content->url);
        $file_path = ROOT . DS . 'files' . DS . $safe_file_name;

        $upload_extensions = (array)Configure::read('upload_movie_extensions');
        $extension = '.' . pathinfo($safe_file_name, PATHINFO_EXTENSION);

        if (!in_array($extension, $upload_extensions)) {
            throw new NotFoundException(__('Invalid content'));
        }

        if (!file_exists($file_path)) {
            $file_path = ROOT . DS . 'webroot' . DS . 'uploads' . DS . $safe_file_name;

            if (!file_exists($file_path)) {
                throw new NotFoundException(__('File not found'));
            }
        }

        $this->response = $this->response->withFile($file_path, ['download' => false, 'name' => $safe_file_name]);

        return $this->response;
    }

    /**
     * 画像ファイルの表示
     *
     * @param string $file_name ファイル名
     * @return \Cake\Http\Response|null
     */
    public function file_image($file_name): ?\Cake\Http\Response
    {
        if (!$file_name) {
            throw new NotFoundException(__('Invalid content'));
        }

        $file_name = mb_convert_encoding($file_name, 'UTF-8', 'UTF-8');

        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $file_name)) {
            throw new NotFoundException(__('Invalid filename'));
        }

        if (strlen($file_name) > 255) {
            throw new NotFoundException(__('Invalid filename'));
        }

        if (str_starts_with($file_name, '.')) {
            throw new NotFoundException(__('Invalid filename'));
        }

        $upload_extensions = (array)Configure::read('upload_image_extensions');
        $extension = '.' . pathinfo($file_name, PATHINFO_EXTENSION);

        if (!in_array($extension, $upload_extensions)) {
            throw new NotFoundException(__('Invalid content'));
        }

        $safe_file_name = basename($file_name);
        $file_path = ROOT . DS . 'files' . DS . $safe_file_name;

        if (is_dir($file_path)) {
            throw new NotFoundException(__('Invalid content'));
        }

        if (!file_exists($file_path)) {
            $file_path = ROOT . DS . 'webroot' . DS . 'uploads' . DS . $safe_file_name;

            if (!file_exists($file_path)) {
                throw new NotFoundException(__('File not found'));
            }
        }

        $this->response = $this->response->withFile($file_path, ['download' => false, 'name' => $safe_file_name]);

        return $this->response;
    }
}
