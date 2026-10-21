<?php
$is_practice = !empty($is_practice);
$current_timelimit = isset($timelimit) ? (int)$timelimit : (int)$content->timelimit;
$current_wrong_mode = $is_practice && isset($practice_settings['wrong_mode'])
	? (int)$practice_settings['wrong_mode']
	: (int)$content->wrong_mode;
?>
<?php $this->start('css-embedded'); ?>
<style type='text/css'>
	<?php if($is_admin_record) { // 管理者による学習履歴表示モードの場合、ロゴのリンクを無効化 ?>
	.ib-navi-item
	{
		display: none;
	}
	
	.ib-logo a
	{
		pointer-events: none;
	}
	<?php }?>
</style>
<?php $this->end(); ?>

<?php $this->start('script-embedded'); ?>
<script>
	var TIMELIMIT_SEC	= parseInt('<?= $current_timelimit ?>') * 60;	// 制限時間（単位：秒）
	var IS_RECORD		= '<?= $is_record ?>';										// テスト結果表示フラグ
	var CONTENT_ID		= '<?= (int)$content->id ?>';								// コンテンツID
	var USER_ID			= '<?= (int)$this->AppView->readAuthUser('id') ?>';			// ログインユーザID
	var IS_ADMIN_RECORD	= '<?= $is_admin_record ?>';									// 管理者による履歴表示フラグ
	var IS_PRACTICE		= '<?= $is_practice ? '1' : '0' ?>';							// 練習モードフラグ
	var TEST_MODE		= '<?= $is_practice ? 'practice' : 'official' ?>';			// 受験中データの退避モード
	var MSG_TIMELIMIT	= '<?= __('制限時間を過ぎましたので自動採点を行います。') ?>';
	var MSG_REST_TIME	= '<?= __('残り時間') ?>';
	var MSG_TIME		= '<?= __('経過') ?>';
	var MSG_RESTORED	= '<?= __('前回の回答を復元しました') ?>';
	var MSG_RESTORED_PARTIAL	= '<?= __('前回の回答を復元しました（出題内容が変更されたため、一部の回答は復元されていません）') ?>';
</script>
<?= $this->Html->script('contents_questions.js?20261021');?>
<?php $this->end(); ?>
<div class="contents-questions-index">
	<div class="breadcrumb">
	<?php
	// 管理者による学習履歴表示モードの場合、コース一覧リンクを表示しない
	if($is_admin_record)
	{
		$course_url = ['controller' => 'contents', 'action' => 'record', $record->course_id, $record->user_id];
	}
	else
	{
		$course_url = ['controller' => 'contents', 'action' => 'index', $content->course_id];
		$this->Html->addCrumb(
			'<span class="glyphicon glyphicon-book" aria-hidden="true"></span> コース一覧',
			['controller' => 'UsersCourses','action' => 'index'],
			['escape' => false]
		);
	}
	
	if($is_practice)
	{
		$this->Html->addCrumb($content->course->title, $course_url);
		$this->Html->addCrumb(h($content->title), ['controller' => 'ContentsQuestions', 'action' => 'index', $content->id]);
		$this->Html->addCrumb(__('練習'));
		$back_url = ['controller' => 'ContentsQuestions', 'action' => 'practice', $content->id];
	}
	else
	{
		$this->Html->addCrumb($content->course->title, $course_url);
		$this->Html->addCrumb(h($content->title)); // addCrumb 内でエスケープされない為、別途エスケープ
		$back_url = $course_url;
	}
	echo $this->Html->getCrumbs(' / ');
	?>
	</div>
	<?php if($is_practice){ ?>
	<div class="alert alert-warning">
		<strong><?= __('練習（記録されません）'); ?></strong>
		<?= __('この結果は成績・学習回数・理解度・コース完了に反映されません。'); ?>
	</div>
	<?php }?>
	<div id="lblStudySec" class="btn btn-info"></div>
	<!-- テスト結果ヘッダ表示 -->
	<?php if($is_record){ ?>
		<?php
		$result_color  = ($record->is_passed == 1) ? 'text-primary' : 'text-danger';
		$result_label  = ($record->is_passed == 1) ? __('合格') : __('不合格');
		?>
		<table class="result-table">
			<caption><?= __('テスト結果'); ?></caption>
			<tr>
				<td><?= __('合否'); ?></td>
				<td><div class="<?= $result_color; ?>"><?= $result_label; ?></div></td>
			</tr>
			<tr>
				<td><?= __('得点'); ?></td>
				<td><?= $record->score.' / '.$record->full_score; ?></td>
			</tr>
			<tr>
				<td><?= __('合格基準得点'); ?></td>
				<td><?= ($record->pass_score) ? $record->pass_score : __('設定されていません'); ?></td>
			</tr>
		</table>
	<?php }?>
	
	<!-- 問題一覧 -->
	<?php
		$question_index = 1; // 設問番号
		
		// 問題IDをキーに問題の成績が参照できる配列を作成
		$question_records = [];
		
		if($is_record)
		{
			foreach ($record->records_questions as $rec)
			{
				$question_records[$rec['question_id']] = $rec;
			}
		}
		
		echo $this->Form->create(null, $is_practice
			? ['url' => ['action' => 'practiceGrade', $content->id]]
			: []);
	?>
		<?php foreach ($contentsQuestions as $contentsQuestion): ?>
			<?= $this->element('ContentsQuestions/question', [
				'contentsQuestion' => $contentsQuestion,
				'question_index' => $question_index,
				'question_records' => $question_records,
				'is_record' => $is_record,
				'wrong_mode' => $current_wrong_mode,
			]); ?>
			<?php $question_index++;?>
		<?php endforeach; ?>
		
		<?php
			echo '<div class="form-inline"><!--start-->';
			
			// テスト実施の場合のみ、採点ボタンを表示
			if (!$is_record)
			{
				// セッション切れ時の復元用に出題リスト（出題順）を送信する
				if ($question_id_list !== [])
				{
					echo $this->Form->hidden('question_id_list', ['value' => implode(',', $question_id_list)]);
				}
				
				echo $this->Form->hidden('ContentsQuestion.study_sec');
				echo '<input type="button" value="'.__('採点').'" class="btn btn-primary btn-lg btn-score" onclick="$(\'#confirmModal\').modal()">';
				echo '&nbsp;';
			}
			
			echo '<input type="button" value="'.__('戻る').'" class="btn btn-default btn-lg" onclick="location.href=\''. $this->Url->build($back_url).'\'">';
			echo '</div><!--end-->';
			echo $this->Form->end();
		?>
	<br>
</div>
<!--採点確認ダイアログ-->
<div class="modal fade" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmModalLabel" aria-describedby="confirmModalDesc">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span><span class="sr-only">閉じる</span></button>
				<h4 class="modal-title" id="confirmModalLabel"><?= __('採点確認');?></h4>
			</div>
			<div class="modal-body">
				<p id="confirmModalDesc"><?= __('採点してよろしいですか？');?></p>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-dismiss="modal"><?= __('キャンセル');?></button>
				<button type="button" class="btn btn-primary btn-score" onclick="sendData();"><?= __('採点');?></button>
			</div>
		</div><!-- /.modal-content -->
	</div><!-- /.modal-dialog -->
</div><!-- /.modal -->
