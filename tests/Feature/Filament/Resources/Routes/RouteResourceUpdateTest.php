<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Pages\EditRoute;
use App\Models\CompanyUser;
use App\Models\Route;
use Livewire\Livewire;

describe('Route Resource Updates', function (): void {
    test('updates a company route while preserving its existing code', function (string $role): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create([
            'code' => 'R-101',
            'name' => 'Original Route',
        ]);

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
                ['ViewAny:Route', 'View:Route', 'Update:Route'],
                $company,
            );
        }

        actingAsInCompany($actor, $company);

        Livewire::test(EditRoute::class, [
            'record' => $route->getKey(),
        ])
            ->fillForm([
                'code' => 'R-101',
                'name' => 'San Jose - Heredia',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Route::class, [
            'id' => $route->getKey(),
            'company_id' => $company->getKey(),
            'code' => 'R-101',
            'name' => 'San Jose - Heredia',
        ]);
    })->with([
        'system admin' => UserRole::SuperAdmin->value,
        'company admin with permissions' => UserRole::Admin->value,
        'custom role with permissions' => 'route-editor',
    ]);

    test('rejects a reserved code without saving other changes', function (bool $archived): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create([
            'code' => 'R-101',
            'name' => 'Original Route',
        ]);

        $reservedRoute = Route::factory()->for($company)->create([
            'code' => 'R-201',
            'name' => 'Reserved Route',
        ]);

        if ($archived) {
            $reservedRoute->delete();
        }

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(EditRoute::class, [
            'record' => $route->getKey(),
        ])
            ->fillForm([
                'code' => 'R-201',
                'name' => 'Changed Route',
            ])
            ->call('save')
            ->assertHasFormErrors(['code' => 'unique']);

        $this->assertDatabaseHas(Route::class, [
            'id' => $route->getKey(),
            'company_id' => $company->getKey(),
            'code' => 'R-101',
            'name' => 'Original Route',
        ]);
    })->with([
        'active route code' => false,
        'archived route code' => true,
    ]);

    test('ignores a forged company identifier when updating a route', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $route = Route::factory()->for($company)->create([
            'code' => 'R-101',
            'name' => 'Original Route',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(EditRoute::class, [
            'record' => $route->getKey(),
        ])
            ->fillForm([
                'code' => 'R-101',
                'name' => 'Updated Route',
            ])
            ->set('data.company_id', $otherCompany->getKey())
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Route::class, [
            'id' => $route->getKey(),
            'company_id' => $company->getKey(),
            'code' => 'R-101',
            'name' => 'Updated Route',
        ]);
    });
});
