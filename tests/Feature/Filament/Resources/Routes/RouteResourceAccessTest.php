<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Pages\CreateRoute;
use App\Filament\Resources\Routes\Pages\EditRoute;
use App\Filament\Resources\Routes\Pages\ListRoutes;
use App\Models\CompanyUser;
use App\Models\Route;
use Livewire\Livewire;

describe('Route Resource Access', function (): void {
    test('shows only routes from the selected company to an authorized user', function (string $role): void {
        [$company, $otherCompany] = createTenantPair();

        $ownRoute = Route::factory()->for($company)->create();
        $foreignRoute = Route::factory()->for($otherCompany)->create();

        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $company);

            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            grantShield(
                $actor,
                ['ViewAny:Route', 'View:Route'],
                $company,
            );
        }

        actingAsInCompany($actor, $company);

        Livewire::test(ListRoutes::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$ownRoute])
            ->assertCanNotSeeTableRecords([$foreignRoute]);

        if ($role === UserRole::SuperAdmin->value) {
            expect(CompanyUser::withTrashed()
                ->where('user_id', $actor->getKey())
                ->exists())->toBeFalse();
        }
    })->with([
        'system admin' => UserRole::SuperAdmin->value,
        'company admin with permissions' => UserRole::Admin->value,
        'custom role with permissions' => 'route-viewer',
    ]);

    test('denies listing routes without the required permission', function (): void {
        $company = createCompany();
        $actor = createUserWithRole('route-viewer', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        actingAsInCompany($actor, $company);

        Livewire::test(ListRoutes::class)
            ->assertForbidden();
    });

    test('denies route creation to a user with viewing permissions only', function (): void {
        $company = createCompany();
        $actor = createUserWithRole('route-viewer', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield(
            $actor,
            ['ViewAny:Route', 'View:Route'],
            $company,
        );

        actingAsInCompany($actor, $company);

        Livewire::test(CreateRoute::class)
            ->assertForbidden();

        expect(Route::query()->exists())->toBeFalse();
    });

    test('does not resolve a foreign company route even for a system admin', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $foreignRoute = Route::factory()->for($otherCompany)->create();
        $actor = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, $company);

        Livewire::test(EditRoute::class, [
            'record' => $foreignRoute->getKey(),
        ])->assertStatus(404);
    });
});

describe('Route Resource Permission Boundaries', function (): void {
    test('denies access when permissions exist without an active company membership', function (): void {
        $company = createCompany();
        $actor = createUserWithRole('route-viewer', $company);

        grantShield(
            $actor,
            ['ViewAny:Route', 'View:Route'],
            $company,
        );

        actingAsInCompany($actor, $company);

        Livewire::test(ListRoutes::class)
            ->assertForbidden();
    });

    test('denies dashboard route access to excluded roles even with permissions', function (string $role): void {
        $company = createCompany();
        $actor = createUserWithRole($role, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield(
            $actor,
            ['ViewAny:Route', 'View:Route', 'Update:Route'],
            $company,
        );

        actingAsInCompany($actor, $company);

        Livewire::test(ListRoutes::class)
            ->assertForbidden();
    })->with([
        'driver' => UserRole::Driver->value,
        'passenger' => UserRole::Passenger->value,
    ]);

    test('denies opening the edit page with viewing permissions only', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $actor = createUserWithRole('route-viewer', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield(
            $actor,
            ['ViewAny:Route', 'View:Route'],
            $company,
        );

        actingAsInCompany($actor, $company);

        Livewire::test(EditRoute::class, [
            'record' => $route->getKey(),
        ])->assertForbidden();

        $this->assertDatabaseHas(Route::class, [
            'id' => $route->getKey(),
            'company_id' => $company->getKey(),
            'code' => $route->code,
            'name' => $route->name,
        ]);
    });

    test('allows a custom role with editing permissions to open its company route', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $actor = createUserWithRole('route-editor', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield(
            $actor,
            ['ViewAny:Route', 'View:Route', 'Update:Route'],
            $company,
        );

        actingAsInCompany($actor, $company);

        Livewire::test(EditRoute::class, [
            'record' => $route->getKey(),
        ])->assertSuccessful();
    });
});
