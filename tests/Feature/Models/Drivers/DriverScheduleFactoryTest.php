<?php

use App\Models\Driver;
use App\Models\DriverSchedule;
use Illuminate\Support\Facades\Http;

describe('Driver Schedule Factory', function (): void {
    test('persists deterministic shift states', function (string $state, string $start, string $end, bool $overnight): void {
        Http::preventStrayRequests();
        $driver = Driver::factory()->create();

        $schedule = DriverSchedule::factory()->for($driver)->{$state}()->create(['day_of_week' => 6])->fresh();

        expect($schedule->id)->toBeUuid()
            ->and($schedule->driver->id)->toBe($driver->id)
            ->and($schedule->day_of_week)->toBe(6)
            ->and($schedule->start_time)->toBe($start)
            ->and($schedule->end_time)->toBe($end)
            ->and($schedule->ends_next_day)->toBe($overnight);
        Http::assertNothingSent();
    })->with([
        'same day' => ['sameDay', '08:00:00', '16:00:00', false],
        'overnight' => ['overnight', '22:00:00', '06:00:00', true],
        'full local day' => ['fullDay', '08:00:00', '08:00:00', true],
    ]);

    test('supports bounded and explicitly unbounded validity', function (): void {
        $bounded = DriverSchedule::factory()->bounded('2026-11-01', '2026-11-30')->create();
        $unbounded = DriverSchedule::factory()->bounded('2026-11-01', '2026-11-30')->unbounded()->create();

        expect($bounded->valid_from->toDateString())->toBe('2026-11-01')
            ->and($bounded->valid_until->toDateString())->toBe('2026-11-30')
            ->and($unbounded->valid_from)->toBeNull()
            ->and($unbounded->valid_until)->toBeNull();
    });

    test('supports explicit adjacent and overlapping fixtures on one employment', function (): void {
        $driver = Driver::factory()->create();

        $schedules = DriverSchedule::factory()->for($driver)->sameDay()->bounded()->count(3)->sequence(
            ['start_time' => '08:00', 'end_time' => '12:00'],
            ['start_time' => '12:00', 'end_time' => '16:00'],
            ['start_time' => '11:00', 'end_time' => '13:00'],
        )->create();

        expect($schedules->map(fn (DriverSchedule $schedule): array => [$schedule->start_time, $schedule->end_time])->all())->toBe([
            ['08:00:00', '12:00:00'], ['12:00:00', '16:00:00'], ['11:00:00', '13:00:00'],
        ]);
        $this->assertDatabaseCount('drivers', 1);
        $this->assertDatabaseCount('users', 1, 'mysql');
    });
});
