<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Pages\CreateRoute;
use App\Filament\Resources\Routes\Pages\EditRoute;
use App\Filament\Resources\Routes\RouteResource;
use App\Models\CompanyUser;
use App\Models\Route;
use Livewire\Livewire;

describe('Route Resource Creation', function (): void {
    test('creates a route in the selected company for an authorized user', function (string $role): void {
        $company = createCompany();

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
                ['ViewAny:Route', 'Create:Route'],
                $company,
            );
        }

        actingAsInCompany($actor, $company);

        Livewire::test(CreateRoute::class)
            ->fillForm([
                'code' => 'R-101',
                'name' => 'San Jose - Heredia',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Route::class, [
            'company_id' => $company->getKey(),
            'code' => 'R-101',
            'name' => 'San Jose - Heredia',
        ]);

        expect(Route::query()->count())->toBe(1);

        if ($role === UserRole::SuperAdmin->value) {
            expect(CompanyUser::withTrashed()
                ->where('user_id', $actor->getKey())
                ->exists())->toBeFalse();
        }
    })->with([
        'system admin' => UserRole::SuperAdmin->value,
        'company admin with permissions' => UserRole::Admin->value,
        'custom role with permissions' => 'route-editor',
    ]);

    test('shows a validation error when the company route code is already reserved', function (bool $archived): void {
        $company = createCompany();

        $existingRoute = Route::factory()->for($company)->create([
            'code' => 'R-101',
            'name' => 'Existing Route',
        ]);

        if ($archived) {
            $existingRoute->delete();
        }

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(CreateRoute::class)
            ->fillForm([
                'code' => 'R-101',
                'name' => 'Replacement Route',
            ])
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']);

        expect(Route::withTrashed()
            ->where('company_id', $company->getKey())
            ->count())->toBe(1);

        $this->assertDatabaseHas(Route::class, [
            'id' => $existingRoute->getKey(),
            'name' => 'Existing Route',
        ]);
    })->with([
        'active route' => false,
        'archived route' => true,
    ]);

    test('ignores a forged company identifier and uses the selected tenant', function (): void {
        [$company, $otherCompany] = createTenantPair();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(CreateRoute::class)
            ->fillForm([
                'code' => 'R-101',
                'name' => 'San Jose - Heredia',
            ])
            ->set('data.company_id', $otherCompany->getKey())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Route::class, [
            'company_id' => $company->getKey(),
            'code' => 'R-101',
        ]);

        $this->assertDatabaseMissing(Route::class, [
            'company_id' => $otherCompany->getKey(),
            'code' => 'R-101',
        ]);
    });

    test('requires a route code and name before creating a record', function (): void {
        $company = createCompany();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(CreateRoute::class)
            ->fillForm([
                'code' => null,
                'name' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'code' => 'required',
                'name' => 'required',
            ]);

        expect(Route::query()->exists())->toBeFalse();
    });

    test('allows a code already used by another company', function (): void {
        [$company, $otherCompany] = createTenantPair();

        Route::factory()->for($otherCompany)->create([
            'code' => 'R-101',
            'name' => 'Foreign Route',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(CreateRoute::class)
            ->fillForm([
                'code' => 'R-101',
                'name' => 'San Jose - Heredia',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Route::class, [
            'company_id' => $company->getKey(),
            'code' => 'R-101',
            'name' => 'San Jose - Heredia',
        ]);

        $this->assertDatabaseHas(Route::class, [
            'company_id' => $otherCompany->getKey(),
            'code' => 'R-101',
            'name' => 'Foreign Route',
        ]);
    });
});

describe('Route Creation Guidance And Navigation', function (): void {
    test('explains that patterns are configured after saving the route', function (): void {
        app()->setLocale('en');

        $company = createCompany();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(CreateRoute::class)
            ->assertSee(
                'Create the route first. You can add patterns after saving.'
            );
    });

    test('redirects a system admin to editing after creating a route', function (): void {
        $company = createCompany();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $component = Livewire::test(CreateRoute::class)
            ->fillForm([
                'code' => 'R-101',
                'name' => 'San Jose - Heredia',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $route = Route::query()->sole();

        $component->assertRedirect(RouteResource::getUrl(
            'edit',
            ['record' => $route->getKey()],
            panel: 'admin',
            tenant: $company,
        ));
    });

    test('redirects to viewing when the creator cannot edit the route', function (): void {
        $company = createCompany();
        $actor = createUserWithRole('route-creator', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Route',
            'View:Route',
            'Create:Route',
        ], $company);

        actingAsInCompany($actor, $company);

        $component = Livewire::test(CreateRoute::class)
            ->fillForm([
                'code' => 'R-101',
                'name' => 'San Jose - Heredia',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $route = Route::query()->sole();

        $component->assertRedirect(RouteResource::getUrl(
            'view',
            ['record' => $route->getKey()],
            panel: 'admin',
            tenant: $company,
        ));
    });

    test('redirects to listing when the creator cannot view or edit route details', function (): void {
        $company = createCompany();
        $actor = createUserWithRole('route-creator', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Route',
            'Create:Route',
        ], $company);

        actingAsInCompany($actor, $company);

        Livewire::test(CreateRoute::class)
            ->fillForm([
                'code' => 'R-101',
                'name' => 'San Jose - Heredia',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(RouteResource::getUrl(
                'index',
                panel: 'admin',
                tenant: $company,
            ));
    });

    test('hides the creation instruction when editing an existing route', function (): void {
        app()->setLocale('en');

        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(EditRoute::class, [
            'record' => $route->getKey(),
        ])->assertDontSee(
            'Create the route first. You can add patterns after saving.'
        );
    });
});
