<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\Role;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\WithDriverConcurrency;

uses(WithDriverConcurrency::class);

afterEach(function (): void {
    $this->finishDriverConcurrency();
});

test('serializes starts and preserves timestamps or rejects competing occupancy after waiting', function (string $scenario): void {
    Http::preventStrayRequests();
    $company = $this->createConcurrentCompany();
    $company->update(['timezone' => 'America/Costa_Rica']);
    $firstTrip = Trip::factory()->for($company)->withDriverAvailability()->create()->refresh();
    $secondTrip = $firstTrip;
    $instant = '2026-10-26T12:45:00Z';
    if ($scenario !== 'retry') {
        $instant = '2026-11-02T12:45:00Z';
        DriverSchedule::factory()->for($firstTrip->driver)->create([
            'day_of_week' => 1, 'start_time' => '00:00', 'end_time' => '00:00', 'ends_next_day' => true,
            'valid_from' => '2026-11-02', 'valid_until' => '2026-11-02',
        ]);
        if ($scenario === 'bus') {
            $secondTrip = Trip::factory()->for($company)->withDriverAvailability()->create(['bus_id' => $firstTrip->bus_id, 'service_date' => '2026-11-02']);
        } else {
            $other = $this->createConcurrentCompany();
            $other->update(['timezone' => 'America/Costa_Rica']);
            $profile = Driver::factory()->for($other)->create(['user_id' => $firstTrip->driver->user_id]);
            CompanyUser::factory()->create(['company_id' => $other->id, 'user_id' => $profile->user_id]);
            $previous = getPermissionsTeamId();
            try {
                setPermissionsTeamId($other->id);
                $profile->user->assignRole(Role::findOrCreate(UserRole::Driver->value, 'web'));
            } finally {
                setPermissionsTeamId($previous);
            }
            DriverSchedule::factory()->for($profile)->create([
                'day_of_week' => 1, 'start_time' => '00:00', 'end_time' => '00:00', 'ends_next_day' => true,
                'valid_from' => '2026-11-02', 'valid_until' => '2026-11-02',
            ]);
            $secondTrip = Trip::factory()->for($other)->for($profile, 'driver')->create(['service_date' => '2026-11-02']);
        }
    }
    $secondTrip->refresh();
    $before = $secondTrip->getRawOriginal();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $team = $this->createConcurrentCompany()->id;
    $firstBarrier = match ($scenario) {
        'retry' => 'trip_locked', 'bus' => 'bus_locked', default => 'person_locked'
    };
    $secondBarrier = match ($scenario) {
        'retry' => 'trip_lock_attempt', 'bus' => 'bus_attempt', default => 'person_attempt'
    };
    $attemptEvent = match ($scenario) {
        'retry' => 'trip_lock_attempt', 'bus' => 'bus_lock_attempt', default => 'person_lock_attempt'
    };
    $first = $this->startDriverProcess([
        'operation' => 'trip_start', 'actor_id' => $actor->id, 'company_id' => $firstTrip->company_id,
        'trip_id' => $firstTrip->id, 'previous_team' => $team, 'instant' => $instant, 'barrier_at' => $firstBarrier,
    ]);
    $firstReady = $first->await('ready');
    $first->await($firstBarrier);
    $second = $this->startDriverProcess([
        'operation' => 'trip_start', 'actor_id' => $actor->id, 'company_id' => $secondTrip->company_id,
        'trip_id' => $secondTrip->id, 'previous_team' => $team,
        'instant' => $scenario === 'retry' ? '2026-10-26T13:45:00Z' : $instant, 'barrier_at' => $secondBarrier,
    ]);
    $secondReady = $second->await('ready');
    $second->await($attemptEvent);
    $second->release();
    $deadline = microtime(true) + 5;
    do {
        $waiting = (bool) DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS waiting', [$firstReady['pgsql_pid'], $secondReady['pgsql_pid']])->waiting;
    } while (! $waiting && microtime(true) < $deadline);
    expect($waiting)->toBeTrue();
    $first->release();
    $winner = $first->await('result');
    $secondResult = $second->await('result');

    expect($winner['status'])->toBe('success');
    expect($firstTrip->fresh()->status)->toBe(TripStatus::IN_PROGRESS);
    if ($scenario === 'retry') {
        expect($secondResult['status'])->toBe('success');
        expect($secondResult['actual_departure_at'])->toBe($winner['actual_departure_at']);
        expect($secondTrip->fresh()->actual_departure_at->utc()->format('Y-m-d H:i:s.uP'))->toBe('2026-10-26 12:45:00.000000+00:00');
    } else {
        expect($secondResult['status'])->toBe('validation');
        expect($secondResult['errors'])->toHaveKey($scenario === 'bus' ? 'bus_id' : 'driver_id');
        expect($secondTrip->fresh()->getRawOriginal())->toBe($before);
    }
    foreach ([$winner, $secondResult] as $result) {
        expect($result['team_restored'])->toBeTrue();
        expect($result['actor_cache_cleared'])->toBeTrue();
        expect($result['pgsql_level'])->toBe(0);
        expect($result['pgsql_transaction'])->toBeFalse();
    }
})->with(['retry', 'bus', 'person']);
