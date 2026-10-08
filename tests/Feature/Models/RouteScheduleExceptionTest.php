<?php

use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\RouteScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

describe('Route Schedule Date Exceptions', function (): void {
    test('cancels a departure only on the specified local date', function (): void {
        $pattern = RoutePattern::factory()->create();

        $schedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
        ]);

        $exception = RouteScheduleException::factory()->create([
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => '2026-11-02',
        ]);

        $cancelledDate = CarbonImmutable::parse(
            '2026-11-02',
            $pattern->route->company->timezone,
        );

        $followingWeek = $cancelledDate->addWeek();

        expect(Str::isUuid($exception->getKey()))->toBeTrue()
            ->and($exception->fresh()->schedule->is($schedule))->toBeTrue()
            ->and($pattern->schedules()
                ->scheduledOn($cancelledDate)
                ->exists())->toBeFalse()
            ->and($pattern->schedules()
                ->scheduledOn($followingWeek)
                ->pluck('id')
                ->all())->toBe([$schedule->getKey()]);

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'id' => $exception->getKey(),
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => '2026-11-02',
        ]);
    });

    test('preserves other departures when one departure is cancelled', function (): void {
        $pattern = RoutePattern::factory()->create();

        $cancelledSchedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
        ]);

        $remainingSchedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '07:00:00',
        ]);

        RouteScheduleException::factory()->create([
            'route_schedule_id' => $cancelledSchedule->getKey(),
            'service_date' => '2026-11-02',
        ]);

        $date = CarbonImmutable::parse(
            '2026-11-02',
            $pattern->route->company->timezone,
        );

        expect($pattern->schedules()
            ->scheduledOn($date)
            ->pluck('id')
            ->all())->toBe([$remainingSchedule->getKey()]);
    });

    test('restores the departure when its date exception is removed', function (): void {
        $schedule = RouteSchedule::factory()->create([
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
        ]);

        $exception = RouteScheduleException::factory()->create([
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => '2026-11-02',
        ]);

        $pattern = $schedule->pattern;

        $date = CarbonImmutable::parse(
            '2026-11-02',
            $pattern->route->company->timezone,
        );

        expect($pattern->schedules()
            ->scheduledOn($date)
            ->exists())->toBeFalse();

        $exception->delete();

        expect($pattern->schedules()
            ->scheduledOn($date)
            ->pluck('id')
            ->all())->toBe([$schedule->getKey()]);

        $this->assertDatabaseMissing(RouteScheduleException::class, [
            'id' => $exception->getKey(),
        ]);
    });

    test('rejects duplicate exceptions for the same departure and date', function (): void {
        $schedule = RouteSchedule::factory()->create([
            'day_of_week' => 1,
        ]);

        $attributes = [
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => '2026-11-02',
        ];

        RouteScheduleException::factory()->create($attributes);

        $duplicate = RouteScheduleException::factory()->make($attributes);

        expect(fn () => $duplicate->save())
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('Route Schedule Exception Date Validation', function (): void {
    test('allows suspensions on the inclusive validity boundaries', function (string $date): void {
        $schedule = RouteSchedule::factory()->create([
            'day_of_week' => 1,
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-16',
        ]);

        $exception = RouteScheduleException::factory()->create([
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => $date,
        ]);

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'id' => $exception->getKey(),
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => $date,
        ]);
    })->with([
        'first valid day' => '2026-11-02',
        'last valid day' => '2026-11-16',
    ]);

    test('rejects a suspension outside the departure calendar', function (string $date): void {
        $schedule = RouteSchedule::factory()->create([
            'day_of_week' => 1,
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-16',
        ]);

        $exception = RouteScheduleException::factory()->make([
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => $date,
        ]);

        expect(fn () => $exception->save())
            ->toThrow(ValidationException::class)
            ->and($schedule->exceptions()->exists())->toBeFalse();
    })->with([
        'before validity' => '2026-10-26',
        'after validity' => '2026-11-23',
        'different weekday' => '2026-11-03',
    ]);

    test('preserves the suspension when an invalid date update is rejected', function (): void {
        $schedule = RouteSchedule::factory()->create([
            'day_of_week' => 1,
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-16',
        ]);

        $exception = RouteScheduleException::factory()->create([
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => '2026-11-09',
        ]);

        expect(fn () => $exception->update([
            'service_date' => '2026-11-03',
        ]))->toThrow(ValidationException::class)
            ->and($exception->fresh()->service_date->format('Y-m-d'))->toBe('2026-11-09');
    });

    test('allows saving an existing suspension without treating itself as a calendar conflict', function (): void {
        $schedule = RouteSchedule::factory()->create([
            'day_of_week' => 1,
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-16',
        ]);

        $exception = RouteScheduleException::factory()->create([
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => '2026-11-09',
        ]);

        expect($exception->save())->toBeTrue()
            ->and($schedule->exceptions()->count())->toBe(1)
            ->and($exception->fresh()->service_date->format('Y-m-d'))->toBe('2026-11-09');
    });
});
