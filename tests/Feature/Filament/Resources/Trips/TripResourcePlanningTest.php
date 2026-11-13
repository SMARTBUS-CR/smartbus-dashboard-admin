<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Trips\Pages\CreateTrip;
use App\Filament\Resources\Trips\Pages\EditTrip;
use App\Models\Bus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\Trip;
use App\Services\TripManagementService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** @return array{Company, array<string, string>} */
function tripResourcePlanningFixture(): array
{
    Http::preventStrayRequests();
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $fixture = Trip::factory()->for($company)->withEligibleDriver()->make();
    $driver = Driver::query()->findOrFail($fixture->driver_id);
    DriverSchedule::factory()->for($driver)->create([
        'day_of_week' => 1,
        'start_time' => '00:00:00',
        'end_time' => '00:00:00',
        'ends_next_day' => true,
    ]);
    $actor = createUserWithRole(UserRole::Admin, $company);
    CompanyUser::factory()->for($company)->for($actor, 'user')->create();
    grantShield($actor, [
        'ViewAny:Trip', 'View:Trip', 'Create:Trip', 'Update:Trip',
    ], $company);
    actingAsInCompany($actor, $company);

    return [$company, [
        'route_pattern_id' => $fixture->route_pattern_id,
        'route_schedule_id' => $fixture->route_schedule_id,
        'bus_id' => $fixture->bus_id,
        'driver_id' => $fixture->driver_id,
        'service_date' => '2026-10-26',
        'arrival_at' => '2026-10-26 07:30:00',
    ]];
}

describe('Trip Resource Planning', function (): void {
    test('schedules a trip through the canonical planning service', function (): void {
        [$company, $data] = tripResourcePlanningFixture();

        Livewire::test(CreateTrip::class)
            ->fillForm($data)
            ->set('data.company_id', createCompany()->getKey())
            ->set('data.status', TripStatus::COMPLETED->value)
            ->set('data.actual_departure_at', now()->toIso8601String())
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertRedirect();

        $trip = Trip::query()->sole();

        expect($trip->company_id)->toBe($company->getKey())
            ->and($trip->status)->toBe(TripStatus::SCHEDULED)
            ->and($trip->actual_departure_at)->toBeNull()
            ->and($trip->scheduled_departure_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-26 12:30:00')
            ->and($trip->scheduled_arrival_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-26 13:30:00')
            ->and($trip->planned_route_snapshot['pattern_id'])->toBe($data['route_pattern_id']);
        Http::assertNothingSent();
    });

    test('maps planning validation errors to the corresponding form field', function (): void {
        [, $data] = tripResourcePlanningFixture();
        $data['service_date'] = '2026-10-27';

        Livewire::test(CreateTrip::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasFormErrors(['service_date']);

        $this->assertDatabaseCount('trips', 0);
    });

    test('replans only trusted fields while preserving trip identity and execution state', function (): void {
        [$company, $data] = tripResourcePlanningFixture();
        $trip = app(TripManagementService::class)->schedule(auth()->user(), $company, [
            ...$data,
            'arrival_at' => '2026-10-26T07:30:00-06:00',
        ]);
        $createdAt = $trip->getRawOriginal('created_at');
        $data['service_date'] = '2026-11-02';
        $data['arrival_at'] = '2026-11-02 07:45:00';

        Livewire::test(EditTrip::class, ['record' => $trip->getKey()])
            ->fillForm($data)
            ->set('data.company_id', createCompany()->getKey())
            ->set('data.status', TripStatus::CANCELLED->value)
            ->set('data.cancellation_reason', 'Forged')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $trip->refresh();

        expect($trip->company_id)->toBe($company->getKey())
            ->and($trip->status)->toBe(TripStatus::SCHEDULED)
            ->and($trip->cancellation_reason)->toBeNull()
            ->and($trip->service_date->toDateString())->toBe('2026-11-02')
            ->and($trip->scheduled_arrival_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-02 13:45:00')
            ->and($trip->getRawOriginal('created_at'))->toBe($createdAt);
    });

    test('reassigns the bus without changing the scheduled trip times', function (): void {
        [$company, $data] = tripResourcePlanningFixture();
        $trip = app(TripManagementService::class)->schedule(auth()->user(), $company, [
            ...$data,
            'arrival_at' => '2026-10-26T07:30:00-06:00',
        ]);
        $newBus = Bus::factory()->for($company)->active()->create();

        Livewire::test(EditTrip::class, ['record' => $trip->getKey()])
            ->assertFormSet(['bus_id' => $trip->bus_id])
            ->fillForm(['bus_id' => $newBus->getKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $replanned = $trip->fresh();

        expect($replanned->bus_id)->toBe($newBus->getKey())
            ->and($replanned->driver_id)->toBe($trip->driver_id)
            ->and($replanned->status)->toBe(TripStatus::SCHEDULED)
            ->and($replanned->scheduled_departure_at->equalTo($trip->scheduled_departure_at))->toBeTrue()
            ->and($replanned->scheduled_arrival_at->equalTo($trip->scheduled_arrival_at))->toBeTrue();
    });

    test('preserves the scheduled plan when replanning is rejected', function (): void {
        [$company, $data] = tripResourcePlanningFixture();
        $trip = app(TripManagementService::class)->schedule(auth()->user(), $company, [
            ...$data,
            'arrival_at' => '2026-10-26T07:30:00-06:00',
        ]);
        $before = $trip->getRawOriginal();
        $data['arrival_at'] = '2026-10-26 06:00:00';

        Livewire::test(EditTrip::class, ['record' => $trip->getKey()])
            ->fillForm($data)
            ->call('save')
            ->assertHasFormErrors(['arrival_at']);

        expect($trip->fresh()->getRawOriginal())->toBe($before);
    });
});
