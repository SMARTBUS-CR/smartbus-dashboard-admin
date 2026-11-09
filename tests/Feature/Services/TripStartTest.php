<?php

use App\Enums\CompanyStatus;
use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\RouteScheduleException;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** @return array{Trip, User} */
function tripStartInputs(): array
{
    Http::preventStrayRequests();

    return [Trip::factory()->withDriverAvailability()->create()->refresh(), createUserWithRole(UserRole::SuperAdmin)];
}

test('starts a scheduled trip once with a trusted UTC instant and preserves its plan', function (): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $before = $trip->getRawOriginal();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00.123456Z'));

    $started = app(TripManagementService::class)->start($actor, $trip->company, $trip->id);

    expect($started->status)->toBe(TripStatus::IN_PROGRESS)
        ->and($started->actual_departure_at->utc()->format('Y-m-d H:i:s.uP'))->toBe('2026-10-26 12:45:00.123456+00:00')
        ->and(Arr::except($started->getRawOriginal(), ['status', 'actual_departure_at', 'updated_at']))->toBe(Arr::except($before, ['status', 'actual_departure_at', 'updated_at']));
    Http::assertNothingSent();
});

test('revalidates current resources and eligibility before starting even for a super administrator', function (string $scenario, string $field): void {
    [$trip, $actor] = tripStartInputs();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
    $driver = $trip->driver;
    match ($scenario) {
        'inactive company' => $trip->company->update(['status' => CompanyStatus::INACTIVE]),
        'inactive bus' => Bus::query()->whereKey($trip->bus_id)->update(['is_active' => false]),
        'archived bus' => Bus::query()->whereKey($trip->bus_id)->update(['deleted_at' => now()]),
        'inactive driver' => Driver::query()->whereKey($driver->id)->update(['status' => DriverStatus::INACTIVE]),
        'archived driver' => Driver::query()->whereKey($driver->id)->update(['deleted_at' => now()]),
        'archived identity' => $driver->user->delete(),
        'missing membership' => CompanyUser::query()->where('company_id', $trip->company_id)->where('user_id', $driver->user_id)->delete(),
        'missing role' => $driver->user->getConnection()->table('model_has_roles')->where('model_uuid', $driver->user_id)->where('company_id', $trip->company_id)->delete(),
        'employment not started' => Driver::query()->whereKey($driver->id)->update(['employment_starts_on' => '2026-10-27']),
        'employment ended' => Driver::query()->whereKey($driver->id)->update(['employment_starts_on' => '2026-10-01', 'employment_ends_on' => '2026-10-25']),
        'expired license' => Driver::query()->whereKey($driver->id)->update(['license_expires_at' => '2026-10-25']),
        'availability gap' => DriverSchedule::query()->where('driver_id', $driver->id)->delete(),
        'suspended departure' => RouteScheduleException::factory()->create(['route_schedule_id' => $trip->route_schedule_id, 'service_date' => '2026-10-26']),
        'invalid timezone' => $trip->company->newQuery()->whereKey($trip->company_id)->update(['timezone' => 'Invalid/Zone']),
    };
    $before = $trip->getRawOriginal();

    expect(fn () => app(TripManagementService::class)->start($actor, $trip->company, $trip->id))
        ->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        })
        ->and($trip->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['inactive company', 'company'], ['inactive bus', 'bus_id'], ['archived bus', 'bus_id'],
    ['inactive driver', 'driver_id'], ['archived driver', 'driver_id'], ['archived identity', 'driver_id'],
    ['missing membership', 'driver_id'], ['missing role', 'driver_id'], ['employment not started', 'driver_id'],
    ['employment ended', 'driver_id'], ['expired license', 'driver_id'], ['availability gap', 'driver_id'],
    ['suspended departure', 'service_date'],
    ['invalid timezone', 'timezone'],
]);

