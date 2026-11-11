<?php

use App\Enums\TripStatus;
use App\Models\CompanyUser;
use App\Models\DriverSchedule;
use App\Models\Trip;
use App\Services\DriverAvailabilityService;
use App\Services\TripDriverEligibilityService;
use App\Services\TripPlanPreparationService;
use App\Services\TripPlanSnapshotService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

test('persists a complete scheduled factory graph and canonical historical plan without granting membership', function (): void {
    Http::preventStrayRequests();

    $trip = Trip::factory()->create()->fresh();

    expect($trip->status)->toBe(TripStatus::SCHEDULED)
        ->and($trip->getKey())->toBeUuid();
    foreach ([$trip->pattern->route, $trip->bus, $trip->driver] as $resource) {
        expect($resource->company_id)->toBe($trip->company_id);
    }
    expect($trip->schedule->route_pattern_id)->toBe($trip->route_pattern_id)
        ->and($trip->planned_route_snapshot['occurrences'])->toHaveCount(2)
        ->and($trip->planned_route_snapshot['revision_hash'])->toBe(app(TripPlanSnapshotService::class)->revisionHash($trip->planned_route_snapshot))
        ->and($trip->scheduled_departure_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-26 12:30:00')
        ->and($trip->scheduled_arrival_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-26 13:30:00')
        ->and($trip->actual_departure_at)->toBeNull()
        ->and($trip->actual_arrival_at)->toBeNull()
        ->and(CompanyUser::query()->where('user_id', $trip->driver->user_id)->exists())->toBeFalse();
    $this->assertModelExists($trip);
    Http::assertNothingSent();
});

test('explicit eligible driver state preserves permission context and supports an overridden company and overnight dates', function (string $arrival, bool $eligible): void {
    Http::preventStrayRequests();
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $team = createCompany()->getKey();
    setPermissionsTeamId($team);
    $trip = Trip::factory()->for($company)->withEligibleDriver()->create([
        'scheduled_arrival_at' => $arrival,
    ])->fresh();
    expect(app(TripDriverEligibilityService::class)->validate($company, $trip->driver_id, ['2026-10-26', '2026-10-27'])->getKey())->toBe($trip->driver_id)
        ->and($trip->driver->user->permissions)->toBeEmpty();
    $trip->driver->update(['employment_ends_on' => '2026-10-26', 'license_expires_at' => '2026-10-26']);
    $plan = app(TripPlanPreparationService::class)->prepare($company, [
        'route_pattern_id' => $trip->route_pattern_id, 'route_schedule_id' => $trip->route_schedule_id,
        'bus_id' => $trip->bus_id, 'service_date' => $trip->service_date->toDateString(),
        'arrival_at' => $trip->scheduled_arrival_at->setTimezone($company->timezone)->format('Y-m-d\TH:i:sP'),
    ]);

    if ($eligible) {
        expect(app(TripDriverEligibilityService::class)->validate($company, $trip->driver_id, $plan['local_dates'])->getKey())->toBe($trip->driver_id);
    } else {
        expect(fn () => app(TripDriverEligibilityService::class)->validate($company, $trip->driver_id, $plan['local_dates']))
            ->toThrow(ValidationException::class);
    }
    expect(getPermissionsTeamId())->toBe($team);
    Http::assertNothingSent();
})->with([
    'midnight excludes next day' => ['2026-10-27T06:00:00+00:00', true],
    'after midnight requires next day' => ['2026-10-27T06:01:00+00:00', false],
]);

test('retains the persisted factory snapshot and times after catalogue edits', function (): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->create()->fresh();
    $snapshot = $trip->planned_route_snapshot;
    $departure = $trip->scheduled_departure_at;
    $arrival = $trip->scheduled_arrival_at;

    $trip->pattern->update(['name' => 'Changed catalogue pattern']);
    $trip->schedule->update(['departure_time' => '10:30:00']);
    $trip->pattern->stopOccurrences()->firstOrFail()->stop->update(['name' => 'Changed catalogue stop']);
    $trip->refresh();

    expect($trip->planned_route_snapshot)->toBe($snapshot)
        ->and($trip->scheduled_departure_at->equalTo($departure))->toBeTrue()
        ->and($trip->scheduled_arrival_at->equalTo($arrival))->toBeTrue();
    Http::assertNothingSent();
});

test('explicit availability fixtures cover the real local window without changing the default factory', function (string $arrival, array $dates): void {
    Http::preventStrayRequests();
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $team = createCompany()->getKey();
    setPermissionsTeamId($team);

    $trip = Trip::factory()->for($company)->withDriverAvailability()->create(['scheduled_arrival_at' => $arrival])->refresh();

    $slots = DriverSchedule::query()->where('driver_id', $trip->driver_id)->orderBy('valid_from')->get();
    expect($slots->pluck('valid_from')->map->toDateString()->all())->toBe($dates)
        ->and($slots->pluck('valid_until')->map->toDateString()->all())->toBe($dates)
        ->and(app(DriverAvailabilityService::class)->covers($trip->driver, $trip->scheduled_departure_at->utc(), $trip->scheduled_arrival_at->utc()))->toBeTrue();
    $plan = app(TripPlanPreparationService::class)->prepare($company, [
        'route_pattern_id' => $trip->route_pattern_id, 'route_schedule_id' => $trip->route_schedule_id,
        'bus_id' => $trip->bus_id, 'service_date' => $trip->service_date->toDateString(),
        'arrival_at' => $trip->scheduled_arrival_at->setTimezone($company->timezone)->format('Y-m-d\TH:i:sP'),
    ]);
    expect(app(TripDriverEligibilityService::class)->validateForPlan($company, $trip->driver_id, $plan)->getKey())->toBe($trip->driver_id)
        ->and(getPermissionsTeamId())->toBe($team);
    Http::assertNothingSent();
})->with([
    ['2026-10-26T13:30:00Z', ['2026-10-26']],
    ['2026-10-27T06:00:00Z', ['2026-10-26']],
    ['2026-10-27T06:01:00Z', ['2026-10-26', '2026-10-27']],
]);

test('default trip fixtures do not create driver availability', function (): void {
    Http::preventStrayRequests();

    Trip::factory()->create();

    $this->assertDatabaseCount('driver_schedules', 0);
    Http::assertNothingSent();
});

test('persists lifecycle factory states without weakening direct model guards', function (string $method, TripStatus $status): void {
    Http::preventStrayRequests();
    $trip = Trip::factory()->{$method}()->create()->refresh();
    expect($trip->status)->toBe($status)
        ->and($trip->actual_departure_at?->equalTo($trip->scheduled_departure_at))->toBe($status !== TripStatus::CANCELLED ? true : null)
        ->and($trip->actual_arrival_at?->equalTo($trip->scheduled_arrival_at))->toBe($status === TripStatus::COMPLETED ? true : null)
        ->and($trip->cancelled_at?->equalTo($trip->scheduled_departure_at->subHour()))->toBe($status === TripStatus::CANCELLED ? true : null)
        ->and($trip->cancellation_reason)->toBe($status === TripStatus::CANCELLED ? 'Demo cancellation' : null);
    $before = $trip->getRawOriginal();
    $trip->forceFill(['status' => TripStatus::SCHEDULED]);
    expect(fn () => $trip->saveQuietly())->toThrow(ValidationException::class)
        ->and($trip->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with([
    ['inProgress', TripStatus::IN_PROGRESS],
    ['completed', TripStatus::COMPLETED],
    ['cancelled', TripStatus::CANCELLED],
]);
