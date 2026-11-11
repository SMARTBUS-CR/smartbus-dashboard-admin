<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\Trip;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** @return array<string, mixed> */
function planningTripAttributes(): array
{
    $pattern = RoutePattern::factory()->withSavedPlan()->create();
    $company = $pattern->route->company;
    $schedule = RouteSchedule::factory()->for($pattern, 'pattern')->create();
    $bus = Bus::factory()->for($company)->create();
    $user = createUserWithRole(UserRole::Driver, $company);
    $driver = Driver::factory()->for($company)->for($user)->create([
        'license' => 'LICENSE-001', 'license_expires_at' => '2030-01-01',
        'employment_starts_on' => '2026-01-01', 'salary_amount' => 600000, 'salary_currency' => 'CRC',
    ]);

    return [
        'company_id' => $company->getKey(), 'route_pattern_id' => $pattern->getKey(),
        'route_schedule_id' => $schedule->getKey(), 'bus_id' => $bus->getKey(), 'driver_id' => $driver->getKey(),
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

test('retains the real driver relationship after the employment is archived', function (): void {
    $trip = Trip::query()->create(planningTripAttributes())->refresh();
    $driver = Driver::query()->findOrFail($trip->driver_id);

    expect($trip->driver->is($driver))->toBeTrue();
    $driver->delete();
    $trip->unsetRelations();

    $this->assertSoftDeleted($trip->driver);
    expect($trip->driver->getKey())->toBe($driver->getKey());
    $this->assertModelExists($trip);
});

test('protects stored trip planning and execution fields against direct writes', function (string $field, mixed $value): void {
    $trip = Trip::query()->create(planningTripAttributes())->refresh();
    $before = $trip->getRawOriginal();
    $trip->forceFill([$field => $value]);

    try {
        $trip->save();
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field)
            ->and(Trip::query()->findOrFail($before['id'])->getRawOriginal())->toBe($before);
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
]);

test('soft deletes trips and restores them without changing their history', function (string $method, string $restore): void {
    $trip = Trip::query()->create(planningTripAttributes())->refresh();
    $before = $trip->getRawOriginal();

    expect($trip->{$method}())->toBeTrue();
    $this->assertSoftDeleted($trip);
    expect(Trip::query()->find($trip->getKey()))->toBeNull()
        ->and($trip->{$restore}())->toBeTrue();
    $restored = Trip::query()->findOrFail($trip->getKey());
    expect($restored->getRawOriginal('planned_route_snapshot'))->toBe($before['planned_route_snapshot']);
})->with(['delete', 'deleteQuietly'])->with(['restore', 'restoreQuietly']);

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

test('rejects execution data on new trips', function (string $field, string $value, bool $quiet): void {
    $trip = (new Trip(planningTripAttributes()))->forceFill([$field => $value]);

    try {
        $quiet ? $trip->saveQuietly() : $trip->save();
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
        $this->assertDatabaseCount('trips', 0);
    }
})->with([
    ['status', 'completed'], ['actual_departure_at', '2026-10-26 12:00:00+00'],
    ['actual_arrival_at', '2026-10-26 13:00:00+00'], ['cancelled_at', '2026-10-26 11:00:00+00'],
    ['cancellation_reason', 'Forged cancellation'],
])->with(['normal' => [false], 'quiet' => [true]]);

test('rejects database-valid changes to terminal history when events are suppressed', function (string $state, string $method): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->{$state}()->create()->refresh();
    $before = $trip->getRawOriginal();
    if ($state === 'completed') {
        $trip->actual_arrival_at = $trip->actual_arrival_at->addMinute();
    } else {
        $trip->cancellation_reason = 'Changed reason';
    }

    expect(fn () => $method === 'saveQuietly'
        ? $trip->saveQuietly()
        : Trip::withoutEvents(fn () => $trip->save()))->toThrow(ValidationException::class)
        ->and($trip->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with(['completed', 'cancelled'])->with(['saveQuietly', 'withoutEvents']);

test('rejects permanent deletion of visible and soft deleted trip history', function (string $method, bool $archived): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->completed()->create()->refresh();
    if ($archived) {
        $trip->delete();
    }
    $before = Trip::withTrashed()->findOrFail($trip->id)->getRawOriginal();

    expect(fn () => $method === 'forceDestroy'
        ? Trip::forceDestroy($trip->id)
        : $trip->{$method}())->toThrow(ValidationException::class)
        ->and(Trip::withTrashed()->findOrFail($trip->id)->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with(['forceDelete', 'forceDeleteQuietly', 'forceDestroy'])->with([false, true]);

test('rejects history changes hidden inside a restoration', function (string $method): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->completed()->create();
    $trip->delete();
    $trip = Trip::withTrashed()->findOrFail($trip->id);
    $before = $trip->getRawOriginal();
    $trip->actual_arrival_at = $trip->actual_arrival_at->addMinute();

    expect(fn () => $trip->{$method}())->toThrow(ValidationException::class)
        ->and(Trip::withTrashed()->findOrFail($trip->id)->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with(['restore', 'restoreQuietly']);

test('localizes permanent trip deletion errors', function (string $locale, string $message): void {
    Http::preventStrayRequests();
    app()->setLocale($locale);
    $trip = Trip::factory()->completed()->create();

    expect(fn () => $trip->forceDeleteQuietly())->toThrow(function (ValidationException $exception) use ($message): void {
        expect($exception->errors())->toBe(['trip' => [$message]]);
    });

    $this->assertModelExists($trip);
    Http::assertNothingSent();
})->with([
    ['en', 'Trip deletion is disabled. Preserve the trip history.'],
    ['es', 'La eliminación de viajes está deshabilitada. Conserva el historial del viaje.'],
]);

test('translates history protection messages', function (string $locale, string $historyMessage): void {
    app()->setLocale($locale);
    $trip = Trip::query()->create(planningTripAttributes())->refresh();
    $trip->service_date = '2026-10-27';

    try {
        $trip->save();
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['service_date' => [$historyMessage]]);
    }
    $this->assertModelExists($trip);
})->with([
    ['en', 'Trip history cannot be changed directly.'],
    ['es', 'El historial del viaje no se puede modificar directamente.'],
]);