test('checks availability only at the actual instant with an exclusive end boundary', function (string $instant, bool $allowed): void {
    [$trip, $actor] = tripStartInputs();
    DriverSchedule::query()->where('driver_id', $trip->driver_id)->update(['start_time' => '06:00', 'end_time' => '07:00', 'ends_next_day' => false]);
    $this->travelTo(CarbonImmutable::parse($instant));

    if ($allowed) {
        $started = app(TripManagementService::class)->start($actor, $trip->company, $trip->id);
        expect($started->status)->toBe(TripStatus::IN_PROGRESS)
            ->and($started->actual_departure_at->utc()->format('Y-m-d\TH:i:s.uP'))->toBe($instant);
    } else {
        expect(fn () => app(TripManagementService::class)->start($actor, $trip->company, $trip->id))
            ->toThrow(ValidationException::class)
            ->and($trip->fresh()->status)->toBe(TripStatus::SCHEDULED);
    }
})->with([
    ['2026-10-26T12:45:00.000000+00:00', true],
    ['2026-10-26T12:59:59.999999+00:00', true],
    ['2026-10-26T13:00:00.000000+00:00', false],
]);

test('allows the assigned driver to start without administrative permissions and restores permission context', function (): void {
    [$trip] = tripStartInputs();
    $actor = $trip->driver->user;
    $team = createCompany()->id;
    setPermissionsTeamId($team);
    $actor->load('roles', 'permissions');
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));

    $started = app(TripManagementService::class)->start($actor, $trip->company, $trip->id);

    expect($started->status)->toBe(TripStatus::IN_PROGRESS)
        ->and(getPermissionsTeamId())->toBe($team)
        ->and($actor->relationLoaded('roles'))->toBeFalse()
        ->and($actor->relationLoaded('permissions'))->toBeFalse();
});

test('authorizes before validating identifiers or resolving an unauthorized retry', function (): void {
    [$trip, $super] = tripStartInputs();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
    $service = app(TripManagementService::class);
    $started = $service->start($super, $trip->company, $trip->id);
    $actor = createUserWithRole(UserRole::Passenger, $trip->company);
    CompanyUser::factory()->create(['company_id' => $trip->company_id, 'user_id' => $actor->id]);
    grantShield($actor, ['Start:Trip'], $trip->company);
    $before = $started->getRawOriginal();

    expect(fn () => $service->start($actor, $trip->company, 'invalid'))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->start($actor, $trip->company, $trip->id))->toThrow(AuthorizationException::class)
        ->and($trip->fresh()->getRawOriginal())->toBe($before);
});

test('preserves the original start on an authorized retry even when operational eligibility changed', function (): void {
    [$trip, $actor] = tripStartInputs();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00.123456Z'));
    $service = app(TripManagementService::class);
    $before = $service->start($actor, $trip->company, $trip->id)->getRawOriginal();
    $trip->company->update(['status' => CompanyStatus::INACTIVE]);
    DriverSchedule::query()->where('driver_id', $trip->driver_id)->delete();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T14:45:00Z'));

    $retried = $service->start($actor, $trip->company, $trip->id);

    expect($retried->getRawOriginal())->toBe($before);
});

test('scopes trip lookup to the selected company even for a super administrator', function (): void {
    [$trip, $actor] = tripStartInputs();

    expect(fn () => app(TripManagementService::class)->start($actor, createCompany(), $trip->id))
        ->toThrow(ModelNotFoundException::class)
        ->and($trip->fresh()->status)->toBe(TripStatus::SCHEDULED);
});

