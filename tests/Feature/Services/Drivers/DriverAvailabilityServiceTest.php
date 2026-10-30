<?php

use App\Enums\CompanyStatus;
use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Services\DriverAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->company = createCompany(['timezone' => 'America/Costa_Rica']);
    $this->driver = Driver::factory()->for($this->company)->create();
});

describe('Driver Availability Service', function (): void {
    test('requires coverage of every instant including fractional endpoint boundaries', function (string $start, string $end, bool $covered): void {
        DriverSchedule::factory()->for($this->driver)->create(['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']);

        expect(app(DriverAvailabilityService::class)->covers($this->driver, CarbonImmutable::parse($start, 'UTC'), CarbonImmutable::parse($end, 'UTC')))->toBe($covered);
    })->with([
        ['2026-10-19 14:00:00', '2026-10-19 18:00:00', true],
        ['2026-10-19 15:00:00', '2026-10-19 17:00:00', true],
        ['2026-10-19 13:59:59.999999', '2026-10-19 18:00:00', false],
        ['2026-10-19 14:00:00', '2026-10-19 18:00:00.000001', false],
        ['2026-10-19 18:00:00', '2026-10-19 19:00:00', false],
        ['2026-10-19 17:59:59.999999', '2026-10-19 18:00:00', true],
    ]);

    test('merges adjacent or overlapping intervals but never bridges a gap', function (string $secondStart, bool $covered): void {
        DriverSchedule::factory()->for($this->driver)->create(['start_time' => '08:00', 'end_time' => '12:00']);
        DriverSchedule::factory()->for($this->driver)->create(['start_time' => $secondStart, 'end_time' => '16:00']);

        expect(app(DriverAvailabilityService::class)->covers($this->driver, CarbonImmutable::parse('2026-10-19 14:00', 'UTC'), CarbonImmutable::parse('2026-10-19 22:00', 'UTC')))->toBe($covered);
    })->with([['12:00', true], ['11:00', true], ['12:00:01', false]]);

    test('includes previous-day overnight portions using validity on the starting day', function (string $end, bool $covered): void {
        DriverSchedule::factory()->for($this->driver)->create([
            'day_of_week' => 0, 'start_time' => '22:00', 'end_time' => '06:00', 'ends_next_day' => true,
            'valid_from' => '2026-10-18', 'valid_until' => '2026-10-18',
        ]);

        expect(app(DriverAvailabilityService::class)->covers($this->driver, CarbonImmutable::parse('2026-10-19 06:00', 'UTC'), CarbonImmutable::parse($end, 'UTC')))->toBe($covered);
    })->with([['2026-10-19 12:00:00', true], ['2026-10-19 12:00:00.000001', false]]);

    test('uses the local weekday rather than the UTC weekday', function (): void {
        DriverSchedule::factory()->for($this->driver)->create(['day_of_week' => 0, 'start_time' => '20:00', 'end_time' => '23:00']);

        expect(app(DriverAvailabilityService::class)->covers($this->driver, CarbonImmutable::parse('2026-10-19 02:00', 'UTC'), CarbonImmutable::parse('2026-10-19 05:00', 'UTC')))->toBeTrue();
    });

    test('respects inclusive nullable validity dates and weekly occurrences', function (?string $from, ?string $until, bool $covered): void {
        DriverSchedule::factory()->for($this->driver)->create(['valid_from' => $from, 'valid_until' => $until]);

        expect(app(DriverAvailabilityService::class)->covers($this->driver, CarbonImmutable::parse('2026-10-19 14:00', 'UTC'), CarbonImmutable::parse('2026-10-19 18:00', 'UTC')))->toBe($covered);
    })->with([
        [null, null, true], ['2026-10-19', '2026-10-19', true], [null, '2026-10-18', false],
        ['2026-10-20', null, false], ['2026-10-12', '2026-10-18', false],
    ]);

    test('covers multiple local dates only when every intervening day is covered', function (bool $gap): void {
        foreach (range(0, 6) as $weekday) {
            if ($gap && $weekday === 2) {
                continue;
            }
            DriverSchedule::factory()->for($this->driver)->create(['day_of_week' => $weekday, 'start_time' => '00:00', 'end_time' => '00:00', 'ends_next_day' => true]);
        }

        expect(app(DriverAvailabilityService::class)->covers($this->driver, CarbonImmutable::parse('2026-10-18 15:00', 'UTC'), CarbonImmutable::parse('2026-10-22 02:00', 'UTC')))->toBe(! $gap);
    })->with([false, true]);

    test('rejects invalid requested intervals with field errors before checking driver data', function (string $start, string $end, string $field): void {
        expect(fn () => app(DriverAvailabilityService::class)->covers($this->driver, CarbonImmutable::parse($start), CarbonImmutable::parse($end)))
            ->toThrow(function (ValidationException $exception) use ($field): void {
                expect($exception->errors())->toHaveKey($field);
            });
    })->with([
        ['2026-10-19T14:00:00Z', '2026-10-19T14:00:00Z', 'end_utc'],
        ['2026-10-19T14:00:00Z', '2026-10-19T13:00:00Z', 'end_utc'],
        ['2026-10-19T08:00:00-06:00', '2026-10-19T18:00:00Z', 'start_utc'],
        ['2026-10-19T14:00:00Z', '2026-10-19T12:00:00-06:00', 'end_utc'],
    ]);

    test('returns false for missing inactive or archived employment and company', function (string $state): void {
        DriverSchedule::factory()->for($this->driver)->create();
        match ($state) {
            'driver inactive' => $this->driver->update(['status' => DriverStatus::INACTIVE]),
            'driver archived' => $this->driver->delete(),
            'company inactive' => $this->company->update(['status' => CompanyStatus::INACTIVE]),
            'company archived' => $this->company->delete(),
            'missing' => $this->driver->id = fake()->uuid(),
        };

        expect(app(DriverAvailabilityService::class)->covers($this->driver, CarbonImmutable::parse('2026-10-19 14:00', 'UTC'), CarbonImmutable::parse('2026-10-19 18:00', 'UTC')))->toBeFalse();
    })->with(['driver inactive', 'driver archived', 'company inactive', 'company archived', 'missing']);

    test('reads persisted schedules deterministically without identities UI or network access', function (): void {
        Http::preventStrayRequests();
        $this->driver->setRelation('company', createCompany(['timezone' => 'Pacific/Auckland']));
        $this->driver->setRelation('schedules', collect());
        $this->driver->status = DriverStatus::INACTIVE;
        $start = CarbonImmutable::parse('2026-10-19 14:00', 'UTC');
        $end = CarbonImmutable::parse('2026-10-19 18:00', 'UTC');
        $mysqlQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$mysqlQueries): void {
            if ($query->connectionName === 'mysql') {
                $mysqlQueries[] = $query->sql;
            }
        });
        $service = app(DriverAvailabilityService::class);
        expect($service->covers($this->driver, $start, $end))->toBeFalse();
        DriverSchedule::factory()->for($this->driver)->create();
        expect($service->covers($this->driver, $start, $end))->toBeTrue();
        expect($service->covers($this->driver, $start, $end))->toBeTrue();
        expect($mysqlQueries)->toBe([]);
        expect($start->format('Y-m-d H:i:sP'))->toBe('2026-10-19 14:00:00+00:00');
        Http::assertNothingSent();
    });

    test('does not round a requested interval entirely inside a subsecond portion of a gap', function (): void {
        DriverSchedule::factory()->for($this->driver)->create(['end_time' => '12:00']);
        DriverSchedule::factory()->for($this->driver)->create(['start_time' => '12:00:01']);

        expect(app(DriverAvailabilityService::class)->covers($this->driver, CarbonImmutable::parse('2026-10-19 18:00:00.1', 'UTC'), CarbonImmutable::parse('2026-10-19 18:00:00.9', 'UTC')))->toBeFalse();
    });

    test('supports UTC companies and zero-offset endpoint aliases', function (): void {
        $this->company->update(['timezone' => 'UTC']);
        DriverSchedule::factory()->for($this->driver)->create();

        expect(app(DriverAvailabilityService::class)->covers($this->driver, CarbonImmutable::parse('2026-10-19 08:00', 'GMT'), CarbonImmutable::parse('2026-10-19 16:00', 'Etc/UTC')))->toBeTrue();
    });

    test('returns translated interval errors', function (string $locale, bool $nonUtc, string $message): void {
        app()->setLocale($locale);
        $start = CarbonImmutable::parse($nonUtc ? '2026-10-19T08:00:00-06:00' : '2026-10-19T14:00:00Z');
        $end = CarbonImmutable::parse($nonUtc ? '2026-10-19T18:00:00Z' : '2026-10-19T14:00:00Z');
        $field = $nonUtc ? 'start_utc' : 'end_utc';

        expect(fn () => app(DriverAvailabilityService::class)->covers($this->driver, $start, $end))
            ->toThrow(function (ValidationException $exception) use ($field, $message): void {
                expect($exception->errors())->toBe([$field => [$message]]);
            });
    })->with([
        ['en', true, 'Availability interval endpoints must use UTC.'],
        ['es', true, 'Los extremos del intervalo de disponibilidad deben estar en UTC.'],
        ['en', false, 'The end of the availability interval must be after its start.'],
        ['es', false, 'El fin del intervalo de disponibilidad debe ser posterior a su inicio.'],
    ]);

    test('never fills a gap using another employment profile including one sharing the same user', function (): void {
        $otherDriver = Driver::factory()->for($this->company)->create();
        $otherCompany = createCompany(['timezone' => 'America/Costa_Rica']);
        $otherEmployment = Driver::factory()->for($otherCompany)->for($this->driver->user)->create();
        foreach ([$otherDriver, $otherEmployment] as $driver) {
            DriverSchedule::factory()->for($driver)->create();
        }
        DriverSchedule::factory()->for($this->driver)->create(['end_time' => '10:00']);

        expect(app(DriverAvailabilityService::class)->covers($this->driver, CarbonImmutable::parse('2026-10-19 14:00', 'UTC'), CarbonImmutable::parse('2026-10-19 18:00', 'UTC')))->toBeFalse();
    });
});
