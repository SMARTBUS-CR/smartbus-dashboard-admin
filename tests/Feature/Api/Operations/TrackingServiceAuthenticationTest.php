<?php

use App\Enums\UserRole;
use App\Models\Trip;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(now()->startOfSecond());
    config(['services.smartbus.tracking_context_secret' => 'gps-test-secret']);
    Http::preventStrayRequests();
});

/** @return array<string, string> */
function trackingServiceHeaders(string $uri, ?int $timestamp = null, string $method = 'GET', string $body = ''): array
{
    $timestamp = (string) ($timestamp ?? now()->timestamp);

    return [
        'X-Service-Timestamp' => $timestamp,
        'X-Service-Signature' => hash_hmac('sha256', implode("\n", [$timestamp, $method, $uri, hash('sha256', $body)]), 'gps-test-secret'),
    ];
}

test('rejects unsigned tracking requests even from a superadmin with 401', function (string $prefix): void {
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $this->actingAs(createUserWithRole(UserRole::SuperAdmin));

    $this->get($prefix.'/'.$trip->id.'/tracking-context')->assertUnauthorized();

    Http::assertNothingSent();
})->with(['/api/internal/v1/trips', '/api/v1/operations/trips']);

test('rejects an assigned driver without a service signature with 401', function (): void {
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $this->actingAs($trip->driver->user);

    $this->get('/api/internal/v1/trips/'.$trip->id.'/tracking-context')->assertUnauthorized();
    Http::assertNothingSent();
});

test('rejects malformed or tampered service signatures with 401', function (string $scenario): void {
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $uri = '/api/internal/v1/trips/'.$trip->id.'/tracking-context';
    $headers = trackingServiceHeaders($uri);
    match ($scenario) {
        'missing timestamp' => $headers['X-Service-Timestamp'] = '',
        'malformed timestamp' => $headers['X-Service-Timestamp'] = 'not-a-timestamp',
        'missing signature' => $headers['X-Service-Signature'] = '',
        'malformed signature' => $headers['X-Service-Signature'] = 'not-a-signature',
        'wrong secret' => $headers['X-Service-Signature'] = str_repeat('0', 64),
        'different timestamp' => $headers['X-Service-Timestamp'] = (string) (now()->timestamp - 1),
        'different method' => $headers = trackingServiceHeaders($uri, method: 'POST'),
        'different path' => $headers = trackingServiceHeaders(str_replace('/internal/v1/', '/v1/operations/', $uri)),
        'different query' => $headers = trackingServiceHeaders($uri.'?source=gps'),
        'different body' => $headers = trackingServiceHeaders($uri, body: 'altered'),
    };

    $this->get($uri, $headers)->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('errors.0.status', '401');
    Http::assertNothingSent();
})->with([
    'missing timestamp', 'malformed timestamp', 'missing signature', 'malformed signature',
    'wrong secret', 'different timestamp', 'different method', 'different path', 'different query', 'different body',
]);

test('rejects expired and excessively future service signatures with 401', function (int $offset): void {
    $this->travelTo(now()->startOfSecond());
    $uri = '/api/internal/v1/trips/0192e2b3-0000-7000-8000-000000000000/tracking-context';

    $this->get($uri, trackingServiceHeaders($uri, now()->timestamp + $offset))->assertUnauthorized();
})->with([-301, 301]);

test('accepts signatures at the clock tolerance boundaries with 200', function (int $offset): void {
    $this->travelTo(now()->startOfSecond());
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $uri = '/api/internal/v1/trips/'.$trip->id.'/tracking-context?source=gps';

    $this->get($uri, trackingServiceHeaders($uri, now()->timestamp + $offset))->assertOk()
        ->assertJsonPath('data.id', $trip->id);
    Http::assertNothingSent();
})->with([-300, 300]);

test('fails closed when the service secret is not configured with 503', function (?string $secret): void {
    config(['services.smartbus.tracking_context_secret' => $secret]);
    $uri = '/api/internal/v1/trips/0192e2b3-0000-7000-8000-000000000000/tracking-context';

    $this->get($uri, trackingServiceHeaders($uri))->assertServiceUnavailable();
    Http::assertNothingSent();
})->with([null, '', '   ']);

test('keeps the GPS contract on the operations path even with the ETA selector', function (): void {
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $uri = '/api/v1/operations/trips/'.$trip->id.'/tracking-context?projection=eta-v1';

    $this->get($uri, trackingServiceHeaders($uri))->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('data.id', $trip->id)
        ->assertJsonMissingPath('contract_version');
});
