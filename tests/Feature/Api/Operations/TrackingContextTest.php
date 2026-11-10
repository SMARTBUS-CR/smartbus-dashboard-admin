<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.smartbus.tracking_context_secret' => 'gps-test-secret']);
    Http::preventStrayRequests();
});

describe('Trip Tracking Context Retrieval', function (): void {
    it('returns trusted tracking context and planned snapshot for a signed GPS request without a user', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $timestamp = (string) now()->timestamp;
        $signedHeaders = static fn (string $uri): array => [
            'X-Service-Timestamp' => $timestamp,
            'X-Service-Signature' => hash_hmac('sha256', $timestamp."\nGET\n".$uri."\n".hash('sha256', ''), 'gps-test-secret'),
        ];

        $uri = "/api/v1/operations/trips/{$trip->id}/tracking-context";
        $response = $this->get($uri, $signedHeaders($uri));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonStructure([
                'data' => [
                    'type',
                    'id',
                    'attributes' => [
                        'trip_id',
                        'company_id',
                        'bus_id',
                        'driver_id',
                        'route_pattern_id',
                        'status',
                        'scheduled_departure_at',
                        'scheduled_arrival_at',
                        'planned_route_snapshot',
                    ],
                ],
            ]);

        expect($response->json('data.id'))->toBe($trip->id)
            ->and($response->json('data.attributes.status'))->toBe(TripStatus::SCHEDULED->value)
            ->and($response->json('data.attributes.route_pattern_id'))->toBe($trip->route_pattern_id);

        $internalUri = "/api/internal/v1/trips/{$trip->id}/tracking-context";
        $internalResponse = $this->get($internalUri, $signedHeaders($internalUri));
        $internalResponse->assertOk()
            ->assertJsonPath('data.id', $trip->id);
        Http::assertNothingSent();
    });

    it('rejects a user bearer token as a substitute for a GPS signature with 401', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);
        $this->actingAs($superAdmin);

        $response = $this->withToken('valid-sanctum-token')
            ->get("/api/v1/operations/trips/{$trip->id}/tracking-context");

        $response->assertUnauthorized();
        Http::assertNothingSent();
    });

    it('rejects unauthenticated requests without token with 401 unauthorized', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();

        $response = $this->get("/api/v1/operations/trips/{$trip->id}/tracking-context");
        $response->assertStatus(401);
    });

    it('rejects invalid bearer credentials without calling external auth with 401', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();

        $response = $this->withToken('expired-or-revoked-token')
            ->get("/api/v1/operations/trips/{$trip->id}/tracking-context");

        $response->assertStatus(401);
        Http::assertNothingSent();
    });

    it('rejects authenticated users without a GPS signature with 401', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $unauthorizedUser = User::factory()->create();

        $this->actingAs($unauthorizedUser);

        $response = $this->get("/api/v1/operations/trips/{$trip->id}/tracking-context");
        $response->assertUnauthorized();
    });

    it('returns 404 for non-existent trip tracking context when authorized', function (): void {
        $uri = '/api/internal/v1/trips/0192e2b3-0000-7000-8000-000000000000/tracking-context';
        $timestamp = (string) now()->timestamp;
        $response = $this->get($uri, [
            'X-Service-Timestamp' => $timestamp,
            'X-Service-Signature' => hash_hmac('sha256', $timestamp."\nGET\n".$uri."\n".hash('sha256', ''), 'gps-test-secret'),
        ]);
        $response->assertNotFound();
    });
});