test('rejects another in-progress trip even after its planned interval has elapsed', function (string $resource, bool $archived): void {
    [$trip, $actor] = tripStartInputs();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
    if ($resource === 'bus') {
        $other = Trip::factory()->for($trip->company)->create(['bus_id' => $trip->bus_id, 'service_date' => '2026-10-19']);
    } else {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $profile = Driver::factory()->for($company)->create(['user_id' => $trip->driver->user_id]);
        $other = Trip::factory()->for($company)->for($profile, 'driver')->create(['service_date' => '2026-10-19']);
    }
    Trip::query()->whereKey($other->id)->update(['status' => TripStatus::IN_PROGRESS, 'actual_departure_at' => '2026-10-19 12:30:00+00']);
    if ($archived) {
        $other->delete();
    }
    $before = $trip->getRawOriginal();

    expect(fn () => app(TripManagementService::class)->start($actor, $trip->company, $trip->id))
        ->toThrow(function (ValidationException $exception) use ($resource): void {
            expect($exception->errors())->toBe([$resource === 'bus' ? 'bus_id' : 'driver_id' => [__('The selected departure or resource conflicts with an existing trip.')]]);
        })
        ->and($trip->fresh()->getRawOriginal())->toBe($before)
        ->and(Trip::withTrashed()->findOrFail($other->id)->status)->toBe(TripStatus::IN_PROGRESS);
})->with(['bus', 'person'])->with(['visible' => [false], 'soft deleted' => [true]]);

test('rejects started-trip retries after current actor authorization is revoked', function (string $revoked): void {
    [$trip] = tripStartInputs();
    $actor = $revoked === 'permission' ? createUserWithRole(UserRole::Admin, $trip->company) : $trip->driver->user;
    if ($revoked === 'permission') {
        CompanyUser::factory()->create(['company_id' => $trip->company_id, 'user_id' => $actor->id]);
        grantShield($actor, ['Start:Trip'], $trip->company);
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
    $service = app(TripManagementService::class);
    $before = $service->start($actor, $trip->company, $trip->id)->getRawOriginal();
    if ($revoked === 'membership') {
        CompanyUser::query()->where('company_id', $trip->company_id)->where('user_id', $actor->id)->delete();
    } elseif ($revoked === 'identity') {
        $actor->delete();
    } else {
        $actor->getConnection()->table('model_has_roles')->where('model_uuid', $actor->id)->where('company_id', $trip->company_id)->delete();
    }
    $team = createCompany()->id;
    setPermissionsTeamId($team);

    expect(fn () => $service->start($actor, $trip->company, $trip->id))->toThrow(AuthorizationException::class)
        ->and($trip->fresh()->getRawOriginal())->toBe($before)
        ->and(getPermissionsTeamId())->toBe($team);
})->with(['permission', 'membership', 'identity']);

test('rejects starting terminal trips without changing history', function (TripStatus $status): void {
    [$trip, $actor] = tripStartInputs();
    $values = $status === TripStatus::COMPLETED
        ? ['actual_departure_at' => '2026-10-26 12:30:00+00', 'actual_arrival_at' => '2026-10-26 13:30:00+00']
        : ['cancelled_at' => '2026-10-26 12:00:00+00', 'cancellation_reason' => 'Preserved cancellation'];
    Trip::query()->whereKey($trip->id)->update(['status' => $status, ...$values]);
    $before = $trip->refresh()->getRawOriginal();

    expect(fn () => app(TripManagementService::class)->start($actor, $trip->company, $trip->id))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['status' => [__('Only scheduled trips can be started.')]]);
        })
        ->and($trip->fresh()->getRawOriginal())->toBe($before);
})->with([TripStatus::COMPLETED, TripStatus::CANCELLED]);

test('uses the actual company-local date for employment and license eligibility', function (string $instant, bool $allowed): void {
    [$trip, $actor] = tripStartInputs();
    Driver::query()->whereKey($trip->driver_id)->update(['employment_ends_on' => '2026-10-26', 'license_expires_at' => '2026-10-26']);
    $this->travelTo(CarbonImmutable::parse($instant));

    if ($allowed) {
        expect(app(TripManagementService::class)->start($actor, $trip->company, $trip->id)->status)->toBe(TripStatus::IN_PROGRESS);
    } else {
        expect(fn () => app(TripManagementService::class)->start($actor, $trip->company, $trip->id))->toThrow(ValidationException::class)
            ->and($trip->fresh()->status)->toBe(TripStatus::SCHEDULED);
    }
})->with([['2026-10-27T05:59:59.999999Z', true], ['2026-10-27T06:00:00Z', false]]);

