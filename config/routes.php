<?php
/**
 * Routes configuration.
 *
 * In this file, you set up routes to your controllers and their actions.
 * Routes are very important mechanism that allows you to freely connect
 * different URLs to chosen controllers and their actions (functions).
 *
 * It's loaded within the context of `Application::routes()` method which
 * receives a `RouteBuilder` instance `$routes` as method argument.
 */

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

/*
 * This file is loaded in the context of the `Application` class.
 * So you can use `$this` to reference the application class instance
 * if required.
 */
return function (RouteBuilder $routes): void {
    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {
        // / → UsersCourses::index
        $builder->connect('/', [
            'controller' => 'UsersCourses',
            'action' => 'index',
        ]);

        // /admin → Admin/Users::index
        $builder->prefix('Admin', function (RouteBuilder $builder): void {
            $builder->connect('/', [
                'controller' => 'Users',
                'action' => 'index',
            ]);

            // /admin/users/login → Admin/Users::login
            $builder->connect('/users/login', [
                'controller' => 'Users',
                'action' => 'login',
            ]);

            // Catch-all for admin controllers (e.g. /admin/contents/index, /admin/groups/index)
            $builder->fallbacks();
        });

        // /pages/* は Pages コントローラ
        $builder->connect('/pages/*', 'Pages::display');

        // REST API v1
        $builder->scope('/api/v1', ['namespace' => 'App\Controller\Api'], function (RouteBuilder $builder): void {

            // 認証（トークン発行・失効）
            $builder->connect('/auth/token', [
                'controller' => 'Auth',
                'action' => 'issueToken',
                '_method' => 'POST',
            ]);
            $builder->connect('/auth/token', [
                'controller' => 'Auth',
                'action' => 'revokeToken',
                '_method' => 'DELETE',
            ]);

            // ユーザ
            $builder->connect('/users', [
                'controller' => 'Users',
                'action' => 'index',
                '_method' => 'GET',
            ]);
            $builder->connect('/users', [
                'controller' => 'Users',
                'action' => 'add',
                '_method' => 'POST',
            ]);
            $builder->connect('/users/{id}', [
                'controller' => 'Users',
                'action' => 'view',
                '_method' => 'GET',
            ], ['id' => '[0-9]+']);
            $builder->connect('/users/{id}', [
                'controller' => 'Users',
                'action' => 'edit',
                '_method' => 'PUT',
            ], ['id' => '[0-9]+']);
            $builder->connect('/users/{id}', [
                'controller' => 'Users',
                'action' => 'edit',
                '_method' => 'PATCH',
            ], ['id' => '[0-9]+']);
            $builder->connect('/users/{id}', [
                'controller' => 'Users',
                'action' => 'delete',
                '_method' => 'DELETE',
            ], ['id' => '[0-9]+']);

            // ユーザのパスワード変更
            $builder->connect('/users/{id}/password', [
                'controller' => 'Users',
                'action' => 'changePassword',
                '_method' => 'PUT',
            ], ['id' => '[0-9]+']);
            $builder->connect('/users/{id}/password', [
                'controller' => 'Users',
                'action' => 'changePassword',
                '_method' => 'PATCH',
            ], ['id' => '[0-9]+']);

            // ユーザのコース割当
            $builder->connect('/users/{id}/courses', [
                'controller' => 'Users',
                'action' => 'courses',
                '_method' => 'GET',
            ], ['id' => '[0-9]+']);
            $builder->connect('/users/{id}/courses', [
                'controller' => 'Users',
                'action' => 'assignCourse',
                '_method' => 'POST',
            ], ['id' => '[0-9]+']);
            $builder->connect('/users/{id}/courses/{course_id}', [
                'controller' => 'Users',
                'action' => 'unassignCourse',
                '_method' => 'DELETE',
            ], ['id' => '[0-9]+', 'course_id' => '[0-9]+']);

            // コース
            $builder->connect('/courses', [
                'controller' => 'Courses',
                'action' => 'index',
                '_method' => 'GET',
            ]);
            $builder->connect('/courses', [
                'controller' => 'Courses',
                'action' => 'add',
                '_method' => 'POST',
            ]);
            $builder->connect('/courses/{id}', [
                'controller' => 'Courses',
                'action' => 'view',
                '_method' => 'GET',
            ], ['id' => '[0-9]+']);
            $builder->connect('/courses/{id}', [
                'controller' => 'Courses',
                'action' => 'delete',
                '_method' => 'DELETE',
            ], ['id' => '[0-9]+']);

            // コンテンツ
            $builder->connect('/contents', [
                'controller' => 'Contents',
                'action' => 'index',
                '_method' => 'GET',
            ]);
            $builder->connect('/contents/{id}', [
                'controller' => 'Contents',
                'action' => 'view',
                '_method' => 'GET',
            ], ['id' => '[0-9]+']);

            // 学習履歴
            $builder->connect('/records', [
                'controller' => 'Records',
                'action' => 'index',
                '_method' => 'GET',
            ]);
            $builder->connect('/records/{id}', [
                'controller' => 'Records',
                'action' => 'view',
                '_method' => 'GET',
            ], ['id' => '[0-9]+']);

            // グループ
            $builder->connect('/groups', [
                'controller' => 'Groups',
                'action' => 'index',
                '_method' => 'GET',
            ]);
            $builder->connect('/groups/{id}', [
                'controller' => 'Groups',
                'action' => 'view',
                '_method' => 'GET',
            ], ['id' => '[0-9]+']);

            // グループのユーザ割当
            $builder->connect('/groups/{id}/users', [
                'controller' => 'Groups',
                'action' => 'users',
                '_method' => 'GET',
            ], ['id' => '[0-9]+']);
            $builder->connect('/groups/{id}/users', [
                'controller' => 'Groups',
                'action' => 'assignUser',
                '_method' => 'POST',
            ], ['id' => '[0-9]+']);
            $builder->connect('/groups/{id}/users/{user_id}', [
                'controller' => 'Groups',
                'action' => 'unassignUser',
                '_method' => 'DELETE',
            ], ['id' => '[0-9]+', 'user_id' => '[0-9]+']);
        });

        // 未定義の /api/* は Api/Errors::notFound（JSON 404）
        $builder->scope('/api', ['namespace' => 'App\Controller\Api'], function (RouteBuilder $builder): void {
            $builder->connect('*', [
                'controller' => 'Errors',
                'action' => 'notFound',
            ]);
            $builder->connect('/', [
                'controller' => 'Errors',
                'action' => 'notFound',
            ]);
        });

        /*
         * Connect catchall routes for all controllers.
         * Phase 1 時点では削除せず維持（Controller 移行完了後、Phase 6 で fallbacks は削除する）
         */
        $builder->fallbacks();
    });
};