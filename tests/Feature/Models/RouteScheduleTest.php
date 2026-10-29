<?php

use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\RouteScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

describe('Route Schedule Ownership', function (): void {
    test('stores a weekly departure associated with its pattern', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $otherPattern = RoutePattern::factory()->for($route)->create();

        $schedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
        ]);

        $storedSchedule = $schedule->fresh();

        expect(Str::isUuid($storedSchedule->getKey()))->toBeTrue()
            ->and($storedSchedule->pattern->is($pattern))->toBeTrue()
            ->and($storedSchedule->day_of_week)->toBe(1)
            ->and($storedSchedule->departure_time)->toBe('06:30:00')
            ->and($pattern->schedules()
                ->whereKey($schedule->getKey())
                ->exists())->toBeTrue()
            ->and($otherPattern->schedules()->exists())->toBeFalse();

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $schedule->getKey(),
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
        ]);
    });
});

describe('Route Schedule Departure Constraints', function (): void {
    test('allows the same departure time on different weekdays', function (): void {
        $pattern = RoutePattern::factory()->create();

        foreach ([1, 2] as $day) {
            RouteSchedule::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'day_of_week' => $day,
                'departure_time' => '06:30:00',
            ]);
        }

        expect($pattern->schedules()
            ->orderBy('day_of_week')
            ->pluck('day_of_week')
            ->all())->toBe([1, 2]);
    });

    test('allows different patterns to share the same weekday and departure time', function (): void {
        $route = Route::factory()->create();

        $firstPattern = RoutePattern::factory()->for($route)->create();
        $secondPattern = RoutePattern::factory()->for($route)->create();

        foreach ([$firstPattern, $secondPattern] as $pattern) {
            RouteSchedule::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'day_of_week' => 1,
                'departure_time' => '06:30:00',
            ]);
        }

        expect($firstPattern->schedules()->count())->toBe(1)
            ->and($secondPattern->schedules()->count())->toBe(1);
    });

    test('rejects duplicate departures within the same pattern and weekday', function (): void {
        $pattern = RoutePattern::factory()->create();

        $attributes = [
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
        ];

        RouteSchedule::factory()->create($attributes);

        $duplicate = RouteSchedule::factory()->make($attributes);

        expect(fn () => $duplicate->save())
            ->toThrow(QueryException::class);
    });
});
describe('Route Schedule Validation', function (): void {
    test('accepts the weekday and time boundaries', function (int $day, string $time): void {
        $schedule = RouteSchedule::factory()->create([
            'day_of_week' => $day,
            'departure_time' => $time,
        ]);

        expect($schedule->fresh()->day_of_week)->toBe($day)
            ->and($schedule->fresh()->departure_time)->toBe($time);
    })->with([
        'sunday at midnight' => [0, '00:00:00'],
        'saturday before midnight' => [6, '23:59:59'],
    ]);

    test('rejects invalid weekdays', function (mixed $day): void {
        $pattern = RoutePattern::factory()->create();

        $schedule = RouteSchedule::factory()->make([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => $day,
        ]);

        expect(fn () => $schedule->save())
            ->toThrow(ValidationException::class)
            ->and($pattern->schedules()->exists())->toBeFalse();
    })->with([
        'below minimum' => -1,
        'above maximum' => 7,
        'fractional day' => 1.5,
        'non-numeric day' => 'monday',
    ]);

    test('rejects invalid departure times', function (string $time): void {
        $pattern = RoutePattern::factory()->create();

        $schedule = RouteSchedule::factory()->make([
            'route_pattern_id' => $pattern->getKey(),
            'departure_time' => $time,
        ]);

        expect(fn () => $schedule->save())
            ->toThrow(ValidationException::class)
            ->and($pattern->schedules()->exists())->toBeFalse();
    })->with([
        'hour outside range' => '24:00:00',
        'minute outside range' => '06:60:00',
        'missing seconds' => '06:30',
        'invalid text' => 'morning',
    ]);

    test('preserves a departure when an invalid update is rejected', function (): void {
        $schedule = RouteSchedule::factory()->create([
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
        ]);

        expect(fn () => $schedule->update([
            'day_of_week' => 7,
        ]))->toThrow(ValidationException::class)
            ->and($schedule->fresh()->day_of_week)->toBe(1)
            ->and($schedule->fresh()->departure_time)->toBe('06:30:00');
    });
});

