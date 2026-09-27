<!DOCTYPE html>
<html lang="ja">
<head>
	<?= $this->Html->charset(); ?>
	<title>
		<?= $this->fetch('title'); ?>
	</title>
	<?php
		echo $this->Html->meta('icon');

		echo $this->Html->css('common');

		echo $this->fetch('meta');
		echo $this->fetch('css');
		echo $this->fetch('script');
	?>
</head>
<body>
	<div id="container">
		<div id="content">
			<?= $this->Flash->render(); ?>
			<?= $this->fetch('content'); ?>
		</div>
	</div>
	<?php /* sql_dump 要素は CakePHP 5 には存在しない。呼ぶと MissingElementException →
	         エラー描画の無限再帰（debug=false でハング）になるため呼び出さない。 */ ?>
</body>
</html>
