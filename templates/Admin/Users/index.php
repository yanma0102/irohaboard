<?= $this->element('admin_menu');?>
<?php use Cake\Core\Configure; ?>
<?php $this->start('script-embedded'); ?>
<script>
	function downloadCSV()
	{
		$('#UserCmd').val('export');
		$('#UserAdminIndexForm').submit();
		$('#UserCmd').val('');
	}
</script>
<?php $this->end(); ?>
<div class="admin-users-index">
	<div class="ib-page-title"><?= __('ユーザ一覧'); ?></div>
	<div class="buttons_container">
		<?php if($loginedUser['role'] == 'admin') { ?>
		<button type="button" class="btn btn-primary btn-export" onclick="downloadCSV();">エクスポート</button>
		<button type="button" class="btn btn-primary btn-import" onclick="location.href='<?= $this->Url->build(['action' => 'import']) ?>'">インポート</button>
		<button type="button" class="btn btn-primary btn-add" onclick="location.href='<?= $this->Url->build(['action' => 'add']) ?>'">+ 追加</button>
		<?php }?>
	</div>
	<div class="ib-horizontal">
	<?php
		echo $this->Form->create(null);
		echo $this->Form->searchField('group_id', [
			'label'    => __('グループ'),
			'options'  => $groups, 
			'selected' => $group_id, 
			'empty'    => '全て', 
			'onchange' => 'submit(this.form);'
		]);
		echo $this->Form->searchField('username',		['label' => __('ログインID')]);
		echo $this->Form->searchField('name',			['label' => __('氏名')]);
		echo $this->Form->hidden('cmd');
		echo $this->Form->submit(__('検索'),	['class' => 'btn btn-info btn-add']);
		echo $this->Form->end();
	?>
	</div>
	<table>
	<thead>
	<tr>
		<th nowrap><?= $this->Paginator->sort('username', __('ログインID')); ?></th>
		<th nowrap class="col-width"><?= $this->Paginator->sort('name', __('氏名')); ?></th>
		<th nowrap><?= $this->Paginator->sort('role', '権限'); ?></th>
		<th nowrap><?= __('所属グループ'); ?></th>
		<th nowrap class="ib-col-datetime"><?= __('受講コース'); ?></th>
		<th class="ib-col-datetime"><?= $this->Paginator->sort('last_logined', __('最終ログイン日時')); ?></th>
		<th class="ib-col-datetime"><?= $this->Paginator->sort('created', __('作成日時')); ?></th>
		<?php if($loginedUser['role'] == 'admin') {?>
		<th class="ib-col-action"><?= __('Actions'); ?></th>
		<?php }?>
	</tr>
	</thead>
	<tbody>
	<?php foreach ($users as $user): ?>
	<tr>
		<td><?= h($user['username']); ?>&nbsp;</td>
		<td><?= h($user['name']); ?></td>
		<td nowrap><?= h(Configure::read('user_role.'.$user['role'])); ?>&nbsp;</td>
		<td><div class="reader" title="<?= h($user['group_title']); ?>"><p><?= h($user['group_title']); ?>&nbsp;</p></div></td>
		<td><div class="reader" title="<?= h($user['course_title']); ?>"><p><?= h($user['course_title']); ?>&nbsp;</p></div></td>
		<td class="ib-col-datetime"><?= h(Utils::getYMDHN($user['last_logined'])); ?>&nbsp;</td>
		<td class="ib-col-datetime"><?= h(Utils::getYMDHN($user['created'])); ?>&nbsp;</td>
		<?php if($loginedUser['role'] == 'admin') {?>
		<td class="ib-col-action">
			<button type="button" class="btn btn-success" onclick="location.href='<?= $this->Url->build(['action' => 'edit', $user['id']]) ?>'"><?= __('編集')?></button>
			<?= $this->Form->postLink(__('削除'), ['action' => 'delete', $user['id']], ['class' => 'btn btn-danger'],__('[%s] を削除してもよろしいですか?', $user['name']));?>
		</td>
		<?php }?>
	</tr>
	<?php endforeach; ?>
	</tbody>
	</table>
	<?= $this->element('paging');?>
</div>