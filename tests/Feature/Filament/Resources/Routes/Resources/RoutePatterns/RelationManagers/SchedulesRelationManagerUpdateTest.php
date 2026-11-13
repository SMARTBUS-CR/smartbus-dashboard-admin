<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\ViewRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers\SchedulesRelationManager;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\RouteScheduleException;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Route Pattern Schedule Relation Manager Update', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany();
        $this->route = Route::factory()->for($this->company)->create();
        $this->pattern = RoutePattern::factory()->for($this->route)->create();

        $this->schedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);
    });

    test('updates a departure for authorized users', function (string $role): void {
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

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('edit')->table($this->schedule), [
                'day_of_week' => 2,
                'departure_time' => '07:45:00',
                'valid_from' => '2026-11-01',
                'valid_until' => '2027-01-31',
            ])
            ->assertNotified();

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $this->schedule->getKey(),
            'route_pattern_id' => $this->pattern->getKey(),
            'day_of_week' => 2,
            'departure_time' => '07:45:00',
            'valid_from' => '2026-11-01',
            'valid_until' => '2027-01-31',
        ]);

        expect($this->pattern->schedules()->count())->toBe(1);
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['route-editor'],
    ]);

    test('allows saving a departure without changing its existing values', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->mountAction(TestAction::make('edit')->table($this->schedule))
            ->assertActionMounted(
                TestAction::make('edit')->table($this->schedule),
            )
            ->assertSchemaStateSet([
                'day_of_week' => 1,
                'valid_from' => '2026-10-01',
                'valid_until' => '2026-12-31',
            ])
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $this->schedule->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);

        expect($this->pattern->schedules()->count())->toBe(1);
    });

    test('rejects changes that overlap another departure and preserves both schedules', function (): void {
        $otherSchedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '08:00:00',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('edit')->table($this->schedule), [
                'day_of_week' => 1,
                'departure_time' => '08:00:00',
                'valid_from' => '2026-10-15',
                'valid_until' => '2026-11-15',
            ])
            ->assertHasFormErrors([
                'departure_time' => __(
                    'A departure already exists for this day and time during the selected validity period.',
                ),
            ]);

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $this->schedule->getKey(),
            'departure_time' => '06:30:00',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $otherSchedule->getKey(),
            'departure_time' => '08:00:00',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);

        expect($this->pattern->schedules()->count())->toBe(2);
    });

    test('rejects editing without permission to update the pattern', function (): void {
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

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->call('mountAction', 'edit', [], [
                'table' => true,
                'recordKey' => $this->schedule->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $this->schedule->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);
    });

    test('rejects editing from the read-only page even for a system admin', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->call('mountAction', 'edit', [], [
                'table' => true,
                'recordKey' => $this->schedule->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $this->schedule->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);
    });

    test('does not allow editing a departure from another pattern', function (): void {
        $otherPattern = RoutePattern::factory()->for($this->route)->create();

        $foreignSchedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $otherPattern->getKey(),
            'day_of_week' => 2,
            'departure_time' => '08:00:00',
            'valid_from' => null,
            'valid_until' => null,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->call('mountAction', 'edit', [], [
                'table' => true,
                'recordKey' => $foreignSchedule->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $foreignSchedule->getKey(),
            'route_pattern_id' => $otherPattern->getKey(),
            'day_of_week' => 2,
            'departure_time' => '08:00:00',
            'valid_from' => null,
            'valid_until' => null,
        ]);
    });

    test('rejects calendar changes that invalidate an existing suspension', function (array $overrides): void {
        $suspension = RouteScheduleException::factory()->create([
            'route_schedule_id' => $this->schedule->getKey(),
            'service_date' => '2026-11-02',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(
                TestAction::make('edit')->table($this->schedule),
                array_replace([
                    'day_of_week' => 1,
                    'departure_time' => '06:30:00',
                    'valid_from' => '2026-10-01',
                    'valid_until' => '2026-12-31',
                ], $overrides),
            )
            ->assertHasFormErrors([
                'day_of_week' => __(
                    'The calendar change would invalidate an existing suspension.',
                ),
            ]);

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $this->schedule->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'id' => $suspension->getKey(),
            'route_schedule_id' => $this->schedule->getKey(),
            'service_date' => '2026-11-02',
        ]);
    })->with([
        'different weekday' => [
            ['day_of_week' => 2],
        ],
        'validity starts after the suspension' => [
            ['valid_from' => '2026-11-03'],
        ],
        'validity ends before the suspension' => [
            ['valid_until' => '2026-11-01'],
        ],
    ]);
});
