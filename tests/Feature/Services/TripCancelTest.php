<?php

use App\Enums\CompanyStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\CompanyUser;
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
function tripCancellationInputs(): array
{
    Http::preventStrayRequests();

    return [Trip::factory()->withDriverAvailability()->create()->refresh(), createUserWithRole(UserRole::SuperAdmin)];
}

test('cancels a scheduled trip with a trimmed reason and server UTC timestamp while preserving its plan', function (): void {
    [$trip, $actor] = tripCancellationInputs();
    $before = $trip->getRawOriginal();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00.123456Z'));

    $cancelled = app(TripManagementService::class)->cancel($actor, $trip->company, $trip->id, "  Operational change.\n");

    expect($cancelled->status)->toBe(TripStatus::CANCELLED);
    expect($cancelled->cancellation_reason)->toBe('Operational change.');
    expect($cancelled->cancelled_at->utc()->format('Y-m-d H:i:s.uP'))->toBe('2026-10-26 12:00:00.123456+00:00');
    expect(Arr::except($cancelled->getRawOriginal(), ['status', 'cancellation_reason', 'cancelled_at', 'updated_at']))
        ->toBe(Arr::except($before, ['status', 'cancellation_reason', 'cancelled_at', 'updated_at']));
    Http::assertNothingSent();
});

test('rejects invalid cancellation reasons without persisting a transition', function (string $reason): void {
    [$trip, $actor] = tripCancellationInputs();
    $before = $trip->getRawOriginal();

    expect(fn () => app(TripManagementService::class)->cancel($actor, $trip->company, $trip->id, $reason))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toHaveKey('cancellation_reason');
        });
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with(['empty' => [''], 'spaces' => ['   '], 'control whitespace' => ["\t\r\n"],
    'too long' => [str_repeat('a', 501)], 'multibyte too long' => [str_repeat('ñ', 501)]]);

test('accepts exactly 500 multibyte characters after trimming the reason', function (): void {
    [$trip, $actor] = tripCancellationInputs();
    $reason = str_repeat('ñ', 500);

    $cancelled = app(TripManagementService::class)->cancel($actor, $trip->company, $trip->id, '  '.$reason."\n");

    expect($cancelled->status)->toBe(TripStatus::CANCELLED);
    expect($cancelled->cancellation_reason)->toBe($reason);
});

test('preserves the first cancellation timestamp and reason when a valid retry supplies a different reason', function (): void {
    [$trip, $actor] = tripCancellationInputs();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00.123456Z'));
    $service = app(TripManagementService::class);
    $before = $service->cancel($actor, $trip->company, $trip->id, 'Original reason')->getRawOriginal();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T13:00:00Z'));

    $retried = $service->cancel($actor, $trip->company, $trip->id, '  Different reason  ');

    expect($retried->getRawOriginal())->toBe($before);
});

