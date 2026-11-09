<?php

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use App\Services\DriverAttendanceService;
use Carbon\CarbonImmutable;
use Database\Factories\TripFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

function driverAttendanceActor(Company $company): User
{
    $actor = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $actor->id]);
    grantShield($actor, ['ViewAttendance:Driver'], $company);

    return $actor;
}

function recordedDriverTrip(
    Company $company,
    Driver $driver,
    string $status,
    string $serviceDate,
    string $departureUtc,
    string $arrivalUtc,
    string $patternCode,
): Trip {
    /** @var TripFactory $factory */
    $factory = Trip::factory()->for($company)->for($driver, 'driver');
    $factory = match ($status) {
        'in_progress' => $factory->inProgress(),
        'completed' => $factory->completed(),
        'cancelled' => $factory->cancelled(),
        default => $factory,
    };

    return $factory->create([
        'service_date' => $serviceDate,
        'scheduled_departure_at' => $departureUtc,
        'scheduled_arrival_at' => $arrivalUtc,
        'planned_route_snapshot' => [
            'schema_version' => 1,
            'pattern_code' => $patternCode,
            'pattern_name' => 'Pattern '.$patternCode,
        ],
    ])->refresh();
}

describe('Driver Attendance Service', function (): void {
    test('returns exact mixed lifecycle counters and the documented projection', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = driverAttendanceActor($company);
        $driver = Driver::factory()->for($company)->create(['salary_amount' => '9999999999.99']);
        $scheduled = recordedDriverTrip($company, $driver, 'scheduled', '2026-10-26', '2026-10-26T12:00:00Z', '2026-10-26T13:00:00Z', 'PAT-S');
        $inProgress = recordedDriverTrip($company, $driver, 'in_progress', '2026-10-26', '2026-10-26T13:00:00Z', '2026-10-26T14:00:00Z', 'PAT-I');
        $completed = recordedDriverTrip($company, $driver, 'completed', '2026-10-26', '2026-10-26T14:00:00Z', '2026-10-26T15:00:00Z', 'PAT-C');
        $cancelled = recordedDriverTrip($company, $driver, 'cancelled', '2026-10-26', '2026-10-26T15:00:00Z', '2026-10-26T16:00:00Z', 'PAT-X');

        $summary = app(DriverAttendanceService::class)->summarize(
            $actor,
            $company,
            $driver,
            CarbonImmutable::parse('2026-10-26', $company->timezone),
            CarbonImmutable::parse('2026-10-26', $company->timezone),
        );

        expect(array_keys($summary))->toBe(['company_timezone', 'from_date', 'until_date', 'days']);
        expect($summary['company_timezone'])->toBe('America/Costa_Rica')
            ->and($summary['from_date'])->toBe('2026-10-26')
            ->and($summary['until_date'])->toBe('2026-10-26')
            ->and($summary['days'])->toHaveCount(1);
        $day = $summary['days'][0];
        expect(array_keys($day))->toBe([
            'service_date', 'assigned_count', 'started_count', 'completed_count', 'cancelled_count',
            'pending_count', 'in_progress_count', 'first_departure_at', 'last_arrival_at', 'trips',
        ]);
        expect($day)->toMatchArray([
            'service_date' => '2026-10-26',
            'assigned_count' => 4,
            'started_count' => 2,
            'completed_count' => 1,
            'cancelled_count' => 1,
            'pending_count' => 1,
            'in_progress_count' => 1,
            'first_departure_at' => '2026-10-26T13:00:00.000000Z',
            'last_arrival_at' => '2026-10-26T15:00:00.000000Z',
        ]);
        expect($day['trips'])->toHaveCount(4);
        expect(array_keys($day['trips'][0]))->toBe([
            'id', 'service_date', 'route_schedule_id', 'bus_id', 'status', 'pattern_code', 'pattern_name',
            'scheduled_departure_at', 'scheduled_arrival_at', 'actual_departure_at', 'actual_arrival_at', 'cancelled_at',
        ]);
        expect($day['trips'][0])->toMatchArray([
            'id' => $scheduled->id,
            'route_schedule_id' => $scheduled->route_schedule_id,
            'bus_id' => $scheduled->bus_id,
            'status' => 'scheduled',
            'pattern_code' => 'PAT-S',
            'pattern_name' => 'Pattern PAT-S',
            'scheduled_departure_at' => '2026-10-26T12:00:00.000000Z',
            'scheduled_arrival_at' => '2026-10-26T13:00:00.000000Z',
            'actual_departure_at' => null,
            'actual_arrival_at' => null,
        ]);
        expect($day['trips'][1]['id'])->toBe($inProgress->id)
            ->and($day['trips'][2]['id'])->toBe($completed->id)
            ->and($day['trips'][3]['id'])->toBe($cancelled->id);
        expect(json_encode($summary, JSON_THROW_ON_ERROR))
            ->not->toContain('9999999999.99')
            ->not->toContain($actor->email)
            ->not->toContain($actor->password);
    });

    test('represents empty cancelled-only and pending-only dates truthfully', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = driverAttendanceActor($company);
        $driver = Driver::factory()->for($company)->create();
        recordedDriverTrip($company, $driver, 'cancelled', '2026-10-26', '2026-10-26T12:00:00Z', '2026-10-26T13:00:00Z', 'CANCELLED');
        recordedDriverTrip($company, $driver, 'scheduled', '2026-10-28', '2026-10-28T12:00:00Z', '2026-10-28T13:00:00Z', 'PENDING');

        $days = app(DriverAttendanceService::class)->summarize(
            $actor,
            $company,
            $driver,
            CarbonImmutable::parse('2026-10-26', $company->timezone),
            CarbonImmutable::parse('2026-10-28', $company->timezone),
        )['days'];

        expect($days)->toHaveCount(3);
        expect($days[0])->toMatchArray([
            'service_date' => '2026-10-26', 'assigned_count' => 1, 'cancelled_count' => 1,
            'started_count' => 0, 'pending_count' => 0, 'first_departure_at' => null, 'last_arrival_at' => null,
        ]);
        expect($days[1])->toBe([
            'service_date' => '2026-10-27', 'assigned_count' => 0, 'started_count' => 0,
            'completed_count' => 0, 'cancelled_count' => 0, 'pending_count' => 0,
            'in_progress_count' => 0, 'first_departure_at' => null, 'last_arrival_at' => null, 'trips' => [],
        ]);
        expect($days[2])->toMatchArray([
            'service_date' => '2026-10-28', 'assigned_count' => 1, 'pending_count' => 1,
            'started_count' => 0, 'cancelled_count' => 0, 'first_departure_at' => null, 'last_arrival_at' => null,
        ]);
    });

    test('groups an overnight trip by its original service date', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = driverAttendanceActor($company);
        $driver = Driver::factory()->for($company)->create();
        $trip = recordedDriverTrip($company, $driver, 'completed', '2026-10-26', '2026-10-27T05:00:00Z', '2026-10-27T08:00:00Z', 'OVERNIGHT');

        $days = app(DriverAttendanceService::class)->summarize(
            $actor,
            $company,
            $driver,
            CarbonImmutable::parse('2026-10-26', $company->timezone),
            CarbonImmutable::parse('2026-10-27', $company->timezone),
        )['days'];

        expect($days[0]['service_date'])->toBe('2026-10-26')
            ->and($days[0]['assigned_count'])->toBe(1)
            ->and($days[0]['trips'][0]['id'])->toBe($trip->id)
            ->and($days[0]['last_arrival_at'])->toBe('2026-10-27T08:00:00.000000Z');
        expect($days[1]['service_date'])->toBe('2026-10-27')
            ->and($days[1]['assigned_count'])->toBe(0);
    });

    test('authorizes archived history and rejects unauthorized or cross-company access', function (): void {
        [$company, $otherCompany] = createTenantPair();
        $company->update(['timezone' => 'America/Costa_Rica']);
        $otherCompany->update(['timezone' => 'America/Costa_Rica']);
        $actor = driverAttendanceActor($company);
        $unauthorized = User::factory()->create();
        CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $unauthorized->id]);
        $driver = Driver::factory()->for($company)->create();
        $trip = recordedDriverTrip($company, $driver, 'completed', '2026-10-26', '2026-10-26T12:00:00Z', '2026-10-26T13:00:00Z', 'HISTORY');
        $otherDriver = Driver::factory()->for($otherCompany)->create(['user_id' => $driver->user_id]);
        recordedDriverTrip($otherCompany, $otherDriver, 'completed', '2026-10-26', '2026-10-26T14:00:00Z', '2026-10-26T15:00:00Z', 'OTHER');
        $trip->delete();
        $driver->delete();
        $date = CarbonImmutable::parse('2026-10-26', $company->timezone);
        $service = app(DriverAttendanceService::class);

        $summary = $service->summarize($actor, $company, $driver, $date, $date);

        expect($summary['days'][0]['assigned_count'])->toBe(1)
            ->and($summary['days'][0]['trips'][0]['id'])->toBe($trip->id);
        expect(fn () => $service->summarize($unauthorized, $company, $driver, $date, $date))
            ->toThrow(AuthorizationException::class);
        expect(fn () => $service->summarize($actor, $company, $otherDriver, $date, $date))
            ->toThrow(AuthorizationException::class);
    });

    test('normalizes an inclusive local date range and rejects invalid ranges or timezones', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = driverAttendanceActor($company);
        $driver = Driver::factory()->for($company)->create();
        $service = app(DriverAttendanceService::class);

        $summary = $service->summarize(
            $actor,
            $company,
            $driver,
            CarbonImmutable::parse('2026-10-26T05:59:59Z'),
            CarbonImmutable::parse('2026-10-26T06:00:00Z'),
        );

        expect($summary['from_date'])->toBe('2026-10-25')
            ->and($summary['until_date'])->toBe('2026-10-26')
            ->and($summary['days'])->toHaveCount(2);
        expect(fn () => $service->summarize(
            $actor,
            $company,
            $driver,
            CarbonImmutable::parse('2026-10-27', $company->timezone),
            CarbonImmutable::parse('2026-10-26', $company->timezone),
        ))->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toHaveKey('until_date');
        });

        $company->timezone = 'Invalid/Timezone';
        expect(fn () => $service->summarize(
            $actor,
            $company,
            $driver,
            CarbonImmutable::parse('2026-10-26'),
            CarbonImmutable::parse('2026-10-26'),
        ))->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toHaveKey('timezone');
        });
    });

    test('keeps PostgreSQL query growth bounded and does not change trip history', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = driverAttendanceActor($company);
        $driver = Driver::factory()->for($company)->create();
        $trips = collect();
        for ($day = 1; $day <= 12; $day++) {
            $date = sprintf('2026-10-%02d', $day);
            $trips->push(recordedDriverTrip($company, $driver, 'completed', $date, $date.'T12:00:00Z', $date.'T13:00:00Z', 'Q'.$day));
        }
        $before = $trips->mapWithKeys(fn (Trip $trip): array => [$trip->id => $trip->getRawOriginal()]);
        $queryCounts = ['small' => 0, 'large' => 0];
        $writeStatements = [];
        $measurement = null;
        DB::listen(function (QueryExecuted $query) use (&$measurement, &$queryCounts, &$writeStatements): void {
            if ($measurement === null || $query->connectionName !== 'pgsql') {
                return;
            }
            if (preg_match('/^\s*select\b/i', $query->sql) === 1) {
                $queryCounts[$measurement]++;
            } else {
                $writeStatements[] = $query->sql;
            }
        });
        $service = app(DriverAttendanceService::class);

        $measurement = 'small';
        $service->summarize($actor, $company, $driver, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-01'));
        $measurement = 'large';
        $summary = $service->summarize($actor, $company, $driver, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        $measurement = null;

        expect($queryCounts['large'])->toBeLessThanOrEqual($queryCounts['small'])
            ->and($summary['days'])->toHaveCount(31)
            ->and($writeStatements)->toBe([]);
        foreach ($trips as $trip) {
            expect($trip->fresh()->getRawOriginal())->toBe($before[$trip->id]);
        }
    });
});
