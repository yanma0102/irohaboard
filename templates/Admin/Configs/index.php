<?= $this->element('admin_menu'); ?>
<?php
use Cake\Core\Configure;
?>
<style>
.admin-configs-index .form-group input[type="checkbox"] {
    margin-top: 8px;
}
</style>
<?php $this->start('script-embedded'); ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 接続テストボタン
    const testBtn = document.getElementById('ldap-test-btn');
    if (testBtn) {
        testBtn.addEventListener('click', async function() {
            const resultDiv = document.getElementById('ldap-test-result');
            resultDiv.textContent = 'テスト中...';
            resultDiv.className = 'alert alert-info';

            // フォームからLDAPフィールドを収集
            const form = document.querySelector('form[id^="config-form-ldap"]');
            if (!form) {
                resultDiv.textContent = 'LDAPフォームが見つかりません';
                resultDiv.className = 'alert alert-danger';
                return;
            }
            const formData = new FormData(form);
            // 接続テスト用のアクションを一時的に上書き
            const originalAction = form.action;
            form.action = '/admin/configs/test-ldap';
            const submitter = document.createElement('input');
            submitter.type = 'hidden';
            submitter.name = 'op';
            submitter.value = 'test';
            form.appendChild(submitter);

            try {
                const response = await fetch('/admin/configs/test-ldap', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-Token': document.querySelector('input[name="_csrfToken"]').value
                    },
                    credentials: 'same-origin'
                });
                const result = await response.json();
                resultDiv.textContent = result.message;
                resultDiv.className = result.success ? 'alert alert-success' : 'alert alert-danger';
            } catch (e) {
                resultDiv.textContent = 'テスト実行エラー: ' + e.message;
                resultDiv.className = 'alert alert-danger';
            } finally {
                form.removeChild(submitter);
                form.action = originalAction;
            }
        });
    }
});
</script>
<?php $this->end(); ?>

