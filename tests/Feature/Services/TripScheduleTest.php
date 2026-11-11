<?php

use App\Enums\CompanyStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\RouteSchedule;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripManagementService;
use App\Services\TripPlanSnapshotService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** @return array{User, Company, array<string, mixed>} */
function tripSchedulingInputs(): array
{
    Http::preventStrayRequests();
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $fixture = Trip::factory()->for($company)->withEligibleDriver()->make();
    $driver = Driver::query()->findOrFail($fixture->driver_id);
    DriverSchedule::factory()->for($driver)->create([
        'day_of_week' => 1, 'start_time' => '00:00', 'end_time' => '00:00', 'ends_next_day' => true,
    ]);
    $actor = createUserWithRole(UserRole::Admin, $company);
    CompanyUser::factory()->for($company)->for($actor, 'user')->create();
    grantShield($actor, ['Create:Trip'], $company);

    return [$actor, $company, [
        'route_pattern_id' => $fixture->route_pattern_id, 'route_schedule_id' => $fixture->route_schedule_id,
        'bus_id' => $fixture->bus_id, 'driver_id' => $fixture->driver_id,
        'service_date' => '2026-10-26', 'arrival_at' => '2026-10-26T07:30:00-06:00',
    ]];
}

test('persists a fully validated scheduled trip and restores the explicit permission context', function (): void {
    [$actor, $company, $data] = tripSchedulingInputs();
    $team = createCompany()->getKey();
    setPermissionsTeamId($team);

    $trip = app(TripManagementService::class)->schedule($actor, $company, $data)->fresh();

    expect($trip->getKey())->toBeUuid()
        ->and($trip->status)->toBe(TripStatus::SCHEDULED)
        ->and($trip->company_id)->toBe($company->getKey())
        ->and($trip->driver_id)->toBe($data['driver_id'])
        ->and($trip->service_date->toDateString())->toBe('2026-10-26')
        ->and($trip->scheduled_departure_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-26 12:30:00')
        ->and($trip->scheduled_arrival_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-26 13:30:00')
        ->and($trip->only(['actual_departure_at', 'actual_arrival_at', 'cancelled_at', 'cancellation_reason']))->toBe(['actual_departure_at' => null, 'actual_arrival_at' => null, 'cancelled_at' => null, 'cancellation_reason' => null])
        ->and($trip->planned_route_snapshot['occurrences'])->toHaveCount(2)
        ->and($trip->planned_route_snapshot['revision_hash'])->toBe(app(TripPlanSnapshotService::class)->revisionHash($trip->planned_route_snapshot))
        ->and(getPermissionsTeamId())->toBe($team);
    $this->assertDatabaseCount('trips', 1);
    Http::assertNothingSent();
});

test('authorizes before validating payload or inspecting protected resources', function (string $scenario): void {
    [$actor, $company] = tripSchedulingInputs();
    if ($scenario === 'archived actor') {
        $actor->delete();
    } elseif ($scenario === 'missing membership') {
        CompanyUser::query()->where('user_id', $actor->getKey())->delete();
    } elseif ($scenario === 'missing permission') {
        $actor->getConnection()->table('model_has_roles')->where('model_uuid', $actor->getKey())->delete();
    } else {
        $owner = $scenario === 'foreign admin' ? createCompany() : $company;
        $role = $scenario === 'driver' ? UserRole::Driver : ($scenario === 'passenger' ? UserRole::Passenger : UserRole::Admin);
        $actor = createUserWithRole($role, $owner);
        CompanyUser::factory()->for($owner, 'company')->for($actor, 'user')->create();
        grantShield($actor, ['Create:Trip'], $owner);
    }
    $team = createCompany()->getKey();
    setPermissionsTeamId($team);

    expect(fn () => app(TripManagementService::class)->schedule($actor, $company, ['driver_id' => 'invalid']))
        ->toThrow(AuthorizationException::class)
        ->and(getPermissionsTeamId())->toBe($team);
    $this->assertDatabaseCount('trips', 0);
    Http::assertNothingSent();
})->with(['archived actor', 'missing membership', 'missing permission', 'foreign admin', 'driver', 'passenger']);

test('rejects supplied protected fields including null values without creating a trip', function (string $field, mixed $value): void {
    [$actor, $company, $data] = tripSchedulingInputs();
    $data[$field] = $value;

    expect(fn () => app(TripManagementService::class)->schedule($actor, $company, $data))
        ->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        });
    $this->assertDatabaseCount('trips', 0);
    Http::assertNothingSent();
})->with([
    ['id', null], ['company_id', null], ['status', 'scheduled'], ['actual_departure_at', null],
    ['actual_arrival_at', null], ['created_at', null], ['updated_at', null], ['planned_route_snapshot', []],
    ['route_geometry', []], ['revision_hash', 'forged'], ['cancelled_at', null], ['cancellation_reason', null],
]);

