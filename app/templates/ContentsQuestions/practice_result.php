<?php
/**
 * カスタム受験（練習）の結果画面
 *
 * @var \App\Model\Entity\Content $content コンテンツ
 * @var \Cake\Datasource\ResultSetInterface $contentsQuestions 出題した問題
 * @var array $question_records 設問IDをキーにした結果
 * @var array $practice_result 練習結果
 */
$wrong_mode = (int)$practice_result['settings']['wrong_mode'];
$question_index = 1;
?>
<?php $this->start('script-embedded'); ?>
<script>
	var TIMELIMIT_SEC	= 0;											// 結果画面ではタイマーを使用しない
	var IS_RECORD		= '1';											// 結果表示フラグ
	var CONTENT_ID		= '<?= (int)$content->id ?>';					// コンテンツID
	var USER_ID			= '<?= (int)$this->AppView->readAuthUser('id') ?>';	// ログインユーザID
	var IS_ADMIN_RECORD	= '';											// 管理者による履歴表示ではない
	var IS_PRACTICE		= '1';											// 練習モード
	var TEST_MODE		= 'practice';									// 練習用の退避データを削除する
</script>
<?= $this->Html->script('contents_questions.js?20261021');?>
<?php $this->end(); ?>
<div class="contents-questions-practice-result">
	<div class="breadcrumb">
	<?php
		$this->Html->addCrumb(
			'<span class="glyphicon glyphicon-book" aria-hidden="true"></span> コース一覧',
			['controller' => 'UsersCourses', 'action' => 'index'],
			['escape' => false]
		);
		$this->Html->addCrumb($content->course->title, ['controller' => 'Contents', 'action' => 'index', $content->course_id]);
		$this->Html->addCrumb(h($content->title), ['controller' => 'ContentsQuestions', 'action' => 'index', $content->id]);
		$this->Html->addCrumb(__('練習'), ['controller' => 'ContentsQuestions', 'action' => 'practice', $content->id]);
		$this->Html->addCrumb(__('結果'));

		echo $this->Html->getCrumbs(' / ');
	?>
	</div>

	<div class="alert alert-info">
		<strong><?= __('練習結果（記録されません）'); ?></strong>
		<?= __('この結果は成績・学習回数・理解度・コース完了に反映されません。'); ?>
	</div>

	<table class="result-table">
		<caption><?= __('練習結果'); ?></caption>
		<tr>
			<td><?= __('得点'); ?></td>
			<td><?= (int)$practice_result['my_score']; ?> / <?= (int)$practice_result['full_score']; ?></td>
		</tr>
		<tr>
			<td><?= __('正解数'); ?></td>
			<td><?= (int)$practice_result['correct_count']; ?> / <?= (int)$practice_result['question_count']; ?> <?= __('問'); ?></td>
		</tr>
	</table>

	<?php foreach ($contentsQuestions as $contentsQuestion): ?>
		<?= $this->element('ContentsQuestions/question', [
			'contentsQuestion' => $contentsQuestion,
			'question_index' => $question_index,
			'question_records' => $question_records,
			'is_record' => true,
			'wrong_mode' => $wrong_mode,
		]); ?>
		<?php $question_index++; ?>
	<?php endforeach; ?>

	<div class="form-inline">
		<?= $this->Html->link(__('もう一度練習'), ['controller' => 'ContentsQuestions', 'action' => 'practice', $content->id], ['class' => 'btn btn-primary btn-lg']); ?>
		<?= $this->Html->link(__('設定を変更'), ['controller' => 'ContentsQuestions', 'action' => 'practice', $content->id], ['class' => 'btn btn-default btn-lg']); ?>
		<?= $this->Html->link(__('コースに戻る'), ['controller' => 'Contents', 'action' => 'index', $content->course_id], ['class' => 'btn btn-default btn-lg']); ?>
	</div>
</div>
