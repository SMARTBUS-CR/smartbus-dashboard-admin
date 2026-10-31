<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DriverSchedule;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripManagementService;
use App\Services\TripPlanSnapshotService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** @return array{User, Company, Trip, array<string, mixed>} */
function tripReplanningInputs(): array
{
    Http::preventStrayRequests();
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $trip = Trip::factory()->for($company)->withEligibleDriver()->create()->refresh();
    DriverSchedule::factory()->for($trip->driver)->create([
        'day_of_week' => 1, 'start_time' => '00:00', 'end_time' => '00:00', 'ends_next_day' => true,
    ]);
    $actor = createUserWithRole(UserRole::Admin, $company);
    CompanyUser::factory()->for($company)->for($actor, 'user')->create();
    grantShield($actor, ['Create:Trip', 'Update:Trip'], $company);

    return [$actor, $company, $trip, [
        'route_pattern_id' => $trip->route_pattern_id, 'route_schedule_id' => $trip->route_schedule_id,
        'bus_id' => $trip->bus_id, 'driver_id' => $trip->driver_id,
        'service_date' => '2026-11-02', 'arrival_at' => '2026-11-02T07:30:00-06:00',
    ]];
}

test('replaces the complete scheduled plan while preserving trip identity and history protections', function (): void {
    [$actor, $company, $trip, $data] = tripReplanningInputs();
    $before = $trip->getRawOriginal();
    $replacement = Trip::factory()->for($company)->withEligibleDriver()->make();
    DriverSchedule::factory()->for($replacement->driver)->create([
        'day_of_week' => 1, 'start_time' => '00:00', 'end_time' => '00:00', 'ends_next_day' => true,
    ]);
    $data = array_replace($data, $replacement->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']));
    $team = createCompany()->getKey();
    setPermissionsTeamId($team);

    $updated = app(TripManagementService::class)->replan($actor, $company, $trip->getKey(), $data);

    expect($updated->getKey())->toBe($trip->getKey());
    expect($updated->company_id)->toBe($company->getKey());
    expect($updated->getRawOriginal('created_at'))->toBe($before['created_at']);
    expect($updated->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']))
        ->toBe($replacement->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']));
    expect($updated->service_date->toDateString())->toBe('2026-11-02');
    expect($updated->scheduled_departure_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-02 12:30:00');
    expect($updated->scheduled_arrival_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-02 13:30:00');
    expect($updated->status)->toBe(TripStatus::SCHEDULED);
    expect($updated->actual_departure_at)->toBeNull();
    expect($updated->actual_arrival_at)->toBeNull();
    expect($updated->planned_route_snapshot['revision_hash'])->toBe(app(TripPlanSnapshotService::class)->revisionHash($updated->planned_route_snapshot));
    expect(getPermissionsTeamId())->toBe($team);
    $this->assertDatabaseCount('trips', 1);
    $updated->forceFill(['driver_id' => $trip->driver_id]);
    expect(fn () => $updated->saveQuietly())->toThrow(ValidationException::class);
    expect($trip->fresh()->driver_id)->toBe($replacement->driver_id);
    Http::assertNothingSent();
});

test('authorizes replanning before resolving the trip or validating the payload', function (string $scenario): void {
    [$actor, $company, $trip] = tripReplanningInputs();
    if ($scenario === 'archived actor') {
        $actor->delete();
    } elseif ($scenario === 'archived membership') {
        CompanyUser::query()->where('user_id', $actor->getKey())->delete();
    } elseif ($scenario === 'missing permission') {
        $actor->getConnection()->table('model_has_roles')->where('model_uuid', $actor->getKey())->delete();
    } else {
        $owner = $scenario === 'foreign admin' ? createCompany() : $company;
        $role = match ($scenario) {
            'driver' => UserRole::Driver,
            'passenger' => UserRole::Passenger,
            default => UserRole::Admin,
        };
        $actor = createUserWithRole($role, $owner);
        if ($scenario !== 'missing membership') {
            CompanyUser::factory()->for($owner, 'company')->for($actor, 'user')->create();
        }
        grantShield($actor, ['Update:Trip'], $owner);
    }
    $team = createCompany()->getKey();
    setPermissionsTeamId($team);
    $before = $trip->getRawOriginal();

    expect(fn () => app(TripManagementService::class)->replan($actor, $company, 'not-a-uuid', []))
        ->toThrow(AuthorizationException::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
    expect(getPermissionsTeamId())->toBe($team);
})->with(['archived actor', 'archived membership', 'missing permission', 'missing membership', 'foreign admin', 'driver', 'passenger']);

test('hides missing and foreign trip identifiers even from super admins', function (bool $foreign): void {
    [, $company, $trip, $data] = tripReplanningInputs();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    if ($foreign) {
        $company = createCompany();
    }
    $id = $foreign ? $trip->getKey() : '00000000-0000-4000-8000-000000000001';
    $before = $trip->getRawOriginal();

    expect(fn () => app(TripManagementService::class)->replan($actor, $company, $id, $data))
        ->toThrow(ModelNotFoundException::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with([false, true]);

test('rejects started and terminal replanning even for super admins', function (string $status): void {
    [, $company, $trip, $data] = tripReplanningInputs();
    $values = match ($status) {
        'in_progress' => ['actual_departure_at' => '2026-10-26T12:30:00Z'],
        'completed' => ['actual_departure_at' => '2026-10-26T12:30:00Z', 'actual_arrival_at' => '2026-10-26T13:30:00Z'],
        'cancelled' => ['cancelled_at' => '2026-10-26T12:00:00Z', 'cancellation_reason' => 'Existing history'],
    };
    Trip::query()->whereKey($trip->getKey())->update(['status' => $status, ...$values]);
    $before = $trip->fresh()->getRawOriginal();
    $actor = createUserWithRole(UserRole::SuperAdmin);

    expect(fn () => app(TripManagementService::class)->replan($actor, $company, $trip->getKey(), $data))
        ->toThrow(AuthorizationException::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with(['in_progress', 'completed', 'cancelled']);

test('requires the complete plan and rejects forged replanning fields without changing the original', function (string $field, bool $remove): void {
    [$actor, $company, $trip, $data] = tripReplanningInputs();
    if ($remove) {
        unset($data[$field]);
    } else {
        $data[$field] = null;
    }
    $before = $trip->getRawOriginal();

    expect(fn () => app(TripManagementService::class)->replan($actor, $company, $trip->getKey(), $data))
        ->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        });
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['route_pattern_id', true], ['route_schedule_id', true], ['bus_id', true], ['driver_id', true], ['service_date', true], ['arrival_at', true],
    ['id', false], ['company_id', false], ['status', false], ['actual_departure_at', false], ['actual_arrival_at', false],
    ['created_at', false], ['updated_at', false], ['planned_route_snapshot', false], ['route_geometry', false],
    ['revision_hash', false], ['cancelled_at', false], ['cancellation_reason', false],
]);

test('rolls back replanning conflicts and preserves both persisted plans', function (string $conflict, string $field): void {
    [$actor, $company, $trip, $data] = tripReplanningInputs();
    $reserved = app(TripManagementService::class)->schedule($actor, $company, $data);
    if ($conflict !== 'departure') {
        $schedule = $trip->schedule->replicate();
        $schedule->departure_time = '06:45:00';
        $schedule->save();
        $data['route_schedule_id'] = $schedule->getKey();
    }
    if ($conflict === 'driver') {
        $data['bus_id'] = Bus::factory()->for($company)->active()->create()->getKey();
    }
    $before = Trip::query()->orderBy('id')->get()->map->getRawOriginal()->all();

    expect(fn () => app(TripManagementService::class)->replan($actor, $company, $trip->getKey(), $data))
        ->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        });
    expect(Trip::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before);
    $this->assertModelExists($reserved);
})->with([['departure', 'service_date'], ['bus', 'bus_id'], ['driver', 'driver_id']]);

test('rebuilds snapshots only through explicit replanning and accepts an equivalent plan', function (): void {
    [$actor, $company, $trip, $data] = tripReplanningInputs();
    $before = $trip->getRawOriginal();
    $trip->pattern->update(['name' => 'Reviewed replacement pattern']);
    $trip->pattern->stopOccurrences()->firstOrFail()->stop->update(['name' => 'Renamed historical stop']);
    $trip->schedule->update(['departure_time' => '07:30:00']);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
    $data['service_date'] = '2026-10-26';
    $data['arrival_at'] = '2026-10-26T08:30:00-06:00';

    $updated = app(TripManagementService::class)->replan($actor, $company, $trip->getKey(), $data);

    expect($updated->planned_route_snapshot['pattern_name'])->toBe('Reviewed replacement pattern');
    expect($updated->planned_route_snapshot['revision_hash'])->not->toBe($trip->planned_route_snapshot['revision_hash']);
    expect($updated->scheduled_departure_at->utc()->format('H:i:s'))->toBe('13:30:00');
    $repeated = app(TripManagementService::class)->replan($actor, $company, $trip->getKey(), $data);
    expect($repeated->planned_route_snapshot)->toBe($updated->planned_route_snapshot);
    expect($repeated->getKey())->toBe($trip->getKey());
    Http::assertNothingSent();
});

test('rolls back a failed replanning write and restores permission context', function (): void {
    [$actor, $company, $trip, $data] = tripReplanningInputs();
    $before = $trip->getRawOriginal();
    $capacity = $trip->bus->capacity;
    $connection = $trip->getConnection();
    $dispatcher = $connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    $connection->getEventDispatcher()->listen(QueryExecuted::class, function (QueryExecuted $query) use ($trip): void {
        if (str_starts_with($query->sql, 'update "trips"')) {
            $query->connection->table('buses')->where('id', $trip->bus_id)->update(['capacity' => 99]);
            throw new RuntimeException('Injected replanning failure.');
        }
    });
    $team = createCompany()->getKey();
    setPermissionsTeamId($team);

    try {
        expect(fn () => app(TripManagementService::class)->replan($actor, $company, $trip->getKey(), $data))
            ->toThrow(RuntimeException::class, 'Injected replanning failure.');
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }
    expect($trip->fresh()->getRawOriginal())->toBe($before);
    expect($trip->bus->fresh()->capacity)->toBe($capacity);
    expect(getPermissionsTeamId())->toBe($team);
    $this->assertDatabaseCount('trips', 1);
});

test('localizes replanning failures and preserves the stored plan', function (string $locale, string $message): void {
    [$actor, $company, $trip, $data] = tripReplanningInputs();
    DriverSchedule::query()->where('driver_id', $trip->driver_id)->delete();
    $before = $trip->getRawOriginal();
    app()->setLocale($locale);

    expect(fn () => app(TripManagementService::class)->replan($actor, $company, $trip->getKey(), $data))
        ->toThrow(function (ValidationException $exception) use ($message): void {
            expect($exception->errors())->toBe(['driver_id' => [$message]]);
        });
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['en', 'The selected driver does not cover the complete planned interval.'],
    ['es', 'El conductor seleccionado no tiene disponibilidad para todo el intervalo planificado.'],
]);
