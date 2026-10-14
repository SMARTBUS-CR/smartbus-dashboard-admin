<?php

use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** @return array<string, mixed> */
function operationalBusRow(string $companyId): array
{
    return ['id' => (string) Str::uuid(), 'company_id' => $companyId, 'country_code' => 'CR', 'plate_number' => 'BUS-'.Str::upper(Str::random(8)), 'unit_number' => Str::random(8), 'capacity' => 40];
}

/** @return array<string, mixed> */
function operationalDriverRow(string $companyId): array
{
    return [
        'id' => (string) Str::uuid(), 'company_id' => $companyId, 'user_id' => (string) Str::uuid(),
        'license' => 'LICENSE-'.Str::upper(Str::random(8)), 'license_expires_at' => '2030-01-01',
        'employment_starts_on' => '2026-01-01', 'salary_amount' => 600000, 'salary_currency' => 'CRC',
    ];
}

/** @return array<string, mixed> */
function operationalTripRow(): array
{
    $company = createCompany();
    $route = Route::factory()->for($company)->create();
    $pattern = RoutePattern::factory()->for($route)->create();
    $schedule = RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 2, 'departure_time' => '06:00:00']);
    $bus = operationalBusRow($company->getKey());
    $driver = operationalDriverRow($company->getKey());
    DB::table('buses')->insert($bus);
    DB::table('drivers')->insert($driver);

    return [
        'id' => (string) Str::uuid(), 'company_id' => $company->getKey(),
        'route_pattern_id' => $pattern->getKey(), 'route_schedule_id' => $schedule->getKey(),
        'bus_id' => $bus['id'], 'driver_id' => $driver['id'], 'service_date' => '2026-10-13',
        'scheduled_departure_at' => '2026-10-13 12:00:00+00',
        'scheduled_arrival_at' => '2026-10-13 13:00:00+00',
        'planned_route_snapshot' => json_encode(['version' => 1], JSON_THROW_ON_ERROR),
    ];
}

test('stores a driver profile per company without a cross database user foreign key', function (): void {
    $userId = (string) Str::uuid();
    foreach ([createCompany(), createCompany()] as $company) {
        DB::table('drivers')->insert([
            'id' => Str::uuid(), 'company_id' => $company->getKey(), 'user_id' => $userId,
            'license' => 'LICENSE-001', 'license_expires_at' => '2030-01-01',
            'employment_starts_on' => '2026-01-01', 'salary_amount' => 600000, 'salary_currency' => 'CRC',
        ]);
    }

    expect(DB::table('drivers')->where('user_id', $userId)->count())->toBe(2);
});

test('rejects invalid bus values at the database boundary', function (array $changes, string $constraint): void {
    $bus = ['id' => Str::uuid(), 'company_id' => createCompany()->getKey(), 'country_code' => 'CR', 'plate_number' => 'BUS-001', 'unit_number' => '001', 'capacity' => 40];

    expect(fn () => DB::transaction(fn () => DB::table('buses')->insert([...$bus, ...$changes])))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'zero capacity' => [['capacity' => 0], 'buses_capacity_check'],
    'negative capacity' => [['capacity' => -1], 'buses_capacity_check'],
    'invalid manufacture year' => [['year' => 1800], 'buses_year_check'],
]);

test('keeps archived bus identifiers reserved', function (): void {
    $bus = ['company_id' => createCompany()->getKey(), 'country_code' => 'CR', 'plate_number' => 'BUS-001', 'unit_number' => '001', 'capacity' => 40];
    DB::table('buses')->insert(['id' => Str::uuid(), ...$bus, 'deleted_at' => now()]);

    expect(fn () => DB::transaction(fn () => DB::table('buses')->insert(['id' => Str::uuid(), ...$bus])))
        ->toThrow(QueryException::class, 'buses_country_code_plate_number_unique');
});

test('rejects manufacture years more than one year ahead', function (): void {
    $bus = operationalBusRow(createCompany()->getKey());

    expect(fn () => DB::transaction(fn () => DB::table('buses')->insert([...$bus, 'year' => (int) date('Y') + 2])))
        ->toThrow(QueryException::class, 'buses_year_check');
});

test('stores overnight availability and UTC GPS observation times for a planned trip', function (): void {
    $trip = operationalTripRow();
    DB::table('trips')->insert($trip);
    DB::table('driver_schedules')->insert([
        'id' => Str::uuid(), 'driver_id' => $trip['driver_id'], 'day_of_week' => 2,
        'start_time' => '22:00', 'end_time' => '06:00', 'ends_next_day' => true,
    ]);
    $fix = (string) Str::uuid();

    DB::table('gps_locations')->insert([
        'id' => $fix, 'trip_id' => $trip['id'], 'latitude' => 10.4547665,
        'longitude' => -84.0091243, 'recorded_at' => '2026-10-13 06:00:00-06',
    ]);

    $this->assertDatabaseHas('gps_locations', ['id' => $fix, 'recorded_at' => '2026-10-13 12:00:00+00']);
    expect(DB::table('gps_locations')->where('id', $fix)->value('received_at'))->not->toBeNull();
});