test('rejects invalid planning inputs atomically even for super admins', function (string $scenario, string $field): void {
    [, $company, $data] = tripSchedulingInputs();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    match ($scenario) {
        'inactive company' => $company->update(['status' => CompanyStatus::INACTIVE]),
        'archived company' => $company->delete(),
        'foreign bus' => $data['bus_id'] = Bus::factory()->create()->getKey(),
        'inactive bus' => Bus::query()->findOrFail($data['bus_id'])->forceFill(['is_active' => false])->save(),
        'archived bus' => Bus::query()->findOrFail($data['bus_id'])->delete(),
        'foreign driver' => $data['driver_id'] = Driver::factory()->create()->getKey(),
        'archived driver' => Driver::query()->findOrFail($data['driver_id'])->delete(),
        'missing availability' => DriverSchedule::query()->where('driver_id', $data['driver_id'])->delete(),
        'wrong calendar' => $data['service_date'] = '2026-10-27',
        'wrong schedule' => $data['route_schedule_id'] = RouteSchedule::factory()->create()->getKey(),
        'invalid arrival' => $data['arrival_at'] = '2026-10-26T06:30:00-06:00',
        'missing driver' => $data['driver_id'] = null,
    };
    $team = createCompany()->getKey();
    setPermissionsTeamId($team);

    expect(fn () => app(TripManagementService::class)->schedule($actor, $company, $data))
        ->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        })
        ->and(getPermissionsTeamId())->toBe($team);
    $this->assertDatabaseCount('trips', 0);
    Http::assertNothingSent();
})->with([
    ['inactive company', 'company'], ['archived company', 'company'], ['foreign bus', 'bus_id'],
    ['inactive bus', 'bus_id'], ['archived bus', 'bus_id'], ['foreign driver', 'driver_id'],
    ['archived driver', 'driver_id'], ['missing availability', 'driver_id'], ['wrong calendar', 'service_date'],
    ['wrong schedule', 'route_schedule_id'], ['invalid arrival', 'arrival_at'], ['missing driver', 'driver_id'],
]);

test('maps existing database identity conflicts privately and preserves the original trip', function (string $conflict, string $field): void {
    [$actor, $company, $data] = tripSchedulingInputs();
    $service = app(TripManagementService::class);
    $original = $service->schedule($actor, $company, $data);
    $before = $original->getRawOriginal();
    if ($conflict !== 'departure') {
        $schedule = RouteSchedule::factory()->create(['route_pattern_id' => $data['route_pattern_id'], 'departure_time' => '06:45:00']);
        $data['route_schedule_id'] = $schedule->getKey();
    }
    if ($conflict === 'driver') {
        $data['bus_id'] = Bus::factory()->for($company)->active()->create()->getKey();
    }

    expect(fn () => $service->schedule($actor, $company, $data))
        ->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        })
        ->and($original->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('trips', 1);
    Http::assertNothingSent();
})->with([['departure', 'service_date'], ['bus', 'bus_id'], ['driver', 'driver_id']]);

test('rolls back inserted trips and database side effects when persistence fails', function (): void {
    [$actor, $company, $data] = tripSchedulingInputs();
    $capacity = Bus::query()->findOrFail($data['bus_id'])->capacity;
    $dispatcher = Trip::getEventDispatcher();
    Trip::setEventDispatcher(clone $dispatcher);
    Trip::created(function (Trip $trip): void {
        $trip->bus->update(['capacity' => 99]);
        throw new RuntimeException('Injected persistence failure.');
    });
    $team = createCompany()->getKey();
    setPermissionsTeamId($team);

    try {
        expect(fn () => app(TripManagementService::class)->schedule($actor, $company, $data))
            ->toThrow(RuntimeException::class, 'Injected persistence failure.');
    } finally {
        Trip::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseCount('trips', 0);
    expect(Bus::query()->findOrFail($data['bus_id'])->capacity)->toBe($capacity)
        ->and(getPermissionsTeamId())->toBe($team);
    Http::assertNothingSent();
});

test('propagates unexpected database constraints without misreporting an identity conflict', function (): void {
    [$actor, $company, $data] = tripSchedulingInputs();
    $dispatcher = Trip::getEventDispatcher();
    Trip::setEventDispatcher(clone $dispatcher);
    Trip::creating(function (Trip $trip): void {
        $trip->planned_route_snapshot = [];
    });

    try {
        expect(fn () => app(TripManagementService::class)->schedule($actor, $company, $data))
            ->toThrow(function (QueryException $exception): void {
                expect($exception->errorInfo[0])->toBe('23514');
            });
    } finally {
        Trip::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseCount('trips', 0);
    Http::assertNothingSent();
});

test('localizes scheduling errors without exposing conflicting trip details', function (string $locale, string $scenario, string $message): void {
    [$actor, $company, $data] = tripSchedulingInputs();
    $field = 'driver_id';
    if ($scenario === 'coverage') {
        DriverSchedule::query()->where('driver_id', $data['driver_id'])->delete();
    } elseif ($scenario === 'payload') {
        $field = 'id';
        $data['id'] = null;
    } else {
        app(TripManagementService::class)->schedule($actor, $company, $data);
        $field = 'service_date';
    }
    app()->setLocale($locale);

    expect(fn () => app(TripManagementService::class)->schedule($actor, $company, $data))
        ->toThrow(function (ValidationException $exception) use ($field, $message): void {
            expect($exception->errors())->toBe([$field => [$message]]);
        });
    $this->assertDatabaseCount('trips', $scenario === 'conflict' ? 1 : 0);
    Http::assertNothingSent();
})->with([
    ['en', 'coverage', 'The selected driver does not cover the complete planned interval.'],
    ['es', 'coverage', 'El conductor seleccionado no tiene disponibilidad para todo el intervalo planificado.'],
    ['en', 'payload', 'This field cannot be supplied when planning a trip.'],
    ['es', 'payload', 'Este campo no se puede proporcionar al planificar un viaje.'],
    ['en', 'conflict', 'The selected departure or resource conflicts with an existing trip.'],
    ['es', 'conflict', 'La salida o el recurso seleccionado entra en conflicto con un viaje existente.'],
]);
