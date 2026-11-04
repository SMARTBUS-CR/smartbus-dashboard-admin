<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Trip;
use App\Services\TripManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\WithDriverConcurrency;

uses(WithDriverConcurrency::class);

afterEach(function (): void {
    $this->finishDriverConcurrency();
});

test('serializes concurrent terminal retries and competing start or cancellation requests', function (string $firstOperation, string $secondOperation, TripStatus $expectedState, bool $retry): void {
    Http::preventStrayRequests();
    $company = $this->createConcurrentCompany();
    $company->update(['timezone' => 'America/Costa_Rica']);
    $trip = Trip::factory()->for($company)->withDriverAvailability()->create()->refresh();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    if ($firstOperation === 'trip_complete') {
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
        $trip = app(TripManagementService::class)->start($actor, $company, $trip->id);
    }
    $before = $trip->getRawOriginal();
    $team = $this->createConcurrentCompany()->id;
    $input = ['actor_id' => $actor->id, 'company_id' => $company->id, 'trip_id' => $trip->id, 'previous_team' => $team];
    $firstInstant = $firstOperation === 'trip_complete' ? '2026-10-26T13:45:00.123456Z' : '2026-10-26T12:45:00.123456Z';
    $first = $this->startDriverProcess([
        ...$input, 'operation' => $firstOperation, 'instant' => $firstInstant, 'reason' => 'First reason', 'barrier_at' => 'trip_locked',
    ]);
    $firstReady = $first->await('ready');
    $first->await('trip_locked');
    $second = $this->startDriverProcess([
        ...$input, 'operation' => $secondOperation, 'instant' => '2026-10-26T14:45:00Z', 'reason' => 'Second reason', 'barrier_at' => 'trip_lock_attempt',
    ]);
    $secondReady = $second->await('ready');
    $second->await('trip_lock_attempt');
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
    expect($secondResult['status'])->toBe($retry ? 'success' : 'validation');
    $stored = $trip->fresh();
    expect($stored->status)->toBe($expectedState);
    expect(Arr::except($stored->getRawOriginal(), ['status', 'actual_arrival_at', 'actual_departure_at', 'cancelled_at', 'cancellation_reason', 'updated_at']))
        ->toBe(Arr::except($before, ['status', 'actual_arrival_at', 'actual_departure_at', 'cancelled_at', 'cancellation_reason', 'updated_at']));
    if ($retry) {
        expect(Arr::only($secondResult, ['actual_departure_at', 'actual_arrival_at', 'cancelled_at', 'cancellation_reason']))
            ->toBe(Arr::only($winner, ['actual_departure_at', 'actual_arrival_at', 'cancelled_at', 'cancellation_reason']));
    } else {
        expect($secondResult['errors'])->toHaveKey('status');
    }
    if ($expectedState === TripStatus::CANCELLED) {
        expect($stored->cancellation_reason)->toBe('First reason');
        expect($stored->cancelled_at->utc()->format('Y-m-d H:i:s.uP'))->toBe('2026-10-26 12:45:00.123456+00:00');
        expect($stored->actual_departure_at)->toBeNull();
    } elseif ($expectedState === TripStatus::COMPLETED) {
        expect($stored->actual_arrival_at->utc()->format('Y-m-d H:i:s.uP'))->toBe('2026-10-26 13:45:00.123456+00:00');
        expect($stored->actual_departure_at->utc()->format('H:i:s'))->toBe('12:45:00');
    } else {
        expect($stored->cancelled_at)->toBeNull();
        expect($stored->cancellation_reason)->toBeNull();
    }
    foreach ([$winner, $secondResult] as $result) {
        expect($result['team_restored'])->toBeTrue();
        expect($result['actor_cache_cleared'])->toBeTrue();
        expect($result['pgsql_level'])->toBe(0);
        expect($result['pgsql_transaction'])->toBeFalse();
    }
})->with([
    'completion retry' => ['trip_complete', 'trip_complete', TripStatus::COMPLETED, true],
    'cancellation retry' => ['trip_cancel', 'trip_cancel', TripStatus::CANCELLED, true],
    'start wins' => ['trip_start', 'trip_cancel', TripStatus::IN_PROGRESS, false],
    'cancellation wins' => ['trip_cancel', 'trip_start', TripStatus::CANCELLED, false],
]);
