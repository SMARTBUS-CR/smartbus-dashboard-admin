<?php

use App\Enums\TripAssignmentStatus;
use App\Models\TripAssignment;
use Illuminate\Support\Facades\Http;

describe('Trip Assignment Factory', function (): void {
    test('persists deterministic interval states', function (string $state, string $arrival, bool $endsNextDay): void {
        Http::preventStrayRequests();

        $assignment = TripAssignment::factory()->{$state}()->create()->fresh();

        expect($assignment->planned_arrival_time)->toBe($arrival)
            ->and($assignment->ends_next_day)->toBe($endsNextDay);
        Http::assertNothingSent();
    })->with([
        'same day' => ['sameDay', '07:30:00', false],
        'overnight' => ['overnight', '05:30:00', true],
        'complete local day' => ['fullDay', '06:30:00', true],
    ]);

    test('supports active inactive and archived lifecycle fixtures', function (): void {
        $active = TripAssignment::factory()->active()->create();
        $inactive = TripAssignment::factory()->inactive()->create();
        $archived = TripAssignment::factory()->archived()->create();

        expect($active->status)->toBe(TripAssignmentStatus::ACTIVE)
            ->and($inactive->status)->toBe(TripAssignmentStatus::INACTIVE)
            ->and($archived->trashed())->toBeTrue()
            ->and(TripAssignment::withTrashed()->find($archived->id))->not->toBeNull();
    });

    test('supports bounded and unbounded validity fixtures', function (): void {
        $bounded = TripAssignment::factory()->bounded('2026-11-01', '2027-03-31')->create();
        $unbounded = TripAssignment::factory()->bounded()->unbounded()->create();

        expect($bounded->valid_from->toDateString())->toBe('2026-11-01')
            ->and($bounded->valid_until->toDateString())->toBe('2027-03-31')
            ->and($unbounded->valid_from)->toBeNull()
            ->and($unbounded->valid_until)->toBeNull();
    });
});
