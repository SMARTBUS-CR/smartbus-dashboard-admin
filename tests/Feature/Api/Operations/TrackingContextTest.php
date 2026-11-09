<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\Http;

describe('Trip Tracking Context Retrieval', function (): void {
    it('returns trusted tracking context and planned snapshot when authorized as superadmin', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);

        $this->actingAs($superAdmin);

        $response = $this->getJson("/api/v1/operations/trips/{$trip->id}/tracking-context");

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

        // Also test the internal service route
        $internalResponse = $this->getJson("/api/internal/v1/trips/{$trip->id}/tracking-context");
        $internalResponse->assertOk()
            ->assertJsonPath('data.id', $trip->id);
    });

    it('authenticates via external sanctum bearer token validated against auth service', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);

        Http::fake([
            '*/auth/token/validate' => Http::response([
                'meta' => [
                    'valid' => true,
                ],
            ], 200),
            '*/auth/user*' => Http::response([
                'data' => [
                    'id' => $superAdmin->id,
                    'type' => 'users',
                    'attributes' => [
                        'name' => $superAdmin->name,
                        'email' => $superAdmin->email,
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withToken('valid-sanctum-token')
            ->getJson("/api/v1/operations/trips/{$trip->id}/tracking-context");

        $response->assertOk()
            ->assertJsonPath('data.id', $trip->id);
    });

    it('rejects unauthenticated requests without token with 401 unauthorized', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();

        $response = $this->getJson("/api/v1/operations/trips/{$trip->id}/tracking-context");
        $response->assertStatus(401);
    });

    it('rejects invalid external sanctum bearer token with 401 unauthorized', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();

        Http::fake([
            '*/auth/token/validate' => Http::response([
                'meta' => [
                    'valid' => false,
                ],
            ], 200),
        ]);

        $response = $this->withToken('expired-or-revoked-token')
            ->getJson("/api/v1/operations/trips/{$trip->id}/tracking-context");

        $response->assertStatus(401);
    });

    it('rejects authenticated users who lack permission for this company trip with 403', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $unauthorizedUser = User::factory()->create();

        $this->actingAs($unauthorizedUser);

        $response = $this->getJson("/api/v1/operations/trips/{$trip->id}/tracking-context");
        $response->assertStatus(403);
    });

    it('returns 404 for non-existent trip tracking context when authorized', function (): void {
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);

        $this->actingAs($superAdmin);

        $response = $this->getJson('/api/v1/operations/trips/0192e2b3-0000-7000-8000-000000000000/tracking-context');
        $response->assertNotFound();
    });
});
