<?php

use App\Enums\UserRole;
use App\Exceptions\DriverRegistrationCompensationException;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\DriverManagementService;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverCompensation;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class, WithDriverCompensation::class);

describe('Driver Registration Compensation', function (): void {
    test('recovers partial persistence and permits one consistent retry', function (string $phase, bool $newIdentity, bool $missingRole): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $company = $this->createCompensationCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = $newIdentity ? null : User::factory()->create();
        if ($missingRole) {
            Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'driver')->delete();
        }
        $previousTeam = $this->createCompensationCompany()->id;
        setPermissionsTeamId($previousTeam);
        $actor->load('roles', 'permissions');
        $target?->load('roles', 'permissions');
        $before = $this->registrationDatabaseSnapshot();
        $observed = false;
        $disarm = $this->injectRegistrationFailure($phase, function () use ($company, $phase, &$observed): void {
            $observed = true;
            $this->assertDatabaseCount('drivers', 1);
            $this->assertDatabaseCount('company_users', 2);
            $this->assertDatabaseCount('users', 2, 'mysql');
            $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'driver')->sole();
            expect(DB::connection('mysql')->table('model_has_roles')->where('role_id', $role->id)->where('company_id', $company->id)->count())->toBe(1);
            if ($phase !== 'pgsql deferred commit') {
                expect(DB::connection('pgsql')->getPdo()->inTransaction())->toBeFalse();
            }
            if ($phase === 'mysql committed') {
                expect(DB::connection('mysql')->getPdo()->inTransaction())->toBeFalse();
            }
        });
        $register = fn (): Driver => $newIdentity
            ? app(DriverManagementService::class)->register($actor, $company, $this->validDriverIdentity(), $this->validDriverEmployment())
            : app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment());

        try {
            expect($register)->toThrow(function (Exception $exception): void {
                expect($exception->getMessage())->toContain('Injected registration failure');
            });
            expect($observed)->toBeTrue();
            expect($this->registrationDatabaseSnapshot())->toBe($before);
            expect(getPermissionsTeamId())->toBe($previousTeam);
            expect($actor->relationLoaded('roles'))->toBeFalse();
            expect($actor->relationLoaded('permissions'))->toBeFalse();
            if ($target !== null) {
                expect($target->relationLoaded('roles'))->toBeFalse();
                expect($target->relationLoaded('permissions'))->toBeFalse();
            }
            foreach (['pgsql', 'mysql'] as $name) {
                expect(DB::connection($name)->transactionLevel())->toBe(0);
                expect(DB::connection($name)->getPdo()->inTransaction())->toBeFalse();
            }
        } finally {
            $disarm();
        }

        $driver = $register();

        $this->assertModelExists($driver);
        $this->assertDatabaseCount('drivers', 1);
        $this->assertDatabaseCount('users', 2, 'mysql');
        $this->assertDatabaseCount('company_users', 2);
        $this->assertDatabaseCount('model_has_roles', 3, 'mysql');
        expect($driver->user->email)->toBe($newIdentity ? 'new-driver@example.test' : $target->email);
        expect(getPermissionsTeamId())->toBe($previousTeam);
        expect($register)->toThrow(ValidationException::class);
        $this->assertDatabaseCount('drivers', 1);
        $this->assertDatabaseCount('users', 2, 'mysql');
    })->with(['pgsql deferred commit', 'pgsql committed', 'mysql committing', 'mysql committed'])
        ->with(['new identity' => true, 'existing identity' => false])
        ->with(['new role' => true, 'existing role' => false]);

    test('preserves existing identity roles memberships and employment after both commits', function (bool $archivedMembership): void {
        $company = $this->createCompensationCompany();
        $otherCompany = $this->createCompensationCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = createUserWithRole(UserRole::Admin, $company);
        setPermissionsTeamId($company->id);
        $target->assignRole('driver');
        $membership = CompanyUser::create(['company_id' => $company->id, 'user_id' => $target->id]);
        if ($archivedMembership) {
            $membership->delete();
        }
        CompanyUser::create(['company_id' => $otherCompany->id, 'user_id' => $target->id]);
        Driver::factory()->for($otherCompany)->for($target)->create();
        setPermissionsTeamId($otherCompany->id);
        $before = $this->registrationDatabaseSnapshot();
        $disarm = $this->injectRegistrationFailure('mysql committed', function (): void {
            $this->assertDatabaseCount('drivers', 2);
        });

        try {
            expect(fn () => app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment()))
                ->toThrow(RuntimeException::class, 'Injected registration failure');
            expect($this->registrationDatabaseSnapshot())->toBe($before);
            expect(getPermissionsTeamId())->toBe($otherCompany->id);
        } finally {
            $disarm();
        }

        $driver = app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment());
        $this->assertModelExists($driver);
        expect($membership->fresh()->trashed())->toBeFalse();
        $this->assertDatabaseCount('drivers', 2);
        $this->assertDatabaseCount('users', 2, 'mysql');
    })->with(['active membership' => false, 'restored membership' => true]);

    test('rolls back identity creation when PostgreSQL rejects the employment insert', function (bool $newIdentity): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $company = $this->createCompensationCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = $newIdentity ? null : User::factory()->create();
        $before = $this->registrationDatabaseSnapshot();
        DB::connection('pgsql')->unprepared(<<<'SQL'
            CREATE FUNCTION reject_driver_registration_insert() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'Injected employment insert failure'; END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER reject_driver_registration_insert BEFORE INSERT ON drivers
            FOR EACH ROW EXECUTE FUNCTION reject_driver_registration_insert();
            SQL);
        $register = fn (): Driver => $newIdentity
            ? app(DriverManagementService::class)->register($actor, $company, $this->validDriverIdentity(), $this->validDriverEmployment())
            : app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment());

        try {
            expect($register)->toThrow(QueryException::class, 'Injected employment insert failure');
            expect($this->registrationDatabaseSnapshot())->toBe($before);
        } finally {
            DB::connection('pgsql')->unprepared('DROP TRIGGER reject_driver_registration_insert ON drivers; DROP FUNCTION reject_driver_registration_insert();');
        }

        $this->assertModelExists($register());
        $this->assertDatabaseCount('drivers', 1);
        $this->assertDatabaseCount('users', 2, 'mysql');
    })->with(['new identity' => true, 'existing identity' => false]);

    test('exposes incomplete recovery without hiding the cause and supports safe repeated recovery', function (string $cleanupConnection, bool $newIdentity): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $company = $this->createCompensationCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = $newIdentity ? null : User::factory()->create();
        $previousTeam = $this->createCompensationCompany()->id;
        setPermissionsTeamId($previousTeam);
        $before = $this->registrationDatabaseSnapshot();
        $disarm = $this->injectRegistrationFailure('mysql committed', function (): void {
            $this->assertDatabaseCount('drivers', 1);
        });
        if ($cleanupConnection === 'pgsql') {
            DB::connection('pgsql')->unprepared(<<<'SQL'
                CREATE FUNCTION reject_driver_cleanup() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'Injected compensation failure'; END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER reject_driver_cleanup BEFORE DELETE ON drivers
                FOR EACH ROW EXECUTE FUNCTION reject_driver_cleanup();
                SQL);
        } else {
            DB::connection('mysql')->unprepared("CREATE TRIGGER reject_driver_assignment_cleanup BEFORE DELETE ON model_has_roles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected compensation failure'");
        }
        $failure = null;
        $register = fn (): Driver => $newIdentity
            ? app(DriverManagementService::class)->register($actor, $company, $this->validDriverIdentity(), $this->validDriverEmployment())
            : app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment());

        try {
            expect($register)->toThrow(function (DriverRegistrationCompensationException $exception) use (&$failure): void {
                $failure = $exception;
                expect($exception->getPrevious()->getMessage())->toBe('Injected registration failure');
                expect($exception->recoveryFailures)->toHaveCount(1);
                expect($exception->recoveryFailures[0]->getMessage())->toContain('Injected compensation failure');
                expect($exception->context()['driver_id'])->not->toBeNull();
            });
            $this->assertDatabaseCount('users', 2, 'mysql');
            $this->assertDatabaseCount('drivers', $cleanupConnection === 'pgsql' ? 1 : 0);
            expect(getPermissionsTeamId())->toBe($previousTeam);
        } finally {
            $disarm();
            DB::connection('pgsql')->unprepared('DROP TRIGGER IF EXISTS reject_driver_cleanup ON drivers; DROP FUNCTION IF EXISTS reject_driver_cleanup();');
            DB::connection('mysql')->unprepared('DROP TRIGGER IF EXISTS reject_driver_assignment_cleanup');
        }

        $failure->operation->compensate();
        $failure->operation->compensate();
        expect($this->registrationDatabaseSnapshot())->toBe($before);
        $driver = $register();
        $afterRetry = $this->registrationDatabaseSnapshot();
        $failure->operation->compensate();
        expect($this->registrationDatabaseSnapshot())->toBe($afterRetry);
        $this->assertModelExists($driver);
        $this->assertDatabaseCount('users', 2, 'mysql');
    })->with(['pgsql cleanup' => 'pgsql', 'mysql cleanup' => 'mysql'])
        ->with(['new identity' => true, 'existing identity' => false]);

    test('retains a newly provisioned role if another assignment or permission now depends on it', function (bool $assignedToAnotherUser): void {
        $company = $this->createCompensationCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = User::factory()->create();
        $other = User::factory()->create();
        $membership = CompanyUser::create(['company_id' => $company->id, 'user_id' => $other->id]);
        Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'driver')->delete();
        $roleId = null;
        $disarm = $this->injectRegistrationFailure('mysql committed', function () use ($company, $other, $assignedToAnotherUser, &$roleId): void {
            $roleId = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'driver')->sole()->id;
            if ($assignedToAnotherUser) {
                DB::connection('mysql')->table('model_has_roles')->insert([
                    'role_id' => $roleId, 'company_id' => $company->id,
                    'model_uuid' => $other->id, 'model_type' => $other->getMorphClass(),
                ]);
            } else {
                DB::connection('mysql')->table('role_has_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => DB::connection('mysql')->table('permissions')->where('name', 'Create:Driver')->value('id'),
                ]);
            }
        });

        try {
            expect(fn () => app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment()))
                ->toThrow(RuntimeException::class, 'Injected registration failure');
            $this->assertDatabaseHas('roles', ['id' => $roleId], 'mysql');
            $this->assertDatabaseMissing('model_has_roles', ['role_id' => $roleId, 'model_uuid' => $target->id], 'mysql');
            $this->assertDatabaseCount('drivers', 0);
            $this->assertDatabaseCount('users', 3, 'mysql');
            $this->assertModelExists($membership);
            if ($assignedToAnotherUser) {
                $this->assertDatabaseHas('model_has_roles', ['role_id' => $roleId, 'model_uuid' => $other->id], 'mysql');
            } else {
                $this->assertDatabaseHas('role_has_permissions', ['role_id' => $roleId], 'mysql');
            }
        } finally {
            $disarm();
        }
    })->with(['another user assignment' => true, 'permission assignment' => false]);
});
