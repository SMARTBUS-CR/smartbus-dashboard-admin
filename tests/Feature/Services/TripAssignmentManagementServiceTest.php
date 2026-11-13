<?php

use App\Enums\TripAssignmentStatus;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/** @return array{User, Company, RouteSchedule, Driver, Bus, array<string, mixed>} */
function tripAssignmentManagementFixture(): array
{
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $route = Route::factory()->for($company)->create();
    $pattern = RoutePattern::factory()->for($route)->create();
    $schedule = RouteSchedule::factory()->for($pattern, 'pattern')->create([
        'day_of_week' => 1,
        'departure_time' => '06:30:00',
    ]);
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
    $bus = Bus::factory()->for($company)->active()->create();
    $data = [
        'route_schedule_id' => $schedule->id,
        'driver_id' => $driver->id,
        'bus_id' => $bus->id,
        'planned_arrival_time' => '07:30:00',
        'ends_next_day' => false,
        'valid_from' => '2026-11-02',
        'valid_until' => '2026-12-28',
    ];

    return [$actor, $company, $schedule, $driver, $bus, $data];
}

describe('Trip Assignment Management Service', function (): void {
    test('creates and updates a validated recurring assignment in explicit company context', function (): void {
        [$actor, $company, $schedule, $driver, $bus, $data] = tripAssignmentManagementFixture();
        $previousTeam = createCompany()->id;
        setPermissionsTeamId($previousTeam);
        $service = app(TripAssignmentManagementService::class);

        $assignment = $service->create($actor, $company, $data);
        $updated = $service->update($actor, $company, $assignment, [
            'planned_arrival_time' => '08:00:00',
            'valid_until' => '2027-01-25',
        ]);

        expect($assignment->id)->toBeUuid()
            ->and($updated->id)->toBe($assignment->id)
            ->and($updated->company_id)->toBe($company->id)
            ->and($updated->route_schedule_id)->toBe($schedule->id)
            ->and($updated->driver_id)->toBe($driver->id)
            ->and($updated->bus_id)->toBe($bus->id)
            ->and($updated->planned_arrival_time)->toBe('08:00:00')
            ->and($updated->valid_until->toDateString())->toBe('2027-01-25')
            ->and(getPermissionsTeamId())->toBe($previousTeam)
            ->and($actor->relationLoaded('roles'))->toBeFalse()
            ->and($actor->relationLoaded('permissions'))->toBeFalse();
    });

    test('changes state archives and restores through explicit lifecycle operations', function (): void {
        [$actor, $company, , , , $data] = tripAssignmentManagementFixture();
        $service = app(TripAssignmentManagementService::class);
        $assignment = $service->create($actor, $company, $data);

        $inactive = $service->setActive($actor, $company, $assignment, false);
        expect($inactive->status)->toBe(TripAssignmentStatus::INACTIVE);
        $active = $service->setActive($actor, $company, $inactive, true);
        $service->archive($actor, $company, $active);
        $restored = $service->restore($actor, $company, $active);

        expect($active->status)->toBe(TripAssignmentStatus::ACTIVE)
            ->and($restored->id)->toBe($assignment->id)
            ->and($restored->trashed())->toBeFalse();
    });

    test('rejects protected and unsupported fields without changing stored values', function (): void {
        [$actor, $company, , , , $data] = tripAssignmentManagementFixture();
        $service = app(TripAssignmentManagementService::class);
        $assignment = $service->create($actor, $company, $data);
        $before = $assignment->fresh()->getRawOriginal();

        expect(fn () => $service->update($actor, $company, $assignment, [
            'id' => fake()->uuid(),
            'company_id' => createCompany()->id,
            'status' => TripAssignmentStatus::INACTIVE->value,
            'deleted_at' => now(),
        ]))->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toHaveKeys(['id', 'company_id', 'status', 'deleted_at']);
        })
            ->and($assignment->fresh()->getRawOriginal())->toBe($before);
    });

    test('rejects invalid local intervals and validity dates with field errors', function (array $changes, string $field): void {
        [$actor, $company, , , , $data] = tripAssignmentManagementFixture();

        expect(fn () => app(TripAssignmentManagementService::class)->create($actor, $company, array_replace($data, $changes)))
            ->toThrow(function (ValidationException $exception) use ($field): void {
                expect($exception->errors())->toHaveKey($field);
            });
        $this->assertDatabaseCount('trip_assignments', 0);
    })->with([
        'equal same-day times' => [['planned_arrival_time' => '06:30:00'], 'planned_arrival_time'],
        'later overnight arrival' => [['planned_arrival_time' => '07:00:00', 'ends_next_day' => true], 'planned_arrival_time'],
        'reversed validity' => [['valid_from' => '2026-12-01', 'valid_until' => '2026-11-01'], 'valid_until'],
        'impossible date' => [['valid_from' => '2026-02-30'], 'valid_from'],
    ]);

    test('rejects unavailable resources and missing driver availability', function (string $resource): void {
        [$actor, $company, $schedule, $driver, $bus, $data] = tripAssignmentManagementFixture();
        if ($resource === 'bus_id') {
            Bus::query()->whereKey($bus->id)->update(['is_active' => false]);
        } elseif ($resource === 'driver_id') {
            $driver->update(['status' => 'inactive']);
        } elseif ($resource === 'route_schedule_id') {
            $other = createCompany();
            $data['route_schedule_id'] = RouteSchedule::factory()
                ->for(RoutePattern::factory()->for(Route::factory()->for($other)), 'pattern')
                ->create(['day_of_week' => 1, 'departure_time' => $schedule->departure_time])
                ->id;
        } else {
            DriverSchedule::query()->where('driver_id', $driver->id)->delete();
        }

        expect(fn () => app(TripAssignmentManagementService::class)->create($actor, $company, $data))
            ->toThrow(function (ValidationException $exception) use ($resource): void {
                expect($exception->errors())->toHaveKey($resource);
            });
    })->with(['bus_id', 'driver_id', 'route_schedule_id', 'availability']);

    test('requires an active identity membership and company driver role', function (string $condition): void {
        [$actor, $company, , $driver, , $data] = tripAssignmentManagementFixture();
        if ($condition === 'identity') {
            User::query()->whereKey($driver->user_id)->delete();
        } elseif ($condition === 'membership') {
            CompanyUser::query()->where('company_id', $company->id)->where('user_id', $driver->user_id)->delete();
        } else {
            $previousTeam = getPermissionsTeamId();
            try {
                setPermissionsTeamId($company->id);
                $driver->user->removeRole(UserRole::Driver->value);
            } finally {
                setPermissionsTeamId($previousTeam);
            }
        }

        expect(fn () => app(TripAssignmentManagementService::class)->create($actor, $company, $data))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toHaveKey('driver_id');
            });
    })->with(['identity', 'membership', 'role']);

    test('requires authorization and rejects records from another company', function (): void {
        [$actor, $company, , , , $data] = tripAssignmentManagementFixture();
        $assignment = app(TripAssignmentManagementService::class)->create($actor, $company, $data);
        $unauthorized = User::factory()->create();
        $other = createCompany();

        expect(fn () => app(TripAssignmentManagementService::class)->setActive($unauthorized, $company, $assignment, false))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(TripAssignmentManagementService::class)->archive($actor, $other, $assignment))
            ->toThrow(ModelNotFoundException::class)
            ->and($assignment->fresh()->status)->toBe(TripAssignmentStatus::ACTIVE)
            ->and($assignment->fresh()->trashed())->toBeFalse();
    });
});