describe('Route Schedule Archived References', function (): void {
    test('rejects scheduling an archived pattern or route', function (string $reference): void {
        $route = Route::factory()->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        match ($reference) {
            'route' => $route->delete(),
            'pattern' => $pattern->delete(),
        };

        $schedule = RouteSchedule::factory()->make([
            'route_pattern_id' => $pattern->getKey(),
        ]);

        expect(fn () => $schedule->save())
            ->toThrow(ValidationException::class)
            ->and(RouteSchedule::query()
                ->where('route_pattern_id', $pattern->getKey())->exists())->toBeFalse();
    })->with([
        'archived route' => 'route',
        'archived pattern' => 'pattern',
    ]);
});

describe('Route Schedule Validity', function (): void {
    test('stores the validity dates without changing their calendar day', function (): void {
        $schedule = RouteSchedule::factory()->create([
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-12-31',
        ]);

        $storedSchedule = $schedule->fresh();

        expect($storedSchedule->valid_from->format('Y-m-d'))
            ->toBe('2026-11-01')
            ->and($storedSchedule->valid_until->format('Y-m-d'))
            ->toBe('2026-12-31');

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $schedule->getKey(),
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-12-31',
        ]);
    });

    test('allows validity without a starting or ending boundary', function (?string $from, ?string $until): void {
        $schedule = RouteSchedule::factory()->create([
            'valid_from' => $from,
            'valid_until' => $until,
        ]);

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $schedule->getKey(),
            'valid_from' => $from,
            'valid_until' => $until,
        ]);
    })->with([
        'no boundaries' => [null, null],
        'starting boundary only' => ['2026-11-01', null],
        'ending boundary only' => [null, '2026-12-31'],
    ]);

    test('allows validity for a single calendar day', function (): void {
        $schedule = RouteSchedule::factory()->create([
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-11-01',
        ]);

        $storedSchedule = $schedule->fresh();

        expect($storedSchedule->valid_from->format('Y-m-d'))
            ->toBe('2026-11-01')
            ->and($storedSchedule->valid_until->format('Y-m-d'))
            ->toBe('2026-11-01');
    });

    test('rejects an ending date before the starting date', function (): void {
        $pattern = RoutePattern::factory()->create();

        $schedule = RouteSchedule::factory()->make([
            'route_pattern_id' => $pattern->getKey(),
            'valid_from' => '2026-12-01',
            'valid_until' => '2026-11-30',
        ]);

        expect(fn () => $schedule->save())
            ->toThrow(ValidationException::class)
            ->and($pattern->schedules()->exists())->toBeFalse();
    });

    test('preserves the validity dates when an invalid update is rejected', function (): void {
        $schedule = RouteSchedule::factory()->create([
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-12-31',
        ]);

        expect(fn () => $schedule->update([
            'valid_until' => '2026-10-31',
        ]))->toThrow(ValidationException::class);

        $storedSchedule = $schedule->fresh();

        expect($storedSchedule->valid_from->format('Y-m-d'))
            ->toBe('2026-11-01')
            ->and($storedSchedule->valid_until->format('Y-m-d'))
            ->toBe('2026-12-31');
    });
});

describe('Route Schedule Validity Overlap', function (): void {
    test('allows the same departure in consecutive non-overlapping periods', function (): void {
        $pattern = RoutePattern::factory()->create();

        $firstSchedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-11-30',
        ]);

        $secondSchedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-12-01',
            'valid_until' => null,
        ]);

        expect($pattern->schedules()->count())->toBe(2)
            ->and($firstSchedule->fresh()->valid_until->format('Y-m-d'))
            ->toBe('2026-11-30')
            ->and($secondSchedule->fresh()->valid_from->format('Y-m-d'))
            ->toBe('2026-12-01');
    });

    test('allows separated periods without an initial or final boundary', function (): void {
        $pattern = RoutePattern::factory()->create();

        RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => null,
            'valid_until' => '2026-11-30',
        ]);

        RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-12-01',
            'valid_until' => null,
        ]);

        expect($pattern->schedules()->count())->toBe(2);
    });

    test('rejects overlapping periods for the same departure', function (
        ?string $from,
        ?string $until,
    ): void {
        $pattern = RoutePattern::factory()->create();

        RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-11-30',
        ]);

        $conflictingSchedule = RouteSchedule::factory()->make([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => $from,
            'valid_until' => $until,
        ]);

        expect(fn () => $conflictingSchedule->save())
            ->toThrow(QueryException::class);
    })->with([
        'partial overlap' => ['2026-11-15', '2026-12-15'],
        'shared inclusive boundary' => ['2026-11-30', '2026-12-15'],
        'unbounded period' => [null, null],
    ]);
});

