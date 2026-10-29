<?php

use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Services\DriverAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

describe('Driver Availability Timezone', function (): void {
    test('maps real UTC instants through daylight changes independently of the server timezone', function (array $schedule, string $start, string $end, bool $covered, string $serverTimezone): void {
        config(['app.timezone' => $serverTimezone]);
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set($serverTimezone);
        try {
            $company = createCompany(['timezone' => 'America/New_York']);
            $driver = Driver::factory()->for($company)->create();
            DriverSchedule::factory()->for($driver)->create(array_replace(['day_of_week' => 0], $schedule));

            expect(app(DriverAvailabilityService::class)->covers($driver, CarbonImmutable::parse($start, 'UTC'), CarbonImmutable::parse($end, 'UTC')))->toBe($covered);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    })->with([
        'spring crossing' => [['start_time' => '01:30', 'end_time' => '03:30'], '2026-03-08 06:30', '2026-03-08 07:30', true],
        'spring outside start' => [['start_time' => '01:30', 'end_time' => '03:30'], '2026-03-08 06:29:59', '2026-03-08 07:30', false],
        'nonexistent start contributes surviving times' => [['start_time' => '02:15', 'end_time' => '03:15'], '2026-03-08 07:00', '2026-03-08 07:15', true],
        'entirely nonexistent window' => [['start_time' => '02:15', 'end_time' => '02:45'], '2026-03-08 07:00', '2026-03-08 07:01', false],
        'first repeated occurrence' => [['start_time' => '01:15', 'end_time' => '01:45'], '2026-11-01 05:15', '2026-11-01 05:45', true],
        'second repeated occurrence' => [['start_time' => '01:15', 'end_time' => '01:45'], '2026-11-01 06:15', '2026-11-01 06:45', true],
        'fold must not bridge outside wall times' => [['start_time' => '01:15', 'end_time' => '01:45'], '2026-11-01 05:30', '2026-11-01 06:30', false],
        'full repeated period' => [['start_time' => '00:30', 'end_time' => '02:30'], '2026-11-01 04:30', '2026-11-01 07:30', true],
        'transition is an exclusive endpoint' => [['start_time' => '01:15', 'end_time' => '02:00'], '2026-11-01 05:15', '2026-11-01 06:00', true],
        'one microsecond beyond transition enters a gap' => [['start_time' => '01:15', 'end_time' => '02:00'], '2026-11-01 05:15', '2026-11-01 06:00:00.000001', false],
        '23 hour local day' => [['start_time' => '00:00', 'end_time' => '00:00', 'ends_next_day' => true], '2026-03-08 05:00', '2026-03-09 04:00', true],
        '25 hour local day' => [['start_time' => '00:00', 'end_time' => '00:00', 'ends_next_day' => true], '2026-11-01 04:00', '2026-11-02 05:00', true],
        'spring overnight' => [['day_of_week' => 6, 'start_time' => '22:00', 'end_time' => '03:00', 'ends_next_day' => true], '2026-03-08 03:00', '2026-03-08 07:00', true],
        'fall overnight final validity day' => [['day_of_week' => 6, 'start_time' => '22:00', 'end_time' => '03:00', 'ends_next_day' => true, 'valid_until' => '2026-10-31'], '2026-11-01 02:00', '2026-11-01 08:00', true],
    ])->with(['UTC', 'Pacific/Auckland']);

    test('does not assume daylight transitions are one hour', function (string $start, string $end, bool $covered): void {
        $company = createCompany(['timezone' => 'Australia/Lord_Howe']);
        $driver = Driver::factory()->for($company)->create();
        DriverSchedule::factory()->for($driver)->create(['day_of_week' => 0, 'start_time' => '01:40', 'end_time' => '01:50']);

        expect(app(DriverAvailabilityService::class)->covers($driver, CarbonImmutable::parse($start, 'UTC'), CarbonImmutable::parse($end, 'UTC')))->toBe($covered);
    })->with([
        ['2026-04-04 14:40', '2026-04-04 14:50', true],
        ['2026-04-04 15:10', '2026-04-04 15:20', true],
        ['2026-04-04 14:45', '2026-04-04 15:15', false],
    ]);

    test('rejects invalid persisted company timezone configuration', function (): void {
        $company = createCompany(['timezone' => 'Invalid/Timezone']);
        $driver = Driver::factory()->for($company)->create();

        expect(fn () => app(DriverAvailabilityService::class)->covers($driver, CarbonImmutable::parse('2026-10-19 14:00', 'UTC'), CarbonImmutable::parse('2026-10-19 18:00', 'UTC')))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toHaveKey('timezone');
            });
    });

    test('merges adjacent local schedules through both occurrences of a repeated hour', function (): void {
        $company = createCompany(['timezone' => 'America/New_York']);
        $driver = Driver::factory()->for($company)->create();
        DriverSchedule::factory()->for($driver)->create(['day_of_week' => 0, 'start_time' => '01:00', 'end_time' => '01:30']);
        DriverSchedule::factory()->for($driver)->create(['day_of_week' => 0, 'start_time' => '01:30', 'end_time' => '02:00']);

        expect(app(DriverAvailabilityService::class)->covers($driver, CarbonImmutable::parse('2026-11-01 05:00', 'UTC'), CarbonImmutable::parse('2026-11-01 07:00', 'UTC')))->toBeTrue();
    });
});
