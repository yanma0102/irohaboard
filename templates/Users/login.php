<div class="users-login">
	<div class="panel panel-info form-signin">
		<div class="panel-heading">
			<?= __('受講者ログイン')?>
		</div>
		<div class="panel-body">
			<?php use Cake\Core\Configure; ?>
<?php if(Configure::read('show_admin_link')) {?>
			<div class="text-right"><a href="<?= $this->Url->build(['action' => 'login', 'prefix' => 'Admin']) ?>"><?= __('管理者ログインへ')?></a></div>
			<?php }?>
			<?= $this->Flash->render('auth'); ?>
			<?= $this->Form->create(null, ['url' => ['action' => 'login']]); ?>
			
			<div class="form-group">
				<?= $this->Form->control('username', ['label' => __('ログインID'), 'class'=>'form-control', 'value' => $username]); ?>
			</div>
			<div class="form-group">
				<?= $this->Form->control('password', ['label' => __('パスワード'), 'class'=>'form-control', 'value' => $password]);?>
				<?php if($this->AppView->isHTTPS()) {?>
				<input type="checkbox" name="data[User][remember_me]" value="1" id="remember_me"><?= __('ログイン状態を保持')?>
				<?= $this->Form->unlockField('remember_me'); ?>
				<?php }?>
			</div>
			<div class="form-group">
				<?= $this->Form->button(__('ログイン'), ['class' => 'btn btn-lg btn-primary btn-block', 'type' => 'submit']); ?>
			</div>
			<?= $this->Form->end(); ?>
		</div>
	</div>
</div>
