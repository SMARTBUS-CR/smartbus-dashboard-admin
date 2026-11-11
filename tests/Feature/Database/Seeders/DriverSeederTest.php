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

pest()->use(WithDriverRegistration::class);

describe('Driver Seeder', function (): void {
    test('uses only the authentication driver and preserves identity edits and archives on reruns', function (): void {
        Http::preventStrayRequests();
        $company = createCompany(['slug' => 'smartbus-demo']);
        $actor = $this->createWorkforceAdministrator($company);
        $user = User::factory()->create(['name' => 'Driver User', 'email' => 'user@driver.com', 'password' => 'driver123']);
        User::factory()->create(['email' => 'demo.driver.001@example.invalid']);
        $beforeUsers = User::withTrashed()->orderBy('id')->get()->map->getAttributes()->all();
        $previousTeam = createCompany()->id;
        setPermissionsTeamId($previousTeam);

        $this->seed(DriverSeeder::class);

        $driver = Driver::query()->sole();
        expect($driver->user_id)->toBe($user->id)
            ->and($driver->salary_amount)->toBe('650000.00')
            ->and($driver->salary_currency)->toBe('CRC')
            ->and($driver->salary_period)->toBe('monthly');
        $this->assertDatabaseCount('driver_schedules', 5);
        $this->assertDatabaseHas('company_users', ['company_id' => $company->id, 'user_id' => $user->id, 'deleted_at' => null]);
        expect(DB::connection('mysql')->table('model_has_roles')->where('model_uuid', $user->id)
            ->where('company_id', $company->id)->count())->toBe(1);
        $driver->update(['salary_amount' => '789123.45', 'license' => 'MANUALLY-EDITED']);
        DriverSchedule::query()->where('driver_id', $driver->id)->firstOrFail()->update(['start_time' => '09:00:00']);
        DriverSchedule::query()->where('driver_id', $driver->id)->firstOrFail()->delete();
        $beforeDrivers = Driver::withTrashed()->get()->map->getAttributes()->all();
        $beforeSchedules = DriverSchedule::query()->orderBy('id')->get()->map->getAttributes()->all();

        $this->seed(DriverSeeder::class);

        expect(Driver::withTrashed()->get()->map->getAttributes()->all())->toBe($beforeDrivers)
            ->and(DriverSchedule::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeSchedules);
        $driver->delete();
        $membership = CompanyUser::query()->where('user_id', $user->id)->sole();
        $membership->delete();
        $this->seed(DriverSeeder::class);

        $this->assertSoftDeleted($driver);
        $this->assertSoftDeleted($membership);
        expect(User::withTrashed()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeUsers)
            ->and(getPermissionsTeamId())->toBe($previousTeam);
        $this->assertModelExists($actor);
        Http::assertNothingSent();
    });

    test('does not write demo records outside approved environments', function (string $environment): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->createWorkforceAdministrator($company);
        $existing = Driver::factory()->for($company)->withMembership()->create();
        $before = $existing->fresh()->getAttributes();
        $rolesBefore = DB::connection('mysql')->table('roles')->count();
        User::factory()->create(['email' => 'user@driver.com']);
        $this->app->instance('env', $environment);

        try {
            $this->artisan('db:seed', ['--class' => DriverSeeder::class, '--force' => true])->assertSuccessful();
        } finally {
            $this->app->instance('env', 'testing');
        }

        expect($existing->fresh()->getAttributes())->toBe($before);
        $this->assertDatabaseCount('drivers', 1);
        $this->assertDatabaseCount('driver_schedules', 0);
        $this->assertDatabaseCount('users', 3, 'mysql');
        $this->assertDatabaseCount('roles', $rolesBefore, 'mysql');
    })->with(['production', 'staging', 'preview']);

    test('does not create missing companies or administrators', function (): void {
        $this->seed(DriverSeeder::class);
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->seed(DriverSeeder::class);
        $this->createWorkforceAdministrator($company);
        $this->artisan('db:seed', ['--class' => DriverSeeder::class, '--force' => true])
            ->expectsOutputToContain('run the authentication seeders')->assertSuccessful();

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('drivers', 0);
        $this->assertDatabaseCount('users', 1, 'mysql');
    });

    test('supports approved development environments', function (string $environment): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->createWorkforceAdministrator($company);
        User::factory()->create(['email' => 'user@driver.com']);
        $this->app->instance('env', $environment);

        try {
            $this->seed(DriverSeeder::class);
        } finally {
            $this->app->instance('env', 'testing');
        }

        $this->assertDatabaseCount('drivers', 1);
        $this->assertDatabaseCount('driver_schedules', 5);
    })->with(['local', 'development']);

    test('reuses the authentication identity and preserves credentials roles and unrelated employment', function (): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->createWorkforceAdministrator($company);
        $other = createCompany();
        $user = User::factory()->create([
            'email' => 'user@driver.com',
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
        $this->assertDatabaseCount('drivers', 2);
        $this->assertDatabaseCount('users', 2, 'mysql');
    });

    test('skips unrelated identities reserved licenses and revoked memberships', function (string $scenario): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->createWorkforceAdministrator($company);
        $id = 'd3000000-0000-4000-8000-000000000001';
        $email = 'demo.driver.001@example.invalid';
        $user = match ($scenario) {
            'unrelated user' => User::factory()->create(['email' => $email]),
            'unrelated UUID' => User::factory()->create(['id' => $id]),
            default => User::factory()->create(['id' => $id, 'email' => 'user@driver.com']),
        };
        if ($scenario === 'archived user') {
            $user->delete();
        }
        if ($scenario === 'revoked membership') {
            CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $id])->delete();
        }
        if ($scenario === 'reserved license') {
            Driver::factory()->for($company)->archived()->create(['license' => ' auth-driver-user ']);
        }
        $before = $user->fresh()->getAttributes();

        $this->seed(DriverSeeder::class);

        expect($user->fresh()->getAttributes())->toBe($before)
            ->and(Driver::withTrashed()->where('company_id', $company->id)->where('user_id', $id)->exists())->toBeFalse();
        $this->assertDatabaseCount('driver_schedules', 0);
        if ($scenario === 'revoked membership') {
            $this->assertDatabaseHas('company_users', ['company_id' => $company->id, 'user_id' => $id]);
            expect(CompanyUser::onlyTrashed()->where('user_id', $id)->exists())->toBeTrue();
        }
    })->with(['unrelated user', 'unrelated UUID', 'archived user', 'revoked membership', 'reserved license']);

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

    test('preserves the authentication identity on failed registration and allows retry', function (): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $actor = $this->createWorkforceAdministrator($company);
        setPermissionsTeamId($company->id);
        $user = User::factory()->create(['email' => 'user@driver.com']);
        $before = $user->fresh()->getAttributes();
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

        $this->assertDatabaseCount('users', 2, 'mysql');
        expect($user->fresh()->getAttributes())->toBe($before);
        $this->assertDatabaseCount('drivers', 0);
        $this->assertModelExists($actor);
        expect(getPermissionsTeamId())->toBe($company->id);
        $this->seed(DriverSeeder::class);
        $this->assertDatabaseCount('drivers', 1);
        $this->assertDatabaseCount('driver_schedules', 5);
    });
    test('registers the authentication driver even when legacy demo employment exists', function (): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->createWorkforceAdministrator($company);
        $legacy = Driver::factory()->for($company)->withMembership()->create(['license' => 'DEMO-DRIVER-001']);
        $user = User::factory()->create(['name' => 'Driver User', 'email' => 'user@driver.com']);
        $users = User::query()->count();

        $this->seed(DriverSeeder::class);

        $this->assertDatabaseHas('drivers', ['company_id' => $company->id, 'user_id' => $user->id, 'license' => 'AUTH-DRIVER-USER']);
        $this->assertModelExists($legacy);
        $this->assertDatabaseCount('users', $users, 'mysql');
    });

});
