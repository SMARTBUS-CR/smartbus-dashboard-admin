<?php

use App\Enums\TripStatus;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverAttendanceService;
use App\Services\DriverAvailabilityService;
use App\Services\DriverManagementService;
use App\Services\DriverReadService;
use App\Services\DriverScheduleService;
use App\Services\TripManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverRegistration;

pest()->use(WithDriverRegistration::class);

describe('Driver Workforce Integration', function (): void {
    test('keeps identity employment availability trips and historical reporting consistent', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, [
            'View:Driver', 'Update:Driver', 'Delete:Driver', 'Restore:Driver',
            'ViewSalary:Driver', 'ViewAttendance:Driver',
            'Create:DriverSchedule', 'Update:DriverSchedule', 'Delete:DriverSchedule',
            'Create:Trip', 'Start:Trip', 'Complete:Trip',
        ], $company);

        $management = app(DriverManagementService::class);
        $identityData = $this->validDriverIdentity(['email' => 'integration.driver@example.test']);
        $driver = $management->register(
            $actor,
            $company,
            $identityData,
            $this->validDriverEmployment(['license' => ' integration-dw-tc30 ']),
        );
        $identity = User::query()->findOrFail($driver->user_id);
        $driverRoleAssigned = DB::connection('mysql')->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.company_id', $company->id)
            ->where('assignments.model_uuid', $identity->id)
            ->where('roles.name', 'driver')
            ->where('roles.guard_name', 'web')
            ->exists();
        $schedule = app(DriverScheduleService::class)->create($actor, $company, $driver, [
            'day_of_week' => 1,
            'start_time' => '06:00',
            'end_time' => '08:00',
            'ends_next_day' => false,
            'valid_from' => '2026-10-26',
            'valid_until' => '2026-10-26',
        ]);

        expect($driver->id)->toBeUuid()
            ->and($driver->license)->toBe('INTEGRATION-DW-TC30')
            ->and(Hash::check($identityData['password'], $identity->password))->toBeTrue()
            ->and(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $identity->id)->exists())->toBeTrue()
            ->and($driverRoleAssigned)->toBeTrue()
            ->and(app(DriverAvailabilityService::class)->covers(
                $driver,
                CarbonImmutable::parse('2026-10-26T12:30:00Z'),
                CarbonImmutable::parse('2026-10-26T13:30:00Z'),
            ))->toBeTrue();

        $fixture = Trip::factory()->for($company)->for($driver, 'driver')->make();
        $trip = app(TripManagementService::class)->schedule($actor, $company, [
            ...$fixture->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
            'service_date' => '2026-10-26',
            'arrival_at' => '2026-10-26T07:30:00-06:00',
        ]);
        $tripBeforeEmploymentUpdate = $trip->getRawOriginal();

        $updated = $management->updateEmployment($actor, $company, $driver, [
            'salary_amount' => '700000.10',
        ]);

        expect($updated->salary_amount)->toBe('700000.10')
            ->and($trip->fresh()->getRawOriginal())->toBe($tripBeforeEmploymentUpdate)
            ->and(array_keys($updated->toArray()))->not->toContain('salary_amount', 'salary_currency', 'salary_period', 'user')
            ->and(app(DriverReadService::class)->salaryFor($actor, $company, $driver->id))->toBe([
                'salary_amount' => '700000.10',
                'salary_currency' => 'CRC',
                'salary_period' => 'monthly',
            ]);

        $scheduleBeforeInvalidUpdate = $schedule->fresh()->getRawOriginal();
        expect(fn () => app(DriverScheduleService::class)->update(
            $actor,
            $company,
            $driver,
            $schedule,
            ['end_time' => '07:00'],
        ))->toThrow(ValidationException::class)
            ->and($schedule->fresh()->getRawOriginal())->toBe($scheduleBeforeInvalidUpdate)
            ->and($trip->fresh()->getRawOriginal())->toBe($tripBeforeEmploymentUpdate);

        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:30:00Z'));
        app(TripManagementService::class)->start($actor, $company, $trip->id);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T13:30:00Z'));
        $completed = app(TripManagementService::class)->complete($actor, $company, $trip->id);
        $management->archive($actor, $company, $driver);

        $summary = app(DriverAttendanceService::class)->summarize(
            $actor,
            $company,
            $driver,
            CarbonImmutable::parse('2026-10-26', $company->timezone),
            CarbonImmutable::parse('2026-10-26', $company->timezone),
        );

        expect($completed->status)->toBe(TripStatus::COMPLETED)
            ->and(Driver::onlyTrashed()->findOrFail($driver->id)->id)->toBe($driver->id)
            ->and(User::query()->findOrFail($identity->id)->id)->toBe($identity->id)
            ->and(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $identity->id)->exists())->toBeTrue()
            ->and($summary['days'][0])->toMatchArray([
                'service_date' => '2026-10-26',
                'assigned_count' => 1,
                'started_count' => 1,
                'completed_count' => 1,
                'cancelled_count' => 0,
                'pending_count' => 0,
                'in_progress_count' => 0,
                'first_departure_at' => '2026-10-26T12:30:00.000000Z',
                'last_arrival_at' => '2026-10-26T13:30:00.000000Z',
            ])
            ->and(json_encode($summary, JSON_THROW_ON_ERROR))->not->toContain('700000.10')->not->toContain($identity->email)->not->toContain($identity->password);
    });
});
