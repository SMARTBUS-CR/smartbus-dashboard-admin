<?php

use App\Enums\CompanyStatus;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DriverSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

describe('Driver Seeder', function (): void {
    test('creates offline demo employment and preserves manual edits and archives on reruns', function (): void {
        Http::preventStrayRequests();
        $company = createCompany(['slug' => 'smartbus-demo']);
        $actor = $this->createWorkforceAdministrator($company);
        $previousTeam = createCompany()->id;
        setPermissionsTeamId($previousTeam);

        $this->seed(DriverSeeder::class);

        $drivers = Driver::query()->where('company_id', $company->id)->orderBy('license')->get();
        expect($drivers)->toHaveCount(3);
        foreach ($drivers as $driver) {
            expect($driver->license)->toStartWith('DEMO-DRIVER-')
                ->and($driver->salary_amount)->toBe('650000.00')
                ->and($driver->salary_currency)->toBe('CRC')
                ->and($driver->salary_period)->toBe('monthly')
                ->and($driver->user->email)->toEndWith('@example.invalid')
                ->and(DriverSchedule::query()->where('driver_id', $driver->id)->count())->toBe(5);
            $this->assertDatabaseHas('company_users', ['company_id' => $company->id, 'user_id' => $driver->user_id, 'deleted_at' => null]);
            expect(DB::connection('mysql')->table('model_has_roles')->where('model_uuid', $driver->user_id)
                ->where('company_id', $company->id)->count())->toBe(1);
        }
        $drivers[0]->update(['salary_amount' => '789123.45', 'license' => 'MANUALLY-EDITED']);
        DriverSchedule::query()->where('driver_id', $drivers[0]->id)->firstOrFail()->update(['start_time' => '09:00:00']);
        DriverSchedule::query()->where('driver_id', $drivers[1]->id)->firstOrFail()->delete();
        $drivers[2]->delete();
        $membership = CompanyUser::query()->where('user_id', $drivers[2]->user_id)->sole();
        $membership->delete();
        $beforeDrivers = Driver::withTrashed()->orderBy('id')->get()->map->getAttributes()->all();
        $beforeSchedules = DriverSchedule::query()->orderBy('id')->get()->map->getAttributes()->all();
        $beforeUsers = User::withTrashed()->orderBy('id')->get()->map->getAttributes()->all();

        $this->seed(DriverSeeder::class);

        expect(Driver::withTrashed()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeDrivers)
            ->and(DriverSchedule::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeSchedules)
            ->and(User::withTrashed()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeUsers)
            ->and(getPermissionsTeamId())->toBe($previousTeam);
        $this->assertSoftDeleted($membership);
        $this->assertDatabaseCount('users', 4, 'mysql');
        $this->assertModelExists($actor);
        Http::assertNothingSent();
    });

    test('does not write demo records outside approved environments', function (string $environment): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->createWorkforceAdministrator($company);
        $existing = Driver::factory()->for($company)->withMembership()->create();
        $before = $existing->fresh()->getAttributes();
        $rolesBefore = DB::connection('mysql')->table('roles')->count();
        $this->app->instance('env', $environment);

        try {
            $this->artisan('db:seed', ['--class' => DriverSeeder::class, '--force' => true])->assertSuccessful();
        } finally {
            $this->app->instance('env', 'testing');
        }

        expect($existing->fresh()->getAttributes())->toBe($before);
        $this->assertDatabaseCount('drivers', 1);
        $this->assertDatabaseCount('driver_schedules', 0);
        $this->assertDatabaseCount('users', 2, 'mysql');
        $this->assertDatabaseCount('roles', $rolesBefore, 'mysql');
    })->with(['production', 'staging', 'preview']);

    test('does not create missing companies or administrators', function (): void {
        $this->seed(DriverSeeder::class);
        createCompany(['slug' => 'smartbus-demo']);
        $this->seed(DriverSeeder::class);

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('drivers', 0);
        $this->assertDatabaseCount('users', 0, 'mysql');
    });

    test('supports approved development environments', function (string $environment): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->createWorkforceAdministrator($company);
        $this->app->instance('env', $environment);

        try {
            $this->seed(DriverSeeder::class);
        } finally {
            $this->app->instance('env', 'testing');
        }

        $this->assertDatabaseCount('drivers', 3);
        $this->assertDatabaseCount('driver_schedules', 15);
    })->with(['local', 'development']);

    test('reuses owned demo identities and preserves credentials roles and unrelated employment', function (): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->createWorkforceAdministrator($company);
        $other = createCompany();
        $user = User::factory()->create([
            'id' => 'd3000000-0000-4000-8000-000000000001', 'email' => 'demo.driver.001@example.invalid',
        ]);
        $otherDriver = Driver::factory()->for($other)->for($user)->withMembership()->create();
        $membership = CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
        setPermissionsTeamId($other->id);
        $role = Role::query()->where('company_id', $other->id)->where('name', 'admin')->sole();
        $user->assignRole($role);
        $before = $user->fresh()->getAttributes();
        $beforeMembership = $membership->fresh()->getAttributes();
        $beforeEmployment = $otherDriver->fresh()->getAttributes();

        $this->seed(DriverSeeder::class);

        expect($user->fresh()->getAttributes())->toBe($before)
            ->and($membership->fresh()->getAttributes())->toBe($beforeMembership)
            ->and($otherDriver->fresh()->getAttributes())->toBe($beforeEmployment)
            ->and(getPermissionsTeamId())->toBe($other->id);
        $this->assertDatabaseHas('model_has_roles', ['role_id' => $role->id, 'model_uuid' => $user->id, 'company_id' => $other->id], 'mysql');
        $this->assertDatabaseCount('drivers', 4);
        $this->assertDatabaseCount('users', 4, 'mysql');
    });

    test('does not take reserved demo identities licenses or revoked memberships', function (string $scenario): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->createWorkforceAdministrator($company);
        $id = 'd3000000-0000-4000-8000-000000000001';
        $email = 'demo.driver.001@example.invalid';
        $user = match ($scenario) {
            'foreign email' => User::factory()->create(['email' => $email]),
            'foreign UUID' => User::factory()->create(['id' => $id]),
            default => User::factory()->create(['id' => $id, 'email' => $email]),
        };
        if ($scenario === 'archived user') {
            $user->delete();
        }
        if ($scenario === 'revoked membership') {
            CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $id])->delete();
        }
        if ($scenario === 'reserved license') {
            Driver::factory()->for($company)->archived()->create(['license' => ' demo-driver-001 ']);
        }
        $before = $user->fresh()->getAttributes();

        $this->seed(DriverSeeder::class);

        expect($user->fresh()->getAttributes())->toBe($before)
            ->and(Driver::withTrashed()->where('company_id', $company->id)->where('user_id', $id)->exists())->toBeFalse();
        $this->assertDatabaseCount('driver_schedules', 10);
        if ($scenario === 'revoked membership') {
            $this->assertDatabaseHas('company_users', ['company_id' => $company->id, 'user_id' => $id]);
            expect(CompanyUser::onlyTrashed()->where('user_id', $id)->exists())->toBeTrue();
        }
    })->with(['foreign email', 'foreign UUID', 'archived user', 'revoked membership', 'reserved license']);

    test('skips inactive or archived demo companies', function (bool $archived): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->createWorkforceAdministrator($company);
        if ($archived) {
            $company->delete();
        } else {
            $company->update(['status' => CompanyStatus::INACTIVE]);
        }

        $this->seed(DriverSeeder::class);

        $this->assertDatabaseCount('drivers', 0);
        $this->assertDatabaseCount('users', 1, 'mysql');
    })->with(['inactive' => false, 'archived' => true]);

    test('removes only its new identity on failed registration and allows retry', function (): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $actor = $this->createWorkforceAdministrator($company);
        setPermissionsTeamId($company->id);
        $dispatcher = Driver::getEventDispatcher();
        Driver::setEventDispatcher(clone $dispatcher);
        Driver::creating(function (): void {
            throw new RuntimeException('Injected registration failure');
        });

        try {
            expect(fn () => $this->seed(DriverSeeder::class))->toThrow(RuntimeException::class, 'Injected registration failure');
        } finally {
            Driver::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseCount('users', 1, 'mysql');
        $this->assertDatabaseCount('drivers', 0);
        $this->assertModelExists($actor);
        expect(getPermissionsTeamId())->toBe($company->id);
        $this->seed(DriverSeeder::class);
        $this->assertDatabaseCount('drivers', 3);
        $this->assertDatabaseCount('driver_schedules', 15);
    });
});
