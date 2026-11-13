<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Pages\EditRoute;
use App\Filament\Resources\Routes\RelationManagers\PatternsRelationManager;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Route Pattern Relation Manager Creation', function (): void {
    test('creates a pattern associated with the owner route', function (string $role): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $otherRoute = Route::factory()->for($company)->create();

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
                'Create:RoutePattern',
            ], $company);
        }

        actingAsInCompany($actor, $company);

        Livewire::test(PatternsRelationManager::class, [
            'ownerRecord' => $route,
            'pageClass' => EditRoute::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'code' => 'OUTBOUND',
                'name' => 'Outbound',
                'headsign' => 'Heredia',
            ])
            ->assertNotified();

        $this->assertDatabaseHas(RoutePattern::class, [
            'route_id' => $route->getKey(),
            'code' => 'OUTBOUND',
            'name' => 'Outbound',
            'headsign' => 'Heredia',
        ]);

        expect($route->patterns()->count())->toBe(1)
            ->and($otherRoute->patterns()->exists())->toBeFalse();
    })->with([
        'system admin' => UserRole::SuperAdmin->value,
        'company admin with permissions' => UserRole::Admin->value,
        'custom role with permissions' => 'route-manager',
    ]);

    test('shows a validation error for a code reserved within the owner route', function (bool $archived): void {
        $route = Route::factory()->create();

        $existingPattern = RoutePattern::factory()->for($route)->create([
            'code' => 'OUTBOUND',
            'name' => 'Existing Pattern',
        ]);

        if ($archived) {
            $existingPattern->delete();
        }

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $route->company,
        );

        Livewire::test(PatternsRelationManager::class, [
            'ownerRecord' => $route,
            'pageClass' => EditRoute::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'code' => 'OUTBOUND',
                'name' => 'Replacement Pattern',
                'headsign' => 'Heredia',
            ])
            ->assertHasFormErrors(['code' => 'unique']);

        expect(RoutePattern::withTrashed()
            ->where('route_id', $route->getKey())
            ->count())->toBe(1);

        $this->assertDatabaseHas(RoutePattern::class, [
            'id' => $existingPattern->getKey(),
            'name' => 'Existing Pattern',
        ]);
    })->with([
        'active pattern' => false,
        'archived pattern' => true,
    ]);

    test('allows a code used by another route', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $otherRoute = Route::factory()->for($company)->create();

        RoutePattern::factory()->for($otherRoute)->create([
            'code' => 'OUTBOUND',
            'name' => 'Other Route Pattern',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(PatternsRelationManager::class, [
            'ownerRecord' => $route,
            'pageClass' => EditRoute::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'code' => 'OUTBOUND',
                'name' => 'Outbound',
                'headsign' => 'Heredia',
            ])
            ->assertNotified();

        expect($route->patterns()->count())->toBe(1)
            ->and($otherRoute->patterns()->count())->toBe(1);
    });

    test('rejects a direct creation attempt without the pattern creation permission', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

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

        Livewire::test(PatternsRelationManager::class, [
            'ownerRecord' => $route,
            'pageClass' => EditRoute::class,
        ])
            ->call('mountAction', 'create', [], ['table' => true])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($route->patterns()->exists())->toBeFalse();
    });
});
