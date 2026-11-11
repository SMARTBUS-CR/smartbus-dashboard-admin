<?php

use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Http;

describe('Database Seeding', function (): void {
    test('builds repeatable catalogue workforce fleet and trip demo data in dependency order', function (): void {
        Http::fake();
        Http::preventStrayRequests();

        User::factory()->create([
            'email' => 'admin@company.com',
        ]);

        $this->seed(DatabaseSeeder::class);

        $company = Company::query()
            ->where('slug', 'grupo-caribenos')
            ->sole();

        expect($company->routes()->count())->toBe(3)
            ->and($company->stops()->count())->toBe(7);

        $roles = Role::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get();

        expect($roles->pluck('name')->all())->toBe([
            UserRole::Admin->value,
            UserRole::Driver->value,
        ]);

        foreach ($roles as $role) {
            expect($role->color)->toMatch('/^#[0-9a-fA-F]{6}$/');
        }

        $demoCompany = Company::query()->where('slug', 'smartbus-demo')->sole();
        $demoRoute = Route::query()->where('company_id', $demoCompany->id)->where('code', 'DEMO-CITY')->sole();
        $demoPattern = RoutePattern::query()->where('route_id', $demoRoute->id)->where('code', 'OUTBOUND')->sole();
        expect($demoCompany->buses()->count())->toBe(3)
            ->and(Driver::query()->where('company_id', $demoCompany->id)->count())->toBe(3)
            ->and(DriverSchedule::query()->whereIn('driver_id', Driver::query()->where('company_id', $demoCompany->id)->select('id'))->count())->toBe(15)
            ->and(RouteSchedule::query()->where('route_pattern_id', $demoPattern->id)
                ->where('day_of_week', 1)->where('departure_time', '09:00:00')->count())->toBe(1)
            ->and(Trip::query()->where('company_id', $demoCompany->id)->count())->toBe(4)
            ->and(Permission::query()->where('guard_name', 'web')->where('name', 'like', '%:Bus')
                ->orderBy('name')->pluck('name')->all())->toBe([
                    'Create:Bus', 'Delete:Bus', 'Restore:Bus', 'Update:Bus', 'View:Bus', 'ViewAny:Bus',
                ]);

        $edited = $demoCompany->buses()->where('unit_number', 'DEMO-001')->sole();
        $edited->update(['capacity' => 55, 'unit_number' => 'CUSTOM-001', 'plate_number' => 'CUSTOM-PLATE']);
        $archived = $demoCompany->buses()->where('unit_number', 'DEMO-002')->sole();
        $archived->delete();
        $before = Bus::withTrashed()->orderBy('id')->get()->map->getAttributes()->all();
        $permissionIds = Permission::query()->where('name', 'like', '%:Bus')->orderBy('name')->pluck('id')->all();
        $driverIds = Driver::query()->where('company_id', $demoCompany->id)->orderBy('id')->pluck('id')->all();
        $tripAttributes = Trip::query()->where('company_id', $demoCompany->id)->orderBy('id')->get()->map->getRawOriginal()->all();
        expect(Permission::query()->where('name', 'like', '%:Trip')->orderBy('name')->pluck('name')->all())
            ->toBe(['Cancel:Trip', 'Complete:Trip', 'Create:Trip', 'Start:Trip', 'Update:Trip', 'View:Trip', 'ViewAny:Trip']);
        $tripPermissionIds = Permission::query()->where('name', 'like', '%:Trip')->orderBy('name')->pluck('id')->all();

        $this->seed(DatabaseSeeder::class);

        expect(Bus::withTrashed()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
            ->and(Permission::query()->where('name', 'like', '%:Bus')->orderBy('name')->pluck('id')->all())->toBe($permissionIds)
            ->and($company->routes()->count())->toBe(3)
            ->and($company->stops()->count())->toBe(7)
            ->and(Driver::query()->where('company_id', $demoCompany->id)->orderBy('id')->pluck('id')->all())->toBe($driverIds)
            ->and(Trip::query()->where('company_id', $demoCompany->id)->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($tripAttributes);
        $this->assertSoftDeleted($archived);
        $this->assertDatabaseCount('buses', 3);
        expect(Permission::query()->where('name', 'like', '%:Trip')->orderBy('name')->pluck('id')->all())->toBe($tripPermissionIds);
        $this->assertDatabaseCount('trips', 4);

        Http::assertNothingSent();
    });

    test('fails clearly before dependent demo data when the external administrator identity is missing', function (): void {
        expect(fn () => $this->seed(DatabaseSeeder::class))
            ->toThrow(RuntimeException::class, 'admin@company.com');

        $this->assertDatabaseCount('drivers', 0);
        $this->assertDatabaseCount('buses', 0);
        $this->assertDatabaseCount('trips', 0);
        $this->assertDatabaseCount('users', 0, 'mysql');
    });

    test('seeds permissions but no demo operational data outside approved environments', function (string $environment): void {
        User::factory()->create(['email' => 'admin@company.com']);
        $this->app->instance('env', $environment);

        try {
            $this->artisan('db:seed', [
                '--class' => DatabaseSeeder::class,
                '--force' => true,
            ])->assertSuccessful();
        } finally {
            $this->app->instance('env', 'testing');
        }

        expect(Permission::query()->where('guard_name', 'web')->exists())->toBeTrue();
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('drivers', 0);
        $this->assertDatabaseCount('buses', 0);
        $this->assertDatabaseCount('trips', 0);
    })->with(['production', 'staging', 'preview']);
});
