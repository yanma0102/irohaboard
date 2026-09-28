<div class="text-center">
<?php
echo $this->Paginator->counter(__('合計') . ' : {{count}}' . __('件') . '　{{page}} / {{pages}}' . __('ページ'));
?>
</div>
<?php if ($this->Paginator->param('pageCount') > 1): ?>
<div class="text-center">
	<?php
	// CakePHP 5 の numbers() は ul ラッパーを出力しない（旧バージョンの 'ul' オプションは
	// 無視される）ため、Bootstrap の .pagination スタイルが効くよう明示的に ul で囲む。
	?>
	<ul class="pagination">
		<?= $this->Paginator->numbers(); ?>
	</ul>
</div>
<?php endif; ?>

