<?php
/**
 * カスタム受験（練習）の設定画面
 *
 * @var \App\Model\Entity\Content $content コンテンツ
 * @var array $categories カテゴリ一覧
 * @var array $settings 設定
 * @var array $selected_category_keys 選択中のカテゴリ（フォーム値）
 * @var array $timelimit_choices 制限時間の選択肢
 * @var array $wrong_mode_options 不正解時の表示の選択肢
 */

$categoryCounts = [];

foreach ($categories as $category) {
	$categoryCounts[$category['key']] = (int)$category['count'];
}
?>
<?php $this->start('script-embedded'); ?>
<script>
	var PRACTICE_CATEGORY_COUNTS	= <?= json_encode($categoryCounts, JSON_UNESCAPED_UNICODE) ?>;
	var PRACTICE_ALL_COUNT			= <?= (int)array_sum(array_column($categories, 'count')) ?>;

	$(function()
	{
		updateQuestionCountMax();

		$('input[name="categories[]"]').on('change', updateQuestionCountMax);
	});

	// 出題範囲の変更に合わせて出題数の上限を更新する
	function updateQuestionCountMax()
	{
		var $categories = $('input[name="categories[]"]');
		var $checked = $categories.filter(':checked');
		var total = 0;

		if($categories.length === 0 || $checked.length === 0)
		{
			// カテゴリ UI が無い場合、またはすべて外した場合は全カテゴリが対象
			total = PRACTICE_ALL_COUNT;
		}
		else
		{
			$checked.each(function()
			{
				total += PRACTICE_CATEGORY_COUNTS[$(this).val()] || 0;
			});
		}

		$('#question_count').attr('max', total);
		$('#question-count-hint').text('<?= __('上限') ?>: ' + total + '<?= __('問') ?>' + '（0 = ' + '<?= __('全問') ?>' + '）');

		var current = parseInt($('#question_count').val(), 10);

		if(!isNaN(current) && current > total)
		{
			$('#question_count').val(total);
		}
	}
</script>
<?php $this->end(); ?>
<div class="contents-questions-practice">
	<div class="breadcrumb">
	<?php
		$this->Html->addCrumb(
			'<span class="glyphicon glyphicon-book" aria-hidden="true"></span> コース一覧',
			['controller' => 'UsersCourses', 'action' => 'index'],
			['escape' => false]
		);
		$this->Html->addCrumb($content->course->title, ['controller' => 'Contents', 'action' => 'index', $content->course_id]);
		$this->Html->addCrumb(h($content->title), ['controller' => 'ContentsQuestions', 'action' => 'index', $content->id]);
		$this->Html->addCrumb(__('練習'));

		echo $this->Html->getCrumbs(' / ');
	?>
	</div>

	<div class="panel panel-info">
		<div class="panel-heading"><?= __('練習（記録されません）'); ?></div>
		<div class="panel-body">
			<div class="alert alert-warning">
				<?= __('この練習の結果は成績・学習回数・理解度・コース完了に反映されません。'); ?>
			</div>

			<?= $this->Form->create(null, ['url' => ['action' => 'practice', $content->id]]); ?>
			<?php $this->Form->unlockField('categories'); ?>

			<?php if(count($categories) > 1){ ?>
			<div class="form-group">
				<label><?= __('出題範囲'); ?></label>
				<?php foreach($categories as $category){ ?>
				<div class="checkbox">
					<label>
						<input type="checkbox" name="categories[]" value="<?= h($category['key']); ?>"
							<?= in_array($category['key'], $selected_category_keys, true) ? 'checked' : ''; ?>>
						<?= h($category['label']); ?>
						<span class="text-muted">(<?= (int)$category['count']; ?><?= __('問'); ?>)</span>
					</label>
				</div>
				<?php }?>
				<p class="help-block"><?= __('チェックしたカテゴリの問題から出題します。すべて外した場合は全カテゴリが対象になります。'); ?></p>
			</div>
			<?php }?>

			<div class="form-group">
				<label for="question_count"><?= __('出題数'); ?></label>
				<?= $this->Form->number('question_count', [
					'id' => 'question_count',
					'class' => 'form-control',
					'min' => 0,
					'max' => (int)$settings['available_count'],
					'value' => (int)$settings['question_count'],
				]); ?>
				<p class="help-block" id="question-count-hint">
					<?= __('上限'); ?>: <?= (int)$settings['available_count']; ?><?= __('問'); ?>（0 = <?= __('全問'); ?>）
				</p>
			</div>

			<div class="form-group">
				<label for="timelimit"><?= __('制限時間'); ?></label>
				<?= $this->Form->select('timelimit', $timelimit_choices, [
					'id' => 'timelimit',
					'class' => 'form-control',
					'value' => (int)$settings['timelimit'],
				]); ?>
			</div>

			<div class="form-group">
				<label><?= __('出題順'); ?></label>
				<?= $this->Form->radio('order', [
					'random' => __('ランダム'),
					'sort' => __('登録順'),
				], [
					'value' => $settings['order'],
				]); ?>
				<p class="help-block"><?= __('出題する問題は常にランダムに選びます。登録順は表示順のみに反映されます。'); ?></p>
			</div>

			<div class="form-group">
				<label><?= __('不正解時の表示'); ?></label>
				<?= $this->Form->radio('wrong_mode', $wrong_mode_options, [
					'value' => (string)$settings['wrong_mode'],
				]); ?>
			</div>

			<div class="form-actions">
				<?= $this->Form->button(__('練習を開始'), ['type' => 'submit', 'class' => 'btn btn-primary btn-lg']); ?>
				<?= $this->Html->link(__('コースに戻る'), ['controller' => 'Contents', 'action' => 'index', $content->course_id], ['class' => 'btn btn-default btn-lg']); ?>
			</div>

			<?= $this->Form->end(); ?>
		</div>
	</div>
</div>
