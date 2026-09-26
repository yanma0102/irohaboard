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
use Cake\Http\Response;

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
    }

    /**
     * 学習コンテンツ一覧を表示
     *
     * @param string|int $course_id コースID
     * @param string|int|null $user_id 学習履歴を表示するユーザのID
     * @return void
     */
    public function index($course_id, $user_id = null): void
    {
        $course_id = (int)$course_id;
        $user_id = $user_id !== null ? (int)$user_id : null;

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
     * @param string|int $content_id 表示するコンテンツのID
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
     * セッションに保存された情報を元にプレビュー
     *
     * @return void
     */
    public function preview(): void
    {
        $this->viewBuilder()->disableAutoLayout();

        $content = $this->readSession('Iroha.preview_content');
        if (!is_array($content)) {
            // プレビュー用セッションが無い場合は空のプレビューを表示する
            $content = [
                'id' => 0,
                'title' => '',
                'kind' => '',
                'url' => '',
                'body' => '',
                'course_id' => 0,
            ];
        }
        $this->set('content', $content);

        $this->viewBuilder()->setTemplate('view');
    }

    /**
     * ファイルのダウンロード
     *
     * @param string|int $content_id コンテンツID
     * @return \Cake\Http\Response|null
     */
    public function fileDownload($content_id): ?Response
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

        if (empty($content->url)) {
            throw new NotFoundException(__('File not found'));
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
     * @param string|int $content_id コンテンツID
     * @return \Cake\Http\Response|null
     */
    public function fileMovie($content_id): ?Response
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

        if (empty($content->url)) {
            throw new NotFoundException(__('File not found'));
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
    public function fileImage($file_name): ?Response
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