test('rejects invalid employment values', function (array $changes, string $constraint): void {
    $driver = operationalDriverRow(createCompany()->getKey());

    expect(fn () => DB::transaction(fn () => DB::table('drivers')->insert([...$driver, ...$changes])))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'negative salary' => [['salary_amount' => -1], 'drivers_salary_check'],
    'nonmonthly salary' => [['salary_period' => 'weekly'], 'drivers_salary_check'],
    'invalid status' => [['status' => 'unknown'], 'drivers_status_check'],
    'invalid employment interval' => [['employment_ends_on' => '2025-12-31'], 'drivers_employment_dates_check'],
]);

test('rejects invalid weekly availability', function (array $changes): void {
    $driver = operationalDriverRow(createCompany()->getKey());
    DB::table('drivers')->insert($driver);
    $shift = ['id' => Str::uuid(), 'driver_id' => $driver['id'], 'day_of_week' => 1, 'start_time' => '06:00', 'end_time' => '14:00'];

    expect(fn () => DB::transaction(fn () => DB::table('driver_schedules')->insert([...$shift, ...$changes])))
        ->toThrow(function (QueryException $exception): void {
            expect($exception->errorInfo[0])->toBe('23514');
        });
})->with([
    'weekday out of range' => [['day_of_week' => 7]],
    'same day equal times' => [['end_time' => '06:00']],
    'inverted validity' => [['valid_from' => '2026-10-14', 'valid_until' => '2026-10-13']],
    'overnight beyond a full day' => [['ends_next_day' => true]],
]);

test('rejects inconsistent trip execution states', function (array $changes, string $constraint): void {
    $trip = operationalTripRow();

    expect(fn () => DB::transaction(fn () => DB::table('trips')->insert([...$trip, ...$changes])))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'invalid status' => [['status' => 'unknown'], 'trips_execution_check'],
    'arrival before departure' => [['scheduled_arrival_at' => '2026-10-13 11:00:00+00'], 'trips_scheduled_dates_check'],
    'completed without timestamps' => [['status' => 'completed'], 'trips_execution_check'],
    'active without departure' => [['status' => 'in_progress'], 'trips_execution_check'],
    'cancelled without reason' => [['status' => 'cancelled', 'cancelled_at' => '2026-10-13 11:00:00+00'], 'trips_execution_check'],
]);

test('rejects a trip assigned to a bus belonging to another company', function (): void {
    $trip = operationalTripRow();
    $bus = operationalBusRow(createCompany()->getKey());
    DB::table('buses')->insert($bus);

    expect(fn () => DB::transaction(fn () => DB::table('trips')->insert([...$trip, 'bus_id' => $bus['id']])))
        ->toThrow(QueryException::class, 'trips_bus_company_foreign');
});

test('prevents simultaneous bus reservations but permits adjacent trips', function (): void {
    $trip = operationalTripRow();
    DB::table('trips')->insert($trip);
    $schedule = RouteSchedule::factory()->create(['route_pattern_id' => $trip['route_pattern_id'], 'day_of_week' => 2, 'departure_time' => '06:30:00']);
    $replacement = [...$trip, 'id' => (string) Str::uuid(), 'route_schedule_id' => $schedule->getKey(), 'scheduled_departure_at' => '2026-10-13 12:30:00+00', 'scheduled_arrival_at' => '2026-10-13 13:30:00+00'];

    expect(fn () => DB::transaction(fn () => DB::table('trips')->insert($replacement)))
        ->toThrow(QueryException::class, 'trips_no_bus_overlap');

    DB::table('trips')->insert([...$replacement, 'scheduled_departure_at' => '2026-10-13 13:00:00+00', 'scheduled_arrival_at' => '2026-10-13 14:00:00+00']);
    $this->assertDatabaseHas('trips', ['id' => $replacement['id']]);
});

test('prevents a driver from occupying overlapping trips on different buses', function (): void {
    $trip = operationalTripRow();
    DB::table('trips')->insert($trip);
    $bus = operationalBusRow($trip['company_id']);
    DB::table('buses')->insert($bus);
    $schedule = RouteSchedule::factory()->create(['route_pattern_id' => $trip['route_pattern_id'], 'day_of_week' => 2, 'departure_time' => '06:30:00']);

    expect(fn () => DB::transaction(fn () => DB::table('trips')->insert([
        ...$trip, 'id' => Str::uuid(), 'bus_id' => $bus['id'], 'route_schedule_id' => $schedule->getKey(),
        'scheduled_departure_at' => '2026-10-13 12:30:00+00', 'scheduled_arrival_at' => '2026-10-13 13:30:00+00',
    ])))->toThrow(QueryException::class, 'trips_no_driver_overlap');
});

