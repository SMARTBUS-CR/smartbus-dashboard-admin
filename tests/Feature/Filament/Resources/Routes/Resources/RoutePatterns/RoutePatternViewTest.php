<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\ViewRoutePattern;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use Livewire\Livewire;

describe('Route Pattern Viewing', function (): void {
    test('shows readable pattern details without offering editing to a viewing role', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $pattern = RoutePattern::factory()->for($route)->create([
            'code' => 'OUTBOUND',
            'name' => 'Via Central Market',
            'headsign' => 'Heredia Terminal',
        ]);

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

        Livewire::test(ViewRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->assertSuccessful()
            ->assertActionHidden('edit')
            ->assertSee('OUTBOUND')
            ->assertSee('Via Central Market')
            ->assertSee('Heredia Terminal');
    });

    test('rejects opening pattern details without the viewing permission', function (): void {
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

        Livewire::test(ViewRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->assertForbidden();
    });

    test('does not resolve pattern details from another owner route', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $otherRoute = Route::factory()->for($company)->create();
        $foreignPattern = RoutePattern::factory()->for($otherRoute)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(ViewRoutePattern::class, [
            'record' => $foreignPattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->assertNotFound();
    });
});
