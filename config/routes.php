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

        // ---- Front-end (non-admin) explicit routes ----

        // UsersCourses
        $builder->connect('/users-courses', [
            'controller' => 'UsersCourses',
            'action' => 'index',
        ]);

        // Users (front)
        $builder->connect('/users/login', [
            'controller' => 'Users',
            'action' => 'login',
        ]);
        $builder->connect('/users/logout', [
            'controller' => 'Users',
            'action' => 'logout',
        ]);
        $builder->connect('/users/setting', [
            'controller' => 'Users',
            'action' => 'setting',
        ]);
        $builder->connect('/users/index', [
            'controller' => 'Users',
            'action' => 'index',
        ]);

        // Contents (front)
        $builder->connect('/contents/index/{course_id}/{user_id}', [
            'controller' => 'Contents',
            'action' => 'index',
        ], ['pass' => ['course_id', 'user_id']]);
        $builder->connect('/contents/index/{course_id}', [
            'controller' => 'Contents',
            'action' => 'index',
        ], ['pass' => ['course_id']]);
        $builder->connect('/contents/view/{content_id}', [
            'controller' => 'Contents',
            'action' => 'view',
        ], ['pass' => ['content_id']]);
        $builder->connect('/contents/preview', [
            'controller' => 'Contents',
            'action' => 'preview',
        ]);
        $builder->connect('/contents/preview/{course_id}', [
            'controller' => 'Contents',
            'action' => 'preview',
        ]);
        $builder->connect('/contents/file-download/{content_id}', [
            'controller' => 'Contents',
            'action' => 'file_download',
        ], ['pass' => ['content_id']]);
        $builder->connect('/contents/file-movie/{content_id}', [
            'controller' => 'Contents',
            'action' => 'file_movie',
        ], ['pass' => ['content_id']]);
        $builder->connect('/contents/file-image/{file_name}', [
            'controller' => 'Contents',
            'action' => 'file_image',
        ], ['pass' => ['file_name']]);
        // Note: /contents/add is needed because the Contents view/preview template
        // generates a URL for Contents::add via Form->create(). The form is intercepted
        // by JavaScript and posts to Records::add instead.
        $builder->connect('/contents/add', [
            'controller' => 'Contents',
            'action' => 'add',
        ]);

        // ContentsQuestions (front)
        $builder->connect('/contents-questions/index/{content_id}/{record_id}', [
            'controller' => 'ContentsQuestions',
            'action' => 'index',
        ], ['pass' => ['content_id', 'record_id']]);
        $builder->connect('/contents-questions/index/{content_id}', [
            'controller' => 'ContentsQuestions',
            'action' => 'index',
        ], ['pass' => ['content_id']]);
        $builder->connect('/contents-questions/record/{content_id}/{record_id}', [
            'controller' => 'ContentsQuestions',
            'action' => 'record',
        ], ['pass' => ['content_id', 'record_id']]);

        // EnquetesQuestions (front)
        $builder->connect('/enquetes-questions/index/{content_id}/{record_id}', [
            'controller' => 'EnquetesQuestions',
            'action' => 'index',
        ], ['pass' => ['content_id', 'record_id']]);
        $builder->connect('/enquetes-questions/index/{content_id}', [
            'controller' => 'EnquetesQuestions',
            'action' => 'index',
        ], ['pass' => ['content_id']]);
        $builder->connect('/enquetes-questions/record/{content_id}/{record_id}', [
            'controller' => 'EnquetesQuestions',
            'action' => 'record',
        ], ['pass' => ['content_id', 'record_id']]);

        // Infos (front)
        $builder->connect('/infos', [
            'controller' => 'Infos',
            'action' => 'index',
        ]);
        $builder->connect('/infos/index', [
            'controller' => 'Infos',
            'action' => 'index',
        ]);
        $builder->connect('/infos/view/{info_id}', [
            'controller' => 'Infos',
            'action' => 'view',
        ], ['pass' => ['info_id']]);

        // Records (front)
        $builder->connect('/records/add/{content_id}', [
            'controller' => 'Records',
            'action' => 'add',
        ], ['pass' => ['content_id']]);

        // Install (utility)
        $builder->connect('/install', [
            'controller' => 'Install',
            'action' => 'index',
        ]);
        $builder->connect('/install/*', [
            'controller' => 'Install',
            'action' => 'installed',
        ]);

        // Update (utility)
        $builder->connect('/update', [
            'controller' => 'Update',
            'action' => 'index',
        ]);
        $builder->connect('/update/*', [
            'controller' => 'Update',
            'action' => 'error',
        ]);

        // REST API v1
        $builder->scope('/api/v1', ['prefix' => 'Api'], function (RouteBuilder $builder): void {

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
            ], ['pass' => ['id']]);
            $builder->connect('/users/{id}', [
                'controller' => 'Users',
                'action' => 'edit',
                '_method' => 'PUT',
            ], ['pass' => ['id']]);
            $builder->connect('/users/{id}', [
                'controller' => 'Users',
                'action' => 'edit',
                '_method' => 'PATCH',
            ], ['pass' => ['id']]);
            $builder->connect('/users/{id}', [
                'controller' => 'Users',
                'action' => 'delete',
                '_method' => 'DELETE',
            ], ['pass' => ['id']]);

            // ユーザのパスワード変更
            $builder->connect('/users/{id}/password', [
                'controller' => 'Users',
                'action' => 'changePassword',
                '_method' => 'PUT',
            ], ['pass' => ['id']]);
            $builder->connect('/users/{id}/password', [
                'controller' => 'Users',
                'action' => 'changePassword',
                '_method' => 'PATCH',
            ], ['pass' => ['id']]);

            // ユーザのコース割当
            $builder->connect('/users/{id}/courses', [
                'controller' => 'Users',
                'action' => 'courses',
                '_method' => 'GET',
            ], ['pass' => ['id']]);
            $builder->connect('/users/{id}/courses', [
                'controller' => 'Users',
                'action' => 'assignCourse',
                '_method' => 'POST',
            ], ['pass' => ['id']]);
            $builder->connect('/users/{id}/courses/{course_id}', [
                'controller' => 'Users',
                'action' => 'unassignCourse',
                '_method' => 'DELETE',
            ], ['pass' => ['id', 'course_id']]);

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
            ], ['pass' => ['id']]);
            $builder->connect('/courses/{id}', [
                'controller' => 'Courses',
                'action' => 'edit',
                '_method' => 'PUT',
            ], ['pass' => ['id']]);
            $builder->connect('/courses/{id}', [
                'controller' => 'Courses',
                'action' => 'edit',
                '_method' => 'PATCH',
            ], ['pass' => ['id']]);
            $builder->connect('/courses/{id}', [
                'controller' => 'Courses',
                'action' => 'delete',
                '_method' => 'DELETE',
            ], ['pass' => ['id']]);

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
            ], ['pass' => ['id']]);

            // Contents Write (POST/PUT/PATCH/DELETE)
            $builder->connect('/contents', [
                'controller' => 'Contents',
                'action' => 'add',
                '_method' => 'POST',
            ]);
            $builder->connect('/contents/{id}', [
                'controller' => 'Contents',
                'action' => 'edit',
                '_method' => 'PUT',
            ], ['id' => '\d+', 'pass' => ['id']]);
            $builder->connect('/contents/{id}', [
                'controller' => 'Contents',
                'action' => 'edit',
                '_method' => 'PATCH',
            ], ['id' => '\d+', 'pass' => ['id']]);
            $builder->connect('/contents/{id}', [
                'controller' => 'Contents',
                'action' => 'delete',
                '_method' => 'DELETE',
            ], ['id' => '\d+', 'pass' => ['id']]);

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
            ], ['pass' => ['id']]);

            // グループ
            $builder->connect('/groups', [
                'controller' => 'Groups',
                'action' => 'index',
                '_method' => 'GET',
            ]);
            $builder->connect('/groups', [
                'controller' => 'Groups',
                'action' => 'add',
                '_method' => 'POST',
            ]);
            $builder->connect('/groups/{id}', [
                'controller' => 'Groups',
                'action' => 'view',
                '_method' => 'GET',
            ], ['pass' => ['id']]);
            $builder->connect('/groups/{id}', [
                'controller' => 'Groups',
                'action' => 'edit',
                '_method' => 'PUT',
            ], ['pass' => ['id']]);
            $builder->connect('/groups/{id}', [
                'controller' => 'Groups',
                'action' => 'edit',
                '_method' => 'PATCH',
            ], ['pass' => ['id']]);
            $builder->connect('/groups/{id}', [
                'controller' => 'Groups',
                'action' => 'delete',
                '_method' => 'DELETE',
            ], ['pass' => ['id']]);

            // グループのユーザ割当
            $builder->connect('/groups/{id}/users', [
                'controller' => 'Groups',
                'action' => 'users',
                '_method' => 'GET',
            ], ['pass' => ['id']]);
            $builder->connect('/groups/{id}/users', [
                'controller' => 'Groups',
                'action' => 'assignUser',
                '_method' => 'POST',
            ], ['pass' => ['id']]);
            $builder->connect('/groups/{id}/users/{user_id}', [
                'controller' => 'Groups',
                'action' => 'unassignUser',
                '_method' => 'DELETE',
            ], ['pass' => ['id', 'user_id']]);
        });

        // 未定義の /api/* は Api/Errors::notFound（JSON 404）
        $builder->connect('/api/*', [
            'prefix' => 'Api',
            'controller' => 'Errors',
            'action' => 'notFound',
        ]);
        $builder->connect('/api', [
            'prefix' => 'Api',
            'controller' => 'Errors',
            'action' => 'notFound',
        ]);

        // NOTE: Global $builder->fallbacks() was removed in Phase 6.
        // All front-end and API routes are now explicit.
    });
};
