<?= $this->element('admin_menu');?>
<?php use Cake\Core\Configure; ?>
<?php $this->start('script-embedded'); ?>
<script>
	$(document).ready(function()
	{
		$('option').each(function(){
			console.log($(this).val());
			$(this).css('color',		'white');
			$(this).css('background',	$(this).val());
			$(this).css('font-weight',	'bold');
		});
	});
</script>
<?php $this->end(); ?>
<div class="admin-settings-index">
	<div class="panel panel-default">
		<div class="panel-heading">
			<?= __('システム設定'); ?>
		</div>
		<div class="panel-body">
		<?php
			$formDefaults = Configure::read('form_defaults');
			unset($formDefaults['inputDefaults']);
			echo $this->Form->create(null, $formDefaults);
			echo $this->Form->control('Setting.title',		['label' => __('システム名'),		'value'=>$settings['title']]);
			echo $this->Form->control('Setting.copyright',	['label' => __('コピーライト'),		'value'=>$settings['copyright']]);
			echo $this->Form->control('Setting.color',		['label' => __('テーマカラー'),		'options'=>$colors, 'selected'=>$settings['color']]);
			echo $this->Form->control('Setting.information',	['label' => __('全体のお知らせ'),	'value'=>$settings['information'], 'type' => 'textarea']);
			echo Configure::read('form_submit_before')
				.$this->Form->submit(__('保存'), Configure::read('form_submit_defaults'))
				.Configure::read('form_submit_after');
			echo $this->Form->end();
		?>
		</div>
	</div>
</div>
