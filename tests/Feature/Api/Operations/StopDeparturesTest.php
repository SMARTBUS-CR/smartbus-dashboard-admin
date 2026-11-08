<?php

use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\RouteScheduleException;
use App\Models\Stop;

describe('Planned Stop Departures API', function (): void {
    it('lists upcoming departures from a stop within the time window', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $route = Route::factory()->for($company)->create(['code' => 'R-200', 'name' => 'San Jose - Cartago']);
        $pattern = RoutePattern::factory()->for($route)->create(['code' => 'P-CARTAGO', 'headsign' => 'Cartago Centro']);
        $stop = Stop::factory()->for($company)->create(['name' => 'Parada San Pedro']);

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stop->id,
            'stop_sequence' => 3,
            'minutes_from_start' => 15,
        ]);

        // Departure 1: 07:00 at origin -> 07:15 at stop
        RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1, // Monday
            'departure_time' => '07:00:00',
            'valid_from' => '2026-01-01',
        ]);

        // Departure 2: 07:30 at origin -> 07:45 at stop
        RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1,
            'departure_time' => '07:30:00',
            'valid_from' => '2026-01-01',
        ]);

        // Departure 3: 09:30 at origin -> 09:45 at stop (outside 120min window if querying at 07:00)
        RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1,
            'departure_time' => '09:30:00',
            'valid_from' => '2026-01-01',
        ]);

        // Route Fare
        RouteFare::factory()->for($route)->create([
            'amount' => '450.00',
            'currency' => 'CRC',
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
        ]);

        // Query Monday 2026-11-09 07:00 Costa Rica = 13:00 UTC, window 120 minutes (until 09:00 local)
        $response = $this->getJson("/api/v1/operations/stops/{$stop->id}/departures?".http_build_query([
            'departure_at' => '2026-11-09T13:00:00Z',
            'window_minutes' => 120,
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'type',
                        'id',
                        'attributes' => [
                            'route_id',
                            'route_code',
                            'route_name',
                            'pattern_id',
                            'headsign',
                            'stop_id',
                            'stop_name',
                            'stop_sequence',
                            'scheduled_stop_departure_at',
                            'fare' => [
                                'amount',
                                'currency',
                            ],
                        ],
                    ],
                ],
            ]);

        $departures = $response->json('data');
        expect(count($departures))->toBe(2)
            ->and($departures[0]['attributes']['route_code'])->toBe('R-200');
    });

    it('excludes departures that are suspended on the service date', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stop->id,
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $schedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1,
            'departure_time' => '07:30:00',
            'valid_from' => '2026-01-01',
        ]);

        RouteScheduleException::factory()->create([
            'route_schedule_id' => $schedule->id,
            'service_date' => '2026-11-09',
        ]);

        $response = $this->getJson("/api/v1/operations/stops/{$stop->id}/departures?".http_build_query([
            'departure_at' => '2026-11-09T13:00:00Z',
        ]));

        $response->assertOk();
        expect($response->json('data'))->toBeEmpty();
    });

    it('returns 404 for non-existent stop', function (): void {
        $response = $this->getJson('/api/v1/operations/stops/0192e2b3-0000-7000-8000-000000000000/departures');
        $response->assertNotFound();
    });

    it('validates window_minutes parameter limits', function (): void {
        $company = createCompany();
        $stop = Stop::factory()->for($company)->create();

        $response = $this->getJson("/api/v1/operations/stops/{$stop->id}/departures?window_minutes=5000");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['window_minutes']);
    });
});
