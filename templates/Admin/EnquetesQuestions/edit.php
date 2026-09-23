<?= $this->element('admin_menu');?>
<?= $this->Html->css('summernote.css');?>
<?php use Cake\Core\Configure; ?>
<?php $this->start('script-embedded'); ?>
<?= $this->Html->script('summernote.min.js');?>
<?= $this->Html->script('lang/summernote-ja-JP.js');?>
<script>
	$(document).ready(function()
	{
		init();
	});

	function add_option()
	{
		txt	= document.getElementById("option");
		opt	= document.getElementById("option_list").options;
		
		if(txt.value == '')
		{
			alert("選択肢を入力してください");
			return false;
		}
		
		if(txt.value.length > 100)
		{
			alert("選択肢は100文字以内で入力してください");
			return false;
		}
		
		if(opt.length == 10)
		{
			alert("選択肢の数が最大値を超えています");
			return false;
		}
		
		opt[opt.length] = new Option( txt.value, txt.value )
		txt.value = "";
		update_options();

		return false;
	}

	function del_option()
	{
		var opt = document.getElementById("option_list").options;
		
		if( opt.selectedIndex > -1 )
		{
			opt[opt.selectedIndex] = null;
			update_options();
		}
	}

	function update_options()
	{
		var opt = document.getElementById("option_list").options;
		var txt = document.getElementById("options");
		
		txt.value = "";
		
		for(var i=0; i<opt.length; i++)
		{
			if(txt.value == '')
			{
				txt.value = opt[i].value;
			}
			else
			{
				txt.value += "|" + opt[i].value;
			}
		}
		
	}

	function update_correct()
	{
		var opt = document.getElementById("option_list").options;
		
		if( opt.selectedIndex < 0 )
		{
			document.getElementById("correct").value = "";
		}
		else
		{
			var corrects = new Array();
			
			for(var i=0; i<opt.length; i++)
			{
				if(opt[i].selected)
					corrects.push(i+1);
			}
			
			document.getElementById("correct").value = corrects.join(',');
		}
	}

	function init()
	{
		// リッチテキストエディタを起動
		CommonUtil.setRichTextEditor('#body', <?= Configure::read('upload_image_maxsize') ?>, '<?= $this->Url->webroot('/') ?>');
		CommonUtil.setRichTextEditor('#explain', <?= Configure::read('upload_image_maxsize') ?>, '<?= $this->Url->webroot('/') ?>');
		
		// 保存時、コード表示モードの場合、解除する（編集中の内容を反映するため）
		$("form").submit( function() {
			if ($('#explain').summernote('codeview.isActivated')) {
				$('#explain').summernote('codeview.deactivate')
			}
			
			if($('#body').val() == '')
			{
				alert('質問文が入力されていません');
				return false;
			}
			
			if($("#options").val() == '')
			{
				alert('選択肢が追加されていません');
				return false;
			}
		});
		
		if($("#options").val() == '')
			return;
		
		var options = $("#options").val().split('|');
		
		for(var i=0; i<options.length; i++)
		{
			var isSelected = false;
			$option = $('<option>')
				.val(options[i])
				.text(options[i])
				.prop('selected', isSelected);
			
			$("#option_list").append($option);
		}
		
		render();
	}
	
	function render()
	{
		if($('input[name="question_type"]:checked').val() == 'text')
		{
			$('#options').val('none');
			$('.row-options').hide();
		}
		else
		{
			if($('#options').val()=='none')
				$('#option_list').children().remove();
			
			$('.row-options').show();
		}
	}
	
	function question_type_onchange()
	{
		update_options();
		render();
	}
</script>
<?php $this->end(); ?>
<div class="admin-contents-questions-edit">
	<div class="ib-breadcrumb">
	<?php 
		$this->Html->addCrumb(__('コース一覧'),  ['controller' => 'courses', 'action' => 'index']);
		$this->Html->addCrumb($content['course']['title'],  ['controller' => 'contents', 'action' => 'index', $content['course']['id']]);
		$this->Html->addCrumb($content['title'], ['controller' => 'EnquetesQuestions', 'action' => 'index', $content['id']]);
		
		echo $this->Html->getCrumbs(' / ');
	?>
	</div>
	<div class="panel panel-default">
		<div class="panel-heading">
			<?= $this->AppView->isEditPage() ? __('編集') :  __('新規質問'); ?>
		</div>
		<div class="panel-body">
			<?php
				echo $this->Form->create($question, Configure::read('form_defaults'));;
				echo $this->Form->control('id');
				echo $this->Form->control('title',	['label' => __('タイトル')]);
				echo $this->Form->control('body',		['label' => __('質問文')]);
				echo $this->Form->inputRadio('question_type', ['label' => __('回答形式'), 'options' => Configure::read('question_type'), 'default' => 'single', 'onchange' => 'render()']);
			?>
			<div class="form-group row-options required">
				<label for="option_list" class="col col-sm-3 control-label">選択肢／正解</label>
				<div class="col col-sm-9 required">
				「＋」で選択肢の追加、「−」で選択された選択肢を削除します。（※最大10個まで）<br>
				<input type="text" size="20" name="option" id="option" style="width: 80%;display:inline-block;">
				<button class="btn" onclick="add_option();return false;">＋</button>
				<button class="btn" onclick="del_option();return false;">−</button><br>
			<?php
				echo $this->Form->control('option_list',	['label' => __('選択肢／正解'), 
					'type' => 'select',
					'label' => false,
					'multiple' => true,
					'size' => 5,
					'id' => 'option_list'
				]);
				echo $this->Form->hidden('options',		['label' => __('選択肢'), 'id' => 'options']);
			?>
				</div>
			</div>
			<?php
				echo $this->Form->control('comment',	['label' => __('備考')]);
				echo Configure::read('form_submit_before')
					.$this->Form->submit(__('保存'), Configure::read('form_submit_defaults'))
					.Configure::read('form_submit_after');
				echo $this->Form->end();
			?>
		</div>
	</div>
</div>