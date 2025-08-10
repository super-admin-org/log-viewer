<?php

namespace SuperAdmin\Admin\LogViewer;

use SuperAdmin\Admin\Admin;

trait BootExtension
{
    /**
     * {@inheritdoc}
     */
    public static function boot()
    {
        static::registerRoutes();

        Admin::extend('log-viewer', __CLASS__);
    }

    /**
     * Register routes for super-admin.
     *
     * @return void
     */
    protected static function registerRoutes()
    {
        parent::routes(function ($router) {
            /* @var \Illuminate\Routing\Router $router */
            $router->get('logs', 'SuperAdmin\Admin\LogViewer\LogController@index')->name('log-viewer-index');
            $router->get('logs/{file}', 'SuperAdmin\Admin\LogViewer\LogController@index')->name('log-viewer-file');
            $router->get('logs/{file}/tail', 'SuperAdmin\Admin\LogViewer\LogController@tail')->name('log-viewer-tail');
            $router->get('logs/{file}/download', 'SuperAdmin\Admin\LogViewer\LogController@download')
                ->name('log-viewer-download');

            $router->delete('logs/{file}', 'SuperAdmin\Admin\LogViewer\LogController@destroy')
                ->name('log-viewer-destroy');


        });
    }

    /**
     * {@inheritdoc}
     */
    public static function import()
    {
        parent::createMenu('Log viewer', 'logs', 'icon-exclamation-triangle');

        parent::createPermission('Logs', 'ext.log-viewer', 'logs*');
    }
}
