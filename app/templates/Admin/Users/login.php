<div class="admin-users-login">
	<div class="panel panel-default form-signin">
		<div class="panel-heading">
			<?= __('管理者ログイン')?>
		</div>
		<div class="panel-body">
			<div class="text-right"><a href="<?= $this->Url->build(['action' => 'login', 'prefix' => false]) ?>"><?= __('受講者ログインへ')?></a></div>
			<?= $this->Flash->render('auth'); ?>
			<?= $this->Form->create(null); ?>
			
			<div class="form-group">
				<?= $this->Form->control('username', ['label' => __('ログインID'), 'class'=>'form-control']); ?>
			</div>
			<div class="form-group">
				<?= $this->Form->control('password', ['label' => __('パスワード'), 'class'=>'form-control']);?>
			</div>
			<div class="form-group">
				<?= $this->Form->button(__('ログイン'), ['class' => 'btn btn-lg btn-primary btn-block', 'type' => 'submit']); ?>
			</div>
			<?= $this->Form->end(); ?>
		</div>
	</div>
</div>
