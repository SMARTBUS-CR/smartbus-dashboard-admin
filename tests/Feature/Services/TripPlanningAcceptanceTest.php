<?php

use App\Enums\CompanyStatus;
use App\Enums\DriverStatus;
use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\RouteScheduleException;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripManagementService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** @return array{User, Company, Trip, array<string, mixed>} */
function persistedPlanningInputs(string $timezone = 'America/Costa_Rica'): array
{
    Http::preventStrayRequests();
    $company = createCompany(['timezone' => $timezone]);
    $trip = Trip::factory()->for($company)->withEligibleDriver()->create()->refresh();
    foreach (range(0, 6) as $day) {
        DriverSchedule::factory()->for($trip->driver)->create([
            'day_of_week' => $day, 'start_time' => '00:00', 'end_time' => '00:00', 'ends_next_day' => true,
        ]);
    }
    $actor = createUserWithRole(UserRole::SuperAdmin);

    return [$actor, $company, $trip, [
        'route_pattern_id' => $trip->route_pattern_id, 'route_schedule_id' => $trip->route_schedule_id,
        'bus_id' => $trip->bus_id, 'driver_id' => $trip->driver_id,
        'service_date' => '2026-11-02', 'arrival_at' => '2026-11-02T07:30:00-06:00',
    ]];
}

/** @param array<string, mixed> $data */
function runPersistedPlanning(string $operation, User $actor, Company $company, Trip $trip, array $data): Trip
{
    $service = app(TripManagementService::class);

    return $operation === 'schedule' ? $service->schedule($actor, $company, $data)
        : $service->replan($actor, $company, $trip->getKey(), $data);
}

test('validates real relationships eligibility and trusted plans atomically in both operations', function (string $scenario, string $field, string $operation): void {
    [$actor, $company, $trip, $data] = persistedPlanningInputs();
    $pattern = $trip->pattern;
    $driver = $trip->driver;
    match ($scenario) {
        'inactive company' => $company->update(['status' => CompanyStatus::INACTIVE]),
        'archived company' => $company->delete(),
        'foreign pattern' => $data['route_pattern_id'] = RoutePattern::factory()->withSavedPlan()->create()->getKey(),
        'foreign schedule' => $data['route_schedule_id'] = RouteSchedule::factory()->create()->getKey(),
        'archived route' => $pattern->route->delete(),
        'archived pattern' => $pattern->delete(),
        'foreign bus' => $data['bus_id'] = Bus::factory()->create()->getKey(),
        'inactive bus' => $trip->bus->forceFill(['is_active' => false])->save(),
        'archived bus' => $trip->bus->delete(),
        'foreign driver' => $data['driver_id'] = Driver::factory()->create()->getKey(),
        'inactive driver' => $driver->update(['status' => DriverStatus::INACTIVE]),
        'archived driver' => $driver->delete(),
        'archived user' => $driver->user->delete(),
        'missing membership' => CompanyUser::query()->where('company_id', $company->getKey())->where('user_id', $driver->user_id)->delete(),
        'missing role' => $driver->user->getConnection()->table('model_has_roles')->where('model_uuid', $driver->user_id)->delete(),
        'employment begins later' => $driver->update(['employment_starts_on' => '2026-11-03']),
        'employment ended' => $driver->update(['employment_ends_on' => '2026-11-01']),
        'license expired' => $driver->update(['license_expires_at' => '2026-11-01']),
        'availability gap' => DriverSchedule::query()->where('driver_id', $driver->getKey())->where('day_of_week', 1)->update(['start_time' => '07:00:00']),
        'calendar suspension' => RouteScheduleException::factory()->create(['route_schedule_id' => $trip->route_schedule_id, 'service_date' => '2026-11-02']),
        'calendar validity' => $trip->schedule->update(['valid_until' => '2026-11-01']),
        'missing geometry' => $pattern->update(['route_geometry' => null, 'distance_meters' => null, 'driving_duration_seconds' => null, 'routing_points_hash' => null]),
        'stale geometry' => $pattern->update(['routing_points_hash' => str_repeat('a', 64)]),
        'one occurrence' => $pattern->stopOccurrences()->orderByDesc('stop_sequence')->firstOrFail()->delete(),
        'archived stop' => Stop::query()->whereKey($pattern->stopOccurrences()->firstOrFail()->stop_id)->update(['deleted_at' => now()]),
    };
    $before = $trip->fresh()->getRawOriginal();
    $team = createCompany()->getKey();
    setPermissionsTeamId($team);

    expect(fn () => runPersistedPlanning($operation, $actor, $company, $trip, $data))
        ->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        });
    expect($trip->fresh()->getRawOriginal())->toBe($before);
    expect(getPermissionsTeamId())->toBe($team);
    $this->assertDatabaseCount('trips', 1);
    Http::assertNothingSent();
})->with([
    ['inactive company', 'company'], ['archived company', 'company'], ['foreign pattern', 'route_pattern_id'],
    ['foreign schedule', 'route_schedule_id'], ['archived route', 'route_pattern_id'], ['archived pattern', 'route_pattern_id'],
    ['foreign bus', 'bus_id'], ['inactive bus', 'bus_id'], ['archived bus', 'bus_id'],
    ['foreign driver', 'driver_id'], ['inactive driver', 'driver_id'], ['archived driver', 'driver_id'],
    ['archived user', 'driver_id'], ['missing membership', 'driver_id'], ['missing role', 'driver_id'],
    ['employment begins later', 'driver_id'], ['employment ended', 'driver_id'], ['license expired', 'driver_id'],
    ['availability gap', 'driver_id'], ['calendar suspension', 'service_date'], ['calendar validity', 'service_date'],
    ['missing geometry', 'route_pattern_id'], ['stale geometry', 'route_pattern_id'],
    ['one occurrence', 'route_pattern_id'], ['archived stop', 'route_pattern_id'],
])->with(['schedule', 'replan']);

