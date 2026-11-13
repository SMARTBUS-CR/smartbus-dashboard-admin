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
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Route Pattern Stop Relation Manager Updates', function (): void {
    test('updates the stop and estimate while preserving its pattern and position', function (string $role, bool $shared): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $originalStop = Stop::factory()->for($company)->create();

        $replacementStop = Stop::factory()->create([
            'company_id' => $shared ? null : $company->getKey(),
        ]);

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $originalStop->getKey(),
            'stop_sequence' => 3,
            'minutes_from_start' => 10,
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
                'Update:RoutePattern',
            ], $company);
        }

        actingAsInCompany($actor, $company);

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('edit')->table($occurrence), [
                'stop_id' => $replacementStop->getKey(),
                'minutes_from_start' => 20,
            ])
            ->assertNotified();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $replacementStop->getKey(),
            'stop_sequence' => 3,
            'minutes_from_start' => 20,
        ]);
    })->with([
        'system admin private stop' => [UserRole::SuperAdmin->value, false],
        'system admin shared stop' => [UserRole::SuperAdmin->value, true],
        'company admin' => [UserRole::Admin->value, false],
        'custom role' => ['route-editor', false],
    ]);

    test('preserves all occurrence values when an ordering error rejects the update', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $originalStop = Stop::factory()->for($company)->create();
        $replacementStop = Stop::factory()->for($company)->create();

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $originalStop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 10,
        ]);

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $originalStop->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 20,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('edit')->table($occurrence), [
                'stop_id' => $replacementStop->getKey(),
                'minutes_from_start' => 5,
            ])
            ->assertHasFormErrors(['minutes_from_start']);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'stop_id' => $originalStop->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 20,
        ]);
    });

    test('rejects a direct editing attempt without permission to update the pattern', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 10,
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

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->call('mountAction', 'edit', [], [
                'table' => true,
                'recordKey' => $occurrence->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($occurrence->fresh()->minutes_from_start)->toBe(10);
    });

    test('does not resolve an occurrence from another pattern', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $pattern = RoutePattern::factory()->for($route)->create();
        $otherPattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $foreignOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $otherPattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 10,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->call('mountAction', 'edit', [], [
                'table' => true,
                'recordKey' => $foreignOccurrence->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($foreignOccurrence->fresh()->minutes_from_start)->toBe(10);
    });

    test('rejects editing from the read-only page even for a system admin', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 10,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->call('mountAction', 'edit', [], [
                'table' => true,
                'recordKey' => $occurrence->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($occurrence->fresh()->minutes_from_start)->toBe(10);
    });
});
