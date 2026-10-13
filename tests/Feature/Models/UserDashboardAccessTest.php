<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;

describe('Company Dashboard Access', function (): void {
    test('keeps driver and passenger accounts outside the dashboard even with extra permissions', function (string $roleName): void {
        $company = createCompany();
        $user = createUserWithRole($roleName, $company);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id]);
        grantShield($user, ['ViewAny:Role'], $company);

        expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
            ->and($user->canAccessTenant($company))->toBeFalse();
    })->with(['driver', 'passenger']);

    test('allows custom company roles only in companies with a valid assignment', function (): void {
        [$company, $foreign] = createTenantPair();
        $user = User::factory()->create();
        setPermissionsTeamId($company->id);
        $role = Role::create(['name' => 'dispatcher', 'guard_name' => 'web', 'company_id' => $company->id]);
        $user->assignRole($role);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id]);
        CompanyUser::create(['company_id' => $foreign->id, 'user_id' => $user->id]);

        expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
            ->and($user->canAccessTenant($company))->toBeTrue()
            ->and($user->canAccessTenant($foreign))->toBeFalse()
            ->and($user->getTenants(Filament::getPanel('admin'))->modelKeys())->toBe([$company->id]);

    });

    test('rejects scoped and other guard super admin roles as global access', function (string $guard, bool $roleScoped, bool $assignmentScoped): void {
        $company = createCompany();
        $user = User::factory()->create();
        $role = Role::create(['name' => 'super-admin', 'guard_name' => $guard, 'company_id' => $roleScoped ? $company->id : null]);
        $user->getConnection()->table('model_has_roles')->insert([
            'role_id' => $role->id, 'model_type' => $user->getMorphClass(),
            'model_uuid' => $user->id, 'company_id' => $assignmentScoped ? $company->id : null,
        ]);

        expect($user->isSuperAdmin())->toBeFalse()
            ->and($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
            ->and($user->canAccessTenant($company))->toBeFalse();
    })->with([
        'company role' => ['web', true, true],
        'other guard' => ['api', false, false],
        'scoped assignment' => ['web', false, true],
        'scoped role' => ['web', true, false],
    ]);

    test('denies access using a cached account after deactivation', function (string $roleName): void {
        $company = createCompany();
        $user = createUserWithRole($roleName, $roleName === 'admin' ? $company : null);
        if ($roleName === 'admin') {
            CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id]);
        }
        $cachedUser = User::findOrFail($user->id);
        $user->delete();

        expect($cachedUser->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
            ->and($cachedUser->canAccessTenant($company))->toBeFalse();
    })->with(['admin', 'super-admin']);

    test('denies dashboard access after membership removal', function (): void {
        $company = createCompany();
        $user = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id])->delete();

        expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
            ->and($user->canAccessTenant($company))->toBeFalse();
    });
});
