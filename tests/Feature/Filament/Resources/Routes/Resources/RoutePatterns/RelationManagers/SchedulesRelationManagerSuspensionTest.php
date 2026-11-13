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
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Route Pattern Schedule Relation Manager Suspension', function (): void {
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

    test('suspends only the selected departure date for authorized users', function (string $role): void {
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
            ->callAction(TestAction::make('suspend')->table($this->schedule), [
                'service_date' => '2026-11-02',
            ])
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'route_schedule_id' => $this->schedule->getKey(),
            'service_date' => '2026-11-02',
        ]);

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $this->schedule->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
        ]);

        expect(
            $this->pattern->schedules()
                ->scheduledOn(CarbonImmutable::parse('2026-11-02'))
                ->whereKey($this->schedule->getKey())
                ->exists(),
        )->toBeFalse()
            ->and($this->pattern->schedules()
                ->scheduledOn(CarbonImmutable::parse('2026-11-09'))
                ->whereKey($this->schedule->getKey())->exists())->toBeTrue();
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['route-editor'],
    ]);

    test('rejects dates when the departure is not scheduled', function (string $date): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('suspend')->table($this->schedule), [
                'service_date' => $date,
            ])
            ->assertHasFormErrors([
                'service_date' => __(
                    'The departure is not scheduled on the selected date.',
                ),
            ]);

        expect($this->schedule->exceptions()->count())->toBe(0);
    })->with([
        'different weekday' => ['2026-11-03'],
        'before the validity period' => ['2026-09-28'],
        'after the validity period' => ['2027-01-04'],
    ]);

    test('requires a valid suspension date', function (?string $date, string $rule): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('suspend')->table($this->schedule), [
                'service_date' => $date,
            ])
            ->assertHasFormErrors(['service_date' => $rule]);

        expect($this->schedule->exceptions()->count())->toBe(0);
    })->with([
        'missing date' => [null, 'required'],
        'invalid date' => ['not-a-date', 'date'],
    ]);

    test('rejects suspension without permission to update the pattern', function (): void {
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
            ->call('mountAction', 'suspend', [], [
                'table' => true,
                'recordKey' => $this->schedule->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($this->schedule->exceptions()->count())->toBe(0);
    });

    test('rejects suspension from the read-only page even for a system admin', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->call('mountAction', 'suspend', [], [
                'table' => true,
                'recordKey' => $this->schedule->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($this->schedule->exceptions()->count())->toBe(0);
    });

    test('does not suspend a departure from another pattern', function (): void {
        $otherPattern = RoutePattern::factory()->for($this->route)->create();

        $foreignSchedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $otherPattern->getKey(),
            'day_of_week' => 1,
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
            ->call('mountAction', 'suspend', [], [
                'table' => true,
                'recordKey' => $foreignSchedule->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($foreignSchedule->exceptions()->count())->toBe(0)
            ->and($this->schedule->exceptions()->count())->toBe(0);
    });

    test('rejects a duplicate suspension with a form error', function (): void {
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
            ->callAction(TestAction::make('suspend')->table($this->schedule), [
                'service_date' => '2026-11-02',
            ])
            ->assertHasFormErrors(['service_date' => 'unique']);

        expect($this->schedule->exceptions()->count())->toBe(1);

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'id' => $suspension->getKey(),
            'route_schedule_id' => $this->schedule->getKey(),
            'service_date' => '2026-11-02',
        ]);
    });
});
