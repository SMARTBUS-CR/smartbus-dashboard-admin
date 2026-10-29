<?php

use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\Stop;
use Livewire\Livewire;

describe('Route Pattern Endpoint Authorization', function (): void {
    test('rejects editing endpoints without permission to update the pattern', function (): void {
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

        Livewire::test(EditRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->assertForbidden();

        expect($pattern->stopOccurrences()->count())->toBe(0);
    });

    test('rejects creating endpoint stops without stop creation permission and preserves the pattern', function (): void {
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
            'ViewAny:RoutePattern',
            'View:RoutePattern',
            'Update:RoutePattern',
        ], $company);

        actingAsInCompany($actor, $company);

        Livewire::test(EditRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->set('data.name', 'Unauthorized Change')
            ->set('data.origin_search', json_encode([
                'coordinate' => [
                    'lat' => 10.4523456,
                    'lng' => -84.0123456,
                ],
                'name' => 'Central Terminal',
                'display_name' => 'Central Terminal, Costa Rica',
            ]))
            ->set('data.destination_search', json_encode([
                'coordinate' => [
                    'lat' => 10.4623456,
                    'lng' => -84.0223456,
                ],
                'name' => 'North Terminal',
                'display_name' => 'North Terminal, Costa Rica',
            ]))
            ->call('save')
            ->assertForbidden();

        expect($pattern->fresh()->name)->toBe('Original Pattern')
            ->and($pattern->stopOccurrences()->count())->toBe(0)
            ->and(Stop::query()->count())->toBe(0);
    });
});