test('allows a cancelled departure to be replaced without deleting its history', function (): void {
    $trip = operationalTripRow();
    DB::table('trips')->insert([
        ...$trip, 'status' => 'cancelled', 'cancelled_at' => '2026-10-13 11:00:00+00', 'cancellation_reason' => 'Unavailable unit',
    ]);

    DB::table('trips')->insert([...$trip, 'id' => Str::uuid()]);

    $this->assertDatabaseHas('trips', ['id' => $trip['id'], 'status' => 'cancelled']);
    expect(DB::table('trips')->where('route_schedule_id', $trip['route_schedule_id'])->count())->toBe(2);
});

test('keeps a completed departure unique for its schedule and service date', function (): void {
    $trip = operationalTripRow();
    DB::table('trips')->insert([
        ...$trip, 'status' => 'completed', 'actual_departure_at' => '2026-10-13 12:00:00+00', 'actual_arrival_at' => '2026-10-13 13:00:00+00',
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('trips')->insert([...$trip, 'id' => Str::uuid()])))
        ->toThrow(QueryException::class, 'trips_schedule_service_date_unique');
});

test('preserves trips when a referenced driver is physically deleted', function (): void {
    $trip = operationalTripRow();
    DB::table('trips')->insert($trip);

    expect(fn () => DB::transaction(fn () => DB::table('drivers')->where('id', $trip['driver_id'])->delete()))
        ->toThrow(QueryException::class, 'trips_driver_company_foreign');

    $this->assertDatabaseHas('trips', ['id' => $trip['id']]);
});

test('rejects a trip assigned to another company driver', function (): void {
    $trip = operationalTripRow();
    $driver = operationalDriverRow(createCompany()->getKey());
    DB::table('drivers')->insert($driver);

    expect(fn () => DB::transaction(fn () => DB::table('trips')->insert([...$trip, 'driver_id' => $driver['id']])))
        ->toThrow(QueryException::class, 'trips_driver_company_foreign');
});

test('rejects a snapshot that is not a nonempty JSON object', function (string $snapshot): void {
    $trip = operationalTripRow();

    expect(fn () => DB::transaction(fn () => DB::table('trips')->insert([...$trip, 'planned_route_snapshot' => $snapshot])))
        ->toThrow(QueryException::class, 'trips_snapshot_check');
})->with(['empty object' => '{}', 'array' => '[]', 'JSON null' => 'null']);

test('rejects duplicate GPS observations and invalid coordinates', function (array $changes, string $constraint): void {
    $trip = operationalTripRow();
    DB::table('trips')->insert($trip);
    $fix = ['trip_id' => $trip['id'], 'latitude' => 10, 'longitude' => -84, 'recorded_at' => '2026-10-13 12:00:00+00'];
    DB::table('gps_locations')->insert(['id' => Str::uuid(), ...$fix]);

    expect(fn () => DB::transaction(fn () => DB::table('gps_locations')->insert(['id' => Str::uuid(), ...$fix, ...$changes])))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'duplicate observation' => [[], 'gps_locations_trip_id_recorded_at_unique'],
    'invalid latitude' => [['latitude' => 91], 'gps_locations_coordinates_check'],
    'invalid longitude' => [['longitude' => -181], 'gps_locations_coordinates_check'],
    'negative speed' => [['speed_kmh' => -1], 'gps_locations_speed_check'],
    'excessive speed' => [['speed_kmh' => 400.01], 'gps_locations_speed_check'],
]);

test('accepts optional GPS accuracy and rejects negative measurements', function (): void {
    $trip = operationalTripRow();
    DB::table('trips')->insert($trip);
    $fix = ['id' => (string) Str::uuid(), 'trip_id' => $trip['id'], 'latitude' => 10, 'longitude' => -84, 'recorded_at' => '2026-10-13 12:00:00+00'];

    DB::table('gps_locations')->insert([...$fix, 'accuracy_meters' => 5.25]);

    $this->assertDatabaseHas('gps_locations', ['id' => $fix['id'], 'accuracy_meters' => 5.25]);
    expect(fn () => DB::transaction(fn () => DB::table('gps_locations')->where('id', $fix['id'])->update(['accuracy_meters' => -1])))
        ->toThrow(QueryException::class, 'gps_locations_accuracy_check');
});

test('rolls back and recreates the new operational tables without removing the route catalogue', function (): void {
    $company = createCompany();
    $migrations = array_map(
        static fn (string $path) => require $path,
        glob(database_path('migrations/2026_10_13_2132*_create_*_table.php')),
    );

    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
    }
    foreach (['buses', 'drivers', 'driver_schedules', 'trips', 'gps_locations'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    $this->assertModelExists($company);

    foreach ($migrations as $migration) {
        $migration->up();
    }
    $trip = operationalTripRow();
    DB::table('trips')->insert($trip);
    $this->assertDatabaseHas('trips', ['id' => $trip['id']]);
});
