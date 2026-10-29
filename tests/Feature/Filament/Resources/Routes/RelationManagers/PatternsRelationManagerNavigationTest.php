<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Pages\EditRoute;
use App\Filament\Resources\Routes\Pages\ViewRoute;
use App\Filament\Resources\Routes\RelationManagers\PatternsRelationManager;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Route Pattern Relation Manager Navigation', function (): void {
    test('links an authorized editor to the full pattern editing page', function (string $role): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

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
                'View:RoutePattern',
                'Update:RoutePattern',
            ], $company);
        }

        actingAsInCompany($actor, $company);

        Livewire::test(PatternsRelationManager::class, [
            'ownerRecord' => $route,
            'pageClass' => EditRoute::class,
        ])
            ->assertActionVisible(TestAction::make('edit')->table($pattern))
            ->assertActionHidden(TestAction::make('view')->table($pattern))
            ->assertActionDoesNotExist(TestAction::make('open')->table($pattern))
            ->assertActionHasUrl(
                TestAction::make('edit')->table($pattern),
                RoutePatternResource::getUrl(
                    'edit',
                    [
                        'route' => $route->getKey(),
                        'record' => $pattern->getKey(),
                    ],
                    panel: 'admin',
                    tenant: $company,
                ),
            );
    })->with([
        'system admin' => UserRole::SuperAdmin->value,
        'company admin with permissions' => UserRole::Admin->value,
        'custom role with permissions' => 'route-editor',
    ]);

    test('links a viewing user to the full pattern viewing page', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $actor = createUserWithRole('route-viewer', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Route',
            'View:Route',
            'ViewAny:RoutePattern',
            'View:RoutePattern',
        ], $company);

        actingAsInCompany($actor, $company);

        Livewire::test(PatternsRelationManager::class, [
            'ownerRecord' => $route,
            'pageClass' => ViewRoute::class,
        ])
            ->assertActionVisible(TestAction::make('view')->table($pattern))
            ->assertActionHidden(TestAction::make('edit')->table($pattern))
            ->assertActionDoesNotExist(TestAction::make('open')->table($pattern))
            ->assertActionHasUrl(
                TestAction::make('view')->table($pattern),
                RoutePatternResource::getUrl(
                    'view',
                    [
                        'route' => $route->getKey(),
                        'record' => $pattern->getKey(),
                    ],
                    panel: 'admin',
                    tenant: $company,
                ),
            );
    });

    test('hides the full-page link without viewing or editing permission', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $actor = createUserWithRole('route-list-reader', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Route',
            'View:Route',
            'ViewAny:RoutePattern',
        ], $company);

        actingAsInCompany($actor, $company);

        Livewire::test(PatternsRelationManager::class, [
            'ownerRecord' => $route,
            'pageClass' => ViewRoute::class,
        ])
            ->assertActionHidden(TestAction::make('edit')->table($pattern))
            ->assertActionHidden(TestAction::make('view')->table($pattern))
            ->assertActionDoesNotExist(TestAction::make('open')->table($pattern));
    });
});