<div class="admin-configs-index">
    <div class="panel panel-default">
        <div class="panel-heading">
            <?= __('アプリ設定'); ?>
        </div>
        <div class="panel-body">
            <?php
            $formDefaults = Configure::read('form_defaults');
            unset($formDefaults['inputDefaults']);
            foreach ($viewData as $catId => $catData):
                $formId = 'config-form-' . $catId;
            ?>
            <div class="panel panel-default config-category-panel" style="margin-top: 20px;">
                <div class="panel-heading d-flex justify-content-between align-items-center">
                    <h4 class="panel-title" style="margin: 0;"><?= h($catData['label']) ?></h4>
                    <div class="btn-group" role="group">
                        <button type="submit" form="<?= $formId ?>" name="op" value="save" class="btn btn-primary btn-sm">
                            <span class="glyphicon glyphicon-floppy-disk"></span> 保存
                        </button>
                        <button type="submit" form="<?= $formId ?>" name="op" value="reset" class="btn btn-warning btn-sm" onclick="return confirm('このカテゴリの設定をデフォルト値に戻しますか？');">
                            <span class="glyphicon glyphicon-refresh"></span> デフォルトに戻す
                        </button>
                        <input type="hidden" form="<?= $formId ?>" name="category" value="<?= h($catId) ?>">
                    </div>
                </div>
                <div class="panel-body">
                    <?php
                    $formDefaultsCat = $formDefaults;
                    unset($formDefaultsCat['inputDefaults']);
                    // チェックボックスを他の入力と同様「ラベル左・コントロール右」に揃える
                    $formDefaultsCat['templates'] = array_merge($formDefaultsCat['templates'] ?? [], [
                        'checkboxFormGroup' => '{{label}}<div class="col col-sm-9">{{input}}</div>',
                    ]);
                    echo $this->Form->create(null, array_merge($formDefaultsCat, [
                        'id' => $formId,
                        'url' => ['controller' => 'Configs', 'action' => 'index'],
                    ]));
                    $this->Form->unlockField('op');
                    echo $this->Form->control('category', ['type' => 'hidden', 'value' => $catId]);
                    foreach ($catData['fields'] as $key => $field):
                        $def = $field['def'];
                        $val = $field['current'] ?? '';
                        $isOverride = $field['is_override'] ?? false;
                        $defaultVal = $field['default'] ?? null;
                        $help = $def['help'] ?? '';
                        $unit = $def['unit'] ?? '';
                        $options = [
                            'label' => __($def['label']),
                            'value' => $val,
                        ];
                        if ($help) {
                            $options['help'] = $help;
                        }
                        $afterParts = [];
                        if ($unit) {
                            $afterParts[] = '<span class="text-muted small">' . h($unit) . '</span>';
                        }
                        if ($isOverride) {
                            // 「上書き中」は「未保存」を誤解されやすいため、
                            // ib_config.php の既定値から変更済みであることが分かる文言にする
                            $afterParts[] = '<span class="label label-info" style="margin-left: 8px;"'
                                . ' title="ib_config.php の既定値ではなく、DBに保存された値で動作しています">設定変更あり</span>';
                        }
                        if ($defaultVal !== null) {
                            // size 型はバイト保存のため MB 表示に揃える（入力欄と同じ単位にする）
                            $defaultText = $def['type'] === 'size'
                                ? (string)(int)round($defaultVal / 1048576)
                                : (string)$defaultVal;
                            $afterParts[] = '<span class="text-muted small">既定: ' . h($defaultText) . '</span>';
                        }
                        if (!empty($afterParts)) {
                            // label(col-sm-3) と入力欄(col-sm-9) が floats で幅を埋めるため、
                            // after をそのまま置くと左端に折り返す。入力欄と揃うよう offset を付ける。
                            $options['after'] = '<div class="col col-sm-offset-3 col-sm-9">'
                                . implode(' ', $afterParts) . '</div>';
                        }
                        switch ($def['type']) {
                            case 'bool':
                                $options['type'] = 'checkbox';
                                $options['value'] = '1';
                                $options['checked'] = !empty($val);
                                $options['hiddenField'] = true;
                                // label 内にネストせず、ラベルとチェックボックスを分離する
                                $options['nestedInput'] = false;
                                $options['class'] = false;
                                break;
                            case 'int':
                                $options['type'] = 'number';
                                if (isset($def['min'])) $options['min'] = $def['min'];
                                if (isset($def['max'])) $options['max'] = $def['max'];
                                break;
                            case 'size':
                                $options['type'] = 'number';
                                $options['min'] = $def['min'] ?? 1;
                                $options['max'] = $def['max'] ?? 1024;
                                // バイト値をMB表示
                                $options['value'] = $val !== '' ? (int)round($val / 1048576) : '';
                                break;
                            case 'secret':
                                $options['type'] = 'password';
                                $options['value'] = ''; // 現値は表示しない
                                $options['placeholder'] = '設定済み（変更する場合のみ入力）';
                                $options['autocomplete'] = 'new-password';
                                break;
                            default:
                                $options['type'] = 'text';
                        }
                        if (!empty($def['required_if'])) {
                            $options['required'] = true;
                        }
                        echo $this->Form->control('Config.' . $key, $options);
                    endforeach;
                    // LDAPカテゴリのみ: 接続テストボタン
                    if ($catId === 'ldap') {
                        echo '<div class="form-group" style="margin-top: 15px;">';
                        echo '<button type="button" id="ldap-test-btn" class="btn btn-info btn-sm" style="margin-right: 10px;">';
                        echo '<span class="glyphicon glyphicon-wifi"></span> 接続テスト';
                        echo '</button>';
                        echo '<span id="ldap-test-result" class="text-muted small"></span>';
                        echo '</div>';
                    }
                    echo $this->Form->end();
                    ?>
                    </div><!-- /.panel-body -->
            </div><!-- /.config-category-panel -->
            <?php
                endforeach;
            ?>
        </div>
    </div>
</div>
