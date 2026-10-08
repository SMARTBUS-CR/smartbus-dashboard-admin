<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\ViewRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers\SchedulesRelationManager;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use Livewire\Livewire;

describe('Route Pattern Schedule Relation Manager', function (): void {
    test('lists only the schedules belonging to the selected pattern', function (string $role): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $otherPattern = RoutePattern::factory()->for($route)->create();

        $schedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
        ]);

        $otherSchedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $otherPattern->getKey(),
            'day_of_week' => 2,
            'departure_time' => '08:00:00',
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
                'ViewAny:RoutePattern',
                'View:RoutePattern',
            ], $company);
        }

        actingAsInCompany($actor, $company);

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$schedule])
            ->assertCanNotSeeTableRecords([$otherSchedule]);
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['route-viewer'],
    ]);

    test('rejects a pattern belonging to another company even for a system admin', function (): void {
        $company = createCompany();
        $otherCompany = createCompany();

        $otherRoute = Route::factory()->for($otherCompany)->create();
        $otherPattern = RoutePattern::factory()->for($otherRoute)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $otherPattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->assertForbidden();
    });

    test('rejects access without permission to view the pattern', function (): void {
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
        ], $company);

        actingAsInCompany($actor, $company);

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->assertForbidden();
    });

    test('displays schedule validity dates using the global table format', function (): void {
        app()->setLocale('en');

        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $schedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-10-12',
            'valid_until' => '2026-12-31',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->assertTableColumnFormattedStateSet(
                'valid_from',
                '12 Oct, 2026',
                $schedule,
            )
            ->assertTableColumnFormattedStateSet(
                'valid_until',
                '31 Dec, 2026',
                $schedule,
            );
    });
});
