<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\DriverManagementService;
use Filament\Facades\Filament;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

describe('Driver Provisioning Context', function (): void {
    test('preserves credentials admin roles other memberships and independent employment while clearing loaded permissions', function (): void {
        $company = createCompany();
        $otherCompany = createCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = createUserWithRole(UserRole::Admin, $company);
        $membership = CompanyUser::create(['company_id' => $company->id, 'user_id' => $target->id]);
        $otherMembership = CompanyUser::create(['company_id' => $otherCompany->id, 'user_id' => $target->id]);
        setPermissionsTeamId($otherCompany->id);
        $otherAdminRole = Role::withoutGlobalScopes()->where('company_id', $otherCompany->id)->where('name', 'admin')->where('guard_name', 'web')->sole();
        $target->assignRole($otherAdminRole);
        $otherEmployment = Driver::factory()->for($otherCompany)->for($target)->create(['license' => 'OTHER-LICENSE', 'salary_amount' => '1000.25', 'salary_currency' => 'USD']);
        $originalIdentity = Arr::only($target->getAttributes(), ['name', 'email', 'password', 'email_verified_at', 'remember_token']);
        $assignments = DB::connection('mysql')->table('model_has_roles')->where('model_uuid', $target->id)->get();
        $target->load('roles', 'permissions');
        $actor->load('roles', 'permissions');

        $driver = app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment());

        expect(Arr::only($target->fresh()->getAttributes(), array_keys($originalIdentity)))->toBe($originalIdentity);
        expect($driver->company_id)->toBe($company->id);
        expect($otherEmployment->fresh()->salary_amount)->toBe('1000.25');
        expect($otherEmployment->fresh()->salary_currency)->toBe('USD');
        $this->assertNotSoftDeleted($membership);
        $this->assertNotSoftDeleted($otherMembership);
        foreach ($assignments as $assignment) {
            $this->assertDatabaseHas('model_has_roles', (array) $assignment, 'mysql');
        }
        expect(DB::connection('mysql')->table('model_has_roles')->where('model_uuid', $target->id)->count())->toBe(3);
        expect(getPermissionsTeamId())->toBe($otherCompany->id);
        expect($target->relationLoaded('roles'))->toBeFalse();
        expect($target->relationLoaded('permissions'))->toBeFalse();
        expect($actor->relationLoaded('roles'))->toBeFalse();
        expect($actor->relationLoaded('permissions'))->toBeFalse();
        $this->assertDatabaseCount('users', 2, 'mysql');
    });

    test('reuses or restores membership and does not duplicate an existing driver role assignment', function (bool $archived): void {
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = createUserWithRole(UserRole::Driver, $company);
        $membership = CompanyUser::create(['company_id' => $company->id, 'user_id' => $target->id]);
        if ($archived) {
            $membership->delete();
        }
        $membershipId = $membership->id;

        app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment());

        expect(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $target->id)->sole()->id)->toBe($membershipId);
        $this->assertNotSoftDeleted($membership);
        expect(DB::connection('mysql')->table('model_has_roles')->where('model_uuid', $target->id)->count())->toBe(1);
    })->with(['active membership' => false, 'archived membership' => true]);

    test('creates only a missing company web driver role despite a different selected tenant and other driver roles', function (): void {
        $company = createCompany();
        $otherCompany = createCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = User::factory()->create();
        Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'driver')->where('guard_name', 'web')->delete();
        $globalRole = Role::create(['name' => 'driver', 'guard_name' => 'web', 'company_id' => null]);
        $apiRole = Role::create(['name' => 'driver', 'guard_name' => 'api', 'company_id' => $company->id]);
        setPermissionsTeamId($otherCompany->id);
        Filament::setTenant($otherCompany, isQuiet: true);

        try {
            app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment());

            $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'driver')->where('guard_name', 'web')->sole();
            expect($role->id)->not->toBeIn([$globalRole->id, $apiRole->id]);
            $assignment = DB::connection('mysql')->table('model_has_roles')->where('model_uuid', $target->id)->sole();
            expect($assignment->role_id)->toBe($role->id);
            expect($assignment->company_id)->toBe($company->id);
            expect(getPermissionsTeamId())->toBe($otherCompany->id);
        } finally {
            Filament::setTenant(null, isQuiet: true);
        }
    });

    test('restores team context and rolls back precommit writes when driver role assignment fails', function (bool $newIdentity): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = $newIdentity ? null : User::factory()->create();
        $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'driver')->where('guard_name', 'web')->sole();
        $previousTeam = createCompany()->id;
        setPermissionsTeamId($previousTeam);
        $actor->load('roles', 'permissions');
        $target?->load('roles', 'permissions');
        DB::connection('mysql')->unprepared("CREATE TRIGGER reject_workforce_driver BEFORE INSERT ON model_has_roles FOR EACH ROW BEGIN IF NEW.role_id = {$role->id} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Driver role provisioning failed'; END IF; END");

        try {
            expect(function () use ($newIdentity, $actor, $company, $target): void {
                $service = app(DriverManagementService::class);
                if ($newIdentity) {
                    $service->register($actor, $company, $this->validDriverIdentity(), $this->validDriverEmployment());
                } else {
                    $service->createForExistingUser($actor, $company, $target, $this->validDriverEmployment());
                }
            })->toThrow(QueryException::class, 'Driver role provisioning failed');

            expect(getPermissionsTeamId())->toBe($previousTeam);
            expect($actor->relationLoaded('roles'))->toBeFalse();
            expect($actor->relationLoaded('permissions'))->toBeFalse();
            if ($target !== null) {
                expect($target->relationLoaded('roles'))->toBeFalse();
                expect($target->relationLoaded('permissions'))->toBeFalse();
            }
            $this->assertDatabaseCount('drivers', 0);
            $this->assertDatabaseCount('company_users', 1);
            $this->assertDatabaseCount('users', $newIdentity ? 1 : 2, 'mysql');
            $this->assertDatabaseCount('model_has_roles', 2, 'mysql');
        } finally {
            DB::connection('mysql')->unprepared('DROP TRIGGER IF EXISTS reject_workforce_driver');
        }
    })->with(['new identity' => true, 'existing identity' => false]);
});
