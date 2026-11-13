<?php

use App\Enums\TokenValidationStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Trip;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function authenticateTrackingUser(User $user, string $token = 'valid-sanctum-token'): void
{
    $authService = Mockery::mock(AuthService::class);
    $authService->shouldReceive('validateToken')->with($token)->andReturn(TokenValidationStatus::Valid);
    $authService->shouldReceive('getUser')->with($token)->andReturn(['id' => $user->id]);
    app()->instance(AuthService::class, $authService);
}

describe('Trip Tracking Context Retrieval', function (): void {
    it('returns tracking context with a valid bearer token and no HMAC headers', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $user = createUserWithRole(UserRole::Passenger);
        authenticateTrackingUser($user);

        $response = $this->withToken('valid-sanctum-token')
            ->getJson("/api/v1/operations/trips/{$trip->id}/tracking-context");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('data.id', $trip->id)
            ->assertJsonPath('data.attributes.driver_id', $trip->driver_id)
            ->assertJsonPath('data.attributes.driver_user_id', $trip->driver->user_id)
            ->assertJsonPath('data.attributes.status', TripStatus::SCHEDULED->value);
        Http::assertNothingSent();
    });

    it('returns 401 Unauthorized without a bearer token', function (string $uri): void {
        $this->getJson($uri)
            ->assertUnauthorized()
            ->assertJsonPath('errors.0.title', 'Unauthorized');
    })->with([
        '/api/v1/operations/trips/0192e2b3-0000-7000-8000-000000000000/tracking-context',
        '/api/internal/v1/trips/0192e2b3-0000-7000-8000-000000000000/tracking-context',
    ]);

    it('returns 401 for an invalid or expired bearer token', function (): void {
        $authService = Mockery::mock(AuthService::class);
        $authService->shouldReceive('validateToken')->with('expired-token')->andReturn(TokenValidationStatus::Invalid);
        app()->instance(AuthService::class, $authService);

        $this->withToken('expired-token')
            ->getJson('/api/v1/operations/trips/0192e2b3-0000-7000-8000-000000000000/tracking-context')
            ->assertUnauthorized()
            ->assertJsonPath('errors.0.detail', 'The access token is invalid or expired.');
    });

    it('allows every active ecosystem role with a valid bearer token', function (UserRole $role): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $user = createUserWithRole($role);
        authenticateTrackingUser($user);

        $this->withToken('valid-sanctum-token')
            ->getJson("/api/v1/operations/trips/{$trip->id}/tracking-context")
            ->assertOk()
            ->assertJsonPath('data.id', $trip->id);
    })->with([
        UserRole::Driver,
        UserRole::Passenger,
        UserRole::Admin,
        UserRole::SuperAdmin,
    ]);

    it('rejects an archived authenticated user with 401', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->delete();

        $this->getJson("/api/v1/operations/trips/{$trip->id}/tracking-context")
            ->assertUnauthorized();
    });

    it('returns 404 for a missing trip after bearer authentication', function (): void {
        $user = User::factory()->create();
        authenticateTrackingUser($user);

        $this->withToken('valid-sanctum-token')
            ->getJson('/api/internal/v1/trips/0192e2b3-0000-7000-8000-000000000000/tracking-context')
            ->assertNotFound();
    });
});
