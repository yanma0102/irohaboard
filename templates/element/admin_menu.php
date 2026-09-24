<nav class="navbar navbar-default">
	<div class="container">
		<div class="navbar-collapse collapse">
		<ul class="nav navbar-nav">
			<?php
			$currentController = $this->request->getParam('controller');
			$currentAction = $this->request->getParam('action');

			$is_active = (($currentController == 'Users') && ($currentAction != 'login') && ($currentAction != 'logout')) ? ' active' : '';
			echo '<li class="'.$is_active.'">'.$this->Html->link(__('ユーザ'), ['controller' => 'users', 'action' => 'index']).'</li>';

			$is_active = ($currentController == 'Groups') ? ' active' : '';
			echo '<li class="'.$is_active.'">'.$this->Html->link(__('グループ'), ['controller' => 'groups', 'action' => 'index']).'</li>';

			$is_active = (($currentController == 'Courses') || ($currentController == 'Contents') || ($currentController == 'ContentsQuestions') || ($currentController == 'EnquetesQuestions')) ? ' active' : '';
			echo '<li class="'.$is_active.'">'.$this->Html->link(__('コース'), ['controller' => 'courses', 'action' => 'index']).'</li>';

			$is_active = ($currentController == 'Infos') ? ' active' : '';
			echo '<li class="'.$is_active.'">'.$this->Html->link(__('お知らせ'), ['controller' => 'infos', 'action' => 'index']).'</li>';

			$is_active = ($currentController == 'Records') ? ' active' : '';
			echo '<li class="'.$is_active.'">'.$this->Html->link(__('学習履歴'), ['controller' => 'records', 'action' => 'index']).'</li>';

			if($loginedUser['role'] == 'admin')
			{
				$is_active = ($currentController == 'Settings') ? ' active' : '';
				echo '<li class="'.$is_active.'">'.$this->Html->link(__('システム設定'), ['controller' => 'settings', 'action' => 'index']).'</li>';
			}
			?>
		</ul>
		</div><!--/.nav-collapse -->
	</div>
</nav>
