<?php

namespace SuperAdmin\Admin\LogViewer;

use Illuminate\Support\ServiceProvider;

class LogViewerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'super-admin-logs');

        LogViewer::boot();
    }
}
