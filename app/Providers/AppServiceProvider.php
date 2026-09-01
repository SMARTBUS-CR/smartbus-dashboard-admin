<?php

namespace App\Providers;

use App\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the custom authentication guard
        Auth::extend('external_session', function ($app, $name, $config) {
            return new SessionGuard(
                $app['request'],
                $app['session.store']
            );
        });
    }
}
