<?php

use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\Role;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\WithDriverConcurrency;

pest()->use(WithDriverConcurrency::class);

afterEach(function (): void {
    $this->finishDriverConcurrency();
});

test('maps a real duplicate departure race to a private service date error', function (): void {
    Http::preventStrayRequests();
    $company = $this->createConcurrentCompany();
    $company->update(['timezone' => 'America/Costa_Rica']);
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $firstFixture = Trip::factory()->for($company)->withDriverAvailability()->make();
    $secondFixture = Trip::factory()->for($company)->withDriverAvailability()->make();
    $inputs = [];
    foreach ([$firstFixture, $secondFixture] as $fixture) {
        $inputs[] = [
            'operation' => 'trip_schedule', 'company_id' => $company->id, 'actor_id' => $actor->id, 'previous_team' => null,
            'plan' => [...$firstFixture->only(['route_pattern_id', 'route_schedule_id']),
                ...$fixture->only(['bus_id', 'driver_id']), 'service_date' => '2026-10-26', 'arrival_at' => '2026-10-26T07:30:00-06:00'],
        ];
    }
    $first = $this->startDriverProcess([...$inputs[0], 'barrier_at' => 'trip_written']);
    $firstReady = $first->await('ready');
    $first->await('trip_written');
    $second = $this->startDriverProcess([...$inputs[1], 'barrier_at' => 'trip_write_attempt']);
    $secondReady = $second->await('ready');
    $second->await('trip_write_attempt');
    $second->release();
    $deadline = microtime(true) + 5;
    do {
        $waiting = (bool) DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS waiting', [$firstReady['pgsql_pid'], $secondReady['pgsql_pid']])->waiting;
    } while (! $waiting && microtime(true) < $deadline);
    expect($waiting)->toBeTrue();
    $first->release();
    $winner = $first->await('result');
    $loser = $second->await('result');

    expect($winner['status'])->toBe('success')
        ->and($loser['status'])->toBe('validation')
        ->and($loser['errors'])->toBe(['service_date' => ['The selected departure or resource conflicts with an existing trip.']])
        ->and(Trip::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('serializes reservation against resource mutations and revalidates after waiting', function (string $mutation, bool $reservationFirst): void {
    Http::preventStrayRequests();
    $company = $this->createConcurrentCompany();
    $company->update(['timezone' => 'America/Costa_Rica']);
    $fixture = Trip::factory()->for($company)->withDriverAvailability()->make();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $schedule = DriverSchedule::query()->where('driver_id', $fixture->driver_id)->firstOrFail();
    $input = [
        'company_id' => $company->id, 'actor_id' => $actor->id, 'previous_team' => null,
        'driver_id' => $fixture->driver_id, 'bus_id' => $fixture->bus_id, 'schedule_id' => $schedule->id,
        'schedule' => ['end_time' => '06:45', 'ends_next_day' => false],
        'plan' => [...$fixture->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
            'service_date' => '2026-10-26', 'arrival_at' => '2026-10-26T07:30:00-06:00'],
    ];
    $bus = str_starts_with($mutation, 'bus_');
    $first = $this->startDriverProcess([
        ...$input, 'operation' => $reservationFirst ? 'trip_schedule' : $mutation,
        'barrier_at' => $bus ? 'bus_locked' : 'person_locked',
    ]);
    $firstReady = $first->await('ready');
    $first->await($bus ? 'bus_locked' : 'person_locked');
    $second = $this->startDriverProcess([
        ...$input, 'operation' => $reservationFirst ? $mutation : 'trip_schedule',
        'barrier_at' => $bus ? 'bus_attempt' : 'person_attempt',
    ]);
    $secondReady = $second->await('ready');
    $second->await($bus ? 'bus_lock_attempt' : 'person_lock_attempt');
    $second->release();
    $deadline = microtime(true) + 5;
    do {
        $waiting = (bool) DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS waiting', [$firstReady['pgsql_pid'], $secondReady['pgsql_pid']])->waiting;
    } while (! $waiting && microtime(true) < $deadline);
    expect($waiting)->toBeTrue();
    $first->release();
    $firstResult = $first->await('result');
    $secondResult = $second->await('result');

    expect($firstResult['status'])->toBe('success')
        ->and($secondResult['status'])->toBe('validation')
        ->and(Trip::query()->where('company_id', $company->id)->count())->toBe($reservationFirst ? 1 : 0);
    if ($bus) {
        $persistedBus = Bus::withTrashed()->findOrFail($fixture->bus_id);
        expect($mutation === 'bus_archive' ? ! $persistedBus->trashed() : $persistedBus->is_active)->toBe($reservationFirst);
    }
    foreach ([$firstResult, $secondResult] as $result) {
        expect($result['team_restored'])->toBeTrue()
            ->and($result['pgsql_level'])->toBe(0)
            ->and($result['pgsql_transaction'])->toBeFalse();
    }
})->with(['bus_deactivate', 'bus_archive', 'driver_deactivate', 'driver_archive', 'license_shorten', 'schedule_delete', 'schedule_update'])->with([true, false]);

test('serializes the same person across different companies and driver profiles', function (): void {
    Http::preventStrayRequests();
    $firstCompany = $this->createConcurrentCompany();
    $secondCompany = $this->createConcurrentCompany();
    foreach ([$firstCompany, $secondCompany] as $company) {
        $company->update(['timezone' => 'America/Costa_Rica']);
    }
    $firstFixture = Trip::factory()->for($firstCompany)->withDriverAvailability()->make();
    $profile = Driver::factory()->for($secondCompany)->create([
        'user_id' => $firstFixture->driver->user_id, 'employment_starts_on' => '2026-01-01', 'license_expires_at' => '2030-01-01',
    ]);
    CompanyUser::factory()->create(['company_id' => $secondCompany->id, 'user_id' => $profile->user_id]);
    setPermissionsTeamId($secondCompany->id);
    $profile->user->assignRole(Role::findOrCreate(UserRole::Driver->value, 'web'));
    setPermissionsTeamId(null);
    DriverSchedule::factory()->for($profile)->create(['day_of_week' => 1, 'start_time' => '00:00', 'end_time' => '00:00', 'ends_next_day' => true]);
    $secondFixture = Trip::factory()->for($secondCompany)->for($profile, 'driver')->make();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $inputs = [];
    foreach ([$firstFixture, $secondFixture] as $fixture) {
        $inputs[] = [
            'operation' => 'trip_schedule', 'company_id' => $fixture->company_id, 'actor_id' => $actor->id, 'previous_team' => null,
            'plan' => [...$fixture->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
                'service_date' => '2026-10-26', 'arrival_at' => '2026-10-26T07:30:00-06:00'],
        ];
    }
    $first = $this->startDriverProcess([...$inputs[0], 'barrier_at' => 'person_locked']);
    $firstReady = $first->await('ready');
    $first->await('person_locked');
    $second = $this->startDriverProcess([...$inputs[1], 'barrier_at' => 'person_attempt']);
    $secondReady = $second->await('ready');
    $second->await('person_lock_attempt');
    $second->release();
    $deadline = microtime(true) + 5;
    do {
        $waiting = (bool) DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS waiting', [$firstReady['pgsql_pid'], $secondReady['pgsql_pid']])->waiting;
    } while (! $waiting && microtime(true) < $deadline);
    expect($waiting)->toBeTrue();
    $first->release();
    $winner = $first->await('result');
    $loser = $second->await('result');

    expect($winner['status'])->toBe('success')
        ->and($loser['status'])->toBe('validation')
        ->and($loser['errors'])->toBe(['driver_id' => ['The selected departure or resource conflicts with an existing trip.']])
        ->and(Trip::query()->whereIn('company_id', [$firstCompany->id, $secondCompany->id])->count())->toBe(1);
});

test('preserves the losing original plan when two real replans compete for one bus', function (): void {
    Http::preventStrayRequests();
    $company = $this->createConcurrentCompany();
    $company->update(['timezone' => 'America/Costa_Rica']);
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $target = Bus::factory()->for($company)->active()->create();
    $originals = [];
    $inputs = [];
    for ($index = 0; $index < 2; $index++) {
        $trip = Trip::factory()->for($company)->withDriverAvailability()->create()->refresh();
        $originals[] = $trip;
        $inputs[] = [
            'operation' => 'trip_replan', 'trip_id' => $trip->id,
            'company_id' => $company->id, 'actor_id' => $actor->id, 'previous_team' => null,
            'plan' => [...$trip->only(['route_pattern_id', 'route_schedule_id', 'driver_id']), 'bus_id' => $target->id,
                'service_date' => '2026-10-26', 'arrival_at' => '2026-10-26T07:30:00-06:00'],
        ];
    }
    $before = $originals[1]->getRawOriginal();
    $first = $this->startDriverProcess([...$inputs[0], 'barrier_at' => 'bus_locked']);
    $firstReady = $first->await('ready');
    $first->await('bus_locked');
    $second = $this->startDriverProcess([...$inputs[1], 'barrier_at' => 'bus_attempt']);
    $secondReady = $second->await('ready');
    $second->await('bus_lock_attempt');
    $second->release();
    $deadline = microtime(true) + 5;
    do {
        $waiting = (bool) DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS waiting', [$firstReady['pgsql_pid'], $secondReady['pgsql_pid']])->waiting;
    } while (! $waiting && microtime(true) < $deadline);
    expect($waiting)->toBeTrue();
    $first->release();
    $winner = $first->await('result');
    $loser = $second->await('result');

    expect($winner['status'])->toBe('success')
        ->and($loser['status'])->toBe('validation')
        ->and($loser['errors'])->toHaveKey('bus_id')
        ->and($originals[0]->fresh()->bus_id)->toBe($target->id)
        ->and($originals[1]->fresh()->getRawOriginal())->toBe($before);
});
