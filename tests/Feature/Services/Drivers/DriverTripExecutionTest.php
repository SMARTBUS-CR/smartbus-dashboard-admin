<?php

use App\Enums\TripStatus;
use App\Models\CompanyUser;
use App\Models\DriverSchedule;
use App\Services\DriverManagementService;
use App\Services\DriverScheduleService;
use App\Services\TripManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverTrips;

uses(WithDriverTrips::class);

describe('Driver Trip Execution', function (): void {
    test('rejects the real start after current identity eligibility is revoked', function (string $revocation): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $driver = $trip->driver;
        match ($revocation) {
            'identity' => $driver->user->delete(),
            'membership' => CompanyUser::query()->where('company_id', $trip->company_id)->where('user_id', $driver->user_id)->sole()->delete(),
            'role' => DB::connection('mysql')->table('model_has_roles')->where('model_uuid', $driver->user_id)->where('company_id', $trip->company_id)->delete(),
        };
        $before = $trip->getRawOriginal();
        $this->travelTo($trip->scheduled_departure_at->addMinutes(15));

        expect(fn () => app(TripManagementService::class)->start($actor, $trip->company, $trip->id))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toHaveKey('driver_id');
            });

        expect($trip->fresh()->getRawOriginal())->toBe($before);
    })->with(['identity', 'membership', 'role']);

    test('starts successfully after compatible workforce changes', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $driver = $trip->driver;
        $schedule = DriverSchedule::query()->where('driver_id', $driver->id)->sole();
        app(DriverManagementService::class)->updateEmployment($actor, $trip->company, $driver, [
            'license' => 'COMPATIBLE-LICENSE', 'employment_ends_on' => '2026-10-26', 'license_expires_at' => '2026-10-26',
        ]);
        app(DriverScheduleService::class)->update($actor, $trip->company, $driver, $schedule, [
            'start_time' => '06:30', 'end_time' => '07:30', 'ends_next_day' => false,
        ]);
        $snapshot = $trip->planned_route_snapshot;
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));

        $started = app(TripManagementService::class)->start($actor, $trip->company, $trip->id);

        expect($started->status)->toBe(TripStatus::IN_PROGRESS)
            ->and($started->actual_departure_at->utc()->toIso8601String())->toBe('2026-10-26T12:45:00+00:00')
            ->and($started->planned_route_snapshot)->toBe($snapshot);
    });

    test('protects actual execution availability after a late start or overtime', function (string $start, string $now, string $end): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $this->travelTo(CarbonImmutable::parse($start));
        $trip = app(TripManagementService::class)->start($actor, $trip->company, $trip->id);
        $this->travelTo(CarbonImmutable::parse($now));
        $schedule = DriverSchedule::query()->where('driver_id', $trip->driver_id)->sole();
        $before = $schedule->getRawOriginal();
        $history = $trip->getRawOriginal();

        expect(fn () => app(DriverScheduleService::class)->update($actor, $trip->company, $trip->driver, $schedule, [
            'start_time' => '00:00', 'end_time' => $end, 'ends_next_day' => false,
        ]))->toThrow(ValidationException::class);

        expect($schedule->fresh()->getRawOriginal())->toBe($before)
            ->and($trip->fresh()->getRawOriginal())->toBe($history);
    })->with([
        'late start' => ['2026-10-26T14:30:00Z', '2026-10-26T14:30:00Z', '08:00'],
        'execution past planned arrival' => ['2026-10-26T12:45:00Z', '2026-10-26T14:45:00Z', '08:30'],
        'current instant at exclusive schedule end' => ['2026-10-26T12:45:00Z', '2026-10-26T14:30:00Z', '08:30'],
    ]);

    test('protects employment eligibility on the actual execution date', function (string $field, string $start, string $now): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        DriverSchedule::factory()->for($trip->driver)->fullDay()->create([
            'day_of_week' => 2, 'start_time' => '00:00', 'end_time' => '00:00',
            'valid_from' => '2026-10-27', 'valid_until' => '2026-10-27',
        ]);
        $this->travelTo(CarbonImmutable::parse($start));
        $trip = app(TripManagementService::class)->start($actor, $trip->company, $trip->id);
        $this->travelTo(CarbonImmutable::parse($now));
        $driver = $trip->driver;
        $before = $driver->getRawOriginal();
        $history = $trip->getRawOriginal();

        expect(fn () => app(DriverManagementService::class)->updateEmployment($actor, $trip->company, $driver, [$field => '2026-10-26']))
            ->toThrow(ValidationException::class);

        expect($driver->fresh()->getRawOriginal())->toBe($before)
            ->and($trip->fresh()->getRawOriginal())->toBe($history);
    })->with(['employment_ends_on', 'license_expires_at'])->with([
        'late start on next day' => ['2026-10-27T12:45:00Z', '2026-10-27T12:45:00Z'],
        'execution crosses into next day' => ['2026-10-26T12:45:00Z', '2026-10-27T12:45:00Z'],
    ]);

    test('does not invent required coverage between the planned interval and a late actual start', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $schedule = DriverSchedule::query()->where('driver_id', $trip->driver_id)->sole();
        $service = app(DriverScheduleService::class);
        $service->update($actor, $trip->company, $trip->driver, $schedule, [
            'start_time' => '06:30', 'end_time' => '07:30', 'ends_next_day' => false,
        ]);
        $service->create($actor, $trip->company, $trip->driver, [
            'day_of_week' => 1, 'start_time' => '16:00', 'end_time' => '18:00',
            'valid_from' => '2026-10-26', 'valid_until' => '2026-10-26',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T22:30:00Z'));
        $trip = app(TripManagementService::class)->start($actor, $trip->company, $trip->id);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T22:45:00Z'));

        $updated = $service->update($actor, $trip->company, $trip->driver, $schedule, ['end_time' => '08:00']);

        expect($updated->end_time)->toBe('08:00:00')->and($trip->fresh()->status)->toBe(TripStatus::IN_PROGRESS);
    });

    test('keeps protecting active assignments when the trip is soft deleted', function (string $status, string $operation): void {
        [$actor, $trip] = $this->createAssignedDriverTrip($status);
        $trip->delete();
        $history = $trip->fresh()->getRawOriginal();
        $driver = $trip->driver;
        $schedule = DriverSchedule::query()->where('driver_id', $driver->id)->sole();

        expect(fn () => $operation === 'archive'
            ? app(DriverManagementService::class)->archive($actor, $trip->company, $driver)
            : app(DriverScheduleService::class)->delete($actor, $trip->company, $driver, $schedule))
            ->toThrow(ValidationException::class);

        expect($trip->fresh()->getRawOriginal())->toBe($history);
        $this->assertModelExists($driver);
        $this->assertModelExists($schedule);
    })->with(['scheduled', 'in_progress'])->with(['archive', 'delete availability']);
});
