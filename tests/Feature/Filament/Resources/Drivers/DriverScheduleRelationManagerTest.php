<?php

use App\Enums\UserRole;
use App\Filament\Resources\Drivers\Pages\EditDriver;
use App\Filament\Resources\Drivers\Pages\ViewDriver;
use App\Filament\Resources\Drivers\RelationManagers\SchedulesRelationManager;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\Trip;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Driver Schedule Relation Manager', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany(['timezone' => 'America/Costa_Rica']);
        $this->driver = Driver::factory()->for($this->company)->active()->create();
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->actor->getKey(),
        ]);
        grantShield($this->actor, [
            'ViewAny:Driver', 'View:Driver', 'Update:Driver',
            'ViewAny:DriverSchedule', 'View:DriverSchedule', 'Create:DriverSchedule',
            'Update:DriverSchedule', 'Delete:DriverSchedule',
        ], $this->company);
        actingAsInCompany($this->actor, $this->company);
    });

    test('creates updates and deletes availability through the schedule service', function (): void {
        $component = Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->driver,
            'pageClass' => EditDriver::class,
        ])->callAction(TestAction::make('create')->table(), [
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '16:00',
            'ends_next_day' => false,
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-30',
        ])->assertHasNoFormErrors()->assertNotified();

        $schedule = DriverSchedule::query()->where('driver_id', $this->driver->getKey())->sole();
        expect($schedule->start_time)->toBe('08:00:00')
            ->and($schedule->end_time)->toBe('16:00:00')
            ->and($schedule->valid_from?->toDateString())->toBe('2026-11-02')
            ->and($schedule->valid_until?->toDateString())->toBe('2026-11-30');

        $component->callAction(TestAction::make('edit')->table($schedule), [
            'day_of_week' => 1,
            'start_time' => '22:00',
            'end_time' => '06:00',
            'ends_next_day' => true,
            'valid_from' => null,
            'valid_until' => null,
        ])->assertHasNoFormErrors()->assertNotified();

        expect($schedule->fresh()->start_time)->toBe('22:00:00')
            ->and($schedule->fresh()->end_time)->toBe('06:00:00')
            ->and($schedule->fresh()->ends_next_day)->toBeTrue();

        $component->callAction(TestAction::make('delete')->table($schedule->fresh()))->assertNotified();

        $this->assertModelMissing($schedule);
    });

    test('supports a complete local day and rejects overlapping availability', function (): void {
        DriverSchedule::factory()->for($this->driver)->fullDay()->create([
            'day_of_week' => 1,
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-11-30',
        ]);

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->driver,
            'pageClass' => EditDriver::class,
        ])->callAction(TestAction::make('create')->table(), [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'ends_next_day' => false,
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-02',
        ])->assertHasFormErrors([
            'schedule' => 'This availability overlaps an existing schedule for the driver.',
        ]);

        expect($this->driver->schedules()->count())->toBe(1);
    });

    test('preserves availability required by a committed trip', function (): void {
        $schedule = DriverSchedule::factory()->for($this->driver)->create([
            'day_of_week' => 1,
            'start_time' => '00:00:00',
            'end_time' => '00:00:00',
            'ends_next_day' => true,
            'valid_from' => '2026-10-26',
            'valid_until' => '2026-10-26',
        ]);
        Trip::factory()->for($this->company)->for($this->driver, 'driver')->create([
            'service_date' => '2026-10-26',
        ]);

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->driver,
            'pageClass' => EditDriver::class,
        ])->callAction(TestAction::make('delete')->table($schedule))->assertNotified();

        $this->assertModelExists($schedule);
    });

    test('shows archived availability as read only historical information', function (): void {
        $archivedDriver = Driver::factory()->for($this->company)->archived()->create();
        $schedule = DriverSchedule::factory()->for($archivedDriver)->overnight()->create();

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $archivedDriver,
            'pageClass' => ViewDriver::class,
        ])->assertCanSeeTableRecords([$schedule])
            ->assertActionHidden(TestAction::make('create')->table())
            ->assertActionHidden(TestAction::make('edit')->table($schedule))
            ->assertActionHidden(TestAction::make('delete')->table($schedule));
    });
});
