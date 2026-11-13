<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\ViewRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers\SchedulesRelationManager;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\RouteScheduleException;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Route Pattern Schedule Relation Manager Suspension View', function (): void {
    beforeEach(function (): void {
        app()->setLocale('en');

        $this->company = createCompany();
        $this->route = Route::factory()->for($this->company)->create();
        $this->pattern = RoutePattern::factory()->for($this->route)->create();

        $this->schedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => null,
            'valid_until' => null,
        ]);
    });

    test('shows only the selected departure suspensions to users with view permission', function (string $role): void {
        $firstSuspension = RouteScheduleException::factory()->create([
            'route_schedule_id' => $this->schedule->getKey(),
            'service_date' => '2026-11-02',
        ]);

        $secondSuspension = RouteScheduleException::factory()->create([
            'route_schedule_id' => $this->schedule->getKey(),
            'service_date' => '2026-11-09',
        ]);

        $otherSchedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '08:00:00',
            'valid_from' => null,
            'valid_until' => null,
        ]);

        $foreignSuspension = RouteScheduleException::factory()->create([
            'route_schedule_id' => $otherSchedule->getKey(),
            'service_date' => '2026-11-16',
        ]);

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
            ], $this->company);
        }

        actingAsInCompany($actor, $this->company);

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->mountAction(
                TestAction::make('viewSuspensions')->table($this->schedule),
            )
            ->assertActionMounted(
                TestAction::make('viewSuspensions')->table($this->schedule),
            )
            ->assertMountedActionModalSee('November 2, 2026')
            ->assertMountedActionModalSee('November 9, 2026')
            ->assertMountedActionModalDontSee('November 16, 2026');

        foreach ([$firstSuspension, $secondSuspension, $foreignSuspension] as $suspension) {
            $this->assertDatabaseHas(RouteScheduleException::class, [
                'id' => $suspension->getKey(),
                'route_schedule_id' => $suspension->route_schedule_id,
                'service_date' => $suspension->service_date->format('Y-m-d'),
            ]);
        }
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role with view permission' => ['route-viewer'],
    ]);

    test('shows an explanation when the departure has no suspensions', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->mountAction(
                TestAction::make('viewSuspensions')->table($this->schedule),
            )
            ->assertActionMounted(
                TestAction::make('viewSuspensions')->table($this->schedule),
            )
            ->assertMountedActionModalSee('No Suspensions');

        expect($this->schedule->exceptions()->count())->toBe(0);
    });

    test('does not open suspensions for a departure from another pattern', function (): void {
        $otherPattern = RoutePattern::factory()->for($this->route)->create();

        $foreignSchedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $otherPattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '08:00:00',
            'valid_from' => null,
            'valid_until' => null,
        ]);

        $foreignSuspension = RouteScheduleException::factory()->create([
            'route_schedule_id' => $foreignSchedule->getKey(),
            'service_date' => '2026-11-16',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->call('mountAction', 'viewSuspensions', [], [
                'table' => true,
                'recordKey' => $foreignSchedule->getKey(),
            ])
            ->assertActionNotMounted();

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'id' => $foreignSuspension->getKey(),
            'route_schedule_id' => $foreignSchedule->getKey(),
            'service_date' => '2026-11-16',
        ]);
    });
});
