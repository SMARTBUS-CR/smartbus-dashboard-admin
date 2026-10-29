<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

describe('Driver Policy', function (): void {
    test('requires the exact permission and active membership in the explicit company', function (string $ability, string $permission): void {
        [$company, $other] = createTenantPair();
        $driver = Driver::factory()->for($company)->create();
        $actor = User::factory()->create();
        $arguments = [Driver::class, $company, $driver];
        grantShield($actor, [$permission], $other);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        setPermissionsTeamId($other->id);
        $actor->load('roles', 'permissions');

        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBeFalse();
        grantShield($actor, [$permission], $company);
        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBeTrue();
        expect(getPermissionsTeamId())->toBe($other->id);
        expect($actor->relationLoaded('roles'))->toBeFalse();
        expect($actor->relationLoaded('permissions'))->toBeFalse();

        CompanyUser::where('company_id', $company->id)->where('user_id', $actor->id)->sole()->delete();
        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBeFalse();
    })->with([
        ['viewAny', 'ViewAny:Driver'], ['view', 'View:Driver'], ['create', 'Create:Driver'],
        ['update', 'Update:Driver'], ['delete', 'Delete:Driver'], ['restore', 'Restore:Driver'],
        ['viewSalary', 'ViewSalary:Driver'], ['viewAttendance', 'ViewAttendance:Driver'],
    ]);

    test('does not grant administration through a role alone', function (UserRole $role): void {
        $company = createCompany();
        $actor = createUserWithRole($role, $company);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        $driver = Driver::factory()->for($company)->create();

        foreach (['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'viewSalary', 'viewAttendance'] as $ability) {
            expect(Gate::forUser($actor)->allows($ability, [Driver::class, $company, $driver]))->toBeFalse();
        }
    })->with([UserRole::Admin, UserRole::Driver, UserRole::Passenger]);

    test('allows a global administrator only within a valid record company', function (string $ability): void {
        [$company, $other] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $driver = Driver::factory()->for($company)->create();

        expect(Gate::forUser($actor)->allows($ability, [Driver::class, $company, $driver]))->toBeTrue();
        expect(Gate::forUser($actor)->allows($ability, [Driver::class, $other, $driver]))->toBeFalse();
        $company->update(['status' => CompanyStatus::INACTIVE]);
        expect(Gate::forUser($actor)->allows($ability, [Driver::class, $company, $driver]))->toBeFalse();
    })->with(['view', 'update', 'delete', 'restore', 'viewSalary', 'viewAttendance']);

    test('rejects forged ownership and an archived actor', function (): void {
        [$company, $other] = createTenantPair();
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        grantShield($actor, ['View:Driver'], $company);
        $driver = Driver::factory()->for($other)->create();
        $driver->company_id = $company->id;
        expect(Gate::forUser($actor)->allows('view', [Driver::class, $company, $driver]))->toBeFalse();
        $driver = Driver::factory()->for($company)->create();
        User::whereKey($actor->id)->delete();
        expect(Gate::forUser($actor)->allows('view', [Driver::class, $company, $driver]))->toBeFalse();
    });

    test('authorizes archived records without granting permanent deletion', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $driver = Driver::factory()->for($company)->create();
        $driver->delete();

        expect(Gate::forUser($actor)->allows('restore', [Driver::class, $company, $driver]))->toBeTrue();
        expect(Gate::forUser($actor)->allows('forceDelete', [Driver::class, $company, $driver]))->toBeFalse();
        expect(Gate::forUser($actor)->allows('forceDeleteAny', [Driver::class, $company]))->toBeFalse();
    });
});
