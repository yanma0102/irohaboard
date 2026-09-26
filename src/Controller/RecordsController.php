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
     * Initialization hook method.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        // add アクションは JS 動的フォームから POST されるため
        // FormProtection のフィールド一致検証を除外する。
        // CSRF 防御は CsrfProtectionMiddleware が別途担当し、
        // /records/add/* はスキップ対象外なので引き続き有効。
        $this->FormProtection->unlockActions(['add']);
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
}
