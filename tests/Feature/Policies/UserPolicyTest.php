<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

describe('User Policy', function (): void {
    test('limits delegated managers to accounts without higher privileges', function (string $targetRole, bool $directPermission, bool $allowed): void {
        $company = createCompany();
        $actor = createUserWithRole('user-manager', $company);
        $target = createUserWithRole($targetRole, $company);
        foreach ([$actor, $target] as $user) {
            CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id]);
        }
        grantShield($actor, ['Update:User'], $company);
        actingAsInCompany($actor, $company);
        if ($directPermission) {
            $target->givePermissionTo(Permission::findOrCreate('Delete:Role', 'web'));
        }

        expect(Gate::forUser($actor)->allows('update', $target))->toBe($allowed);
    })->with([
        'administrator' => ['admin', false, false],
        'higher direct permission' => ['dispatcher', true, false],
        'ordinary company account' => ['dispatcher', false, true],
    ]);

    test('requires the corresponding permission for company administration', function (string $ability, string $permission, bool $requiresRecord): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);
        $target = createUserWithRole(UserRole::Admin, $company);

        foreach ([$actor, $target] as $user) {
            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
            ]);
        }

        actingAsInCompany($actor, $company);

        $subject = $requiresRecord ? $target : User::class;

        expect(Gate::forUser($actor)->allows($ability, $subject))
            ->toBeFalse();

        grantShield($actor, [$permission], $company);

        expect(Gate::forUser($actor)->allows($ability, $subject))
            ->toBeTrue();
    })->with([
        'list' => ['viewAny', 'ViewAny:User', false],
        'create' => ['create', 'Create:User', false],
        'view' => ['view', 'View:User', true],
        'update' => ['update', 'Update:User', true],
    ]);

    test('rejects a foreign user even for a super-admin', function (string $ability): void {
        [$company, $foreign] = createTenantPair();
        $target = createUserWithRole(UserRole::Admin, $foreign);

        CompanyUser::create([
            'company_id' => $foreign->id,
            'user_id' => $target->id,
        ]);

        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        expect(Gate::forUser($actor)->allows($ability, $target))
            ->toBeFalse();
    })->with(['view', 'update']);

    test('allows global super-admin management without a tenant', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        $this->actingAs($actor, 'web');
        Filament::setCurrentPanel('admin');
        Filament::setTenant(null, isQuiet: true);
        setPermissionsTeamId(null);

        foreach (['viewAny', 'create'] as $ability) {
            expect(Gate::forUser($actor)->allows($ability, User::class))
                ->toBeTrue();
        }

        foreach (['view', 'update'] as $ability) {
            expect(Gate::forUser($actor)->allows($ability, $target))
                ->toBeTrue();
        }

        expect(CompanyUser::query()->exists())->toBeFalse();
    });

    test('prevents a company admin from modifying a super-admin', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);
        $target = createUserWithRole(UserRole::SuperAdmin);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $actor->id,
        ]);

        grantShield($actor, ['View:User', 'Update:User'], $company);
        actingAsInCompany($actor, $company);

        expect(Gate::forUser($actor)->allows('view', $target))
            ->toBeFalse()
            ->and(Gate::forUser($actor)->allows('update', $target))->toBeFalse();
    });

    test('keeps destructive and bulk actions disabled', function (string $ability, bool $requiresRecord): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        $subject = $requiresRecord ? $target : User::class;

        expect(Gate::forUser($actor)->allows($ability, $subject))
            ->toBeFalse();
    })->with([
        ['delete', true],
        ['deleteAny', false],
        ['restore', true],
        ['restoreAny', false],
        ['forceDelete', true],
        ['forceDeleteAny', false],
        ['replicate', true],
        ['reorder', false],
    ]);

    test('authorizes company access removal only with permission in the current company', function (bool $hasPermission, bool $foreignTarget, bool $expected): void {
        [$company, $foreign] = createTenantPair();

        $actor = createUserWithRole(UserRole::Admin, $company);
        $targetCompany = $foreignTarget ? $foreign : $company;
        $target = createUserWithRole(UserRole::Admin, $targetCompany);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $actor->id,
        ]);

        CompanyUser::create([
            'company_id' => $targetCompany->id,
            'user_id' => $target->id,
        ]);

        if ($hasPermission) {
            grantShield($actor, ['Delete:User'], $company);
        }

        actingAsInCompany($actor, $company);

        expect(
            Gate::forUser($actor)->allows('removeCompanyAccess', $target)
        )->toBe($expected);
    })->with([
        'missing permission' => [false, false, false],
        'authorized local target' => [true, false, true],
        'foreign target with permission' => [true, true, false],
    ]);

    test('allows an authorized company admin to manage a member with a custom role', function (string $ability, string $permission): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);
        $target = createUserWithRole('dispatcher', $company);

        foreach ([$actor, $target] as $user) {
            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
            ]);
        }

        actingAsInCompany($actor, $company);

        expect(Gate::forUser($actor)->allows($ability, $target))
            ->toBeFalse();

        grantShield($actor, [$permission], $company);

        expect(Gate::forUser($actor)->allows($ability, $target))
            ->toBeTrue();
    })->with([
        'view' => ['view', 'View:User'],
        'update' => ['update', 'Update:User'],
        'remove access' => ['removeCompanyAccess', 'Delete:User'],
    ]);

    test('checks target membership once across repeated authorizations', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $target->getKey(),
        ]);

        actingAsInCompany($actor, $company);

        $connection = $company->getConnection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            foreach (range(1, 3) as $iteration) {
                expect(
                    Gate::forUser($actor)->allows('update', $target),
                )->toBeTrue()
                    ->and(Gate::forUser($actor)->allows('removeCompanyAccess', $target))->toBeTrue();
            }

            $queries = collect($connection->getQueryLog())
                ->filter(
                    fn (array $query): bool => str_contains($query['query'], 'select exists')
                        && str_contains(
                            $query['query'],
                            '"company_users"',
                        ),
                );

            expect($queries)->toHaveCount(1);
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    });

    test('denies target management after a cached membership is removed', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::Admin, $company);

        $membership = CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $target->getKey(),
        ]);

        actingAsInCompany($actor, $company);

        expect(
            Gate::forUser($actor)->allows('update', $target),
        )->toBeTrue();

        $membership->delete();

        expect(
            Gate::forUser($actor)->allows('update', $target),
        )->toBeFalse()
            ->and(
                Gate::forUser($actor)->allows(
                    'removeCompanyAccess',
                    $target,
                ),
            )->toBeFalse();
    });
});
