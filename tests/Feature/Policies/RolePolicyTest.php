<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Auth\User as AuthUser;

function actingInTenant(?Company $company, callable $callback): mixed
{
    $tenant = Filament::getTenant();
    $previousTeam = getPermissionsTeamId();

    try {
        // RolePolicy reads ownership from Filament::getTenant(), so tests must
        // set the tenant context explicitly instead of only the team id.
        // NOTE: Filament::setTenant() fires TenantSet with auth()->user(), so
        // the owner must be actingAs() BEFORE switching tenant.
        $owner = makeTenantOwner($company);
        setPermissionsTeamId($company?->getKey());
        Filament::setTenant($company, isQuiet: true);

        return $callback();
    } finally {
        setPermissionsTeamId($previousTeam);

        try {
            Filament::setTenant($tenant, isQuiet: true);
        } catch (Throwable) {
            // Best-effort restore; test isolation matters more than the event.
        }
    }
}

/**
 * Filament::setTenant() fires TenantSet with auth()->user(): actingAs() the
 * owner first so the event has a valid HasTenants user.
 */
function makeTenantOwner(?Company $company): ?User
{
    if ($company === null) {
        return null;
    }

    $owner = createUserWithRole(UserRole::Admin);
    $owner->companies()->syncWithoutDetaching([$company->id]);
    test()->actingAs($owner);

    return $owner;
}

function shieldUserWith(array $permissions, Company $team): AuthUser
{
    $user = createUserWithRole(UserRole::Admin);
    grantShield($user, $permissions, $team);

    return $user;
}

describe('Role Policy', function () {
    test('denies viewing a role that belongs to another tenant', function () {
        [$companyA, $companyB] = createTenantPair();

        $foreignRole = actingInTenant($companyB, fn () => Role::create([
            'name' => 'dispatcher',
            'guard_name' => 'web',
            'company_id' => $companyB->id,
        ]));

        $user = actingInTenant($companyA, function () use ($companyA) {
            return shieldUserWith(['ViewAny:Role', 'View:Role'], $companyA);
        });

        $denied = actingInTenant($companyA, fn () => (new RolePolicy)->view($user, $foreignRole));

        // Cross-tenant leak test: permission alone must not expose foreign roles.
        expect($denied)->toBeFalse();
    });

    test('allows viewing and updating an owned role with the shield permission', function () {
        $company = createCompany();

        [$allowedView, $allowedUpdate, $deniedUpdate] = actingInTenant($company, function () use ($company) {
            $role = Role::create(['name' => 'dispatcher', 'guard_name' => 'web', 'company_id' => $company->id]);
            $user = shieldUserWith(['View:Role', 'Update:Role'], $company);
            $policy = new RolePolicy;

            return [$policy->view($user, $role), $policy->update($user, $role), $policy->delete($user, $role)];
        });

        expect($allowedView)->toBeTrue()
            ->and($allowedUpdate)->toBeTrue()
            ->and($deniedUpdate)->toBeFalse();
    });

    test('never allows deleting protected roles', function (string $protected) {
        $company = createCompany();

        $results = actingInTenant($company, function () use ($protected) {
            $role = Role::findOrCreate($protected, 'web');
            // Even a super-admin must not delete system roles through this policy.
            $superAdmin = createUserWithRole(UserRole::SuperAdmin);
            $policy = new RolePolicy;

            return [$policy->delete($superAdmin, $role), $policy->forceDelete($superAdmin, $role)];
        });

        expect($results)->toBe([false, false]);
    })->with([
        'super-admin' => UserRole::SuperAdmin->value,
        'admin' => UserRole::Admin->value,
        'driver' => UserRole::Driver->value,
        'passenger' => UserRole::Passenger->value,
    ]);

    test('refuses to delete a role that still has users assigned', function () {
        $company = createCompany();

        $deleted = actingInTenant($company, function () use ($company) {
            $role = Role::create(['name' => 'dispatcher', 'guard_name' => 'web', 'company_id' => $company->id]);
            $user = createUserWithRole(UserRole::Admin);
            $user->assignRole($role);
            grantShield($user, ['Delete:Role'], $company);

            return (new RolePolicy)->delete($user, $role);
        });

        // Deleting here would orphan role assignments: must stay blocked.
        expect($deleted)->toBeFalse();
    });

    test('guest without shield permission cannot manage roles', function () {
        $company = createCompany();

        $results = actingInTenant($company, function () use ($company) {
            $role = Role::create(['name' => 'dispatcher', 'guard_name' => 'web', 'company_id' => $company->id]);
            $plain = createUserWithRole(UserRole::Driver);
            $policy = new RolePolicy;

            return [$policy->viewAny($plain), $policy->view($plain, $role), $policy->create($plain)];
        });

        expect($results)->toBe([false, false, false]);
    });

    test('creates default roles scoped to each new company independently', function () {
        [$companyA, $companyB] = createTenantPair();

        $roleNamesA = $companyA->roles()->pluck('name')->sort()->values()->all();

        expect($roleNamesA)->toBe([UserRole::Admin->value, UserRole::Driver->value]);

        // Same role name in company B must be a different row (team isolation).
        $adminA = Role::where('company_id', $companyA->id)->where('name', UserRole::Admin->value)->firstOrFail();
        $adminB = Role::where('company_id', $companyB->id)->where('name', UserRole::Admin->value)->firstOrFail();

        expect($adminA->id)->not->toBe($adminB->id);
    });

    test('assigning a permission in one tenant does not grant it in another', function () {
        [$companyA, $companyB] = createTenantPair();
        Permission::findOrCreate('View:Role', 'web');

        $user = createUserWithRole(UserRole::Admin);
        $user->companies()->syncWithoutDetaching([$companyA->id, $companyB->id]);
        grantShield($user, ['View:Role'], $companyA);

        $canInA = actingInTenant($companyA, fn () => $user->can('View:Role'));
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $canInB = actingInTenant($companyB, fn () => $user->can('View:Role'));

        expect($canInA)->toBeTrue()->and($canInB)->toBeFalse();
    });
});
