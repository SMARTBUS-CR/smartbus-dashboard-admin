<?php

use App\Enums\TripStatus;
use App\Models\Trip;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config([
        'services.smartbus.tracking_context_secret' => 'gps-test-secret',
        'services.smartbus.trip_context_token' => 'eta-test-token',
    ]);
    Http::preventStrayRequests();
});

function etaTrackingContextUri(string $tripId): string
{
    return "/api/internal/v1/trips/{$tripId}/tracking-context?projection=eta-v1";
}

test('returns the versioned frozen plan with distinct occurrences on a circular trip', function (): void {
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $snapshot = $trip->planned_route_snapshot;
    [$origin, $middle] = $snapshot['occurrences'];
    $origin['minutes_from_start'] = 0;
    $middle['minutes_from_start'] = null;
    $turn = [
        ...$middle,
        'occurrence_id' => (string) Str::uuid(),
        'stop_id' => (string) Str::uuid(),
        'stop_sequence' => 3,
        'lng' => '-84.02',
        'lat' => '10',
        'minutes_from_start' => 30,
    ];
    $secondMiddle = [
        ...$middle,
        'occurrence_id' => (string) Str::uuid(),
        'stop_sequence' => 4,
    ];
    $return = [
        ...$origin,
        'occurrence_id' => (string) Str::uuid(),
        'stop_sequence' => 5,
        'minutes_from_start' => 60,
    ];
    $snapshot['occurrences'] = [$origin, $middle, $turn, $secondMiddle, $return];
    $snapshot['route_geometry']['coordinates'] = [[-84, 10], [-84.01, 10.01], [-84.02, 10], [-84.01, 10.01], [-84, 10]];
    Trip::query()->whereKey($trip->id)->update(['planned_route_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);

    $response = $this->get(etaTrackingContextUri($trip->id), ['Authorization' => 'Bearer eta-test-token'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('contract_version', 1)
        ->assertJsonPath('trip_id', $trip->id)
        ->assertJsonPath('company_id', $trip->company_id)
        ->assertJsonPath('route_pattern_id', $trip->route_pattern_id)
        ->assertJsonPath('status', 'scheduled')
        ->assertJsonPath('service_date', '2026-10-26')
        ->assertJsonPath('timezone', 'America/Costa_Rica')
        ->assertJsonPath('planned_departure_at', $trip->scheduled_departure_at->toISOString())
        ->assertJsonPath('planned_arrival_at', $trip->scheduled_arrival_at->toISOString())
        ->assertJsonPath('route_geometry', $snapshot['route_geometry'])
        ->assertJsonMissingPath('data')
        ->assertJsonMissingPath('driver_id')
        ->assertJsonMissingPath('bus_id')
        ->assertJsonCount(5, 'stop_occurrences')
        ->assertJsonPath('stop_occurrences.0.occurrence_id', $origin['occurrence_id'])
        ->assertJsonPath('stop_occurrences.0.distance_along_route_m', 0)
        ->assertJsonPath('stop_occurrences.1.planned_arrival_at', null)
        ->assertJsonPath('stop_occurrences.2.planned_arrival_at', $trip->scheduled_departure_at->addMinutes(30)->toISOString())
        ->assertJsonPath('stop_occurrences.3.occurrence_id', $secondMiddle['occurrence_id'])
        ->assertJsonPath('stop_occurrences.3.stop_id', $middle['stop_id'])
        ->assertJsonPath('stop_occurrences.4.occurrence_id', $return['occurrence_id'])
        ->assertJsonPath('stop_occurrences.4.stop_id', $origin['stop_id'])
        ->assertJsonPath('stop_occurrences.4.planned_arrival_at', $trip->scheduled_arrival_at->toISOString());

    expect($response->json('route_revision'))->toMatch('/\A[0-9a-f]{64}\z/');
    expect($response->json('stop_occurrences.1.distance_along_route_m'))->toBeGreaterThan(1500)->toBeLessThan(1600);
    expect($response->json('stop_occurrences.3.distance_along_route_m'))->toBeGreaterThan(4600)->toBeLessThan(4800);
    expect($response->json('route_distance_m'))->toBeGreaterThan(6200)->toBeLessThan(6400);
    expect($response->json('stop_occurrences.4.distance_along_route_m'))->toBe($response->json('route_distance_m'));
    Http::assertNothingSent();
});

test('changes the revision on replan and preserves it when the trip starts', function (): void {
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $uri = etaTrackingContextUri($trip->id);
    $headers = ['Authorization' => 'Bearer eta-test-token'];
    $before = $this->get($uri, $headers)->assertOk()->json('route_revision');
    $departure = $trip->scheduled_departure_at->addMinutes(10);
    Trip::query()->whereKey($trip->id)->update([
        'scheduled_departure_at' => $departure,
        'scheduled_arrival_at' => $trip->scheduled_arrival_at->addMinutes(10),
    ]);
    $replanned = $this->get($uri, $headers)->assertOk()->json('route_revision');

    Trip::query()->whereKey($trip->id)->update([
        'status' => TripStatus::IN_PROGRESS->value,
        'actual_departure_at' => $departure,
    ]);
    expect($replanned)->not->toBe($before);
    $this->get($uri, $headers)->assertOk()
        ->assertJsonPath('status', 'in_progress')
        ->assertJsonPath('route_revision', $replanned);
});

test('rejects missing or invalid ETA credentials with 401', function (array $headers): void {
    $this->get(etaTrackingContextUri('0192e2b3-0000-7000-8000-000000000000'), $headers)
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer');
})->with([
    'missing' => [[]],
    'wrong' => [['Authorization' => 'Bearer wrong-token']],
    'wrong scheme' => [['Authorization' => 'Basic Bearer eta-test-token']],
    'GPS secret' => [['Authorization' => 'Bearer gps-test-secret']],
]);

test('does not accept a GPS signature for the ETA projection', function (): void {
    $uri = etaTrackingContextUri('0192e2b3-0000-7000-8000-000000000000');
    $timestamp = (string) now()->timestamp;

    $this->get($uri, [
        'X-Service-Timestamp' => $timestamp,
        'X-Service-Signature' => hash_hmac('sha256', $timestamp."\nGET\n".$uri."\n".hash('sha256', ''), 'gps-test-secret'),
    ])->assertUnauthorized();
});

test('does not accept the ETA credential outside its internal projection', function (string $uri): void {
    $this->get($uri, ['Authorization' => 'Bearer eta-test-token'])->assertUnauthorized();
})->with([
    '/api/internal/v1/trips/0192e2b3-0000-7000-8000-000000000000/tracking-context',
    '/api/v1/operations/trips/0192e2b3-0000-7000-8000-000000000000/tracking-context?projection=eta-v1',
]);

test('fails closed when the ETA credential is unavailable with 503', function (): void {
    config(['services.smartbus.trip_context_token' => null]);

    $this->get(etaTrackingContextUri('0192e2b3-0000-7000-8000-000000000000'))
        ->assertServiceUnavailable();
});

test('returns 404 for a missing trip when authenticated for ETA', function (): void {
    $this->get(etaTrackingContextUri('0192e2b3-0000-7000-8000-000000000000'), [
        'Authorization' => 'Bearer eta-test-token',
    ])->assertNotFound();
});

test('rejects ambiguous progress on the saved route with 422', function (): void {
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $snapshot = $trip->planned_route_snapshot;
    [$origin, $end] = $snapshot['occurrences'];
    $middle = [
        ...$origin,
        'occurrence_id' => (string) Str::uuid(),
        'stop_sequence' => 2,
    ];
    $end['stop_sequence'] = 3;
    $snapshot['occurrences'] = [$origin, $middle, $end];
    $snapshot['route_geometry']['coordinates'] = [[-84, 10], [-84.02, 10], [-84, 10], [-84.01, 10.01]];
    Trip::query()->whereKey($trip->id)->update(['planned_route_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);

    $this->get(etaTrackingContextUri($trip->id), ['Authorization' => 'Bearer eta-test-token'])
        ->assertUnprocessable();
});
