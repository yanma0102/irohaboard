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

/**
 * Contents Controller (Admin)
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
        $this->FormProtection->unlockActions(['order', 'preview', 'uploadImage', 'copy']);
    }

    /**
     * コンテンツ一覧の表示
     *
     * @param int|string $course_id コースID
     * @return void
     */
    public function index($course_id): void
    {
        $course_id = (int)$course_id;

        $contentsTable = $this->fetchTable('Contents');

        // コースの情報を取得
        $course = $this->fetchTable('Courses')->get($course_id);

        $contents = $contentsTable->find()
            ->where([$contentsTable->aliasField('course_id') => $course_id])
            ->orderBy([$contentsTable->aliasField('sort_no') => 'ASC'])
            ->all();

        $this->set(compact('contents', 'course'));
    }

    /**
     * コンテンツの追加
     *
     * @param int|string $course_id コースID
     * @return \Cake\Http\Response|null
     */
    public function add($course_id): ?\Cake\Http\Response
    {
        $result = $this->edit($course_id);
        if ($result !== null) {
            return $result;
        }
        $this->viewBuilder()->setTemplate('edit');

        return null;
    }

    /**
     * コンテンツの編集
     *
     * @param int|string $course_id 所属するコースのID
     * @param int|string|null $content_id 編集するコンテンツのID (指定しない場合、追加)
     * @return \Cake\Http\Response|null
     */
    public function edit($course_id, $content_id = null): ?\Cake\Http\Response
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
            $content = $content_id !== null ? $contentsTable->get((int)$content_id) : $contentsTable->newEmptyEntity();
        }

        // コース情報を取得
        $course = $course_id !== null ? $this->fetchTable('Courses')->get($course_id) : $this->fetchTable('Courses')->newEmptyEntity();
        $courses = $this->fetchTable('Courses')->find('list');

        $this->set(compact('content', 'course', 'courses'));

        return null;
    }

    /**
     * コンテンツの削除
     *
     * @param int|string $content_id 削除するコンテンツのID
     * @return \Cake\Http\Response|null
     */
    public function delete($content_id): ?\Cake\Http\Response
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
    public function preview(): void
    {
        $this->autoRender = false;

        if ($this->request->is('ajax')) {
            $body = $this->getData('content_body');
            $kind = $this->getData('content_kind');

            if ($kind === 'markdown') {
                $body = \App\Utility\MarkdownRenderer::toHtml($body);
            }

            $data = [
                'id' => 0,
                'title' => $this->getData('content_title'),
                'kind' => $kind,
                'url' => $this->getData('content_url'),
                'body' => $body,
                'course_id' => 0,
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
    public function previewMovie($file_name): ?\Cake\Http\Response
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
     * ファイル（配布資料、動画）のアップロード
     *
     * @param string $file_type ファイルの種類
     * @return void
     */
    public function upload($file_type): void
    {
        $file_path = ROOT . DS . 'webroot' . DS . 'uploads';

        if (!is_dir($file_path)) {
            mkdir($file_path, 0755, true);
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
                } elseif ($file->getSize() > $upload_maxsize) {
                    $mode = 'error';
                    $this->Flash->error('ファイルサイズが上限を超えています');
                } else {
                    $str = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 4);
                    $new_name = date('YmdHis') . $str . '.' . $ext;

                    $dest = $file_path . DS . $new_name;

                    // Laminas\Diactoros\UploadedFile::moveTo() は戻り値 void で、
                    // 失敗時は例外を投げる。戻り値判定では常に失敗扱いになるため、
                    // 例外捕捉と保存後のファイル存在確認で成否を判定する。
                    try {
                        $file->moveTo($dest);
                        $result = is_file($dest);
                    } catch (\Throwable $e) {
                        $result = false;
                    }

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
    public function uploadImage(): \Cake\Http\Response
    {
        $this->autoRender = false;

        if ($this->request->is('ajax')) {
            $file = $this->request->getUploadedFile('file');
            if ($file && $file->getError() === UPLOAD_ERR_OK) {
                $file_path = ROOT . DS . 'webroot' . DS . 'uploads';
                if (!is_dir($file_path)) {
                    mkdir($file_path, 0755, true);
                }

                $original_name = $file->getClientFilename();
                $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                if (!in_array('.' . $ext, (array)Configure::read('upload_image_extensions'), true)) {
                    $response = [false];
                } elseif ($file->getSize() > Configure::read('upload_image_maxsize')) {
                    $response = [false];
                } else {
                    $str = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 4);
                    $new_name = date('YmdHis') . $str . '.' . $ext;

                    $dest = $file_path . DS . $new_name;

                    // moveTo() は戻り値 void・失敗時例外のため、戻り値判定は不可。
                    // 例外捕捉と保存後のファイル存在確認で成否を判定する。
                    try {
                        $file->moveTo($dest);
                        $result = is_file($dest);
                    } catch (\Throwable $e) {
                        $result = false;
                    }

                    $file_url = $this->request->getUri()->getScheme() . '://' . $this->request->getUri()->getHost() . '/contents/file_image/' . $new_name;
                    $response = $result ? [$file_url] : [false];
                }
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
    public function order(): \Cake\Http\Response
    {
        if ($this->request->is('ajax')) {
            $this->fetchTable('Contents')->setOrder($this->request->getData('id_list'));

            return $this->response->withStringBody('OK');
        }

        return $this->response->withStringBody('');
    }

    /**
     * 学習履歴の表示
     *
     * @param int|string $course_id コースID
     * @param int|string $user_id ユーザID
     * @return void
     */
    public function record($course_id, $user_id): void
    {
        $course_id = (int)$course_id;
        $user_id = (int)$user_id;

        $coursesTable = $this->fetchTable('Courses');
        $contentsTable = $this->fetchTable('Contents');

        // コースの情報を取得
        $course = $coursesTable->get($course_id);

        // ロールを取得
        $role = $this->readAuthUser('role');

        $contents = $contentsTable->getContentRecord($user_id, $course_id, $role);

        // アップロードファイル参照用
        $this->writeCookie('LoginStatus', 'logined');

        $this->set(compact('course', 'contents'));
        $this->viewBuilder()->setTemplate('index');
    }

    /**
     * コンテンツのコピー
     *
     * @param int|string $course_id コピー先のコースのID
     * @param int|string $content_id コピーするコンテンツのID
     * @return \Cake\Http\Response|null
     */
    public function copy($course_id, $content_id): ?\Cake\Http\Response
    {
        $this->request->allowMethod(['post']);

        $contentsTable = $this->fetchTable('Contents');
        $contentsQuestionsTable = $this->fetchTable('ContentsQuestions');

        // コンテンツのコピー
        $content = $contentsTable->get((int)$content_id);

        $newContent = $contentsTable->newEmptyEntity();
        $newContent = $contentsTable->patchEntity($newContent, [
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
        $new_content_id = (int)$newContent->id;

        // テスト問題のコピー
        $questions = $contentsQuestionsTable->find()
            ->where(['content_id' => $content_id])
            ->orderBy(['sort_no' => 'ASC'])
            ->all();

        $sort_no = 1;

        foreach ($questions as $question) {
            $newQuestion = $contentsQuestionsTable->newEmptyEntity();
            $newQuestion = $contentsQuestionsTable->patchEntity($newQuestion, [
                'content_id' => $new_content_id,
                'sort_no' => $sort_no,
                'question_type' => $question->question_type,
                'title' => $question->title,
                'body' => $question->body,
                'image' => $question->image,
                'options' => $question->options,
                'correct' => $question->correct,
                'score' => $question->score,
                'explain' => $question->explain,
                'comment' => $question->comment,
            ]);

            $contentsQuestionsTable->saveOrFail($newQuestion);
            $sort_no++;
        }

        return $this->redirect(['action' => 'index', $course_id]);
    }
}
