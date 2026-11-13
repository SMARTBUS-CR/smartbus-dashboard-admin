<?php

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\CompanyUser;
use Filament\Facades\Filament;

describe('User Resource Tenant Scope', function (): void {
    test('shows only admins with an active membership in the selected company', function (): void {
        [$company, $foreign] = createTenantPair();

        $ownAdmin = createUserWithRole(UserRole::Admin, $company);
        $foreignAdmin = createUserWithRole(UserRole::Admin, $foreign);
        $driver = createUserWithRole(UserRole::Driver, $company);
        $passenger = createUserWithRole(UserRole::Passenger);

        foreach ([$ownAdmin, $driver, $passenger] as $user) {
            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
            ]);
        }

        CompanyUser::create([
            'company_id' => $foreign->id,
            'user_id' => $foreignAdmin->id,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        expect(UserResource::getEloquentQuery()->pluck('users.id')->all())
            ->toBe([$ownAdmin->id])
            ->and(UserResource::getRecordRouteBindingEloquentQuery()
                ->whereKey($foreignAdmin->id)->exists())->toBeFalse();
    });

    test('excludes a membership that was soft deleted', function (): void {
        $company = createCompany();
        $admin = createUserWithRole(UserRole::Admin, $company);

        $membership = CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
        ]);

        $membership->delete();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        expect(UserResource::getEloquentQuery()->exists())
            ->toBeFalse();
    });

    test('does not use an admin role from another company', function (): void {
        [$company, $foreign] = createTenantPair();
        $admin = createUserWithRole(UserRole::Admin, $foreign);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        expect(UserResource::getEloquentQuery()->exists())
            ->toBeFalse();
    });

    test('returns no users when there is no selected tenant', function (): void {
        createUserWithRole(UserRole::SuperAdmin);

        Filament::setCurrentPanel('admin');
        Filament::setTenant(null, isQuiet: true);

        expect(UserResource::getEloquentQuery()->exists())
            ->toBeFalse();
    });

    test('does not associate the super-admin actor with the selected company', function (): void {
        $company = createCompany();
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($superAdmin, $company);

        UserResource::getEloquentQuery()->get();

        expect(
            CompanyUser::withTrashed()
                ->where('user_id', $superAdmin->id)
                ->exists()
        )->toBeFalse();
    });

    test('shows a member with a custom role from the selected company', function (): void {
        $company = createCompany();
        $user = createUserWithRole('dispatcher', $company);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        expect(UserResource::getEloquentQuery()->pluck('users.id')->all())
            ->toBe([$user->id])
            ->and(UserResource::getRecordRouteBindingEloquentQuery()
                ->whereKey($user->id)->exists())->toBeTrue();
    });

    test('excludes a member whose custom role belongs to another company', function (): void {
        [$company, $foreign] = createTenantPair();
        $user = createUserWithRole('dispatcher', $foreign);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        expect(UserResource::getEloquentQuery()->exists())->toBeFalse()
            ->and(UserResource::getRecordRouteBindingEloquentQuery()
                ->whereKey($user->id)->exists())->toBeFalse();
    });

    test('loads tenant membership IDs once when building repeated user queries', function (): void {
        $company = createCompany();
        $admin = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $admin->getKey(),
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $connection = $company->getConnection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            foreach (range(1, 3) as $iteration) {
                expect(
                    UserResource::getEloquentQuery()
                        ->pluck('users.id')
                        ->all(),
                )->toBe([$admin->getKey()]);
            }

            $queries = collect($connection->getQueryLog())
                ->filter(
                    fn (array $query): bool => str_contains(
                        $query['query'],
                        'select "user_id" from "company_users"',
                    ),
                );

            expect($queries)->toHaveCount(1);
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    });

    test('refreshes membership IDs after company access is removed', function (): void {
        $company = createCompany();
        $admin = createUserWithRole(UserRole::Admin, $company);

        $membership = CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $admin->getKey(),
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        expect(
            UserResource::getEloquentQuery()->pluck('users.id')->all(),
        )->toBe([$admin->getKey()]);

        $membership->delete();

        expect(
            UserResource::getEloquentQuery()->pluck('users.id')->all(),
        )->toBeEmpty();
    });
});
