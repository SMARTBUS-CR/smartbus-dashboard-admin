<?php

use App\Models\CompanyUser;
use App\Models\DriverSchedule;
use App\Models\Trip;
use App\Services\DriverManagementService;
use App\Services\DriverReadService;
use App\Services\TripManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverTrips;

uses(WithDriverTrips::class);

describe('Driver Trip Lifecycle', function (): void {
    test('rejects invalidating lifecycle changes and preserves employment and trips', function (string $status, string $operation, array $data): void {
        [$actor, $trip] = $this->createAssignedDriverTrip($status);
        $driver = $trip->driver->refresh();
        $before = $driver->getRawOriginal();
        $tripBefore = $trip->getRawOriginal();
        $previousTeam = createCompany()->id;
        setPermissionsTeamId($previousTeam);
        $service = app(DriverManagementService::class);

        expect(fn () => match ($operation) {
            'archive' => $service->archive($actor, $trip->company, $driver),
            'deactivate' => $service->setActive($actor, $trip->company, $driver, false),
            default => $service->updateEmployment($actor, $trip->company, $driver, $data),
        })->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['driver' => [__('This change would invalidate an assigned trip.')]]);
        });

        expect($driver->fresh()->getRawOriginal())->toBe($before)
            ->and($trip->fresh()->getRawOriginal())->toBe($tripBefore)
            ->and(getPermissionsTeamId())->toBe($previousTeam);
    })->with(['scheduled', 'in_progress'])->with([
        'deactivate' => ['deactivate', []], 'archive' => ['archive', []],
        'start after departure' => ['update', ['employment_starts_on' => '2026-10-27']],
        'end before departure' => ['update', ['employment_ends_on' => '2026-10-25']],
        'expired license' => ['update', ['license_expires_at' => '2026-10-25']],
    ]);

    test('checks the current identity membership and driver role on a proposed license change', function (string $revocation): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $driver = $trip->driver->refresh();
        $user = $driver->user;
        match ($revocation) {
            'identity' => $user->delete(),
            'membership' => CompanyUser::query()->where('company_id', $trip->company_id)->where('user_id', $driver->user_id)->sole()->delete(),
            'role' => DB::connection('mysql')->table('model_has_roles')->where('model_uuid', $driver->user_id)->where('company_id', $trip->company_id)->delete(),
        };
        $before = $driver->getRawOriginal();
        $history = $trip->getRawOriginal();

        expect(fn () => app(DriverManagementService::class)->updateEmployment($actor, $trip->company, $driver, ['license' => 'REPLACEMENT-LICENSE']))
            ->toThrow(ValidationException::class);

        expect($driver->fresh()->getRawOriginal())->toBe($before)
            ->and($trip->fresh()->getRawOriginal())->toBe($history);
    })->with(['identity', 'membership', 'role']);

    test('allows compatible license and inclusive employment date edits', function (string $status): void {
        [$actor, $trip] = $this->createAssignedDriverTrip($status);
        $history = $trip->getRawOriginal();

        $updated = app(DriverManagementService::class)->updateEmployment($actor, $trip->company, $trip->driver, [
            'license' => 'NEW-VALID-LICENSE', 'employment_starts_on' => '2026-10-26',
            'employment_ends_on' => '2026-10-26', 'license_expires_at' => '2026-10-26',
        ]);

        expect($updated->license)->toBe('NEW-VALID-LICENSE')
            ->and($updated->employment_ends_on->toDateString())->toBe('2026-10-26')
            ->and($trip->fresh()->getRawOriginal())->toBe($history);
    })->with(['scheduled', 'in_progress']);

    test('updates salary without querying or changing trip assignments', function (string $status): void {
        [$actor, $trip] = $this->createAssignedDriverTrip($status);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if ($query->connectionName === 'pgsql' && str_contains($query->sql, '"trips"')) {
                $queries[] = $query->sql;
            }
        });

        $updated = app(DriverManagementService::class)->updateEmployment($actor, $trip->company, $trip->driver, ['salary_amount' => '9999999999.99']);

        expect($queries)->toBe([])->and($updated->salary_amount)->toBe('9999999999.99');
        $this->assertModelExists($trip);
    })->with(['scheduled', 'in_progress']);

    test('preserves completed and cancelled history through archive and restore', function (string $status): void {
        [$actor, $trip] = $this->createAssignedDriverTrip($status);
        $before = $trip->getRawOriginal();
        $driver = $trip->driver;
        $service = app(DriverManagementService::class);

        $service->setActive($actor, $trip->company, $driver, false);
        $service->archive($actor, $trip->company, $driver);
        $archived = app(DriverReadService::class)->findArchivedFor($actor, $trip->company, $driver->id);

        expect($archived['id'])->toBe($driver->id)
            ->and($archived)->not->toHaveKeys(['salary_amount', 'salary_currency', 'salary_period'])
            ->and($trip->fresh()->driver->trashed())->toBeTrue()
            ->and($trip->fresh()->getRawOriginal())->toBe($before);
        $restored = $service->restore($actor, $trip->company, $driver);
        expect($restored->id)->toBe($driver->id)->and($trip->fresh()->getRawOriginal())->toBe($before);
    })->with(['completed', 'cancelled']);

    test('rejects an unauthorized mutation even when no assignment would be invalidated', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $other = createCompany();
        $outsider = $this->createWorkforceAdministrator($other);

        expect(fn () => app(DriverManagementService::class)->updateEmployment($outsider, $trip->company, $trip->driver, ['salary_amount' => '1.00']))
            ->toThrow(AuthorizationException::class);
        expect(Trip::query()->whereKey($trip->id)->count())->toBe(1);
    });

    test('uses all touched local dates and excludes an exact midnight arrival', function (string $arrival, bool $allowed): void {
        [$actor, $trip] = $this->createAssignedDriverTrip('scheduled', '23:00:00', $arrival);
        $before = $trip->driver->getRawOriginal();
        $update = fn () => app(DriverManagementService::class)->updateEmployment($actor, $trip->company, $trip->driver, [
            'license_expires_at' => '2026-10-26', 'employment_ends_on' => '2026-10-26',
        ]);

        if ($allowed) {
            expect($update()->license_expires_at->toDateString())->toBe('2026-10-26');
        } else {
            expect($update)->toThrow(ValidationException::class);
            expect($trip->driver->fresh()->getRawOriginal())->toBe($before);
        }
    })->with([
        'exact midnight' => ['2026-10-27T00:00:00-06:00', true],
        'one microsecond on next day' => ['2026-10-27T00:00:00.000001-06:00', false],
        'overnight arrival' => ['2026-10-27T01:00:00-06:00', false],
    ]);

    test('checks every committed assignment instead of only the first', function (): void {
        [$actor, $first] = $this->createAssignedDriverTrip();
        DriverSchedule::factory()->for($first->driver)->fullDay()->create([
            'start_time' => '00:00', 'end_time' => '00:00',
            'day_of_week' => 1, 'valid_from' => '2026-11-02', 'valid_until' => '2026-11-02',
        ]);
        $second = app(TripManagementService::class)->schedule($actor, $first->company, [
            ...$first->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
            'service_date' => '2026-11-02', 'arrival_at' => '2026-11-02T07:30:00-06:00',
        ]);
        $before = $first->driver->getRawOriginal();

        expect(fn () => app(DriverManagementService::class)->updateEmployment($actor, $first->company, $first->driver, ['license_expires_at' => '2026-10-30']))
            ->toThrow(ValidationException::class);

        expect($first->driver->fresh()->getRawOriginal())->toBe($before);
        $this->assertModelExists($first);
        $this->assertModelExists($second);
    });

    test('prevents replanning after eligibility revocation without modifying history', function (string $revocation): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $driver = $trip->driver;
        match ($revocation) {
            'identity' => $driver->user->delete(),
            'membership' => CompanyUser::query()->where('company_id', $trip->company_id)->where('user_id', $driver->user_id)->sole()->delete(),
            'role' => DB::connection('mysql')->table('model_has_roles')->where('model_uuid', $driver->user_id)->where('company_id', $trip->company_id)->delete(),
        };
        $before = $trip->getRawOriginal();

        expect(fn () => app(TripManagementService::class)->replan($actor, $trip->company, $trip->id, [
            ...$trip->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
            'service_date' => '2026-10-26', 'arrival_at' => '2026-10-26T07:30:00-06:00',
        ]))->toThrow(ValidationException::class);

        expect($trip->fresh()->getRawOriginal())->toBe($before);
    })->with(['identity', 'membership', 'role']);

    test('returns a private translated assignment error', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        app()->setLocale('es');

        expect(fn () => app(DriverManagementService::class)->archive($actor, $trip->company, $trip->driver))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toBe(['driver' => ['Este cambio invalidaría un viaje asignado.']]);
            });
    });
});
