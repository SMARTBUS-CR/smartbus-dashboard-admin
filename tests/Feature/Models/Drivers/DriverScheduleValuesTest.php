<?php

use App\Models\Driver;
use App\Models\DriverSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

describe('Driver Schedule Values', function (): void {
    test('normalizes working times and persists UUID ownership without transport assignments', function (): void {
        $driver = Driver::factory()->create();
        $schedule = DriverSchedule::factory()->for($driver)->create(['start_time' => '08:30', 'end_time' => '16:45']);

        expect($schedule->start_time)->toBe('08:30:00');
        expect($schedule->end_time)->toBe('16:45:00');
        expect($schedule->id)->toBeUuid();
        expect($schedule->fresh()->driver->id)->toBe($driver->id);
        expect($schedule->fresh()->toArray())->not->toHaveKeys(['route_id', 'route_pattern_id', 'bus_id', 'driver']);
    });

    test('rejects invalid values on creation and update while preserving stored schedules', function (array $data, string $field, bool $update): void {
        $driver = Driver::factory()->create();
        $schedule = $update ? DriverSchedule::factory()->for($driver)->create() : null;
        $before = $schedule?->fresh()->getRawOriginal();

        expect(function () use ($data, $driver, $schedule): void {
            if ($schedule !== null) {
                $schedule->fill($data)->save();
            } else {
                DriverSchedule::factory()->for($driver)->create($data);
            }
        })->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        });
        if ($schedule !== null) {
            expect($schedule->fresh()->getRawOriginal())->toBe($before);
        }
        $this->assertDatabaseCount('driver_schedules', $update ? 1 : 0);
    })->with([
        [['day_of_week' => -1], 'day_of_week'], [['day_of_week' => 7], 'day_of_week'],
        [['day_of_week' => '1.5'], 'day_of_week'], [['ends_next_day' => 'false'], 'ends_next_day'],
        [['day_of_week' => null], 'day_of_week'], [['start_time' => null], 'start_time'], [['end_time' => null], 'end_time'],
        [['start_time' => '24:00'], 'start_time'], [['start_time' => '9:00'], 'start_time'],
        [['end_time' => '17:60:00'], 'end_time'], [['end_time' => 'not-a-time'], 'end_time'],
        [['start_time' => '09:00:00.1'], 'start_time'],
        [['start_time' => '16:00', 'end_time' => '08:00'], 'end_time'],
        [['start_time' => '08:00', 'end_time' => '08:00'], 'end_time'],
        [['start_time' => '08:00', 'end_time' => '16:00', 'ends_next_day' => true], 'end_time'],
        [['valid_from' => '2026-10-20', 'valid_until' => '2026-10-19'], 'valid_until'],
    ])->with([false, true]);

    test('validates original calendar dates before Eloquent conversion', function (string $field, string $value, bool $update): void {
        $driver = Driver::factory()->create();
        $schedule = $update ? DriverSchedule::factory()->for($driver)->create() : null;
        $before = $schedule?->fresh()->getRawOriginal();

        expect(function () use ($field, $value, $driver, $schedule): void {
            if ($schedule !== null) {
                $schedule->fill([$field => $value])->save();
            } else {
                DriverSchedule::factory()->for($driver)->create([$field => $value]);
            }
        })->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        });
        if ($schedule !== null) {
            expect($schedule->fresh()->getRawOriginal())->toBe($before);
        }
    })->with(['valid_from', 'valid_until'])->with(['2026-02-30', '2026-13-01', 'not-a-date', '0000-01-01'])->with([false, true]);

    test('accepts a complete local day and inclusive single-day validity', function (): void {
        $schedule = DriverSchedule::factory()->create([
            'start_time' => '08:00', 'end_time' => '08:00', 'ends_next_day' => true,
            'valid_from' => '2026-10-19', 'valid_until' => '2026-10-19',
        ])->fresh();

        expect($schedule->ends_next_day)->toBeTrue();
        expect($schedule->valid_from->toDateString())->toBe('2026-10-19');
        expect($schedule->start_time)->toBe($schedule->end_time);
    });

    test('prevents persisted schedule identity and ownership reassignment', function (string $field): void {
        $schedule = DriverSchedule::factory()->create();
        $before = $schedule->fresh()->getRawOriginal();
        $schedule->{$field} = Driver::factory()->create()->id;

        expect(fn () => $schedule->save())->toThrow(ValidationException::class);
        expect(DriverSchedule::findOrFail($before['id'])->getRawOriginal())->toBe($before);
    })->with(['id', 'driver_id']);

    test('retains the physical foreign key restriction on employment deletion', function (): void {
        $schedule = DriverSchedule::factory()->create();

        expect(fn () => DB::transaction(fn () => DB::table('drivers')->where('id', $schedule->driver_id)->delete()))
            ->toThrow(function (QueryException $exception): void {
                expect($exception->errorInfo[0])->toBe('23503');
            });
        $this->assertModelExists($schedule);
        $this->assertModelExists($schedule->driver);
    });
});
