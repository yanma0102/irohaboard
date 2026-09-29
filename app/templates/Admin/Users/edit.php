<?= $this->element('admin_menu');?>
<?= $this->Html->css( 'select2.min.css');?>
<?= $this->Html->script( 'select2.min.js');?>
<?php use Cake\Core\Configure; ?>
<?php $this->Html->scriptStart(); ?>
	$(function (e) {
		$('#groups-ids').select2({placeholder:   "<?= __('所属するグループを選択して下さい。(複数選択可)')?>", closeOnSelect: <?= (Configure::read('close_on_select') ? 'true' : 'false'); ?>,});
		$('#courses-ids').select2({placeholder: "<?= __('受講するコースを選択して下さい。(複数選択可)')?>", closeOnSelect: <?= (Configure::read('close_on_select') ? 'true' : 'false'); ?>,});
		// パスワードの自動復元を防止
		setTimeout('$("#new-password").val("");', 500);
	});
<?= $this->Html->scriptEnd(); ?>
<div class="admin-users-edit">
<?= $this->Html->link(__('<< 戻る'), ['action' => 'index'])?>
	<div class="panel panel-default">
		<div class="panel-heading">
			<?= $this->AppView->isEditPage() ? __('編集') :  __('新規ユーザ'); ?>
		</div>
		<div class="panel-body">
		<?php
			echo $this->Form->create(null, Configure::read('form_defaults'));
			
			$password_label = $this->AppView->isEditPage() ? __('新しいパスワード') : __('パスワード');
			
			echo $this->Form->control('id');
			echo $this->Form->control('username',				['label' => __('ログインID')]);
			echo $this->Form->control('new_password',	['label' => $password_label, 'type' => 'password', 'autocomplete' => 'new-password']);
			echo $this->Form->control('name',					['label' => __('氏名')]);
			
			// root アカウント、もしくは admin 権限以外の場合、権限変更を許可しない
			$disabled = (($username == 'root') || ($loginedUser['role'] != 'admin'));
			
			echo $this->Form->inputRadio('role',	['label' => __('権限'), 'options' => Configure::read('user_role')]);
			
			echo $this->Form->control('is_active', [
				'type' => 'checkbox',
				'label' => __('有効'),
				'checked' => ($user->is_active ?? true) !== false,
				// form_input_defaults の form-control はテキスト入力用のため
				// チェックボックスに付くと幅100%になり左端に張り付く。除外する。
				'class' => false,
				'_inputDefaultsApplied' => true,
			]);
			
			echo $this->Form->control('email',				['label' => __('メールアドレス')]);
			echo $this->Form->control('groups._ids',				['label' => __('所属グループ'), 'options' => $groups]);
			echo $this->Form->control('courses._ids',				['label' => __('受講コース'), 'options' => $courses]);
			echo $this->Form->control('comment',				['label' => __('備考')]);
			echo Configure::read('form_submit_before')
				.$this->Form->submit(__('保存'), Configure::read('form_submit_defaults'))
				.Configure::read('form_submit_after');
			echo $this->Form->end();
			
			// 編集の場合のみ、学習履歴削除ボタンを表示
			if($this->AppView->isEditPage())
			{
				echo $this->Form->postLink(__('学習履歴を削除'),
					['action' => 'clear', $user['id']],
					['class' => 'btn btn-default pull-right btn-clear'],
					__('学習履歴を削除してもよろしいですか？', $user['name']));
			}
		?>
		</div>
	</div>
</div>
