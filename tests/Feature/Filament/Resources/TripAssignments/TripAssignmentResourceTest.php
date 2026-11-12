<?php

use App\Enums\UserRole;
use App\Filament\Resources\TripAssignments\Pages\CreateTripAssignment;
use App\Filament\Resources\TripAssignments\Pages\ListTripAssignments;
use App\Filament\Resources\TripAssignments\Schemas\TripAssignmentForm;
use App\Filament\Resources\TripAssignments\TripAssignmentResource;
use App\Models\CompanyUser;
use App\Models\DriverSchedule;
use App\Models\RouteSchedule;
use App\Models\Trip;
use App\Models\TripAssignment;
use Livewire\Livewire;

describe('Trip Assignment Resource', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany(['timezone' => 'America/Costa_Rica']);
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::factory()->for($this->company)->for($this->actor, 'user')->create();
        grantShield($this->actor, [
            'ViewAny:TripAssignment', 'View:TripAssignment', 'Create:TripAssignment',
            'Update:TripAssignment', 'Delete:TripAssignment', 'Restore:TripAssignment',
        ], $this->company);
    });

    test('lists only recurring assignments from the selected company', function (): void {
        $own = TripAssignment::factory()->for($this->company)->create();
        $foreign = TripAssignment::factory()->create(['company_id' => createCompany()->id]);
        actingAsInCompany($this->actor, $this->company);

        expect($foreign->company_id)->not->toBe($this->company->id)
            ->and(TripAssignmentResource::getEloquentQuery()->pluck('id')->all())->toBe([$own->id]);

        Livewire::test(ListTripAssignments::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    });

    test('batch creation is atomic when one selected schedule conflicts', function (): void {
        $planned = Trip::factory()->for($this->company)->withDriverAvailability()->make();
        $first = TripAssignment::factory()->for($this->company)->create([
            'route_schedule_id' => $planned->route_schedule_id,
            'driver_id' => $planned->driver_id,
            'bus_id' => $planned->bus_id,
        ]);
        $secondSchedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $first->schedule->route_pattern_id,
            'day_of_week' => 2,
            'departure_time' => '06:30:00',
        ]);
        DriverSchedule::factory()->for($first->driver)->fullDay()->create([
            'day_of_week' => 2,
            'start_time' => '00:00:00',
            'end_time' => '00:00:00',
        ]);
        $before = TripAssignment::query()->count();
        actingAsInCompany($this->actor, $this->company);

        Livewire::test(CreateTripAssignment::class)
            ->fillForm([
                'route_schedule_ids' => [$secondSchedule->id, $first->route_schedule_id],
                'driver_id' => $first->driver_id,
                'bus_id' => $first->bus_id,
                'planned_duration_minutes' => 60,
            ])->call('create')->assertHasFormErrors();

        expect(TripAssignment::query()->count())->toBe($before);
    });

    test('creates different departures from one duration and shows descriptive labels', function (): void {
        $planned = Trip::factory()->for($this->company)->withDriverAvailability()->make();
        $planned->schedule->pattern->route->update(['code' => 'PV-RF']);
        $planned->schedule->pattern->update(['headsign' => 'Towards Rio Frio']);
        $later = RouteSchedule::factory()->create([
            'route_pattern_id' => $planned->route_pattern_id,
            'day_of_week' => 1,
            'departure_time' => '08:00:00',
        ]);
        DriverSchedule::query()->where('driver_id', $planned->driver_id)->delete();
        DriverSchedule::factory()->for($planned->driver)->fullDay()->unbounded()->create([
            'day_of_week' => 1,
            'start_time' => '00:00:00',
            'end_time' => '00:00:00',
        ]);
        actingAsInCompany($this->actor, $this->company);

        expect(TripAssignmentForm::scheduleOptions()[$planned->route_schedule_id])
            ->toBe('Monday · 06:30 · PV-RF · Towards Rio Frio');

        Livewire::test(CreateTripAssignment::class)->fillForm([
            'route_schedule_ids' => [$planned->route_schedule_id, $later->id],
            'driver_id' => $planned->driver_id,
            'bus_id' => $planned->bus_id,
            'planned_duration_minutes' => 60,
        ])->call('create')->assertHasNoFormErrors();

        expect(TripAssignment::query()->orderBy('planned_arrival_time')->pluck('planned_arrival_time')->all())
            ->toBe(['07:30:00', '09:00:00']);
    });

    test('shows service date validation on its form field', function (): void {
        $planned = Trip::factory()->for($this->company)->withDriverAvailability()->make();
        actingAsInCompany($this->actor, $this->company);

        Livewire::test(CreateTripAssignment::class)
            ->fillForm([
                'route_schedule_ids' => [$planned->route_schedule_id],
                'driver_id' => $planned->driver_id,
                'bus_id' => $planned->bus_id,
                'planned_duration_minutes' => 60,
                'valid_from' => '2026-12-01',
                'valid_until' => '2026-11-01',
            ])->call('create')->assertHasFormErrors(['valid_until']);
    });
});
