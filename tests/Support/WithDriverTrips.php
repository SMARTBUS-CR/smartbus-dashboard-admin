<?php

namespace Tests\Support;

use App\Models\DriverSchedule;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

trait WithDriverTrips
{
    use WithDriverRegistration;

    /** @return array{User, Trip} */
    protected function createAssignedDriverTrip(string $status = 'scheduled', string $departure = '06:30:00', string $arrival = '2026-10-26T07:30:00-06:00'): array
    {
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:30:00Z'));
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, [
            'Create:Trip', 'Update:Trip', 'Start:Trip', 'Complete:Trip', 'Cancel:Trip',
            'Update:Driver', 'Delete:Driver', 'Restore:Driver', 'View:Driver',
            'Create:DriverSchedule', 'Update:DriverSchedule', 'Delete:DriverSchedule',
        ], $company);
        $fixture = Trip::factory()->for($company)->withDriverAvailability()->make();
        $fixture->driver->update(['employment_starts_on' => '2026-10-01']);
        $fixture->schedule->update(['departure_time' => $departure]);
        $end = CarbonImmutable::parse($arrival)->setTimezone($company->timezone);
        for ($day = CarbonImmutable::parse('2026-10-27', $company->timezone); $day < $end; $day = $day->addDay()) {
            DriverSchedule::factory()->for($fixture->driver)->fullDay()->create([
                'start_time' => '00:00', 'end_time' => '00:00',
                'day_of_week' => $day->dayOfWeek, 'valid_from' => $day->toDateString(), 'valid_until' => $day->toDateString(),
            ]);
        }
        $trip = app(TripManagementService::class)->schedule($actor, $company, [
            ...$fixture->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
            'service_date' => '2026-10-26', 'arrival_at' => $arrival,
        ]);

        if ($status !== 'scheduled') {
            $this->setTripFixtureStatus($actor, $trip, $status);
        }

        return [$actor, $trip->refresh()];
    }

    /** Exercise Contributor A's real execution lifecycle for history fixtures. */
    protected function setTripFixtureStatus(User $actor, Trip $trip, string $status): void
    {
        $service = app(TripManagementService::class);
        $previousInstant = CarbonImmutable::getTestNow();

        try {
            $this->travelTo($trip->scheduled_departure_at);
            if ($status === 'cancelled') {
                $service->cancel($actor, $trip->company, $trip->id, 'Historical test fixture');
            } else {
                $service->start($actor, $trip->company, $trip->id);
                if ($status === 'completed') {
                    $this->travelTo($trip->scheduled_arrival_at);
                    $service->complete($actor, $trip->company, $trip->id);
                }
            }
        } finally {
            if ($previousInstant !== null) {
                $this->travelTo($previousInstant);
            } else {
                $this->travelBack();
            }
        }
        $trip->refresh();
    }
}
