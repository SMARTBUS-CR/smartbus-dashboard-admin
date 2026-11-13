<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\Trip;
use App\Services\TripManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\WithDriverConcurrency;

pest()->use(WithDriverConcurrency::class);

afterEach(function (): void {
    $this->finishDriverConcurrency();
});

describe('Driver Trip Concurrency', function (): void {
    test('serializes invalidating employment and validity changes against committed reservations', function (string $operation, array $changes, bool $reservationFirst): void {
        Http::preventStrayRequests();
        $company = $this->createConcurrentCompany();
        $company->update(['timezone' => 'America/Costa_Rica']);
        $fixture = Trip::factory()->for($company)->withDriverAvailability()->make();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $schedule = DriverSchedule::query()->where('driver_id', $fixture->driver_id)->sole();
        $input = [
            'company_id' => $company->id, 'actor_id' => $actor->id, 'previous_team' => null,
            'driver_id' => $fixture->driver_id, 'schedule_id' => $schedule->id,
            'employment' => $changes, 'schedule' => $changes,
            'plan' => [...$fixture->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
                'service_date' => '2026-10-26', 'arrival_at' => '2026-10-26T07:30:00-06:00'],
        ];
        $first = $this->startDriverProcess([...$input, 'operation' => $reservationFirst ? 'trip_schedule' : $operation, 'barrier_at' => 'person_locked']);
        $holder = $first->await('ready');
        $first->await('person_locked');
        $second = $this->startDriverProcess([...$input, 'operation' => $reservationFirst ? $operation : 'trip_schedule', 'barrier_at' => 'person_attempt']);
        $waiter = $second->await('ready');
        $second->await('person_lock_attempt');
        $second->release();
        $deadline = microtime(true) + 5;
        do {
            $blocked = (bool) DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS waiting', [$holder['pgsql_pid'], $waiter['pgsql_pid']])->waiting;
        } while (! $blocked && microtime(true) < $deadline);
        expect($blocked)->toBeTrue();
        $first->release();
        $winner = $first->await('result');
        $loser = $second->await('result');

        expect($winner['status'])->toBe('success')->and($loser['status'])->toBe('validation')
            ->and(Trip::query()->where('company_id', $company->id)->count())->toBe($reservationFirst ? 1 : 0);
        foreach ([$winner, $loser] as $result) {
            expect($result['team_restored'])->toBeTrue()
                ->and($result['actor_cache_cleared'])->toBeTrue()
                ->and($result['pgsql_level'])->toBe(0)
                ->and($result['pgsql_transaction'])->toBeFalse();
        }
        if ($reservationFirst) {
            expect($loser['errors'])->toHaveKey('driver')
                ->and($schedule->fresh()->getRawOriginal())->toBe($schedule->getRawOriginal());
        }
    })->with([
        'employment starts too late' => ['driver_update', ['employment_starts_on' => '2026-10-27']],
        'employment ends too early' => ['driver_update', ['employment_starts_on' => '2026-10-01', 'employment_ends_on' => '2026-10-25']],
        'validity starts too late' => ['schedule_update', ['valid_from' => '2026-10-27', 'valid_until' => null]],
        'validity ends too early' => ['schedule_update', ['valid_from' => null, 'valid_until' => '2026-10-25']],
    ])->with([true, false]);

    test('uses one person lock across companies while allowing unrelated employment changes', function (bool $reservationFirst): void {
        Http::preventStrayRequests();
        $company = $this->createConcurrentCompany();
        $company->update(['timezone' => 'America/Costa_Rica']);
        $otherCompany = $this->createConcurrentCompany();
        $fixture = Trip::factory()->for($company)->withDriverAvailability()->make();
        $otherDriver = Driver::factory()->for($otherCompany)->create(['user_id' => $fixture->driver->user_id]);
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $reservation = [
            'operation' => 'trip_schedule', 'company_id' => $company->id, 'actor_id' => $actor->id, 'previous_team' => null,
            'plan' => [...$fixture->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
                'service_date' => '2026-10-26', 'arrival_at' => '2026-10-26T07:30:00-06:00'],
        ];
        $mutation = ['operation' => 'driver_deactivate', 'company_id' => $otherCompany->id,
            'driver_id' => $otherDriver->id, 'actor_id' => $actor->id, 'previous_team' => null];
        $first = $this->startDriverProcess([...($reservationFirst ? $reservation : $mutation), 'barrier_at' => 'person_locked']);
        $holder = $first->await('ready');
        $first->await('person_locked');
        $second = $this->startDriverProcess([...($reservationFirst ? $mutation : $reservation), 'barrier_at' => 'person_attempt']);
        $waiter = $second->await('ready');
        $second->await('person_lock_attempt');
        $second->release();
        $deadline = microtime(true) + 5;
        do {
            $blocked = (bool) DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS waiting', [$holder['pgsql_pid'], $waiter['pgsql_pid']])->waiting;
        } while (! $blocked && microtime(true) < $deadline);
        expect($blocked)->toBeTrue();
        $first->release();

        expect($first->await('result')['status'])->toBe('success')
            ->and($second->await('result')['status'])->toBe('success')
            ->and($otherDriver->fresh()->status->value)->toBe('inactive')
            ->and($fixture->driver->fresh()->status->value)->toBe('active')
            ->and(Trip::query()->where('company_id', $company->id)->count())->toBe(1);
    })->with([true, false]);

    test('serializes the real late start against workforce changes that preserve only its planned window', function (string $operation, array $changes, string $instant, bool $startFirst): void {
        Http::preventStrayRequests();
        $company = $this->createConcurrentCompany();
        $company->update(['timezone' => 'America/Costa_Rica']);
        $trip = Trip::factory()->for($company)->withDriverAvailability()->create()->refresh();
        if (str_starts_with($instant, '2026-10-27')) {
            DriverSchedule::factory()->for($trip->driver)->fullDay()->create([
                'day_of_week' => 2, 'start_time' => '00:00', 'end_time' => '00:00',
                'valid_from' => '2026-10-27', 'valid_until' => '2026-10-27',
            ]);
        }
        $schedule = DriverSchedule::query()->where('driver_id', $trip->driver_id)->where('day_of_week', 1)->sole();
        $beforeSchedule = $schedule->getRawOriginal();
        $driver = $trip->driver->refresh();
        $beforeDriver = $driver->getRawOriginal();
        $beforeTrip = $trip->getRawOriginal();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $input = [
            'company_id' => $company->id, 'actor_id' => $actor->id, 'previous_team' => null,
            'trip_id' => $trip->id, 'driver_id' => $driver->id, 'schedule_id' => $schedule->id,
            'employment' => $changes, 'schedule' => $changes, 'instant' => $instant,
        ];
        $first = $this->startDriverProcess([...$input, 'operation' => $startFirst ? 'trip_start' : $operation, 'barrier_at' => 'person_locked']);
        $holder = $first->await('ready');
        $first->await('person_locked');
        $second = $this->startDriverProcess([...$input, 'operation' => $startFirst ? $operation : 'trip_start', 'barrier_at' => 'person_attempt']);
        $waiter = $second->await('ready');
        $second->await('person_lock_attempt');
        $second->release();
        $deadline = microtime(true) + 5;
        do {
            $blocked = (bool) DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS waiting', [$holder['pgsql_pid'], $waiter['pgsql_pid']])->waiting;
        } while (! $blocked && microtime(true) < $deadline);
        expect($blocked)->toBeTrue();
        $first->release();
        $winner = $first->await('result');
        $loser = $second->await('result');

        expect($winner['status'])->toBe('success')->and($loser['status'])->toBe('validation');
        foreach ([$winner, $loser] as $result) {
            expect($result['team_restored'])->toBeTrue()
                ->and($result['actor_cache_cleared'])->toBeTrue()
                ->and($result['pgsql_level'])->toBe(0)
                ->and($result['pgsql_transaction'])->toBeFalse();
        }
        if ($startFirst) {
            expect($trip->fresh()->status)->toBe(TripStatus::IN_PROGRESS)
                ->and($loser['errors'])->toHaveKey('driver')
                ->and($driver->fresh()->getRawOriginal())->toBe($beforeDriver)
                ->and($schedule->fresh()->getRawOriginal())->toBe($beforeSchedule);
        } else {
            expect($loser['errors'])->toHaveKey('driver_id')
                ->and($trip->fresh()->getRawOriginal())->toBe($beforeTrip);
        }
    })->with([
        'actual availability' => ['schedule_update', ['start_time' => '00:00', 'end_time' => '08:00', 'ends_next_day' => false], '2026-10-26T14:30:00Z'],
        'actual employment date' => ['driver_update', ['employment_ends_on' => '2026-10-26'], '2026-10-27T12:45:00Z'],
        'actual license date' => ['driver_update', ['license_expires_at' => '2026-10-26'], '2026-10-27T12:45:00Z'],
    ])->with([true, false]);

    test('serializes replanning onto a new driver against deleting that drivers availability', function (bool $replanFirst): void {
        Http::preventStrayRequests();
        $company = $this->createConcurrentCompany();
        $company->update(['timezone' => 'America/Costa_Rica']);
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $source = Trip::factory()->for($company)->withDriverAvailability()->make();
        $target = Trip::factory()->for($company)->withDriverAvailability()->make();
        $basePlan = ['service_date' => '2026-10-26', 'arrival_at' => '2026-10-26T07:30:00-06:00'];
        $trip = app(TripManagementService::class)->schedule($actor, $company, [...$source->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']), ...$basePlan]);
        $schedule = DriverSchedule::query()->where('driver_id', $target->driver_id)->sole();
        $before = $trip->getRawOriginal();
        $shared = ['company_id' => $company->id, 'actor_id' => $actor->id, 'previous_team' => null];
        $replan = [...$shared, 'operation' => 'trip_replan', 'trip_id' => $trip->id,
            'plan' => [...$target->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']), ...$basePlan]];
        $mutation = [...$shared, 'operation' => 'schedule_delete', 'driver_id' => $target->driver_id, 'schedule_id' => $schedule->id];
        $first = $this->startDriverProcess([...($replanFirst ? $replan : $mutation), 'barrier_at' => $replanFirst ? 'drivers_locked' : 'person_locked']);
        $holder = $first->await('ready');
        $first->await($replanFirst ? 'drivers_locked' : 'person_locked');
        $second = $this->startDriverProcess([...($replanFirst ? $mutation : $replan), 'barrier_at' => 'person_attempt']);
        $waiter = $second->await('ready');
        $second->await('person_lock_attempt');
        $second->release();
        $deadline = microtime(true) + 5;
        do {
            $blocked = (bool) DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS waiting', [$holder['pgsql_pid'], $waiter['pgsql_pid']])->waiting;
        } while (! $blocked && microtime(true) < $deadline);
        expect($blocked)->toBeTrue();
        $first->release();

        expect($first->await('result')['status'])->toBe('success')
            ->and($second->await('result')['status'])->toBe('validation');
        if ($replanFirst) {
            expect($trip->fresh()->driver_id)->toBe($target->driver_id);
            $this->assertModelExists($schedule);
        } else {
            expect($trip->fresh()->getRawOriginal())->toBe($before);
            $this->assertModelMissing($schedule);
        }
    })->with([true, false]);
});
