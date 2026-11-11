<?php

use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\CompanyUser;
use Illuminate\Support\Facades\Gate;

describe('Bus Read Permissions', function (): void {
    test('applies the actor and company permission matrix', function (string $scenario, bool $allowed, string $ability, string $permission): void {
        [$company, $otherCompany] = createTenantPair();
        $bus = Bus::factory()->for($company)->create();
        $actorCompany = $scenario === 'foreign admin' ? $otherCompany : $company;
        $role = match ($scenario) {
            'global admin' => UserRole::SuperAdmin,
            'driver' => UserRole::Driver,
            'passenger' => UserRole::Passenger,
            default => UserRole::Admin,
        };
        $actor = createUserWithRole($role, $role === UserRole::SuperAdmin ? null : $actorCompany);
        if ($role !== UserRole::SuperAdmin) {
            $membership = CompanyUser::create(['company_id' => $actorCompany->getKey(), 'user_id' => $actor->getKey()]);
            if ($scenario !== 'missing permission') {
                grantShield($actor, [$permission], $actorCompany);
            }
            if ($scenario === 'archived membership') {
                $membership->delete();
            }
            if ($scenario === 'archived actor') {
                $actor->delete();
            }
        }

        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId($company->getKey());
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        try {
            $arguments = $ability === 'viewAny' ? [Bus::class, $company] : [Bus::class, $company, $bus];
            expect(Gate::forUser($actor)->allows($ability, $arguments))->toBe($allowed);
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    })->with([
        'global admin without membership' => ['global admin', true],
        'authorized company admin' => ['own admin', true],
        'missing permission' => ['missing permission', false],
        'foreign company' => ['foreign admin', false],
        'driver with permission' => ['driver', false],
        'passenger with permission' => ['passenger', false],
        'archived membership' => ['archived membership', false],
        'archived actor' => ['archived actor', false],
    ])->with([
        'listing' => ['viewAny', 'ViewAny:Bus'],
        'detail' => ['view', 'View:Bus'],
    ]);

    test('denies viewing a foreign record despite own company permission', function (): void {
        [$company, $otherCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, ['View:Bus'], $company);
        $bus = Bus::factory()->for($otherCompany)->create();
        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId($company->getKey());
        try {
            expect(Gate::forUser($actor)->allows('view', [Bus::class, $company, $bus]))->toBeFalse();
        } finally {
            setPermissionsTeamId($previousTeam);
        }
    });
});

describe('Bus Record Authorization Contracts', function (): void {
    test('resolves the active tenant for native collection authorization', function (
        string $ability,
        string $permission,
    ): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, [$permission], $company);
        actingAsInCompany($actor, $company);

        $allowed = Gate::forUser($actor)->allows($ability, Bus::class);

        expect($allowed)->toBeTrue();
    })->with([
        'listing' => ['viewAny', 'ViewAny:Bus'],
        'creating' => ['create', 'Create:Bus'],
    ]);

    test('supports native model and explicit company authorization', function (
        string $ability,
        string $permission,
        bool $archived,
    ): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, [$permission], $company);
        $bus = Bus::factory()->for($company)->create();
        if ($archived) {
            $bus->delete();
        }
        actingAsInCompany($actor, $company);

        $nativeAuthorization = Gate::forUser($actor)->allows($ability, $bus);
        $explicitAuthorization = Gate::forUser($actor)->allows($ability, [Bus::class, $company, $bus]);

        expect($nativeAuthorization)->toBeTrue()
            ->and($explicitAuthorization)->toBeTrue();
    })->with([
        'viewing' => ['view', 'View:Bus', false],
        'updating' => ['update', 'Update:Bus', false],
        'archiving' => ['delete', 'Delete:Bus', false],
        'restoring' => ['restore', 'Restore:Bus', true],
    ]);

    test('denies native model authorization for a bus outside the actor company', function (): void {
        [$company, $otherCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, ['Update:Bus'], $company);
        $foreignBus = Bus::factory()->for($otherCompany)->create();
        actingAsInCompany($actor, $company);

        $allowed = Gate::forUser($actor)->allows('update', $foreignBus);

        expect($allowed)->toBeFalse();
    });

    test('denies permanent deletion through native and explicit authorization', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $bus = Bus::factory()->for($company)->create();
        actingAsInCompany($actor, $company);

        $nativeAuthorization = Gate::forUser($actor)->allows('forceDelete', $bus);
        $explicitAuthorization = Gate::forUser($actor)->allows('forceDelete', [Bus::class, $company, $bus]);

        expect($nativeAuthorization)->toBeFalse()
            ->and($explicitAuthorization)->toBeFalse();
    });
});
