<div class="text-center">
<?php
echo $this->Paginator->counter(__('合計') . ' : {{count}}' . __('件') . '　{{page}} / {{pages}}' . __('ページ'));
?>
</div>
<div class="text-center">
	<?= $this->Paginator->numbers(['ul' => 'pagination']); ?>
</div>

