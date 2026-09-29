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
		echo $this->Html->css('error');

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
</body>
</html>