test('rolls back a failed start and its database side effects', function (): void {
    [$trip, $actor] = tripStartInputs();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
    $before = $trip->getRawOriginal();
    $capacity = $trip->bus->capacity;
    $connection = $trip->getConnection();
    $dispatcher = $connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    $connection->getEventDispatcher()->listen(QueryExecuted::class, function (QueryExecuted $query) use ($trip): void {
        if (str_starts_with($query->sql, 'update "trips"')) {
            $query->connection->table('buses')->where('id', $trip->bus_id)->update(['capacity' => 99]);
            throw new RuntimeException('Injected start failure.');
        }
    });
    $team = createCompany()->id;
    setPermissionsTeamId($team);

    try {
        expect(fn () => app(TripManagementService::class)->start($actor, $trip->company, $trip->id))
            ->toThrow(RuntimeException::class, 'Injected start failure.');
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }
    expect($trip->fresh()->getRawOriginal())->toBe($before)
        ->and($trip->bus->fresh()->capacity)->toBe($capacity)
        ->and(getPermissionsTeamId())->toBe($team);
});

test('returns bilingual start errors without changing the stored trip', function (string $locale, string $scenario, string $field, string $message): void {
    [$trip, $actor] = tripStartInputs();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
    if ($scenario === 'availability') {
        DriverSchedule::query()->where('driver_id', $trip->driver_id)->delete();
    } elseif ($scenario === 'suspension') {
        RouteScheduleException::factory()->create(['route_schedule_id' => $trip->route_schedule_id, 'service_date' => '2026-10-26']);
    } else {
        Trip::query()->whereKey($trip->id)->update([
            'status' => TripStatus::CANCELLED, 'cancelled_at' => '2026-10-26 12:00:00+00', 'cancellation_reason' => 'Preserved reason',
        ]);
    }
    $before = $trip->refresh()->getRawOriginal();
    app()->setLocale($locale);

    expect(fn () => app(TripManagementService::class)->start($actor, $trip->company, $trip->id))
        ->toThrow(function (ValidationException $exception) use ($field, $message): void {
            expect($exception->errors())->toBe([$field => [$message]]);
        })
        ->and($trip->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['en', 'availability', 'driver_id', 'The assigned driver is unavailable at the actual start instant.'],
    ['es', 'availability', 'driver_id', 'El conductor asignado no tiene disponibilidad en el instante real de inicio.'],
    ['en', 'suspension', 'service_date', 'The selected departure is currently suspended.'],
    ['es', 'suspension', 'service_date', 'La salida seleccionada está suspendida actualmente.'],
    ['en', 'status', 'status', 'Only scheduled trips can be started.'],
    ['es', 'status', 'status', 'Solo se pueden iniciar viajes programados.'],
]);

test('denies another driver access to a started trip before resolving its retry', function (): void {
    [$trip, $super] = tripStartInputs();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
    $service = app(TripManagementService::class);
    $before = $service->start($super, $trip->company, $trip->id)->getRawOriginal();
    $other = createUserWithRole(UserRole::Driver, $trip->company);
    CompanyUser::factory()->create(['company_id' => $trip->company_id, 'user_id' => $other->id]);

    expect(fn () => $service->start($other, $trip->company, $trip->id))->toThrow(AuthorizationException::class)
        ->and($trip->fresh()->getRawOriginal())->toBe($before);
});

test('starts the stored historical plan without rebuilding it after catalogue edits', function (): void {
    [$trip, $actor] = tripStartInputs();
    $before = $trip->only(['scheduled_departure_at', 'scheduled_arrival_at', 'planned_route_snapshot']);
    $trip->pattern->update(['name' => 'Changed catalogue name']);
    $trip->schedule->update(['departure_time' => '08:00:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));

    $started = app(TripManagementService::class)->start($actor, $trip->company, $trip->id);

    expect($started->only(['scheduled_departure_at', 'scheduled_arrival_at', 'planned_route_snapshot']))->toEqual($before)
        ->and($started->status)->toBe(TripStatus::IN_PROGRESS);
    Http::assertNothingSent();
});
