<?php

use App\Enums\CompanyStatus;
use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** @return array{Trip, User} */
function tripCompletionInputs(): array
{
    Http::preventStrayRequests();
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    test()->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00.123456Z'));

    return [app(TripManagementService::class)->start($actor, $trip->company, $trip->id), $actor];
}

test('completes a running trip with a server UTC arrival while preserving its plan and departure', function (): void {
    [$trip, $actor] = tripCompletionInputs();
    $before = $trip->getRawOriginal();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T13:45:00.654321Z'));

    $completed = app(TripManagementService::class)->complete($actor, $trip->company, $trip->id);

    expect($completed->status)->toBe(TripStatus::COMPLETED);
    expect($completed->actual_arrival_at->utc()->format('Y-m-d H:i:s.uP'))->toBe('2026-10-26 13:45:00.654321+00:00');
    expect(Arr::except($completed->getRawOriginal(), ['status', 'actual_arrival_at', 'updated_at']))
        ->toBe(Arr::except($before, ['status', 'actual_arrival_at', 'updated_at']));
    Http::assertNothingSent();
});

test('allows completion by the assigned driver without administrative permissions', function (): void {
    [$trip] = tripCompletionInputs();
    $actor = $trip->driver->user;
    $team = createCompany()->id;
    setPermissionsTeamId($team);
    $actor->load('roles', 'permissions');
    $this->travelTo(CarbonImmutable::parse('2026-10-26T13:45:00Z'));

    $completed = app(TripManagementService::class)->complete($actor, $trip->company, $trip->id);

    expect($completed->status)->toBe(TripStatus::COMPLETED);
    expect(getPermissionsTeamId())->toBe($team);
    expect($actor->relationLoaded('roles'))->toBeFalse();
    expect($actor->relationLoaded('permissions'))->toBeFalse();
});

test('permits closing history after company and operational resources become unavailable', function (): void {
    [$trip, $actor] = tripCompletionInputs();
    $driver = $trip->driver;
    $driver->user->delete();
    $trip->company->update(['status' => CompanyStatus::INACTIVE]);
    Bus::query()->whereKey($trip->bus_id)->update(['is_active' => false, 'deleted_at' => now()]);
    Driver::query()->whereKey($trip->driver_id)->update(['status' => DriverStatus::INACTIVE, 'deleted_at' => now(), 'license_expires_at' => '2026-10-25']);
    DriverSchedule::query()->where('driver_id', $trip->driver_id)->delete();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T13:45:00Z'));

    $completed = app(TripManagementService::class)->complete($actor, $trip->company, $trip->id);

    expect($completed->status)->toBe(TripStatus::COMPLETED);
    expect($completed->actual_arrival_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-26 13:45:00');
});

test('requires actual arrival to be at or after actual departure without clamping server time', function (string $instant, bool $allowed): void {
    [$trip, $actor] = tripCompletionInputs();
    $before = $trip->getRawOriginal();
    $this->travelTo(CarbonImmutable::parse($instant));

    if ($allowed) {
        $completed = app(TripManagementService::class)->complete($actor, $trip->company, $trip->id);
        expect($completed->actual_arrival_at->equalTo($completed->actual_departure_at))->toBeTrue();
        expect($completed->status)->toBe(TripStatus::COMPLETED);
    } else {
        expect(fn () => app(TripManagementService::class)->complete($actor, $trip->company, $trip->id))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toHaveKey('actual_arrival_at');
            });
        expect($trip->fresh()->getRawOriginal())->toBe($before);
    }
})->with([['2026-10-26T12:45:00.123456Z', true], ['2026-10-26T12:45:00.123455Z', false]]);

