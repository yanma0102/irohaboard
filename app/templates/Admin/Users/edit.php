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

			// 権限の選択肢は運用で実際に使うもの（管理者/受講者）のみとする。
			// ただし旧ロール(manager/editor/teacher)のユーザの場合、選択肢に
			// 現在の値が無ければ required のラジオが1つも checked にならず、
			// ブラウザがフォーム送信そのものをブロックしてしまう。
			// 送信を妨げないよう、その場合のみ現在のロールを選択肢に含める。
			$roleOptions = Configure::read('user_role');
			$currentRole = $user->role ?? '';
			if ($currentRole !== '' && !isset($roleOptions[$currentRole])) {
				$roleOptions[$currentRole] = $currentRole . __('（旧権限）');
			}

			echo $this->Form->inputRadio('role',	['label' => __('権限'), 'options' => $roleOptions]);
			
		echo $this->Form->inputRadio('is_active', [
			'label' => __('アカウント状態'),
			'options' => ['1' => __('有効'), '0' => __('無効')],
			// is_active が NULL の環境でも「有効」が選択された状態にする
			// （未設定だと「有効」「無効」のどちらも選択されない表示になる）
			'value' => $user->isActiveValue ?? '1',
			// CakePHP のラジオは既定で「空文字の hidden」を併せて出力する。
			// ラジオが 1 つも選択・送信されないと空文字が patchEntity に
			// 渡って boolean 検証が失敗し、他の項目一并に
			// 「ユーザ情報が保存できませんでした」になる。
			// hidden を止めると、選択済みの 1 / 0 が常に送信される。
			'hiddenField' => false,
			// 何も選択されていないまま送信されないようにする（ブラウザ側で
			// 送信を止め、原因が分かるメッセージを出させる）。
			// 'value' で必ず 1 つ選択済みの状態になるので通常は発火しない。
			'required' => true,
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
