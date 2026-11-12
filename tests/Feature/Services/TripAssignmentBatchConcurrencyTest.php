<?php

use App\Enums\UserRole;
use App\Models\DriverSchedule;
use App\Models\RouteSchedule;
use App\Models\Trip;
use App\Models\TripAssignment;
use Illuminate\Support\Facades\DB;
use Tests\Support\WithDriverConcurrency;

pest()->use(WithDriverConcurrency::class);

afterEach(function (): void {
    $this->finishDriverConcurrency();
});

test('serializes reverse-order recurring assignment batches without deadlock', function (): void {
    $company = $this->createConcurrentCompany();
    $company->update(['timezone' => 'America/Costa_Rica']);
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $planned = Trip::factory()->for($company)->withDriverAvailability()->make();
    DriverSchedule::query()->where('driver_id', $planned->driver_id)->delete();
    DriverSchedule::factory()->for($planned->driver)->fullDay()->unbounded()->create([
        'day_of_week' => 1, 'start_time' => '00:00:00', 'end_time' => '00:00:00',
    ]);
    $later = RouteSchedule::factory()->create([
        'route_pattern_id' => $planned->route_pattern_id,
        'day_of_week' => 1,
        'departure_time' => '08:00:00',
    ]);
    $scheduleIds = [$planned->route_schedule_id, $later->id];
    $input = [
        'operation' => 'trip_assignment_batch',
        'company_id' => $company->id,
        'actor_id' => $actor->id,
        'previous_team' => null,
        'assignment' => [
            'driver_id' => $planned->driver_id,
            'bus_id' => $planned->bus_id,
            'planned_duration_minutes' => 60,
        ],
    ];

    $first = $this->startDriverProcess([...$input, 'route_schedule_ids' => $scheduleIds, 'barrier_at' => 'person_locked']);
    $holder = $first->await('ready');
    $first->await('person_locked');
    $second = $this->startDriverProcess([...$input, 'route_schedule_ids' => array_reverse($scheduleIds), 'barrier_at' => 'person_attempt']);
    $waiter = $second->await('ready');
    $second->await('person_lock_attempt');
    $second->release();
    $deadline = microtime(true) + 5;
    do {
        $waiting = (bool) DB::selectOne(
            'SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS waiting',
            [$holder['pgsql_pid'], $waiter['pgsql_pid']],
        )->waiting;
    } while (! $waiting && microtime(true) < $deadline);
    expect($waiting)->toBeTrue();
    $first->release();
    $winner = $first->await('result');
    $loser = $second->await('result');

    expect($winner['status'])->toBe('success')
        ->and($loser['status'])->toBe('validation')
        ->and(TripAssignment::query()->where('company_id', $company->id)->count())->toBe(2);
});
