<?php

use App\Enums\TokenValidationStatus;
use App\Enums\UserRole;
use App\Models\Trip;
use App\Services\AuthService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('keeps auth.gps as a backwards-compatible alias of bearer authentication', function (): void {
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $user = createUserWithRole(UserRole::Passenger);
    $authService = Mockery::mock(AuthService::class);
    $authService->shouldReceive('validateToken')->with('active-user-token')->andReturn(TokenValidationStatus::Valid);
    $authService->shouldReceive('getUser')->with('active-user-token')->andReturn(['id' => $user->id]);
    app()->instance(AuthService::class, $authService);

    $this->withToken('active-user-token')
        ->getJson('/api/internal/v1/trips/'.$trip->id.'/tracking-context')
        ->assertOk()
        ->assertJsonPath('data.id', $trip->id);
});

test('does not accept a legacy HMAC signature without a bearer token', function (): void {
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();

    $this->getJson('/api/v1/operations/trips/'.$trip->id.'/tracking-context', [
        'X-Service-Timestamp' => (string) now()->timestamp,
        'X-Service-Signature' => str_repeat('a', 64),
    ])->assertUnauthorized()
        ->assertJsonPath('errors.0.title', 'Unauthorized');
});

test('ignores legacy HMAC headers when the bearer token is valid', function (): void {
    $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
    $user = createUserWithRole(UserRole::Passenger);
    $authService = Mockery::mock(AuthService::class);
    $authService->shouldReceive('validateToken')->andReturn(TokenValidationStatus::Valid);
    $authService->shouldReceive('getUser')->andReturn(['id' => $user->id]);
    app()->instance(AuthService::class, $authService);

    $this->withHeaders([
        'Authorization' => 'Bearer active-user-token',
        'X-Service-Timestamp' => 'invalid',
        'X-Service-Signature' => 'invalid',
    ])->getJson('/api/v1/operations/trips/'.$trip->id.'/tracking-context')
        ->assertOk()
        ->assertJsonPath('data.id', $trip->id);
});
