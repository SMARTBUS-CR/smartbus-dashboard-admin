<?php

use App\Enums\TripStatus;
use App\Models\CompanyUser;
use App\Models\Trip;
use App\Services\TripDriverEligibilityService;
use App\Services\TripPlanPreparationService;
use App\Services\TripPlanSnapshotService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

test('persists a complete scheduled factory graph and canonical historical plan without granting membership', function (): void {
    Http::preventStrayRequests();

    $trip = Trip::factory()->create()->fresh();

    expect($trip->status)->toBe(TripStatus::SCHEDULED);
    expect($trip->getKey())->toBeUuid();
    foreach ([$trip->pattern->route, $trip->bus, $trip->driver] as $resource) {
        expect($resource->company_id)->toBe($trip->company_id);
    }
    expect($trip->schedule->route_pattern_id)->toBe($trip->route_pattern_id);
    expect($trip->planned_route_snapshot['occurrences'])->toHaveCount(2);
    expect($trip->planned_route_snapshot['revision_hash'])->toBe(app(TripPlanSnapshotService::class)->revisionHash($trip->planned_route_snapshot));
    expect($trip->scheduled_departure_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-26 12:30:00');
    expect($trip->scheduled_arrival_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-26 13:30:00');
    expect($trip->actual_departure_at)->toBeNull();
    expect($trip->actual_arrival_at)->toBeNull();
    expect(CompanyUser::query()->where('user_id', $trip->driver->user_id)->exists())->toBeFalse();
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
    expect(app(TripDriverEligibilityService::class)->validate($company, $trip->driver_id, ['2026-10-26', '2026-10-27'])->getKey())->toBe($trip->driver_id);
    expect($trip->driver->user->permissions)->toHaveCount(0);
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

    expect($trip->planned_route_snapshot)->toBe($snapshot);
    expect($trip->scheduled_departure_at->equalTo($departure))->toBeTrue();
    expect($trip->scheduled_arrival_at->equalTo($arrival))->toBeTrue();
    Http::assertNothingSent();
});
