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

    test('checks global administrator access only once for repeated checks', function (): void {
        $user = createUserWithRole(UserRole::SuperAdmin);
        $connection = $user->getConnection();

        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            foreach (range(1, 20) as $iteration) {
                expect($user->isSuperAdmin())->toBeTrue();
            }

            $queries = collect($connection->getQueryLog())
                ->filter(
                    fn (array $query): bool => str_contains($query['query'], 'model_has_roles')
                        && in_array(
                            UserRole::SuperAdmin->value,
                            $query['bindings'],
                            true,
                        ),
                );

            expect($queries)->toHaveCount(1);
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    });

    test('rechecks administrator access after account deactivation', function (): void {
        $user = createUserWithRole(UserRole::SuperAdmin);
        $cachedUser = $user->fresh();

        expect($cachedUser->isSuperAdmin())->toBeTrue();

        $user->delete();

        expect($cachedUser->isSuperAdmin())->toBeFalse();
    });

    test('loads administrator tenants only once for repeated checks', function (): void {
        $company = createCompany();
        $user = createUserWithRole(UserRole::SuperAdmin);
        $panel = Filament::getPanel('admin');
        $connection = $company->getConnection();

        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            foreach (range(1, 3) as $iteration) {
                expect($user->getTenants($panel)->modelKeys())
                    ->toContain($company->getKey());
            }

            $queries = collect($connection->getQueryLog())
                ->filter(
                    fn (array $query): bool => str_contains(
                        $query['query'],
                        'select * from "companies"',
                    ),
                );

            expect($queries)->toHaveCount(1);
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    });

    test('refreshes administrator tenants after a company is archived', function (): void {
        $company = createCompany();
        $user = createUserWithRole(UserRole::SuperAdmin);
        $panel = Filament::getPanel('admin');

        expect($user->getTenants($panel)->modelKeys())
            ->toContain($company->getKey());

        $company->delete();

        expect($user->getTenants($panel)->modelKeys())
            ->not->toContain($company->getKey());
    });

    test('checks company administrator membership once across repeated tenant access checks', function (): void {
        $company = createCompany();
        $user = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $user->getKey(),
        ]);

        actingAsInCompany($user, $company);

        $connection = $company->getConnection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            foreach (range(1, 3) as $iteration) {
                expect($user->canAccessTenant($company))->toBeTrue();
            }

            $queries = collect($connection->getQueryLog())
                ->filter(
                    fn (array $query): bool => str_contains(
                        $query['query'],
                        'from "company_users"',
                    ),
                );

            expect($queries)->toHaveCount(1);
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    });

    test('rechecks cached tenant access after membership removal', function (): void {
        $company = createCompany();
        $user = createUserWithRole(UserRole::Admin, $company);

        $membership = CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $user->getKey(),
        ]);

        actingAsInCompany($user, $company);

        expect($user->canAccessTenant($company))->toBeTrue();

        $membership->delete();

        expect($user->canAccessTenant($company))->toBeFalse();
    });
});
