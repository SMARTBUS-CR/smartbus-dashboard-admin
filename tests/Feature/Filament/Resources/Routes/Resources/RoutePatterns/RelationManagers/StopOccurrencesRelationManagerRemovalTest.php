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

describe('Route Pattern Stop Relation Manager Removal', function (): void {
    test('removes only the selected occurrence while preserving the stop and other appearances', function (string $role, bool $shared): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $pattern = RoutePattern::factory()->for($route)->create();
        $otherPattern = RoutePattern::factory()->for($route)->create();

        $stop = Stop::factory()->create([
            'company_id' => $shared ? null : $company->getKey(),
        ]);

        $selectedOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $remainingOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 3,
            'minutes_from_start' => 40,
        ]);

        $otherOccurrence = RoutePatternStop::factory()->create([
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
            ->callAction(
                TestAction::make('delete')->table($selectedOccurrence),
            )
            ->assertNotified()
            ->assertCanNotSeeTableRecords([$selectedOccurrence])
            ->assertCanSeeTableRecords([$remainingOccurrence]);

        $this->assertDatabaseMissing(RoutePatternStop::class, [
            'id' => $selectedOccurrence->getKey(),
        ]);

        $this->assertNotSoftDeleted($stop);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $remainingOccurrence->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 3,
            'minutes_from_start' => 40,
        ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $otherOccurrence->getKey(),
            'route_pattern_id' => $otherPattern->getKey(),
            'stop_id' => $stop->getKey(),
        ]);
    })->with([
        'system admin' => [UserRole::SuperAdmin->value, false],
        'company admin' => [UserRole::Admin->value, false],
        'company admin shared stop' => [UserRole::Admin->value, true],
        'custom role' => ['route-editor', false],
    ]);

    test('rejects removal without permission to update the pattern', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
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
            ->call('mountAction', 'delete', [], [
                'table' => true,
                'recordKey' => $occurrence->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
        ]);
    });

    test('does not remove an occurrence from another pattern', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $pattern = RoutePattern::factory()->for($route)->create();
        $otherPattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $foreignOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $otherPattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->call('mountAction', 'delete', [], [
                'table' => true,
                'recordKey' => $foreignOccurrence->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $foreignOccurrence->getKey(),
            'route_pattern_id' => $otherPattern->getKey(),
        ]);
    });

    test('rejects removal from the read-only page even for a system admin', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(StopOccurrencesRelationManager::class, [
            'ownerRecord' => $pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->call('mountAction', 'delete', [], [
                'table' => true,
                'recordKey' => $occurrence->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
        ]);
    });
});
