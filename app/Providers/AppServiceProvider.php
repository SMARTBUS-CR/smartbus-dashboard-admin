<?php

namespace App\Providers;

use App\Auth\ExternalUserProvider;
use App\Auth\SessionGuard;
use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use Filament\Tables\Table;
use GuzzleHttp\Middleware;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;
use Spatie\Permission\PermissionRegistrar;

use function in_array;

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
        // Prevent deletion of protected roles or roles that have users assigned to them
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $record = $arguments[0] ?? null;

            if ($record instanceof User || $record === User::class) {
                return null;
            }

            if ($record instanceof Role && in_array($ability, ['delete', 'forceDelete'], true)) {
                if (in_array($record->name, UserRole::protectedRoles(), true) || $record->users()->withoutGlobalScopes()->exists()) {
                    return false;
                }
            }

            return $user->isSuperAdmin() ? true : null;
        });

        // Configure Spatie Permission package to use custom models
        app(PermissionRegistrar::class)
            ->setPermissionClass(Permission::class)
            ->setRoleClass(Role::class);

        // Register custom UserProvider for external authentication
        Auth::provider('external_provider', fn ($app, array $config) => new ExternalUserProvider(
            $app->make(AuthService::class),
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

        // Configure default date and time formats for Filament tables
        Table::configureUsing(fn (Table $table) => $table
            ->defaultDateDisplayFormat('d M, Y')
            ->defaultTimeDisplayFormat('h:i A')
            ->defaultDateTimeDisplayFormat('d M, Y - h:i A')
        );

    }
}
