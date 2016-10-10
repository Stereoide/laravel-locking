<?php

namespace Stereoide\Locking;

use Illuminate\Support\ServiceProvider;

class LockingServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        /* Config file */
        
        $this->publishes([
            __DIR__.'/../config/locking.php' => config_path('locking.php')
        ], 'config');
        
        /* Database migrations */
        
        $this->publishes([
            __DIR__.'/../migrations' => database_path('migrations')
        ], 'migrations');        
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('locking', function ($app) {
            return new Locking();
        });
    }
}