test('preserves the first completion on authorized retries regardless of later server time', function (string $retryInstant): void {
    [$trip, $actor] = tripCompletionInputs();
    $service = app(TripManagementService::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-26T13:45:00.654321Z'));
    $before = $service->complete($actor, $trip->company, $trip->id)->getRawOriginal();
    $this->travelTo(CarbonImmutable::parse($retryInstant));

    $retried = $service->complete($actor, $trip->company, $trip->id);

    expect($retried->getRawOriginal())->toBe($before);
})->with(['2026-10-26T14:45:00Z', '2026-10-26T12:00:00Z']);

test('authorizes before validating identifiers and resolving a completion retry', function (bool $retry): void {
    [$trip, $actor] = tripCompletionInputs();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T13:45:00Z'));
    if ($retry) {
        $trip = app(TripManagementService::class)->complete($actor, $trip->company, $trip->id);
    }
    $other = createUserWithRole(UserRole::Driver, $trip->company);
    CompanyUser::factory()->create(['company_id' => $trip->company_id, 'user_id' => $other->id]);
    grantShield($other, ['Complete:Trip'], $trip->company);
    $before = $trip->getRawOriginal();
    $team = createCompany()->id;
    setPermissionsTeamId($team);

    expect(fn () => app(TripManagementService::class)->complete($other, $trip->company, $trip->id))->toThrow(AuthorizationException::class);
    $passenger = createUserWithRole(UserRole::Passenger, $trip->company);
    expect(fn () => app(TripManagementService::class)->complete($passenger, $trip->company, 'invalid'))->toThrow(AuthorizationException::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
    expect(getPermissionsTeamId())->toBe($team);
})->with([false, true]);

test('rejects completion retries after current authorization is revoked', function (string $revoked): void {
    [$trip] = tripCompletionInputs();
    $actor = $revoked === 'permission' ? createUserWithRole(UserRole::Admin, $trip->company) : $trip->driver->user;
    if ($revoked === 'permission') {
        CompanyUser::factory()->create(['company_id' => $trip->company_id, 'user_id' => $actor->id]);
        grantShield($actor, ['Complete:Trip'], $trip->company);
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-26T13:45:00Z'));
    $before = app(TripManagementService::class)->complete($actor, $trip->company, $trip->id)->getRawOriginal();
    if ($revoked === 'membership') {
        CompanyUser::query()->where('company_id', $trip->company_id)->where('user_id', $actor->id)->delete();
    } elseif ($revoked === 'identity') {
        $actor->delete();
    } else {
        $actor->getConnection()->table('model_has_roles')->where('model_uuid', $actor->id)->where('company_id', $trip->company_id)->delete();
    }

    expect(fn () => app(TripManagementService::class)->complete($actor, $trip->company, $trip->id))->toThrow(AuthorizationException::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with(['membership', 'role', 'identity', 'permission']);

test('rejects completion from incompatible states without changing history', function (bool $cancelled): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
    if ($cancelled) {
        $trip = app(TripManagementService::class)->cancel($actor, $trip->company, $trip->id, 'Preserved cancellation');
    }
    $before = $trip->getRawOriginal();

    expect(fn () => app(TripManagementService::class)->complete($actor, $trip->company, $trip->id))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['status' => [__('Only in-progress trips can be completed.')]]);
        });
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with([false, true]);

test('scopes completion to the selected company even for super administrators', function (): void {
    [$trip, $actor] = tripCompletionInputs();
    $before = $trip->getRawOriginal();

    expect(fn () => app(TripManagementService::class)->complete($actor, createCompany(), $trip->id))->toThrow(ModelNotFoundException::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
});

test('allows an authorized company administrator to complete after the company becomes inactive', function (): void {
    [$trip] = tripCompletionInputs();
    $actor = createUserWithRole(UserRole::Admin, $trip->company);
    CompanyUser::factory()->create(['company_id' => $trip->company_id, 'user_id' => $actor->id]);
    grantShield($actor, ['Complete:Trip'], $trip->company);
    $trip->company->update(['status' => CompanyStatus::INACTIVE]);
    $this->travelTo(CarbonImmutable::parse('2026-10-26T13:45:00Z'));

    $completed = app(TripManagementService::class)->complete($actor, $trip->company, $trip->id);

    expect($completed->status)->toBe(TripStatus::COMPLETED);
});

test('releases an overdue bus for another start only after the running trip is completed', function (): void {
    [$trip, $actor] = tripCompletionInputs();
    $next = Trip::factory()->for($trip->company)->withDriverAvailability()->create(['bus_id' => $trip->bus_id, 'service_date' => '2026-11-02']);
    $service = app(TripManagementService::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-02T12:45:00Z'));
    expect(fn () => $service->start($actor, $trip->company, $next->id))->toThrow(ValidationException::class);

    $completed = $service->complete($actor, $trip->company, $trip->id);
    $started = $service->start($actor, $trip->company, $next->id);

    expect($completed->status)->toBe(TripStatus::COMPLETED);
    expect($started->status)->toBe(TripStatus::IN_PROGRESS);
    expect($completed->actual_arrival_at->equalTo($started->actual_departure_at))->toBeTrue();
});

test('returns bilingual completion state and chronology errors', function (string $locale, string $scenario, string $field, string $message): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $service = app(TripManagementService::class);
    if ($scenario === 'chronology') {
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
        $trip = $service->start($actor, $trip->company, $trip->id);
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
    $before = $trip->getRawOriginal();
    app()->setLocale($locale);

    expect(fn () => $service->complete($actor, $trip->company, $trip->id))
        ->toThrow(function (ValidationException $exception) use ($field, $message): void {
            expect($exception->errors())->toBe([$field => [$message]]);
        });
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['en', 'state', 'status', 'Only in-progress trips can be completed.'],
    ['es', 'state', 'status', 'Solo se pueden completar viajes en curso.'],
    ['en', 'chronology', 'actual_arrival_at', 'The actual arrival cannot be before the actual departure.'],
    ['es', 'chronology', 'actual_arrival_at', 'La llegada real no puede ser anterior a la salida real.'],
]);
