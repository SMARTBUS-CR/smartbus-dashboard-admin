<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\ViewRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers\StopOccurrencesRelationManager;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Livewire\Livewire;

describe('Route Pattern Stop Relation Manager Access', function (): void {
    test('lists repeated stop occurrences in sequence order for the owner pattern', function (string $role, string $pageClass): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $pattern = RoutePattern::factory()->for($route)->create();
        $otherPattern = RoutePattern::factory()->for($route)->create();

        $stop = Stop::factory()->for($company)->create([
            'name' => 'Central Market',
        ]);

        $lastOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 3,
            'minutes_from_start' => 40,
        ]);

        $firstOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $foreignOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $otherPattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $company);

            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            $permissions = [
                'ViewAny:Route',
                'View:Route',
                'ViewAny:RoutePattern',
                'View:RoutePattern',
            ];

            if ($pageClass === EditRoutePattern::class) {
                $permissions[] = 'Update:RoutePattern';
            }

            grantShield($actor, $permissions, $company);
        }

        actingAsInCompany($actor, $company);

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => $pageClass,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords(
                [$firstOccurrence, $lastOccurrence],
                inOrder: true,
            )
            ->assertCanNotSeeTableRecords([$foreignOccurrence])
            ->assertSee('Central Market');
    })->with([
        'system admin' => [
            UserRole::SuperAdmin->value,
            EditRoutePattern::class,
        ],
        'company admin with permissions' => [
            UserRole::Admin->value,
            EditRoutePattern::class,
        ],
        'custom role with viewing permissions' => [
            'route-viewer',
            ViewRoutePattern::class,
        ],
    ]);

    test('rejects a pattern from another selected company', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $foreignRoute = Route::factory()->for($otherCompany)->create();
        $foreignPattern = RoutePattern::factory()
            ->for($foreignRoute)
            ->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        expect(StopOccurrencesRelationManager::canViewForRecord(
            $foreignPattern,
            EditRoutePattern::class,
        ))->toBeFalse();
    });

    test('does not expose stop details without permission to view the pattern', function (): void {
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

        expect(StopOccurrencesRelationManager::canViewForRecord(
            $pattern,
            ViewRoutePattern::class,
        ))->toBeFalse();
    });
});
