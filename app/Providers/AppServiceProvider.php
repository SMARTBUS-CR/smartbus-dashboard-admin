<?php

namespace App\Providers;

use App\Auth\ExternalUserProvider;
use App\Auth\SessionGuard;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Route;
use App\Models\User;
use App\Services\AuthService;
use BezhanSalleh\LanguageSwitch\Enums\TriggerStyle;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Table;
use GuzzleHttp\Middleware;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // Invalidate request-local results after database writes.
        DB::listen(function (QueryExecuted $query): void {
            if (preg_match('/^\s*select\b/i', $query->sql) === 1) {
                return;
            }

            $this->clearRequestResults($query->connectionName);
        });

        // Discard results that may have been read before a rollback.
        Event::listen(
            TransactionRolledBack::class,
            fn (TransactionRolledBack $event) => $this->clearRequestResults($event->connectionName),
        );

        // Enforce protected deletion rules before granting global administrator access.
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $record = $arguments[0] ?? null;

            $isProtectedOperationalModel = $record instanceof Company
                || $record === Company::class
                || $record instanceof Route
                || $record === Route::class;

            if (
                $isProtectedOperationalModel
                && in_array($ability, ['forceDelete', 'forceDeleteAny'], true)
            ) {
                return false;
            }

            if ($record instanceof User || $record === User::class) {
                return null;
            }

            if ($record instanceof Role && in_array($ability, ['delete', 'forceDelete'], true)) {
                if (in_array($record->name, UserRole::protectedRoles(), true) || $record->hasAssignedUsers()) {
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

        FilamentTimezone::set(fn (): string => Filament::getTenant()?->timezone ?? config('app.timezone'));

        // Configure default date and time formats for Filament tables
        Table::configureUsing(fn (Table $table) => $table
            ->defaultDateDisplayFormat('d M, Y')
            ->defaultTimeDisplayFormat('h:i A')
            ->defaultDateTimeDisplayFormat('d M, Y - h:i A')
        );

        // Configure LanguageSwitch package for language switching functionality
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch
                ->locales(['es', 'en'])
                ->flags([
                    'es' => 'https://flagcdn.com/es.svg',
                    'en' => 'https://flagcdn.com/us.svg',
                ])
                ->trigger(style: TriggerStyle::Flag)
                ->userPreferredLocale(fn () => auth()->user()?->locale)
                ->nativeLabel();
        });
    }

    private function clearRequestResults(string $connectionName): void
    {
        if (! in_array($connectionName, ['mysql', 'pgsql'], true)) {
            return;
        }

        $attributes = app('request')->attributes;

        $keys = [
            'dashboard.administrator_tenants',
            'dashboard.company_member_ids',
            'dashboard.manageable_company_members',
            'dashboard.available_routes',
            'dashboard.routing_stop_occurrences',
            'dashboard.company_access_checks',
        ];

        if ($connectionName === 'mysql') {
            $keys = [
                ...$keys,
                'dashboard.super_admin_checks',
                'dashboard.role_assignment_checks',
            ];
        }

        foreach ($keys as $key) {
            $attributes->remove($key);
        }
    }
}
