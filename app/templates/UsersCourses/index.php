<div class="users-courses-index">
	<div class="panel panel-success">
		<div class="panel-heading"><span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span> <?= __('お知らせ'); ?></div>
		<div class="panel-body">
			<?php use Cake\Core\Configure; ?>
<?php if($info != ''){?>
			<div class="well">
			<?php
				$target = Configure::read('open_link_same_window') ? [] : ['target' => '_blank'];
				$info = $this->Text->autoLinkUrls($info, $target);
				$info = nl2br($info);
				echo $info;
			?>
			</div>
			<?php }?>
			
			<?php if(count($infos) > 0){?>
		<table cellpadding="0" cellspacing="0">
		<caption class="sr-only">最新のお知らせ</caption>
		<tbody>
			<?php foreach ($infos as $info): ?>
			<tr>
				<td width="100" valign="top"><?= h(\App\Utility\Utils::getYMD($info->created)); ?></td>
				<td><?= $this->Html->link($info->title, ['controller' => 'infos', 'action' => 'view', $info->id]); ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
			</table>
			<div class="text-right"><?= $this->Html->link(__('一覧を表示'), ['controller' => 'infos', 'action' => 'index']); ?></div>
			<?php }?>
			<?= $no_info;?>
		</div>
	</div>
	<div class="panel panel-info">
	<div class="panel-heading"><span class="glyphicon glyphicon-book" aria-hidden="true"></span> <?= __('コース一覧')?></div>
	<div class="panel-body">
		<ul class="list-group">
		<?php foreach ($courses as $course): ?>
		<?php //debug($course)?>
			<a href="<?= $this->Url->build(['controller' => 'contents', 'action' => 'index', $course['id']]);?>" class="list-group-item">
				<?php if($course['left_cnt'] != 0){?>
				<button type="button" class="btn btn-danger btn-rest"><?= __('残り')?> <span class="badge"><?= h($course['left_cnt']); ?></span></button>
				<?php }?>
				<h3 class="list-group-item-heading"><?= h($course['title']);?></h3>
				<p class="list-group-item-text">
					<span class="first-date"><?= __('学習開始日').': '.\App\Utility\Utils::getYMD($course['first_date']); ?></span>
					<span class="last-date"><?= __('前回学習日').': '.\App\Utility\Utils::getYMD($course['last_date']); ?></span>
				</p>
			</a>
		<?php endforeach; ?>
		<?= $no_record;?>
		</ul>
	</div>
	</div>
</div>
