<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** @return array<string, mixed> */
function planningTripAttributes(): array
{
    $pattern = RoutePattern::factory()->withSavedPlan()->create();
    $company = $pattern->route->company;
    $schedule = RouteSchedule::factory()->for($pattern, 'pattern')->create();
    $bus = Bus::factory()->for($company)->create();
    $user = createUserWithRole(UserRole::Driver, $company);
    $driverId = (string) Str::uuid();
    DB::table('drivers')->insert([
        'id' => $driverId, 'company_id' => $company->getKey(), 'user_id' => $user->getKey(),
        'license' => 'LICENSE-001', 'license_expires_at' => '2030-01-01',
        'employment_starts_on' => '2026-01-01', 'salary_amount' => 600000, 'salary_currency' => 'CRC',
    ]);

    return [
        'company_id' => $company->getKey(), 'route_pattern_id' => $pattern->getKey(),
        'route_schedule_id' => $schedule->getKey(), 'bus_id' => $bus->getKey(), 'driver_id' => $driverId,
        'service_date' => '2026-10-26', 'scheduled_departure_at' => '2026-10-26 12:00:00+00',
        'scheduled_arrival_at' => '2026-10-26 13:00:00+00',
        'planned_route_snapshot' => ['schema_version' => 1, 'pattern_id' => $pattern->getKey()],
    ];
}

test('persists scheduled trips with UUID casts and existing historical relationships', function (): void {
    $trip = Trip::query()->create(planningTripAttributes())->refresh();

    expect($trip->getKey())->toBeUuid()
        ->and($trip->status)->toBe(TripStatus::SCHEDULED)
        ->and($trip->service_date->format('Y-m-d'))->toBe('2026-10-26')
        ->and($trip->scheduled_departure_at->utc()->format('H:i:s'))->toBe('12:00:00')
        ->and($trip->scheduled_arrival_at->utc()->format('H:i:s'))->toBe('13:00:00')
        ->and($trip->actual_departure_at)->toBeNull()
        ->and($trip->actual_arrival_at)->toBeNull()
        ->and($trip->planned_route_snapshot['schema_version'])->toBe(1);
    $this->assertModelExists($trip->schedule);
    $trip->company->delete();
    $trip->pattern->delete();
    $trip->bus->delete();
    $trip->unsetRelations();

    $this->assertSoftDeleted($trip->company);
    $this->assertSoftDeleted($trip->pattern);
    $this->assertSoftDeleted($trip->bus);
    $this->assertModelExists($trip);
});

test('protects stored trip planning and execution fields against direct writes', function (string $field, mixed $value, bool $quiet): void {
    $trip = Trip::query()->create(planningTripAttributes())->refresh();
    $before = $trip->getRawOriginal();
    $trip->forceFill([$field => $value]);

    try {
        $quiet ? $trip->saveQuietly() : $trip->save();
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
        expect(Trip::query()->findOrFail($before['id'])->getRawOriginal())->toBe($before);
    }
})->with([
    'identity' => ['id', '00000000-0000-4000-8000-000000000001'],
    'ownership' => ['company_id', '00000000-0000-4000-8000-000000000001'],
    'pattern' => ['route_pattern_id', '00000000-0000-4000-8000-000000000001'],
    'schedule' => ['route_schedule_id', '00000000-0000-4000-8000-000000000001'],
    'bus' => ['bus_id', '00000000-0000-4000-8000-000000000001'],
    'driver' => ['driver_id', '00000000-0000-4000-8000-000000000001'],
    'local date' => ['service_date', '2026-10-27'],
    'departure' => ['scheduled_departure_at', '2026-10-26 12:30:00+00'],
    'arrival' => ['scheduled_arrival_at', '2026-10-26 14:00:00+00'],
    'snapshot' => ['planned_route_snapshot', ['schema_version' => 999]],
    'state' => ['status', 'in_progress'],
    'actual departure' => ['actual_departure_at', '2026-10-26 12:00:00+00'],
    'actual arrival' => ['actual_arrival_at', '2026-10-26 13:00:00+00'],
    'cancellation time' => ['cancelled_at', '2026-10-26 11:00:00+00'],
    'cancellation reason' => ['cancellation_reason', 'Forged cancellation'],
])->with(['normal save' => [false], 'quiet save' => [true]]);

test('rejects physical trip deletion while preserving its history', function (string $method): void {
    $trip = Trip::query()->create(planningTripAttributes())->refresh();
    $before = $trip->getRawOriginal();

    expect(fn () => $trip->{$method}())->toThrow(ValidationException::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with(['delete', 'deleteQuietly', 'forceDelete']);

test('hydrates every trip execution state without changing historical timestamps', function (string $state, string $case): void {
    $trip = (new Trip)->newFromBuilder([
        'status' => $state, 'actual_departure_at' => '2026-10-26 12:00:00+00',
    ]);

    expect($trip->status->name)->toBe($case)
        ->and($trip->actual_departure_at->format('H:i:s'))->toBe('12:00:00');
})->with([
    ['scheduled', 'SCHEDULED'], ['in_progress', 'IN_PROGRESS'],
    ['completed', 'COMPLETED'], ['cancelled', 'CANCELLED'],
]);

test('rejects execution data on new trips even when model events are bypassed', function (string $field, string $value): void {
    $trip = (new Trip(planningTripAttributes()))->forceFill([$field => $value]);

    try {
        $trip->saveQuietly();
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
        $this->assertDatabaseCount('trips', 0);
    }
})->with([
    ['status', 'completed'], ['actual_departure_at', '2026-10-26 12:00:00+00'],
    ['actual_arrival_at', '2026-10-26 13:00:00+00'], ['cancelled_at', '2026-10-26 11:00:00+00'],
    ['cancellation_reason', 'Forged cancellation'],
]);

test('translates history and deletion protection messages', function (string $locale, string $historyMessage, string $deletionMessage): void {
    app()->setLocale($locale);
    $trip = Trip::query()->create(planningTripAttributes())->refresh();
    $trip->service_date = '2026-10-27';

    try {
        $trip->save();
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['service_date' => [$historyMessage]]);
    }
    try {
        $trip->delete();
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['trip' => [$deletionMessage]]);
    }
    $this->assertModelExists($trip);
})->with([
    ['en', 'Trip history cannot be changed directly.', 'Trip deletion is disabled. Preserve the trip history.'],
    ['es', 'El historial del viaje no se puede modificar directamente.', 'La eliminación de viajes está deshabilitada. Conserva el historial del viaje.'],
]);
