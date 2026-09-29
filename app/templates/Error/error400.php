<?php
/**
 * @var \App\View\AppView $this
 * @var string $message
 * @var string $url
 */
use Cake\Core\Configure;

$this->setLayout('error');
$this->assign('title', 'ページが見つかりません');

if (Configure::read('debug')) :
    $this->assign('templateName', 'error400.php');

    $this->start('file');
    echo $this->element('auto_table_warning');
    $this->end();
endif;
?>
<div class="ib-error">
	<div class="ib-error-icon">404</div>
	<h1>ページが見つかりません</h1>
	<p>
		お探しのページは削除されたか、URLが変更された可能性があります。<br>
		恐れ入りますが、トップページから改めてお探しください。
	</p>
	<div class="ib-error-nav">
		<a href="/" class="ib-error-btn-primary">トップページへ</a>
		<a href="javascript:history.back()" class="ib-error-btn-secondary">前のページへ戻る</a>
	</div>

<?php if (Configure::read('debug')) : ?>
	<div class="ib-error-debug">
		<strong>Debug information</strong>
		<pre><?= h($message) . "\n" . h($url) ?></pre>
		<?= $this->fetch('file') ?>
	</div>
<?php endif; ?>
</div>
