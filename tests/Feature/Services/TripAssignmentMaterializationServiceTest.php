<?php

use App\Enums\TripStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Trip;
use App\Models\TripAssignment;
use App\Services\TripAssignmentMaterializationService;
use Carbon\CarbonImmutable;

/** @return array{Company, TripAssignment} */
function materializationFixture(?Company $company = null): array
{
    $attributes = ['service_date' => '2026-10-26'];
    if ($company !== null) {
        $attributes['company_id'] = $company->getKey();
    }

    $plannedTrip = Trip::factory()->withDriverAvailability()->make($attributes);
    $company ??= Company::query()->findOrFail($plannedTrip->company_id);
    $assignment = TripAssignment::factory()->create([
        'company_id' => $company->getKey(),
        'route_schedule_id' => $plannedTrip->route_schedule_id,
        'driver_id' => $plannedTrip->driver_id,
        'bus_id' => $plannedTrip->bus_id,
        'planned_arrival_time' => $plannedTrip->scheduled_arrival_at
            ->setTimezone($company->timezone)->format('H:i:s'),
        'ends_next_day' => false,
        'valid_from' => '2026-10-26',
        'valid_until' => '2026-10-26',
    ]);

    return [$company, $assignment];
}

describe('Trip Assignment Materialization Service', function (): void {
    test('creates one scheduled trip with an immutable snapshot and is retry safe', function (): void {
        [$company, $assignment] = materializationFixture();
        $service = app(TripAssignmentMaterializationService::class);

        $first = $service->materialize($company, CarbonImmutable::parse('2026-10-26'));
        $second = $service->materialize($company, CarbonImmutable::parse('2026-10-26'));
        $trip = Trip::query()->sole();

        expect($first['created'])->toHaveCount(1)
            ->and($first['created'][0]['assignment_id'])->toBe($assignment->id)
            ->and($second['created'])->toBeEmpty()
            ->and($second['skipped'])->toHaveCount(1)
            ->and($trip->status)->toBe(TripStatus::SCHEDULED)
            ->and($trip->service_date->toDateString())->toBe('2026-10-26')
            ->and($trip->actual_departure_at)->toBeNull()
            ->and($trip->actual_arrival_at)->toBeNull()
            ->and($trip->planned_route_snapshot)->not->toBeEmpty();
    });

    test('treats a cancelled trip as already materialized without changing its history', function (): void {
        [$company] = materializationFixture();
        $service = app(TripAssignmentMaterializationService::class);
        $service->materialize($company, CarbonImmutable::parse('2026-10-26'));
        $trip = Trip::query()->sole();
        Trip::query()->whereKey($trip->id)->update([
            'status' => TripStatus::CANCELLED->value,
            'cancelled_at' => '2026-10-26 11:00:00+00',
            'cancellation_reason' => 'Service suspended',
        ]);

        $result = $service->materialize($company, CarbonImmutable::parse('2026-10-26'));

        expect($result['created'])->toBeEmpty()
            ->and($result['skipped'])->toHaveCount(1)
            ->and(Trip::query()->sole()->status)->toBe(TripStatus::CANCELLED)
            ->and(Trip::query()->sole()->cancellation_reason)->toBe('Service suspended');
    });

    test('isolates an invalid assignment while materializing unrelated valid work', function (): void {
        [$company] = materializationFixture();
        [, $invalidAssignment] = materializationFixture($company);
        Bus::query()->whereKey($invalidAssignment->bus_id)->update(['is_active' => false]);

        $result = app(TripAssignmentMaterializationService::class)
            ->materialize($company, CarbonImmutable::parse('2026-10-26'));

        expect($result['created'])->toHaveCount(1)
            ->and($result['failed'])->toHaveCount(1)
            ->and($result['failed'][0]['assignment_id'])->toBe($invalidAssignment->id)
            ->and(Trip::query()->count())->toBe(1);
    });
});
