<?php
/**
 * @var \App\View\AppView $this
 * @var string $message
 * @var string $url
 */
use Cake\Core\Configure;
use Cake\Error\Debugger;

$this->setLayout('error');
$this->assign('title', 'システムエラー');

if (Configure::read('debug')) :
    $this->assign('templateName', 'error500.php');

    $this->start('file');
?>
<?php if ($error instanceof Error) : ?>
    <?php $file = $error->getFile() ?>
    <?php $line = $error->getLine() ?>
    <strong>Error in: </strong>
    <?= $this->Html->link(sprintf('%s, line %s', Debugger::trimPath($file), $line), Debugger::editorUrl($file, $line)); ?>
<?php endif; ?>
<?php
    echo $this->element('auto_table_warning');

    $this->end();
endif;
?>
<div class="ib-error">
	<div class="ib-error-icon">500</div>
	<h1>エラーが発生しました</h1>
	<p>
		申し訳ございません。システムエラーが発生しました。<br>
		しばらくしてからもう一度お試しください。
	</p>
	<div class="ib-error-nav">
		<a href="/" class="ib-error-btn-primary">トップページへ</a>
		<a href="javascript:history.back()" class="ib-error-btn-secondary">前のページへ戻る</a>
	</div>

<?php if (Configure::read('debug')) : ?>
	<div class="ib-error-debug">
		<strong>Debug information</strong>
		<pre><?= h($message) ?></pre>
		<?= $this->fetch('file') ?>
	</div>
<?php endif; ?>
</div>
