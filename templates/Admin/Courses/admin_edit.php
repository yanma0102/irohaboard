<?= $this->element('admin_menu');?>
<div class="admin-courses-edit">
<?= $this->Html->link(__('<< 戻る'), ['action' => 'index'])?>
	<div class="panel panel-default">
		<div class="panel-heading">
			<?= $this->AppView->isEditPage() ? __('編集') :  __('新規コース'); ?>
		</div>
		<div class="panel-body">
		<?php
			echo $this->Form->create('Course', Configure::read('form_defaults'));
			echo $this->Form->control('id');
			echo $this->Form->control('title',	['label' => __('コース名')]);
			echo $this->Form->control('introduction',	['label' => __('コース紹介')]);
			echo $this->Form->control('comment',		['label' => __('備考')]);
			echo Configure::read('form_submit_before')
				.$this->Form->submit(__('保存'), Configure::read('form_submit_defaults'))
				.Configure::read('form_submit_after');
			echo $this->Form->end();
		?>
		</div>
	</div>
</div>