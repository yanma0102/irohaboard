<?php
/**
 * Routes configuration
 *
 * In this file, you set up routes to your controllers and their actions.
 * Routes are very important mechanism that allows you to freely connect
 * different URLs to chosen controllers and their actions (functions).
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @package       app.Config
 * @since         CakePHP(tm) v 0.2.9
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

/**
 * Here, we are connecting '/' (base path) to controller called 'Pages',
 * its action called 'display', and we pass a param to select the view file
 * to use (in this case, /app/View/Pages/home.ctp)...
 */
	Router::connect('/', ['controller' => 'users_courses', 'action' => 'index']);
	Router::connect('/admin', ['controller' => 'users', 'action' => 'index', 'admin' => true]);

/**
 * REST API v1
 *
 * 認証: Authorization: Bearer <selector:validator>
 * 応答: JSON
 */
	// 認証（トークン発行・失効）
	Router::connect('/api/v1/auth/token', ['controller' => 'api_auth', 'action' => 'issueToken', '[method]' => 'POST']);
	Router::connect('/api/v1/auth/token', ['controller' => 'api_auth', 'action' => 'revokeToken', '[method]' => 'DELETE']);

	// ユーザ
	Router::connect('/api/v1/users', ['controller' => 'api_users', 'action' => 'index', '[method]' => 'GET']);
	Router::connect('/api/v1/users', ['controller' => 'api_users', 'action' => 'add', '[method]' => 'POST']);
	Router::connect('/api/v1/users/:id', ['controller' => 'api_users', 'action' => 'view', '[method]' => 'GET'], ['pass' => ['id'], 'id' => '[0-9]+']);
	Router::connect('/api/v1/users/:id', ['controller' => 'api_users', 'action' => 'edit', '[method]' => 'PUT'], ['pass' => ['id'], 'id' => '[0-9]+']);
	Router::connect('/api/v1/users/:id', ['controller' => 'api_users', 'action' => 'edit', '[method]' => 'PATCH'], ['pass' => ['id'], 'id' => '[0-9]+']);
	Router::connect('/api/v1/users/:id', ['controller' => 'api_users', 'action' => 'delete', '[method]' => 'DELETE'], ['pass' => ['id'], 'id' => '[0-9]+']);

	// ユーザのパスワード変更
	Router::connect('/api/v1/users/:id/password', ['controller' => 'api_users', 'action' => 'changePassword', '[method]' => 'PUT'], ['pass' => ['id'], 'id' => '[0-9]+']);
	Router::connect('/api/v1/users/:id/password', ['controller' => 'api_users', 'action' => 'changePassword', '[method]' => 'PATCH'], ['pass' => ['id'], 'id' => '[0-9]+']);

	// ユーザのコース割当
	Router::connect('/api/v1/users/:id/courses', ['controller' => 'api_users', 'action' => 'courses', '[method]' => 'GET'], ['pass' => ['id'], 'id' => '[0-9]+']);
	Router::connect('/api/v1/users/:id/courses', ['controller' => 'api_users', 'action' => 'assignCourse', '[method]' => 'POST'], ['pass' => ['id'], 'id' => '[0-9]+']);
	Router::connect('/api/v1/users/:id/courses/:course_id', ['controller' => 'api_users', 'action' => 'unassignCourse', '[method]' => 'DELETE'], ['pass' => ['id', 'course_id'], 'id' => '[0-9]+', 'course_id' => '[0-9]+']);

	// コース
	Router::connect('/api/v1/courses', ['controller' => 'api_courses', 'action' => 'index', '[method]' => 'GET']);
	Router::connect('/api/v1/courses', ['controller' => 'api_courses', 'action' => 'add', '[method]' => 'POST']);
	Router::connect('/api/v1/courses/:id', ['controller' => 'api_courses', 'action' => 'view', '[method]' => 'GET'], ['pass' => ['id'], 'id' => '[0-9]+']);
	Router::connect('/api/v1/courses/:id', ['controller' => 'api_courses', 'action' => 'delete', '[method]' => 'DELETE'], ['pass' => ['id'], 'id' => '[0-9]+']);

	// コンテンツ
	Router::connect('/api/v1/contents', ['controller' => 'api_contents', 'action' => 'index', '[method]' => 'GET']);
	Router::connect('/api/v1/contents/:id', ['controller' => 'api_contents', 'action' => 'view', '[method]' => 'GET'], ['pass' => ['id'], 'id' => '[0-9]+']);

	// 学習履歴
	Router::connect('/api/v1/records', ['controller' => 'api_records', 'action' => 'index', '[method]' => 'GET']);
	Router::connect('/api/v1/records/:id', ['controller' => 'api_records', 'action' => 'view', '[method]' => 'GET'], ['pass' => ['id'], 'id' => '[0-9]+']);

	// グループ
	Router::connect('/api/v1/groups', ['controller' => 'api_groups', 'action' => 'index', '[method]' => 'GET']);
	Router::connect('/api/v1/groups/:id', ['controller' => 'api_groups', 'action' => 'view', '[method]' => 'GET'], ['pass' => ['id'], 'id' => '[0-9]+']);

	// グループのユーザ割当
	Router::connect('/api/v1/groups/:id/users', ['controller' => 'api_groups', 'action' => 'users', '[method]' => 'GET'], ['pass' => ['id'], 'id' => '[0-9]+']);
	Router::connect('/api/v1/groups/:id/users', ['controller' => 'api_groups', 'action' => 'assignUser', '[method]' => 'POST'], ['pass' => ['id'], 'id' => '[0-9]+']);
	Router::connect('/api/v1/groups/:id/users/:user_id', ['controller' => 'api_groups', 'action' => 'unassignUser', '[method]' => 'DELETE'], ['pass' => ['id', 'user_id'], 'id' => '[0-9]+', 'user_id' => '[0-9]+']);

	// 未定義の /api 配下（またはメソッド不一致）は JSON の 404 を返す
	Router::connect('/api/v1/*', ['controller' => 'api_errors', 'action' => 'notFound']);
	Router::connect('/api/v1', ['controller' => 'api_errors', 'action' => 'notFound']);
	Router::connect('/api/*', ['controller' => 'api_errors', 'action' => 'notFound']);
	Router::connect('/api', ['controller' => 'api_errors', 'action' => 'notFound']);


/**
 * ...and connect the rest of 'Pages' controller's URLs.
 */
//	Router::connect('/pages/*', array('controller' => 'pages', 'action' => 'display'));

/**
 * Load all plugin routes. See the CakePlugin documentation on
 * how to customize the loading of plugin routes.
 */
	CakePlugin::routes();

/**
 * Load the CakePHP default routes. Only remove this if you do not want to use
 * the built-in default routes.
 */
	require CAKE . 'Config' . DS . 'routes.php';