test('persists complete saved detours without inferring arrival or contacting routing services', function (string $operation): void {
    [$actor, $company, $trip, $data] = persistedPlanningInputs();
    $trip->pattern->update(['distance_meters' => '900000.00', 'driving_duration_seconds' => '90000.00', 'routing_leg_distances' => null]);

    $result = runPersistedPlanning($operation, $actor, $company, $trip, $data);

    expect($result->planned_route_snapshot['distance_meters'])->toBe('900000.00');
    expect($result->planned_route_snapshot['routing_leg_distances'])->toBeNull();
    expect($result->scheduled_arrival_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-02 13:30:00');
    $this->assertModelExists($result);
    Http::assertNothingSent();
})->with(['schedule', 'replan']);

test('persists resolved DST departures and rejects nonexistent or unresolved local times', function (string $date, string $time, ?string $offset, ?string $utc, string $field, string $operation): void {
    [$actor, $company, $trip, $data] = persistedPlanningInputs('America/New_York');
    $trip->schedule->update(['day_of_week' => 0, 'departure_time' => $time]);
    $data['service_date'] = $date;
    $data['arrival_at'] = $date === '2026-03-08' ? '2026-03-08T03:30:00-04:00' : '2026-11-01T02:30:00-05:00';
    if ($offset !== null) {
        $data['departure_utc_offset'] = $offset;
    }
    $before = $trip->getRawOriginal();

    if ($utc === null) {
        expect(fn () => runPersistedPlanning($operation, $actor, $company, $trip, $data))
            ->toThrow(function (ValidationException $exception) use ($field): void {
                expect($exception->errors())->toHaveKey($field);
            });
        expect($trip->fresh()->getRawOriginal())->toBe($before);
    } else {
        $result = runPersistedPlanning($operation, $actor, $company, $trip, $data);
        expect($result->scheduled_departure_at->utc()->format('Y-m-d H:i:s'))->toBe($utc);
        expect($result->service_date->toDateString())->toBe($date);
        $this->assertModelExists($result);
    }
    Http::assertNothingSent();
})->with([
    ['2026-03-08', '02:30:00', null, null, 'service_date'],
    ['2026-11-01', '01:30:00', null, null, 'departure_utc_offset'],
    ['2026-11-01', '01:30:00', '-06:00', null, 'departure_utc_offset'],
    ['2026-11-01', '01:30:00', '-04:00', '2026-11-01 05:30:00', ''],
    ['2026-11-01', '01:30:00', '-05:00', '2026-11-01 06:30:00', ''],
])->with(['schedule', 'replan']);

test('uses every touched local date while excluding an exact midnight arrival', function (string $arrival, bool $valid, string $operation): void {
    [$actor, $company, $trip, $data] = persistedPlanningInputs();
    $trip->schedule->update(['departure_time' => '23:00:00']);
    $trip->driver->update(['employment_ends_on' => '2026-11-02', 'license_expires_at' => '2026-11-02']);
    $data['arrival_at'] = $arrival;
    $before = $trip->getRawOriginal();

    if ($valid) {
        $result = runPersistedPlanning($operation, $actor, $company, $trip, $data);
        expect($result->service_date->toDateString())->toBe('2026-11-02');
        expect($result->scheduled_departure_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-03 05:00:00');
        expect($result->scheduled_arrival_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-03 06:00:00');
    } else {
        expect(fn () => runPersistedPlanning($operation, $actor, $company, $trip, $data))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toHaveKey('driver_id');
            });
        expect($trip->fresh()->getRawOriginal())->toBe($before);
    }
    Http::assertNothingSent();
})->with([['2026-11-03T00:00:00-06:00', true], ['2026-11-03T00:01:00-06:00', false]])->with(['schedule', 'replan']);

test('persists repeated stop occurrences and nullable estimates without merging historical visits', function (string $operation): void {
    [$actor, $company, $trip, $data] = persistedPlanningInputs();
    $pattern = $trip->pattern;
    $first = $pattern->stopOccurrences()->firstOrFail();
    $last = RoutePatternStop::factory()->for($pattern, 'pattern')->create([
        'stop_id' => $first->stop_id, 'stop_sequence' => 3, 'minutes_from_start' => null,
    ]);
    $pattern->refresh()->update([
        'route_geometry' => ['type' => 'LineString', 'coordinates' => [[-84, 10], [-84.01, 10.01], [-84, 10]]],
        'distance_meters' => '3000.00', 'driving_duration_seconds' => '600.00',
        'routing_points_hash' => hash('sha256', '-84.0000000,10.0000000;-84.0100000,10.0100000;-84.0000000,10.0000000'),
        'routing_leg_distances' => [1500, 1500],
    ]);

    $result = runPersistedPlanning($operation, $actor, $company, $trip, $data);

    $visits = $result->planned_route_snapshot['occurrences'];
    expect($visits)->toHaveCount(3);
    expect(array_column($visits, 'stop_sequence'))->toBe([1, 2, 3]);
    expect($visits[0]['stop_id'])->toBe($visits[2]['stop_id']);
    expect($visits[0]['occurrence_id'])->toBe($first->getKey());
    expect($visits[2]['occurrence_id'])->toBe($last->getKey());
    expect($visits[2]['minutes_from_start'])->toBeNull();
    Http::assertNothingSent();
})->with(['schedule', 'replan']);
