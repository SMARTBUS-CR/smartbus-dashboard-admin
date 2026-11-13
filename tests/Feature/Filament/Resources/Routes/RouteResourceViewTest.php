<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Pages\ListRoutes;
use App\Filament\Resources\Routes\Pages\ViewRoute;
use App\Filament\Resources\Routes\RouteResource;
use App\Models\CompanyUser;
use App\Models\Route;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Route Resource Viewing', function (): void {
    test('provides a view action and readable details to a user without editing permission', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create([
            'code' => 'R-101',
            'name' => 'San Jose - Heredia',
        ]);

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

        Livewire::test(ListRoutes::class)
            ->assertActionVisible(TestAction::make('view')->table($route))
            ->assertActionHidden(TestAction::make('edit')->table($route));

        Livewire::test(ViewRoute::class, [
            'record' => $route->getKey(),
        ])
            ->assertSuccessful()
            ->assertSee('R-101')
            ->assertSee('San Jose - Heredia')
            ->assertActionHidden('edit');
    });

    test('denies viewing a route when the user only has listing permission', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $actor = createUserWithRole('route-list-reader', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['ViewAny:Route'], $company);

        actingAsInCompany($actor, $company);

        Livewire::test(ListRoutes::class)
            ->assertSuccessful()
            ->assertActionHidden(TestAction::make('view')->table($route));

        Livewire::test(ViewRoute::class, [
            'record' => $route->getKey(),
        ])->assertForbidden();
    });

    test('does not resolve a foreign company route on the view page', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $foreignRoute = Route::factory()->for($otherCompany)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(ViewRoute::class, [
            'record' => $foreignRoute->getKey(),
        ])->assertStatus(404);
    });

    test('opens route editing and hides the redundant view action for an editor', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $page = Livewire::test(ListRoutes::class)
            ->assertActionVisible(TestAction::make('edit')->table($route))
            ->assertActionHidden(TestAction::make('view')->table($route));

        expect($page->instance()->getTable()->getRecordUrl($route))
            ->toBe(RouteResource::getUrl(
                'edit',
                ['record' => $route->getRouteKey()],
                panel: 'admin',
                tenant: $company,
            ));
    });
});
