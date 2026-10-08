<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use Livewire\Livewire;

describe('Route Pattern Updates', function (): void {
    test('updates a pattern while preserving its code and owner route', function (string $role): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $pattern = RoutePattern::factory()->for($route)->create([
            'code' => 'OUTBOUND',
            'name' => 'Original Pattern',
            'headsign' => 'Original Destination',
        ]);

        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $company);

            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            grantShield($actor, [
                'ViewAny:Route',
                'View:Route',
                'Update:Route',
                'ViewAny:RoutePattern',
                'Update:RoutePattern',
            ], $company);
        }

        actingAsInCompany($actor, $company);

        Livewire::test(EditRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->fillForm([
                'code' => 'OUTBOUND',
                'name' => 'Outbound',
                'headsign' => 'Heredia Terminal',
            ])->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas(RoutePattern::class, [
            'id' => $pattern->getKey(),
            'route_id' => $route->getKey(),
            'code' => 'OUTBOUND',
            'name' => 'Outbound',
            'headsign' => 'Heredia Terminal',
        ]);
    })->with([
        'system admin' => UserRole::SuperAdmin->value,
        'company admin with permissions' => UserRole::Admin->value,
        'custom role with permissions' => 'route-manager',
    ]);

    test('rejects a reserved code without saving other pattern changes', function (bool $archived): void {
        $route = Route::factory()->create();

        $pattern = RoutePattern::factory()->for($route)->create([
            'code' => 'OUTBOUND',
            'name' => 'Original Pattern',
            'headsign' => 'Original Destination',
        ]);

        $reservedPattern = RoutePattern::factory()->for($route)->create([
            'code' => 'INBOUND',
        ]);

        if ($archived) {
            $reservedPattern->delete();
        }

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $route->company,
        );

        Livewire::test(EditRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->fillForm([
                'code' => 'INBOUND',
                'name' => 'Changed Pattern',
                'headsign' => 'Changed Destination',
            ])
            ->call('save')
            ->assertHasFormErrors(['code' => 'unique']);

        $this->assertDatabaseHas(RoutePattern::class, [
            'id' => $pattern->getKey(),
            'code' => 'OUTBOUND',
            'name' => 'Original Pattern',
            'headsign' => 'Original Destination',
        ]);
    })->with([
        'active pattern code' => false,
        'archived pattern code' => true,
    ]);

    test('rejects a direct editing attempt without the pattern update permission', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $pattern = RoutePattern::factory()->for($route)->create([
            'name' => 'Original Pattern',
        ]);

        $actor = createUserWithRole('route-editor', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Route',
            'View:Route',
            'Update:Route',
            'ViewAny:RoutePattern',
        ], $company);

        actingAsInCompany($actor, $company);

        Livewire::test(EditRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->assertForbidden();

        expect($pattern->fresh()->name)->toBe('Original Pattern');
    });

    test('does not resolve a pattern from another owner route', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $otherRoute = Route::factory()->for($company)->create();

        $foreignPattern = RoutePattern::factory()->for($otherRoute)->create([
            'name' => 'Foreign Pattern',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(EditRoutePattern::class, [
            'record' => $foreignPattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->assertNotFound();

        $this->assertDatabaseHas(RoutePattern::class, [
            'id' => $foreignPattern->getKey(),
            'route_id' => $otherRoute->getKey(),
            'name' => 'Foreign Pattern',
        ]);
    });
});
