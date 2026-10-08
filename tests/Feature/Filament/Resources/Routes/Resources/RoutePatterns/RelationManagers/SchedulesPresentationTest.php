<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers\SchedulesRelationManager;
use App\Models\Route;
use App\Models\RoutePattern;
use Livewire\Livewire;

describe('Schedule Presentation', function (): void {
    test('explains departure times using a readable company timezone', function (): void {
        app()->setLocale('en');

        $company = createCompany([
            'country_code' => 'CR',
            'timezone' => 'America/Costa_Rica',
        ]);

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->assertSee('Departure times use Costa Rica local time')
            ->assertSee('UTC-06:00');
    });
});