test('validates the reason on an authorized cancellation retry while preserving the original record', function (): void {
    [$trip, $actor] = tripCancellationInputs();
    $service = app(TripManagementService::class);
    $before = $service->cancel($actor, $trip->company, $trip->id, 'Original reason')->getRawOriginal();

    expect(fn () => $service->cancel($actor, $trip->company, $trip->id, '   '))->toThrow(ValidationException::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
});

test('authorizes cancellation before reason validation or retry resolution', function (UserRole $role, bool $retry): void {
    [$trip, $super] = tripCancellationInputs();
    if ($retry) {
        $trip = app(TripManagementService::class)->cancel($super, $trip->company, $trip->id, 'Original reason');
    }
    $actor = $role === UserRole::Driver ? $trip->driver->user : createUserWithRole($role, $trip->company);
    if ($role !== UserRole::Driver) {
        CompanyUser::factory()->create(['company_id' => $trip->company_id, 'user_id' => $actor->id]);
    }
    grantShield($actor, ['Cancel:Trip'], $trip->company);
    $before = $trip->getRawOriginal();

    expect(fn () => app(TripManagementService::class)->cancel($actor, $trip->company, $trip->id, ''))->toThrow(AuthorizationException::class);
    expect(fn () => app(TripManagementService::class)->cancel($actor, $trip->company, 'invalid', ''))->toThrow(AuthorizationException::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with([UserRole::Driver, UserRole::Passenger])->with([false, true]);

test('rejects an administrator cancellation retry after authorization is revoked', function (): void {
    [$trip] = tripCancellationInputs();
    $actor = createUserWithRole(UserRole::Admin, $trip->company);
    CompanyUser::factory()->create(['company_id' => $trip->company_id, 'user_id' => $actor->id]);
    grantShield($actor, ['Cancel:Trip'], $trip->company);
    $before = app(TripManagementService::class)->cancel($actor, $trip->company, $trip->id, 'Original reason')->getRawOriginal();
    $actor->getConnection()->table('model_has_roles')->where('model_uuid', $actor->id)->where('company_id', $trip->company_id)->delete();
    $team = createCompany()->id;
    setPermissionsTeamId($team);

    expect(fn () => app(TripManagementService::class)->cancel($actor, $trip->company, $trip->id, 'New reason'))->toThrow(AuthorizationException::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
    expect(getPermissionsTeamId())->toBe($team);
});

test('rejects cancellation after execution begins or completes', function (bool $completed): void {
    [$trip, $actor] = tripCancellationInputs();
    $service = app(TripManagementService::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
    $trip = $service->start($actor, $trip->company, $trip->id);
    if ($completed) {
        $this->travelTo(CarbonImmutable::parse('2026-10-26T13:45:00Z'));
        $trip = $service->complete($actor, $trip->company, $trip->id);
    }
    $before = $trip->getRawOriginal();

    expect(fn () => $service->cancel($actor, $trip->company, $trip->id, 'Too late'))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['status' => [__('Only scheduled trips can be cancelled.')]]);
        });
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with([false, true]);

test('permits authorized cancellation in an inactive company to release a scheduled assignment', function (): void {
    [$trip] = tripCancellationInputs();
    $actor = createUserWithRole(UserRole::Admin, $trip->company);
    CompanyUser::factory()->create(['company_id' => $trip->company_id, 'user_id' => $actor->id]);
    grantShield($actor, ['Cancel:Trip'], $trip->company);
    $trip->company->update(['status' => CompanyStatus::INACTIVE]);

    $cancelled = app(TripManagementService::class)->cancel($actor, $trip->company, $trip->id, 'Company paused operations');

    expect($cancelled->status)->toBe(TripStatus::CANCELLED);
});

test('allows a replacement departure after cancellation without changing the cancelled history', function (): void {
    [$trip, $actor] = tripCancellationInputs();
    $service = app(TripManagementService::class);
    $cancelled = $service->cancel($actor, $trip->company, $trip->id, 'Replaced departure');
    $before = $cancelled->getRawOriginal();
    $data = [
        ...$trip->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
        'service_date' => $trip->service_date->toDateString(),
        'arrival_at' => $trip->scheduled_arrival_at->setTimezone($trip->company->timezone)->format('Y-m-d\TH:i:s.uP'),
    ];

    $replacement = $service->schedule($actor, $trip->company, $data);

    expect($replacement->id)->not->toBe($cancelled->id);
    expect($replacement->status)->toBe(TripStatus::SCHEDULED);
    expect($cancelled->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('trips', 2);
});

test('scopes cancellation to the selected company even for super administrators', function (): void {
    [$trip, $actor] = tripCancellationInputs();
    $before = $trip->getRawOriginal();

    expect(fn () => app(TripManagementService::class)->cancel($actor, createCompany(), $trip->id, 'Wrong company'))->toThrow(ModelNotFoundException::class);
    expect($trip->fresh()->getRawOriginal())->toBe($before);
});

test('returns bilingual errors when cancelling an executing trip', function (string $locale, string $message): void {
    [$trip, $actor] = tripCancellationInputs();
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
    $service = app(TripManagementService::class);
    $trip = $service->start($actor, $trip->company, $trip->id);
    $before = $trip->getRawOriginal();
    app()->setLocale($locale);

    expect(fn () => $service->cancel($actor, $trip->company, $trip->id, 'Too late'))
        ->toThrow(function (ValidationException $exception) use ($message): void {
            expect($exception->errors())->toBe(['status' => [$message]]);
        });
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with([['en', 'Only scheduled trips can be cancelled.'], ['es', 'Solo se pueden cancelar viajes programados.']]);

test('localizes cancellation reason validation with its domain field label', function (string $locale, string $reason, string $message): void {
    [$trip, $actor] = tripCancellationInputs();
    $before = $trip->getRawOriginal();
    app()->setLocale($locale);

    expect(fn () => app(TripManagementService::class)->cancel($actor, $trip->company, $trip->id, $reason))
        ->toThrow(function (ValidationException $exception) use ($message): void {
            expect($exception->errors())->toBe(['cancellation_reason' => [$message]]);
        });
    expect($trip->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['en', '', 'The cancellation reason field is required.'],
    ['es', '', 'El campo motivo de cancelación es obligatorio.'],
    ['en', str_repeat('a', 501), 'The cancellation reason field must not be greater than 500 characters.'],
    ['es', str_repeat('a', 501), 'El campo motivo de cancelación no debe ser mayor que 500 caracteres.'],
]);
