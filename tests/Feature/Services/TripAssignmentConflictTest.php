<?php

use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\Role;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\User;
use App\Services\TripAssignmentManagementService;
use Illuminate\Validation\ValidationException;

/** @return array{User, Company, RoutePattern, Driver, Bus} */
function tripAssignmentConflictFixture(): array
{
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $pattern = RoutePattern::factory()->for(Route::factory()->for($company))->create();
    $driver = Driver::factory()->for($company)->create([
        'employment_starts_on' => '2026-01-01',
        'license_expires_at' => '2030-12-31',
    ]);
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $driver->user_id]);
    $previousTeam = getPermissionsTeamId();
    try {
        setPermissionsTeamId($company->id);
        $driver->user->assignRole(Role::findOrCreate(UserRole::Driver->value, 'web'));
    } finally {
        setPermissionsTeamId($previousTeam);
    }
    DriverSchedule::factory()->for($driver)->fullDay()->create([
        'day_of_week' => 1,
        'start_time' => '00:00:00',
        'end_time' => '00:00:00',
    ]);
    DriverSchedule::factory()->for($driver)->fullDay()->create([
        'day_of_week' => 2,
        'start_time' => '00:00:00',
        'end_time' => '00:00:00',
    ]);

    return [$actor, $company, $pattern, $driver, Bus::factory()->for($company)->active()->create()];
}

/** @return array<string, mixed> */
function recurringAssignmentData(RouteSchedule $schedule, Driver $driver, Bus $bus, string $arrival, bool $overnight = false): array
{
    return [
        'route_schedule_id' => $schedule->id,
        'driver_id' => $driver->id,
        'bus_id' => $bus->id,
        'planned_arrival_time' => $arrival,
        'ends_next_day' => $overnight,
        'valid_from' => '2026-11-02',
        'valid_until' => '2026-12-28',
    ];
}

function provisionConflictDriver(Company $company, Driver $driver): void
{
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $driver->user_id]);
    $previousTeam = getPermissionsTeamId();
    try {
        setPermissionsTeamId($company->id);
        $driver->user->assignRole(Role::findOrCreate(UserRole::Driver->value, 'web'));
    } finally {
        setPermissionsTeamId($previousTeam);
    }
    DriverSchedule::factory()->for($driver)->fullDay()->create([
        'day_of_week' => 1,
        'start_time' => '00:00:00',
        'end_time' => '00:00:00',
    ]);
}

describe('Trip Assignment Conflicts', function (): void {
    test('rejects duplicate departure and overlapping driver or bus assignments', function (string $resource): void {
        [$actor, $company, $pattern, $driver, $bus] = tripAssignmentConflictFixture();
        $firstSchedule = RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 1, 'departure_time' => '06:30:00']);
        $secondSchedule = $resource === 'route_schedule_id'
            ? $firstSchedule
            : RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 1, 'departure_time' => '07:00:00']);
        $otherDriver = Driver::factory()->for($company)->create();
        provisionConflictDriver($company, $otherDriver);
        $otherBus = Bus::factory()->for($company)->active()->create();
        $service = app(TripAssignmentManagementService::class);
        $service->create($actor, $company, recurringAssignmentData($firstSchedule, $driver, $bus, '08:00:00'));
        $candidateDriver = $resource === 'bus_id' ? $otherDriver : $driver;
        $candidateBus = $resource === 'driver_id' ? $otherBus : $bus;

        expect(fn () => $service->create(
            $actor,
            $company,
            recurringAssignmentData($secondSchedule, $candidateDriver, $candidateBus, '08:30:00'),
        ))->toThrow(function (ValidationException $exception) use ($resource): void {
            expect($exception->errors())->toHaveKey($resource);
        });
        $this->assertDatabaseCount('trip_assignments', 1);
    })->with(['route_schedule_id', 'driver_id', 'bus_id']);

    test('allows adjacent intervals and date-disjoint reuse of resources', function (): void {
        [$actor, $company, $pattern, $driver, $bus] = tripAssignmentConflictFixture();
        $morning = RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 1, 'departure_time' => '06:30:00']);
        $adjacent = RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 1, 'departure_time' => '07:30:00']);
        $service = app(TripAssignmentManagementService::class);
        $service->create($actor, $company, recurringAssignmentData($morning, $driver, $bus, '07:30:00'));

        $second = $service->create($actor, $company, recurringAssignmentData($adjacent, $driver, $bus, '08:30:00'));
        $third = $service->create($actor, $company, [
            ...recurringAssignmentData($morning, $driver, $bus, '07:30:00'),
            'valid_from' => '2027-01-04',
            'valid_until' => '2027-02-01',
        ]);

        $this->assertModelExists($second);
        $this->assertModelExists($third);
        $this->assertDatabaseCount('trip_assignments', 3);
    });

    test('detects previous-day overnight conflicts', function (): void {
        [$actor, $company, $pattern, $driver, $bus] = tripAssignmentConflictFixture();
        $night = RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 1, 'departure_time' => '22:00:00']);
        $morning = RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 2, 'departure_time' => '05:00:00']);
        $service = app(TripAssignmentManagementService::class);
        $service->create($actor, $company, recurringAssignmentData($night, $driver, $bus, '06:00:00', true));

        expect(fn () => $service->create($actor, $company, recurringAssignmentData($morning, $driver, Bus::factory()->for($company)->active()->create(), '08:00:00')))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toHaveKey('driver_id');
            });
    });

    test('ignores inactive definitions but revalidates conflicts when activating', function (): void {
        [$actor, $company, $pattern, $driver, $bus] = tripAssignmentConflictFixture();
        $schedule = RouteSchedule::factory()->for($pattern, 'pattern')->create(['day_of_week' => 1, 'departure_time' => '06:30:00']);
        $service = app(TripAssignmentManagementService::class);
        $first = $service->create($actor, $company, recurringAssignmentData($schedule, $driver, $bus, '07:30:00'));
        $service->setActive($actor, $company, $first, false);
        $service->create($actor, $company, recurringAssignmentData($schedule, $driver, $bus, '07:30:00'));

        expect(fn () => $service->setActive($actor, $company, $first, true))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toHaveKey('route_schedule_id');
            })
            ->and($first->fresh()->status->value)->toBe('inactive');
    });
});
