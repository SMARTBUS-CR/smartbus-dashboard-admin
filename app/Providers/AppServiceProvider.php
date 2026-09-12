<?php

namespace App\Providers;

use App\Auth\ExternalUserProvider;
use App\Auth\SessionGuard;
use App\Models\User;
use App\Services\ExternalAuthService;
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
        // Register custom UserProvider for external authentication
        Auth::provider('external_provider', fn ($app, array $config) => new ExternalUserProvider(
            $app->make(ExternalAuthService::class),
            $config['model'] ?? User::class
        ));

        // Register custom SessionGuard for external authentication
        Auth::extend('external_session', function ($app, $name, array $config) {
            $provider = Auth::createUserProvider($config['provider'] ?? null);

            return new SessionGuard(
                $name,
                $provider,
                $app['session.store'],
                $app['request']
            );
        });

        // Add middleware to set the Accept-Language header for all HTTP requests
        $this->app->resolving(HttpFactory::class, function (HttpFactory $factory) {
            $factory->globalMiddleware(
                Middleware::mapRequest(fn (RequestInterface $request) => $request->withHeader(
                    'Accept-Language',
                    app()->getLocale()
                ))
            );
        });
    }
}
