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

describe('Route Pattern Stop Relation Manager Reordering', function (): void {
    beforeEach(function (): void {
        app()->setLocale('en');

        $this->company = createCompany();
        $this->route = Route::factory()->for($this->company)->create();
        $this->pattern = RoutePattern::factory()->for($this->route)->create();
        $this->stop = Stop::factory()->for($this->company)->create();

        $this->firstOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'stop_id' => $this->stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $this->secondOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'stop_id' => $this->stop->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 10,
        ]);
    });

    test('reorders appearances and clears estimated times for authorized users', function (string $role): void {
        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $this->company);

            CompanyUser::create([
                'company_id' => $this->company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            grantShield($actor, [
                'ViewAny:Route',
                'View:Route',
                'ViewAny:RoutePattern',
                'View:RoutePattern',
                'Update:RoutePattern',
            ], $this->company);
        }

        actingAsInCompany($actor, $this->company);

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->call('reorderTable', [
                $this->secondOccurrence->getKey(),
                $this->firstOccurrence->getKey(),
            ])
            ->assertNotified('Stops Reordered')
            ->assertCanSeeTableRecords([
                $this->secondOccurrence,
                $this->firstOccurrence,
            ], inOrder: true);

        expect($this->pattern->stopOccurrences()->get()->modelKeys())
            ->toBe([
                $this->secondOccurrence->getKey(),
                $this->firstOccurrence->getKey(),
            ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $this->secondOccurrence->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => null,
        ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $this->firstOccurrence->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => null,
        ]);
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['route-editor'],
    ]);

    test('rejects reordering without permission to update the pattern', function (): void {
        $actor = createUserWithRole('route-viewer', $this->company);

        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Route',
            'View:Route',
            'ViewAny:RoutePattern',
            'View:RoutePattern',
        ], $this->company);

        actingAsInCompany($actor, $this->company);

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->call('reorderTable', [
                $this->secondOccurrence->getKey(),
                $this->firstOccurrence->getKey(),
            ])
            ->assertForbidden();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $this->firstOccurrence->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $this->secondOccurrence->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 10,
        ]);
    });

    test('rejects reordering from the read-only page even for a system admin', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->call('reorderTable', [
                $this->secondOccurrence->getKey(),
                $this->firstOccurrence->getKey(),
            ])
            ->assertForbidden();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $this->firstOccurrence->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $this->secondOccurrence->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 10,
        ]);
    });

    test('rejects an appearance from another pattern without changing either pattern', function (): void {
        $otherPattern = RoutePattern::factory()->for($this->route)->create();

        $foreignOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $otherPattern->getKey(),
            'stop_id' => $this->stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->call('reorderTable', [
                $this->firstOccurrence->getKey(),
                $foreignOccurrence->getKey(),
            ])
            ->assertNotified('Could Not Reorder Stops');

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $this->firstOccurrence->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $this->secondOccurrence->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 10,
        ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $foreignOccurrence->getKey(),
            'route_pattern_id' => $otherPattern->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);
    });

    test('explains that changing stop order clears estimated times before reordering', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->assertSee(
                'Reordering stops clears all estimated minutes from departure and invalidates the calculated route. Arrange the stops first, then enter their estimated times and calculate the route again.',
            );
    });
});
