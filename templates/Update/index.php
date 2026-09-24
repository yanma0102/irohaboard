<div class="update-index">
	<div class="panel panel-info" style="margin:20px;">
		<div class="panel-heading">
			<?= APP_NAME; ?> Updater
		</div>
		<div class="panel-body">
			<p style="margin:20px">データベースのアップデートが完了しました。</p>
		</div>
		<div class="panel-footer text-center">
			<button class="btn btn-primary" onclick="location.href='<?= $this->Url->build(['controller' => 'users', 'action' => 'login', 'prefix' => 'Admin']) ?>'">管理者ログイン画面へ</button>
		</div>
	</div>
</div>
