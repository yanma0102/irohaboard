<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\View\Helper;

use Cake\View\Helper;

/**
 * AppViewHelper - ビュー用ユーティリティヘルパー
 *
 * CakePHP 5 版（AppView のヘルパーメソッドを Helper に移行）
 *
 * @property \Cake\View\Helper\HtmlHelper $Html
 * @property \Cake\View\Helper\FormHelper $Form
 * @property \Cake\View\Helper\FlashHelper $Flash
 */
class AppViewHelper extends Helper
{
    /**
     * ヘルパー
     *
     * @var array<string>
     */
    protected array $helpers = ['Html', 'Form', 'Flash'];

    /**
     * セッションの取得
     *
     * @param string $key キー
     * @return mixed
     */
    public function readSession(string $key): mixed
    {
        $request = $this->_View->getRequest();

        return $request->getSession()->read($key);
    }

    /**
     * セッションの削除
     *
     * @param string $key キー
     * @return void
     */
    public function deleteSession(string $key): void
    {
        $this->_View->getRequest()->getSession()->delete($key);
    }

    /**
     * セッションの存在確認
     *
     * @param string $key キー
     * @return bool
     */
    public function hasSession(string $key): bool
    {
        return $this->_View->getRequest()->getSession()->check($key);
    }

    /**
     * セッションの保存
     *
     * @param string $key キー
     * @param mixed $value 値
     * @return void
     */
    public function writeSession(string $key, mixed $value): void
    {
        $this->_View->getRequest()->getSession()->write($key, $value);
    }

    /**
     * ログインユーザ情報の取得
     *
     * @param string|null $key キー（省略した場合全て）
     * @return mixed
     */
    public function readAuthUser(?string $key = null): mixed
    {
        $identity = $this->_View->getRequest()->getAttribute('identity');

        if (!$identity) {
            return null;
        }

        $user = $identity->getOriginalData();

        if ($key === null) {
            return $user;
        }

        return $user[$key] ?? null;
    }

    /**
     * ログイン状態の確認
     *
     * @return bool
     */
    public function isLogined(): bool
    {
        return $this->readAuthUser() !== null;
    }

    /**
     * 管理画面へのアクセスかを確認
     *
     * @return bool
     */
    public function isAdminPage(): bool
    {
        return $this->_View->getRequest()->getParam('prefix') === 'Admin';
    }

    /**
     * 編集画面へのアクセスかを確認
     *
     * @return bool
     */
    public function isEditPage(): bool
    {
        $action = $this->_View->getRequest()->getParam('action');

        return $action === 'edit';
    }

    /**
     * テスト結果画面へのアクセスかを確認
     *
     * @return bool
     */
    public function isRecordPage(): bool
    {
        $action = $this->_View->getRequest()->getParam('action');

        return $action === 'record';
    }

    /**
     * ログイン画面へのアクセスかを確認
     *
     * @return bool
     */
    public function isLoginPage(): bool
    {
        $action = $this->_View->getRequest()->getParam('action');

        return $action === 'login';
    }

    /**
     * HTTPSかを確認
     *
     * @return bool
     */
    public function isHTTPS(): bool
    {
        return $this->_View->getRequest()->is('https');
    }

    /**
     * 接続元がローカルIPか確認
     *
     * @return bool
     */
    public function isLocalIP(): bool
    {
        $ip = $this->_View->getRequest()->clientIp();

        if ($ip === '::1') {
            return true;
        }

        return (bool)preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip);
    }
}
