<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Service\AppConfigService;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\Http\Exception\ForbiddenException;

/**
 * 設定管理コントローラ（アプリ設定 / ib_config.php オーバーライド）
 */
class ConfigsController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        // testLdap は AJAX POST で FormProtection の Token fields 検証を免除
        $this->FormProtection->unlockActions(['testLdap']);
    }

    public function beforeFilter(EventInterface $event): ?Response
    {
        parent::beforeFilter($event);

        // beforeFilter は Controller.initialize（認証チェックの Controller.startup より前）で発火する。
        // 未ログイン時は identity が無くロール判定も不可能なため、
        // 認証チェックに任せて login へリダイレクトさせる（403 を投げない）。
        if ($this->Authentication->getIdentity() === null) {
            return null;
        }

        // adminロール限定（既存の staff 判定より厳格：secrets 含むため）
        if (!$this->Role->isAdmin()) {
            throw new ForbiddenException(__('アクセス権限がありません'));
        }
        return null;
    }

    /**
     * 設定一覧・保存・リセット
     */
    public function index(): ?Response
    {
        $op = $this->request->getData('op') ?? 'view';

        if ($this->request->is(['post', 'put'])) {
            if (Configure::read('demo_mode')) {
                // demo_mode=true 環境では demo_mode=false への変更のみ許可
                $data = $this->getData('Config');
                if ($op === 'save') {
                    $changed = array_filter($data, fn($v) => $v !== '');
                    $onlyDemoModeOff = count($changed) === 1 && isset($changed['demo_mode']) && $changed['demo_mode'] === '0';
                    if (!$onlyDemoModeOff) {
                        $this->Flash->error(__('デモモードでは設定を変更できません（デモモード解除のみ可能）'));
                        return $this->redirect(['action' => 'index']);
                    }
                }
            }
        }

        $schema = AppConfigService::schema();
        $categories = AppConfigService::categories();

        if ($this->request->is(['post', 'put'])) {
            $category = $this->request->getData('category') ?? 'ldap';
            $errors = AppConfigService::validate($this->getData('Config'), $category);

            if (!empty($errors)) {
                $this->Flash->error(__('入力内容に誤りがあります: ') . implode('; ', $errors));
            } else {
                if ($op === 'save') {
                    AppConfigService::saveCategory($this->getData('Config'), $category);
                    $this->Flash->success(__('{0} を保存しました（次リクエストから反映されます）', $categories[$category]['label']));
                    return $this->redirect(['action' => 'index']);
                } elseif ($op === 'reset') {
                    AppConfigService::resetCategory($category);
                    $this->Flash->success(__('{0} をデフォルト値に戻しました', $categories[$category]['label']));
                    return $this->redirect(['action' => 'index']);
                }
            }
        }

        // 画面表示用: 各カテゴリのキー・現在値・デフォルト値・オーバーライド有無を取得
        $viewData = [];
        foreach (AppConfigService::categories() as $catId => $catInfo) {
            $keys = AppConfigService::keysByCategory($catId);
            $fields = [];
            foreach ($keys as $key) {
                $def = $schema[$key];
                $current = Configure::read($key);
                $default = Configure::read("ib_config_defaults.$key") ?? $def['default'] ?? null;
                $hasOverride = Configure::read("ib_config_defaults.$key") !== null && $current !== $default;
                $fields[$key] = [
                    'def' => $def,
                    'current' => $current,
                    'default' => $default,
                    'is_override' => $hasOverride,
                ];
            }
            $viewData[$catId] = [
                'label' => $catInfo['label'],
                'fields' => $fields,
            ];
        }

        $this->set(compact('viewData', 'categories'));
        return null;
    }

    /**
     * LDAP接続テスト（AJAX POST /admin/configs/test-ldap）
     * 未保存値でテスト可能（JSON返却）
     */
    public function testLdap(): ?Response
    {
        $this->request->allowMethod('post');
        $ldapKeys = ['ldap_host', 'ldap_port', 'ldap_base_dn', 'ldap_bind_dn', 'ldap_bind_password', 'ldap_tls'];
        $params = [];
        foreach ($ldapKeys as $k) {
            $v = $this->request->getData($k);
            if ($v !== null && $v !== '') {
                $params[$k] = $v;
            }
        }
        // secret未入力時は現行値を使用（テストのみ）
        if (empty($params['ldap_bind_password'])) {
            $params['ldap_bind_password'] = Configure::read('ldap_bind_password');
        }
        $result = (new \App\Service\LdapAuthService())->testConnection($params);
        $this->response = $this->response
            ->withType('json')
            ->withStringBody(json_encode($result, JSON_UNESCAPED_UNICODE));
        return $this->response;
    }
}
