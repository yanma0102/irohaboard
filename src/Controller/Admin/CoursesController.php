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
 * Courses Controller (Admin)
 *
 * CakePHP 5 版
 */
class CoursesController extends AppController
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
     * コース一覧を表示
     *
     * @return void
     */
    public function index(): void
    {
        $coursesTable = $this->fetchTable('Courses');
        $courses = $coursesTable->find()
            ->order(['sort_no' => 'ASC'])
            ->all();
        $this->set(compact('courses'));
    }

    /**
     * コースの追加（edit に委譲）
     *
     * @return \Cake\Http\Response|null
     */
    public function add(): ?\Cake\Http\Response
    {
        $result = $this->edit();
        if ($result !== null) {
            return $result;
        }
        $this->viewBuilder()->setTemplate('edit');

        return null;
    }

    /**
     * コースの編集
     *
     * @param int|string|null $course_id コースID
     * @return \Cake\Http\Response|null
     */
    public function edit($course_id = null): ?\Cake\Http\Response
    {
        $coursesTable = $this->fetchTable('Courses');

        if ($this->isEditPage() && $course_id !== null && !$coursesTable->exists(['id' => $course_id])) {
            throw new NotFoundException(__('Invalid course'));
        }

        if ($this->request->is(['post', 'put'])) {
            if (Configure::read('demo_mode')) {
                return null;
            }

            $course = $coursesTable->get((int)$course_id);
            $course = $coursesTable->patchEntity($course, $this->request->getData());
            $course->user_id = $this->readAuthUser('id');

            if ($coursesTable->save($course)) {
                $this->Flash->success(__('コースが保存されました'));

                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error(__('The course could not be saved. Please, try again.'));
            }
        } else {
            $course = $course_id !== null ? $coursesTable->get((int)$course_id) : $coursesTable->newEmptyEntity();
        }

        $this->set(compact('course'));

        return null;
    }

    /**
     * コースの削除
     *
     * @param int|string|null $course_id コースID
     * @return \Cake\Http\Response|null
     */
    public function delete($course_id = null): ?\Cake\Http\Response
    {
        if (Configure::read('demo_mode')) {
            return null;
        }

        $coursesTable = $this->fetchTable('Courses');

        if (!$coursesTable->exists(['id' => $course_id])) {
            throw new NotFoundException(__('Invalid course'));
        }

        $this->request->allowMethod(['post', 'delete']);

        $course = $coursesTable->get((int)$course_id);
        $coursesTable->delete($course);

        $this->Flash->success(__('コースが削除されました'));

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Ajax によるコースの並び替え
     *
     * @return string
     */
    public function order(): \Cake\Http\Response
    {
        if ($this->request->is('ajax')) {
            $this->fetchTable('Courses')->setOrder($this->request->getData('id_list'));

            return $this->response->withStringBody('OK');
        }

        return $this->response->withStringBody('');
    }
}
