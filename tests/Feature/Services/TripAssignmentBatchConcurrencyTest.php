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

test('prelocks overlapping batch schedules before validating assignments with different resources', function (): void {
    $company = $this->createConcurrentCompany();
    $company->update(['timezone' => 'America/Costa_Rica']);
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $firstPlan = Trip::factory()->for($company)->withDriverAvailability()->make();
    $secondPlan = Trip::factory()->for($company)->withDriverAvailability()->make();
    foreach ([$firstPlan->driver, $secondPlan->driver] as $driver) {
        DriverSchedule::query()->where('driver_id', $driver->id)->delete();
        DriverSchedule::factory()->for($driver)->fullDay()->unbounded()->create([
            'day_of_week' => 1, 'start_time' => '00:00:00', 'end_time' => '00:00:00',
        ]);
    }
    $middle = RouteSchedule::factory()->create([
        'route_pattern_id' => $firstPlan->route_pattern_id,
        'day_of_week' => 1,
        'departure_time' => '08:00:00',
    ]);
    $later = RouteSchedule::factory()->create([
        'route_pattern_id' => $firstPlan->route_pattern_id,
        'day_of_week' => 1,
        'departure_time' => '10:00:00',
    ]);
    $existingSchedule = RouteSchedule::factory()->create([
        'route_pattern_id' => $firstPlan->route_pattern_id,
        'day_of_week' => 2,
        'departure_time' => '12:00:00',
    ]);
    TripAssignment::factory()->create([
        'company_id' => $company->id,
        'route_schedule_id' => $existingSchedule->id,
        'driver_id' => $firstPlan->driver_id,
        'bus_id' => $secondPlan->bus_id,
        'planned_arrival_time' => '13:00:00',
    ]);
    $commonInput = [
        'operation' => 'trip_assignment_batch',
        'company_id' => $company->id,
        'actor_id' => $actor->id,
        'previous_team' => null,
    ];
    $firstInput = [...$commonInput,
        'route_schedule_ids' => [$firstPlan->route_schedule_id, $middle->id],
        'assignment' => [
            'driver_id' => $firstPlan->driver_id,
            'bus_id' => $firstPlan->bus_id,
            'planned_duration_minutes' => 60,
        ],
    ];
    $secondInput = [...$commonInput,
        'route_schedule_ids' => [$middle->id, $later->id],
        'assignment' => [
            'driver_id' => $secondPlan->driver_id,
            'bus_id' => $secondPlan->bus_id,
            'planned_duration_minutes' => 60,
        ],
    ];

    $first = $this->startDriverProcess([...$firstInput, 'barrier_at' => 'batch_schedules_locked']);
    $holder = $first->await('ready');
    $first->await('batch_schedules_locked');
    $second = $this->startDriverProcess([...$secondInput, 'barrier_at' => 'batch_schedules_attempt']);
    $waiter = $second->await('ready');
    $second->await('batch_schedules_attempt');
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
        ->and(TripAssignment::query()->where('company_id', $company->id)->count())->toBe(3);
});
