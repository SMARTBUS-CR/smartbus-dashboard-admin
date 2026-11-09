<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;

describe('Trip Details Retrieval', function (): void {
    it('retrieves detailed trip attributes including planned route snapshot', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $driverUser = $trip->driver->user;

        $this->actingAs($driverUser);

        $response = $this->getJson("/api/v1/operations/trips/{$trip->id}");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJson([
                'data' => [
                    'type' => 'trips',
                    'id' => $trip->id,
                    'attributes' => [
                        'status' => TripStatus::SCHEDULED->value,
                        'service_date' => $trip->service_date->toDateString(),
                        'bus_id' => $trip->bus_id,
                        'driver_id' => $trip->driver_id,
                    ],
                ],
            ]);

        expect($response->json('data.attributes.planned_route_snapshot'))->not->toBeEmpty();
    });

    it('returns 404 when requested trip does not exist', function (): void {
        $user = createUserWithRole(UserRole::SuperAdmin);
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/operations/trips/0192e2b3-0000-7000-8000-000000000000');
        $response->assertNotFound();
    });

    it('rejects unauthenticated requests to trip show with 401', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();

        $this->getJson("/api/v1/operations/trips/{$trip->id}")->assertStatus(401);
    });

    it('rejects unauthorized users from retrieving trip details with 403', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser);

        $response = $this->getJson("/api/v1/operations/trips/{$trip->id}");
        $response->assertStatus(403);
    });
});

describe('Trip Start Endpoint', function (): void {
    it('rejects unauthenticated requests to start trip with 401', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();

        $this->postJson("/api/v1/operations/trips/{$trip->id}/start")->assertStatus(401);
    });
    it('allows the assigned driver to start a scheduled trip and returns in_progress state', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $driverUser = $trip->driver->user;

        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:45:00Z'));
        $this->actingAs($driverUser);

        $response = $this->postJson("/api/v1/operations/trips/{$trip->id}/start");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJson([
                'data' => [
                    'type' => 'trips',
                    'id' => $trip->id,
                    'attributes' => [
                        'status' => TripStatus::IN_PROGRESS->value,
                    ],
                ],
            ]);

        expect($response->json('data.attributes.actual_departure_at'))->not->toBeNull()
            ->and($trip->fresh()->status)->toBe(TripStatus::IN_PROGRESS);
    });

    it('handles trip start idempotently when the trip is already in progress', function (): void {
        $trip = Trip::factory()->withEligibleDriver()->inProgress()->create()->refresh();
        $originalDeparture = $trip->actual_departure_at?->toISOString();
        $driverUser = $trip->driver->user;

        $this->actingAs($driverUser);

        $response = $this->postJson("/api/v1/operations/trips/{$trip->id}/start");

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'type' => 'trips',
                    'id' => $trip->id,
                    'attributes' => [
                        'status' => TripStatus::IN_PROGRESS->value,
                        'actual_departure_at' => $originalDeparture,
                    ],
                ],
            ]);
    });

    it('rejects start attempt by an unauthorized driver with 403', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $otherDriverUser = createUserWithRole(UserRole::Driver);

        $this->actingAs($otherDriverUser);

        $response = $this->postJson("/api/v1/operations/trips/{$trip->id}/start");
        $response->assertForbidden();
    });

    it('returns 409 conflict when starting a trip that is already completed or cancelled', function (): void {
        $completedTrip = Trip::factory()->withEligibleDriver()->completed()->create()->refresh();
        $this->actingAs($completedTrip->driver->user);

        $response = $this->postJson("/api/v1/operations/trips/{$completedTrip->id}/start");
        $response->assertStatus(409);
    });
});

describe('Trip Completion Endpoint', function (): void {
    it('rejects unauthenticated requests to complete trip with 401', function (): void {
        $trip = Trip::factory()->withEligibleDriver()->inProgress()->create()->refresh();

        $this->postJson("/api/v1/operations/trips/{$trip->id}/complete")->assertStatus(401);
    });

    it('allows the assigned driver to complete an in-progress trip and returns completed state', function (): void {
        $trip = Trip::factory()->withEligibleDriver()->inProgress()->create()->refresh();
        $driverUser = $trip->driver->user;

        $this->travelTo(CarbonImmutable::parse($trip->scheduled_arrival_at)->addMinutes(10));
        $this->actingAs($driverUser);

        $response = $this->postJson("/api/v1/operations/trips/{$trip->id}/complete");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJson([
                'data' => [
                    'type' => 'trips',
                    'id' => $trip->id,
                    'attributes' => [
                        'status' => TripStatus::COMPLETED->value,
                    ],
                ],
            ]);

        expect($response->json('data.attributes.actual_arrival_at'))->not->toBeNull()
            ->and($trip->fresh()->status)->toBe(TripStatus::COMPLETED);
    });

    it('handles trip completion idempotently when the trip is already completed', function (): void {
        $trip = Trip::factory()->withEligibleDriver()->completed()->create()->refresh();
        $originalArrival = $trip->actual_arrival_at?->toISOString();
        $driverUser = $trip->driver->user;

        $this->actingAs($driverUser);

        $response = $this->postJson("/api/v1/operations/trips/{$trip->id}/complete");

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'type' => 'trips',
                    'id' => $trip->id,
                    'attributes' => [
                        'status' => TripStatus::COMPLETED->value,
                        'actual_arrival_at' => $originalArrival,
                    ],
                ],
            ]);
    });

    it('rejects completion of a trip that has not been started with 409 conflict', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();
        $driverUser = $trip->driver->user;

        $this->actingAs($driverUser);

        $response = $this->postJson("/api/v1/operations/trips/{$trip->id}/complete");
        $response->assertStatus(409);
    });

    it('rejects completion attempt by an unauthorized driver with 403', function (): void {
        $trip = Trip::factory()->inProgress()->create()->refresh();
        $otherDriverUser = createUserWithRole(UserRole::Driver);

        $this->actingAs($otherDriverUser);

        $response = $this->postJson("/api/v1/operations/trips/{$trip->id}/complete");
        $response->assertForbidden();
    });
});
