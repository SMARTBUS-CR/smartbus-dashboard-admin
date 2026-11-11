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
        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBeTrue()
            ->and(getPermissionsTeamId())->toBe($other->id)
            ->and($actor->relationLoaded('roles'))->toBeFalse()
            ->and($actor->relationLoaded('permissions'))->toBeFalse();

        CompanyUser::where('company_id', $company->id)->where('user_id', $actor->id)->sole()->delete();
        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBeFalse();
    })->with([
        ['viewAny', 'ViewAny:Driver'], ['view', 'View:Driver'], ['create', 'Create:Driver'],
        ['update', 'Update:Driver'], ['delete', 'Delete:Driver'], ['restore', 'Restore:Driver'],
        ['viewSalary', 'ViewSalary:Driver'], ['viewAttendance', 'ViewAttendance:Driver'],
    ]);

    test('does not grant administration through employee roles even with permissions', function (UserRole $role): void {
        $company = createCompany();
        $actor = createUserWithRole($role, $company);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        $driver = Driver::factory()->for($company)->create();
        grantShield($actor, [
            'ViewAny:Driver', 'View:Driver', 'Create:Driver', 'Update:Driver',
            'Delete:Driver', 'Restore:Driver', 'ViewSalary:Driver', 'ViewAttendance:Driver',
        ], $company);

        foreach (['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'viewSalary', 'viewAttendance'] as $ability) {
            expect(Gate::forUser($actor)->allows($ability, [Driver::class, $company, $driver]))->toBeFalse();
        }
    })->with([UserRole::Driver, UserRole::Passenger]);

    test('allows a global administrator only within a valid record company', function (string $ability): void {
        [$company, $other] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $driver = Driver::factory()->for($company)->create();

        expect(Gate::forUser($actor)->allows($ability, [Driver::class, $company, $driver]))->toBeTrue()
            ->and(Gate::forUser($actor)->allows($ability, [Driver::class, $other, $driver]))->toBeFalse();
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

        expect(Gate::forUser($actor)->allows('restore', [Driver::class, $company, $driver]))->toBeTrue()
            ->and(Gate::forUser($actor)->allows('forceDelete', [Driver::class, $company, $driver]))->toBeFalse()
            ->and(Gate::forUser($actor)->allows('forceDeleteAny', [Driver::class, $company]))->toBeFalse();
    });
});

describe('Driver Record Authorization Contracts', function (): void {
    test('resolves the active tenant for native collection authorization', function (
        string $ability,
        string $permission,
    ): void {
        $company = createCompany();
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, [$permission], $company);
        actingAsInCompany($actor, $company);

        $allowed = Gate::forUser($actor)->allows($ability, Driver::class);

        expect($allowed)->toBeTrue();
    })->with([
        'listing' => ['viewAny', 'ViewAny:Driver'],
        'creating' => ['create', 'Create:Driver'],
    ]);

    test('supports native model and explicit company authorization', function (
        string $ability,
        string $permission,
        bool $archived,
    ): void {
        $company = createCompany();
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, [$permission], $company);
        $driver = Driver::factory()->for($company)->create();
        if ($archived) {
            $driver->delete();
        }
        actingAsInCompany($actor, $company);

        $nativeAuthorization = Gate::forUser($actor)->allows($ability, $driver);
        $explicitAuthorization = Gate::forUser($actor)->allows(
            $ability,
            [Driver::class, $company, $driver],
        );

        expect($nativeAuthorization)->toBeTrue()
            ->and($explicitAuthorization)->toBeTrue();
    })->with([
        'viewing' => ['view', 'View:Driver', false],
        'updating' => ['update', 'Update:Driver', false],
        'archiving' => ['delete', 'Delete:Driver', false],
        'restoring' => ['restore', 'Restore:Driver', true],
        'viewing salary' => ['viewSalary', 'ViewSalary:Driver', false],
        'viewing attendance' => ['viewAttendance', 'ViewAttendance:Driver', false],
    ]);

    test('denies native model authorization outside the actor company', function (): void {
        [$company, $otherCompany] = createTenantPair();
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, ['Update:Driver'], $company);
        $foreignDriver = Driver::factory()->for($otherCompany)->create();
        actingAsInCompany($actor, $company);

        $allowed = Gate::forUser($actor)->allows('update', $foreignDriver);

        expect($allowed)->toBeFalse();
    });
});
