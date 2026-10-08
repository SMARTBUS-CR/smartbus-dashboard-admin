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

describe('Route Pattern Schedule Relation Manager Resumption', function (): void {
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

        $this->suspension = RouteScheduleException::factory()->create([
            'route_schedule_id' => $this->schedule->getKey(),
            'service_date' => '2026-11-02',
        ]);

        $this->remainingSuspension = RouteScheduleException::factory()->create([
            'route_schedule_id' => $this->schedule->getKey(),
            'service_date' => '2026-11-09',
        ]);
    });

    test('restores only the selected suspension for authorized users', function (string $role): void {
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
            ->callAction(TestAction::make('resume')->table($this->schedule), [
                'exception_id' => $this->suspension->getKey(),
            ])
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseMissing(RouteScheduleException::class, [
            'id' => $this->suspension->getKey(),
        ]);

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'id' => $this->remainingSuspension->getKey(),
            'route_schedule_id' => $this->schedule->getKey(),
            'service_date' => '2026-11-09',
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
        )->toBeTrue()
            ->and($this->pattern->schedules()
                ->scheduledOn(CarbonImmutable::parse('2026-11-09'))
                ->whereKey($this->schedule->getKey())->exists())->toBeFalse();
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['route-editor'],
    ]);

    test('requires selecting a suspension', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->callAction(TestAction::make('resume')->table($this->schedule), [
                'exception_id' => null,
            ])
            ->assertHasFormErrors(['exception_id' => 'required']);

        expect($this->schedule->exceptions()->count())->toBe(2);
    });

    test('rejects a suspension belonging to another departure', function (): void {
        $otherSchedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '08:00:00',
            'valid_from' => null,
            'valid_until' => null,
        ]);

        $foreignSuspension = RouteScheduleException::factory()->create([
            'route_schedule_id' => $otherSchedule->getKey(),
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
            ->callAction(TestAction::make('resume')->table($this->schedule), [
                'exception_id' => $foreignSuspension->getKey(),
            ])
            ->assertHasFormErrors(['exception_id' => 'in']);

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'id' => $foreignSuspension->getKey(),
            'route_schedule_id' => $otherSchedule->getKey(),
        ]);

        expect($this->schedule->exceptions()->count())->toBe(2);
    });

    test('rejects resumption without permission to update the pattern', function (): void {
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
            ->call('mountAction', 'resume', [], [
                'table' => true,
                'recordKey' => $this->schedule->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($this->schedule->exceptions()->count())->toBe(2);
    });

    test('rejects resumption from the read-only page even for a system admin', function (): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->call('mountAction', 'resume', [], [
                'table' => true,
                'recordKey' => $this->schedule->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($this->schedule->exceptions()->count())->toBe(2);
    });

    test('does not resume a departure from another pattern', function (): void {
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
            ->call('mountAction', 'resume', [], [
                'table' => true,
                'recordKey' => $foreignSchedule->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'id' => $foreignSuspension->getKey(),
            'route_schedule_id' => $foreignSchedule->getKey(),
            'service_date' => '2026-11-02',
        ]);

        expect($this->schedule->exceptions()->count())->toBe(2);
    });
});
