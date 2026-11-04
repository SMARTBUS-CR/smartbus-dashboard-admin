<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\DriverManagementService;
use App\Services\UserManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

describe('Driver Registration Authorization', function (): void {
    test('allows a global administrator to create employment without company membership', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = User::factory()->create();

        $driver = app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment());

        $this->assertModelExists($driver);
        expect(CompanyUser::withTrashed()->where('user_id', $actor->id)->exists())->toBeFalse();
    });

    test('denies registration to actors without active scoped workforce permission', function (string $scenario): void {
        $company = createCompany();
        $foreignCompany = createCompany();
        $actorCompany = $scenario === 'foreign administrator' ? $foreignCompany : $company;
        $actor = match ($scenario) {
            'driver' => createUserWithRole(UserRole::Driver, $company),
            'passenger' => createUserWithRole(UserRole::Passenger, $company),
            'archived global administrator' => createUserWithRole(UserRole::SuperAdmin),
            'admin without permission' => createUserWithRole(UserRole::Admin, $company),
            default => $this->createWorkforceAdministrator($actorCompany),
        };
        if (in_array($scenario, ['driver', 'passenger', 'admin without permission'], true)) {
            CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        }
        if (in_array($scenario, ['archived administrator', 'archived global administrator'], true)) {
            $actor->delete();
        }
        if ($scenario === 'archived membership') {
            CompanyUser::query()->where('company_id', $company->id)->where('user_id', $actor->id)->sole()->delete();
        }
        $target = User::factory()->create();
        setPermissionsTeamId($foreignCompany->id);

        expect(fn () => app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment()))
            ->toThrow(AuthorizationException::class);
        expect(getPermissionsTeamId())->toBe($foreignCompany->id);
        $this->assertDatabaseCount('drivers', 0);
        expect(CompanyUser::withTrashed()->where('user_id', $target->id)->exists())->toBeFalse();
    })->with([
        'admin without permission', 'foreign administrator', 'driver', 'passenger',
        'archived administrator', 'archived global administrator', 'archived membership',
    ]);

    test('rejects an inactive or archived company even for a global administrator', function (bool $archived): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = User::factory()->create();
        if ($archived) {
            $company->delete();
        } else {
            $company->update(['status' => 'inactive']);
        }
        $previousTeam = createCompany()->id;
        setPermissionsTeamId($previousTeam);

        expect(fn () => app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment()))
            ->toThrow(ValidationException::class);
        expect(getPermissionsTeamId())->toBe($previousTeam);
        $this->assertDatabaseCount('drivers', 0);
    })->with(['inactive company' => false, 'archived company' => true]);

    test('prevents calling the dedicated identity creation entry point without workforce authorization', function (): void {
        $company = createCompany();
        $actor = User::factory()->create();

        expect(fn () => app(UserManagementService::class)->createDriverIdentity($actor, $company, $this->validDriverIdentity()))
            ->toThrow(AuthorizationException::class);
        $this->assertDatabaseCount('users', 1, 'mysql');
    });
});
