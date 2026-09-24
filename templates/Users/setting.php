<div class="users-setting">
	<div class="breadcrumb">
	<?php use Cake\Core\Configure; ?>
<?php
	$this->Html->addCrumb(
		'<span class="glyphicon glyphicon-home" aria-hidden="true"></span> HOME',
		['controller' => 'UsersCourses','action' => 'index'],
		['escape' => false]
	);
	echo $this->Html->getCrumbs(' / ');
	?>
	</div>
	<div class="panel panel-default">
		<div class="panel-heading">
			<?= __('設定')?>
		</div>
		<div class="panel-body">
		<?php
			echo $this->Form->create(null, Configure::read('form_defaults'));
			echo $this->Form->control('User.new_password', [
				'label' => __('新しいパスワード'),
				'type' => 'password',
				'autocomplete' => 'new-password'
			]);
			
			echo $this->Form->control('User.new_password2', [
				'label' => __('新しいパスワード (確認用)'),
				'type' => 'password',
				'autocomplete' => 'new-password'
			]);
			echo Configure::read('form_submit_before')
				.$this->Form->submit(__('保存'), Configure::read('form_submit_defaults'))
				.Configure::read('form_submit_after');
			echo $this->Form->end();
		?>
		</div>
	</div>
</div>
