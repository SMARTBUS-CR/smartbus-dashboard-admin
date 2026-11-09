<?php

use App\Models\DriverSchedule;
use App\Services\DriverScheduleService;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverTrips;

uses(WithDriverTrips::class);

describe('Driver Trip Availability', function (): void {
    test('rolls back invalidating availability changes and preserves the assigned trip', function (string $status, array $data): void {
        [$actor, $trip] = $this->createAssignedDriverTrip($status);
        $schedule = DriverSchedule::query()->where('driver_id', $trip->driver_id)->sole();
        $before = $schedule->getRawOriginal();
        $history = $trip->getRawOriginal();

        expect(fn () => app(DriverScheduleService::class)->update($actor, $trip->company, $trip->driver, $schedule, $data))
            ->toThrow(ValidationException::class);

        expect($schedule->fresh()->getRawOriginal())->toBe($before)
            ->and($trip->fresh()->getRawOriginal())->toBe($history);
    })->with(['scheduled', 'in_progress'])->with([
        'late start' => [['start_time' => '07:00', 'end_time' => '09:00', 'ends_next_day' => false]],
        'early end' => [['start_time' => '06:00', 'end_time' => '07:00', 'ends_next_day' => false]],
        'wrong weekday' => [['day_of_week' => 2]],
        'validity starts later' => [['valid_from' => '2026-10-27', 'valid_until' => null]],
        'validity ends earlier' => [['valid_from' => null, 'valid_until' => '2026-10-25']],
        'overnight removes morning coverage' => [['start_time' => '22:00', 'end_time' => '06:00', 'ends_next_day' => true]],
    ]);

    test('permits exact boundary coverage and preserves adjacent intervals', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $schedule = DriverSchedule::query()->where('driver_id', $trip->driver_id)->sole();
        $service = app(DriverScheduleService::class);

        $service->update($actor, $trip->company, $trip->driver, $schedule, [
            'start_time' => '06:30', 'end_time' => '07:30', 'ends_next_day' => false,
            'valid_from' => '2026-10-26', 'valid_until' => '2026-10-26',
        ]);
        $added = $service->create($actor, $trip->company, $trip->driver, [
            'day_of_week' => 1, 'start_time' => '07:30', 'end_time' => '08:30',
            'valid_from' => '2026-10-26', 'valid_until' => '2026-10-26',
        ]);
        $service->delete($actor, $trip->company, $trip->driver, $added);

        expect($schedule->fresh()->start_time)->toBe('06:30:00')
            ->and($schedule->fresh()->end_time)->toBe('07:30:00');
        $this->assertModelExists($trip);
    });

    test('permits deleting availability after the assignment becomes historical', function (string $status): void {
        [$actor, $trip] = $this->createAssignedDriverTrip($status);
        $schedule = DriverSchedule::query()->where('driver_id', $trip->driver_id)->sole();
        $history = $trip->getRawOriginal();

        app(DriverScheduleService::class)->delete($actor, $trip->company, $trip->driver, $schedule);

        $this->assertModelMissing($schedule);
        expect($trip->fresh()->getRawOriginal())->toBe($history);
    })->with(['completed', 'cancelled']);

    test('protects previous day overnight coverage when editing its dates or interval', function (array $changes): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $schedule = DriverSchedule::query()->where('driver_id', $trip->driver_id)->sole();
        $service = app(DriverScheduleService::class);
        $service->update($actor, $trip->company, $trip->driver, $schedule, [
            'day_of_week' => 0, 'start_time' => '22:00', 'end_time' => '08:00', 'ends_next_day' => true,
            'valid_from' => '2026-10-25', 'valid_until' => '2026-10-25',
        ]);
        $before = $schedule->fresh()->getRawOriginal();

        expect(fn () => $service->update($actor, $trip->company, $trip->driver, $schedule, $changes))
            ->toThrow(ValidationException::class);

        expect($schedule->fresh()->getRawOriginal())->toBe($before);
        $this->assertModelExists($trip);
    })->with([
        'overnight end before departure' => [['end_time' => '06:00']],
        'starting day excluded by validity' => [['valid_from' => null, 'valid_until' => '2026-10-24']],
        'shift moved to Monday night' => [['day_of_week' => 1]],
    ]);
});
