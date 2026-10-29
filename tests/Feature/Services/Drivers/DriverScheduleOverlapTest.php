<?php

use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Services\DriverScheduleService;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

describe('Driver Schedule Overlap', function (): void {
    test('checks effective weekly intervals in either insertion order', function (array $first, array $second, bool $conflict, bool $reverse): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, ['Create:DriverSchedule'], $company);
        $driver = Driver::factory()->for($company)->create();
        $defaults = ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'ends_next_day' => false, 'valid_from' => null, 'valid_until' => null];
        [$existing, $candidate] = $reverse ? [$second, $first] : [$first, $second];
        DriverSchedule::factory()->for($driver)->create(array_replace($defaults, $existing));
        $create = fn (): DriverSchedule => app(DriverScheduleService::class)->create($actor, $company, $driver, array_replace($defaults, $candidate));

        if ($conflict) {
            expect($create)->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toHaveKey('schedule');
            });
        } else {
            $this->assertModelExists($create());
        }
        $this->assertDatabaseCount('driver_schedules', $conflict ? 1 : 2);
    })->with([
        'duplicate' => [[], [], true],
        'partial overlap' => [[], ['start_time' => '10:00', 'end_time' => '14:00'], true],
        'enclosure' => [[], ['start_time' => '07:00', 'end_time' => '13:00'], true],
        'adjacent before' => [[], ['start_time' => '06:00', 'end_time' => '08:00'], false],
        'adjacent after' => [[], ['start_time' => '12:00', 'end_time' => '16:00'], false],
        'different weekdays' => [[], ['day_of_week' => 3], false],
        'overnight spillover' => [['start_time' => '22:00', 'end_time' => '06:00', 'ends_next_day' => true], ['day_of_week' => 2, 'start_time' => '05:00', 'end_time' => '08:00'], true],
        'overnight adjacency' => [['start_time' => '22:00', 'end_time' => '06:00', 'ends_next_day' => true], ['day_of_week' => 2, 'start_time' => '06:00', 'end_time' => '08:00'], false],
        'overnight ending at midnight' => [['start_time' => '22:00', 'end_time' => '00:00', 'ends_next_day' => true], ['day_of_week' => 2, 'start_time' => '00:00', 'end_time' => '01:00'], false],
        'two overnight shifts' => [['start_time' => '22:00', 'end_time' => '06:00', 'ends_next_day' => true], ['day_of_week' => 2, 'start_time' => '05:00', 'end_time' => '02:00', 'ends_next_day' => true], true],
        'week wrap' => [['day_of_week' => 6, 'start_time' => '22:00', 'end_time' => '06:00', 'ends_next_day' => true], ['day_of_week' => 0, 'start_time' => '05:00', 'end_time' => '08:00'], true],
        'full local day spillover' => [['start_time' => '08:00', 'end_time' => '08:00', 'ends_next_day' => true], ['day_of_week' => 2, 'start_time' => '07:00', 'end_time' => '09:00'], true],
        'full local day adjacency' => [['start_time' => '08:00', 'end_time' => '08:00', 'ends_next_day' => true], ['start_time' => '07:00', 'end_time' => '08:00'], false],
        'one second overlap' => [[], ['start_time' => '11:59:59', 'end_time' => '13:00'], true],
        'disjoint dates' => [['valid_until' => '2026-10-19'], ['valid_from' => '2026-10-26'], false],
        'inclusive Monday' => [['valid_until' => '2026-10-19'], ['valid_from' => '2026-10-19', 'valid_until' => '2026-10-19'], true],
        'no effective Monday' => [[], ['valid_from' => '2026-10-20', 'valid_until' => '2026-10-25'], false],
        'overlapping bounds without shared weekday' => [['valid_from' => '2026-10-19', 'valid_until' => '2026-10-25'], ['valid_from' => '2026-10-20', 'valid_until' => '2026-10-26'], false],
        'spillover beyond final starting day' => [['start_time' => '22:00', 'end_time' => '06:00', 'ends_next_day' => true, 'valid_until' => '2026-10-19'], ['day_of_week' => 2, 'start_time' => '05:00', 'end_time' => '08:00', 'valid_from' => '2026-10-20', 'valid_until' => '2026-10-20'], true],
        'spillover misses validity by one week' => [['start_time' => '22:00', 'end_time' => '06:00', 'ends_next_day' => true, 'valid_until' => '2026-10-19'], ['day_of_week' => 2, 'start_time' => '05:00', 'end_time' => '08:00', 'valid_from' => '2026-10-27'], false],
    ])->with([false, true]);
});