describe('Route Schedule Calendar Selection', function (): void {
    test('selects departures by weekday and inclusive validity boundaries', function (string $date, bool $expected): void {
        $company = createCompany([
            'country_code' => 'CR',
            'timezone' => 'America/Costa_Rica',
        ]);

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $otherPattern = RoutePattern::factory()->for($route)->create();

        $schedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-16',
        ]);

        RouteSchedule::factory()->create([
            'route_pattern_id' => $otherPattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
        ]);

        $localDate = CarbonImmutable::parse($date, $company->timezone);

        expect($pattern->schedules()
            ->scheduledOn($localDate)
            ->pluck('id')
            ->all())->toBe($expected ? [$schedule->getKey()] : []);
    })->with([
        'first valid day' => ['2026-11-02', true],
        'day inside validity' => ['2026-11-09', true],
        'last valid day' => ['2026-11-16', true],
        'before validity' => ['2026-10-26', false],
        'after validity' => ['2026-11-23', false],
        'different weekday' => ['2026-11-03', false],
    ]);

    test('selects the applicable period for a recurring departure', function (string $date, string $period): void {
        $company = createCompany([
            'country_code' => 'CR',
            'timezone' => 'America/Costa_Rica',
        ]);

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $schedules = [
            'previous' => RouteSchedule::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'day_of_week' => 1,
                'departure_time' => '06:30:00',
                'valid_from' => null,
                'valid_until' => '2026-11-30',
            ]),
            'next' => RouteSchedule::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'day_of_week' => 1,
                'departure_time' => '06:30:00',
                'valid_from' => '2026-12-01',
                'valid_until' => null,
            ]),
        ];

        $localDate = CarbonImmutable::parse($date, $company->timezone);

        expect($pattern->schedules()
            ->scheduledOn($localDate)
            ->pluck('id')
            ->all())->toBe([$schedules[$period]->getKey()]);
    })->with([
        'previous period' => ['2026-11-30', 'previous'],
        'next period' => ['2026-12-07', 'next'],
    ]);
});

describe('Route Schedule Changes With Exceptions', function (): void {
    test('rejects calendar changes that invalidate an existing suspension', function (array $changes): void {
        $schedule = RouteSchedule::factory()->create([
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-16',
        ]);

        $exception = RouteScheduleException::factory()->create([
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => '2026-11-09',
        ]);

        $schedule->load('exceptions');

        expect(fn () => $schedule->update($changes))
            ->toThrow(ValidationException::class);

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $schedule->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-16',
        ]);

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'id' => $exception->getKey(),
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => '2026-11-09',
        ]);
    })->with([
        'different weekday' => [['day_of_week' => 2]],
        'starting after the suspension' => [['valid_from' => '2026-11-10']],
        'ending before the suspension' => [['valid_until' => '2026-11-08']],
    ]);

    test('allows reducing validity when existing suspensions remain within its boundaries', function (): void {
        $schedule = RouteSchedule::factory()->create([
            'day_of_week' => 1,
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-16',
        ]);

        $exception = RouteScheduleException::factory()->create([
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => '2026-11-09',
        ]);

        $schedule->update([
            'valid_from' => '2026-11-09',
            'valid_until' => '2026-11-09',
        ]);

        $this->assertDatabaseHas(RouteSchedule::class, [
            'id' => $schedule->getKey(),
            'valid_from' => '2026-11-09',
            'valid_until' => '2026-11-09',
        ]);

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'id' => $exception->getKey(),
            'route_schedule_id' => $schedule->getKey(),
            'service_date' => '2026-11-09',
        ]);
    });
});
