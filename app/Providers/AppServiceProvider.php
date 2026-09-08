<?php

namespace App\Providers;

use App\Auth\SessionGuard;
use GuzzleHttp\Middleware;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;

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
        Auth::extend('external_session', fn ($app, $name, $config) => new SessionGuard(
            $app['request'],
            $app['session.store']
        ));

        $this->app->resolving(HttpFactory::class, function (HttpFactory $factory) {
            $factory->globalMiddleware(
                Middleware::mapRequest(function (RequestInterface $request) {
                    return $request->withHeader('Accept-Language', app()->getLocale());
                })
            );
        });
    }
}
