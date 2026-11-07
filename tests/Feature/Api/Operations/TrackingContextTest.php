<?php

use App\Enums\TripStatus;
use App\Models\Trip;

describe('Trip Tracking Context Retrieval', function (): void {
    it('returns trusted tracking context and planned snapshot for an existing trip', function (): void {
        $trip = Trip::factory()->withDriverAvailability()->create()->refresh();

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
    });

    it('returns 404 for non-existent trip tracking context', function (): void {
        $response = $this->getJson('/api/v1/operations/trips/0192e2b3-0000-7000-8000-000000000000/tracking-context');
        $response->assertNotFound();
    });
});
