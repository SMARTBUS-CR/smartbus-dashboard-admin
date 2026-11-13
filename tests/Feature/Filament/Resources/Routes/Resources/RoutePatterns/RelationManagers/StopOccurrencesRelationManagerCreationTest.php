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
use Filament\Forms\Components\Select;
use Livewire\Livewire;

describe('Route Pattern Stop Relation Manager Creation', function (): void {
    test('appends a repeated stop using the next sequence position', function (string $role, bool $shared): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $stop = Stop::factory()->create([
            'company_id' => $shared ? null : $company->getKey(),
        ]);

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
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
            ->callAction(TestAction::make('create')->table(), [
                'stop_id' => $stop->getKey(),
                'minutes_from_start' => 20,
            ])
            ->assertNotified();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 4,
            'minutes_from_start' => 20,
        ]);

        expect($pattern->stopOccurrences()->count())->toBe(2);
    })->with([
        'system admin private stop' => [UserRole::SuperAdmin->value, false],
        'system admin shared stop' => [UserRole::SuperAdmin->value, true],
        'company admin' => [UserRole::Admin->value, false],
        'custom role' => ['route-editor', false],
    ]);

    test('searches only active private and shared stops available to the company', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $ownStop = Stop::factory()->for($company)->create([
            'name' => 'Market - Private',
        ]);

        $sharedStop = Stop::factory()->shared()->create([
            'name' => 'Market - Shared',
        ]);

        $foreignStop = Stop::factory()->for($otherCompany)->create([
            'name' => 'Market - Foreign',
        ]);

        $archivedStop = Stop::factory()->for($company)->create([
            'name' => 'Market - Archived',
        ]);

        $archivedStop->delete();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->mountAction(TestAction::make('create')->table())
            ->assertFormFieldExists(
                'stop_id',
                function (Select $field) use ($ownStop, $sharedStop, $foreignStop, $archivedStop): bool {
                    $results = $field->getSearchResults('Market');

                    return array_key_exists($ownStop->getKey(), $results)
                        && array_key_exists($sharedStop->getKey(), $results)
                        && ! array_key_exists($foreignStop->getKey(), $results)
                        && ! array_key_exists($archivedStop->getKey(), $results);
                },
            );
    });

    test('rejects a forged unavailable stop identifier', function (bool $foreign): void {
        [$company, $otherCompany] = createTenantPair();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $stop = Stop::factory()->create([
            'company_id' => $foreign
                ? $otherCompany->getKey()
                : $company->getKey(),
        ]);

        if (! $foreign) {
            $stop->delete();
        }

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'stop_id' => $stop->getKey(),
                'minutes_from_start' => null,
            ])
            ->assertHasFormErrors(['stop_id' => 'in']);

        expect($pattern->stopOccurrences()->exists())->toBeFalse();
    })->with([
        'another company stop' => true,
        'archived stop' => false,
    ]);

    test('rejects adding stops from the read-only page even for a system admin', function (): void {
        $route = Route::factory()->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $route->company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->call('mountAction', 'create', [], ['table' => true])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($pattern->stopOccurrences()->exists())->toBeFalse();
    });
});

describe('Route Pattern Stop Creation Validation', function (): void {
    test('rejects invalid estimates before creating an occurrence', function (mixed $minutes, string $rule): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'stop_id' => $stop->getKey(),
                'minutes_from_start' => $minutes,
            ])
            ->assertHasFormErrors([
                'minutes_from_start' => $rule,
            ]);

        expect($pattern->stopOccurrences()->exists())->toBeFalse();
    })->with([
        'negative estimate' => [-1, 'min'],
        'fractional estimate' => [1.5, 'integer'],
    ]);

    test('shows an ordering error when the new estimate precedes an earlier stop', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $existingOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
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
            ->callAction(TestAction::make('create')->table(), [
                'stop_id' => $stop->getKey(),
                'minutes_from_start' => 10,
            ])
            ->assertHasFormErrors(['minutes_from_start']);

        expect($pattern->stopOccurrences()->count())->toBe(1);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $existingOccurrence->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 20,
        ]);
    });

    test('allows an unknown estimate after a known estimate', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
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
            ->callAction(TestAction::make('create')->table(), [
                'stop_id' => $stop->getKey(),
                'minutes_from_start' => null,
            ])
            ->assertNotified();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'route_pattern_id' => $pattern->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => null,
        ]);
    });

    test('rejects a direct creation attempt without permission to update the pattern', function (): void {
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

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->call('mountAction', 'create', [], ['table' => true])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($pattern->stopOccurrences()->exists())->toBeFalse();
    });
});
