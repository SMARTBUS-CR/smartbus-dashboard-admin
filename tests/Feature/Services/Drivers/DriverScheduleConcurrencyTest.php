<?php

use App\Models\Driver;
use App\Models\DriverSchedule;
use Illuminate\Support\Facades\DB;
use Tests\Support\WithDriverConcurrency;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class, WithDriverConcurrency::class);

afterEach(function (): void {
    $this->finishDriverConcurrency();
});

describe('Driver Schedule Concurrency', function (): void {
    test('serializes competing mutations on the persisted driver row', function (string $scenario): void {
        $company = $this->createConcurrentCompany();
        $previous = $this->createConcurrentCompany();
        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, ['Create:DriverSchedule', 'Update:DriverSchedule', 'Delete:DriverSchedule'], $company);
        $driver = Driver::factory()->for($company)->create();
        $data = ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'];
        $schedule = $scenario === 'create-create' ? null : DriverSchedule::factory()->for($driver)->create([
            'start_time' => $scenario === 'delete-create' ? '08:00' : '16:00',
            'end_time' => $scenario === 'delete-create' ? '12:00' : '18:00',
        ]);
        $input = [
            'company_id' => $company->id, 'actor_id' => $actor->id, 'driver_id' => $driver->id,
            'previous_team' => $previous->id, 'schedule' => $data, 'schedule_id' => $schedule?->id,
        ];
        $first = $this->startDriverProcess([
            ...$input, 'operation' => $scenario === 'delete-create' ? 'schedule_delete' : 'schedule_create', 'barrier_at' => 'schedule_locked',
        ]);
        $firstReady = $first->await('ready');
        $first->await('driver_locked');
        $second = $this->startDriverProcess([
            ...$input, 'operation' => $scenario === 'create-update' ? 'schedule_update' : 'schedule_create', 'barrier_at' => 'schedule_attempt',
        ]);
        $secondReady = $second->await('ready');
        $second->await('driver_lock_attempt');
        $second->release();
        $waiting = false;
        $deadline = microtime(true) + 5;
        do {
            $waiting = (bool) DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS waiting', [$firstReady['pgsql_pid'], $secondReady['pgsql_pid']])->waiting;
        } while (! $waiting && microtime(true) < $deadline);
        expect($waiting)->toBeTrue();
        $first->release();
        $firstResult = $first->await('result');
        $secondResult = $second->await('result');

        expect($firstReady['pgsql_pid'])->not->toBe($secondReady['pgsql_pid']);
        expect($firstResult['status'])->toBe('success');
        expect($secondResult['status'])->toBe($scenario === 'delete-create' ? 'success' : 'validation');
        if ($scenario !== 'delete-create') {
            expect($secondResult['errors'])->toHaveKey('schedule');
        }
        $this->assertDatabaseCount('driver_schedules', $scenario === 'create-update' ? 2 : 1);
        if ($scenario === 'create-update') {
            expect($schedule->fresh()->start_time)->toBe('16:00:00');
        }
        foreach ([$firstResult, $secondResult] as $result) {
            expect($result['team_restored'])->toBeTrue();
            expect($result['actor_cache_cleared'])->toBeTrue();
            expect($result['pgsql_level'])->toBe(0);
            expect($result['pgsql_transaction'])->toBeFalse();
        }
    })->with(['create-create', 'create-update', 'delete-create']);
});
