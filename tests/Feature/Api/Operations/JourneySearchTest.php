<?php

use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\RouteScheduleException;
use App\Models\Stop;

describe('Direct Journey Search API', function (): void {
    it('finds direct journey options between nearby stops with schedule and fare', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $route = Route::factory()->for($company)->create(['code' => 'R-SJ-ALJ', 'name' => 'San Jose - Alajuela']);
        $pattern = RoutePattern::factory()->for($route)->create(['code' => 'P-OUT', 'name' => 'Sentido Alajuela', 'headsign' => 'Terminal Alajuela']);

        // Stop 1: San Jose Center (Origin nearby)
        $stopOrigin = Stop::factory()->for($company)->create([
            'name' => 'Terminal San Jose',
            'latitude' => 9.9333000,
            'longitude' => -84.0833000,
        ]);

        // Stop 2: Alajuela Center (Destination nearby)
        $stopDest = Stop::factory()->for($company)->create([
            'name' => 'Terminal Alajuela',
            'latitude' => 10.0167000,
            'longitude' => -84.2167000,
        ]);

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopOrigin->id,
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);
        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopDest->id,
            'stop_sequence' => 2,
            'minutes_from_start' => 45,
        ]);

        // Service date: Monday 2026-11-09 (dayOfWeek = 1)
        $schedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1,
            'departure_time' => '07:00:00',
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
        ]);

        // Route Fare
        RouteFare::factory()->for($route)->create([
            'amount' => '650.00',
            'currency' => 'CRC',
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
        ]);

        // Query: Passenger is 100m away from Stop 1, and destination is 150m away from Stop 2
        // Monday 2026-11-09 06:45:00 Costa Rica = 12:45:00 UTC
        $response = $this->getJson('/api/v1/operations/journeys?'.http_build_query([
            'origin_lat' => 9.9335,
            'origin_lng' => -84.0830,
            'destination_lat' => 10.0165,
            'destination_lng' => -84.2165,
            'departure_at' => '2026-11-09T12:45:00Z',
            'walking_radius_m' => 500,
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
                            'pattern_name',
                            'headsign',
                            'company_id',
                            'company_name',
                            'boarding_stop' => [
                                'stop_id',
                                'stop_name',
                                'stop_sequence',
                                'distance_m',
                            ],
                            'alighting_stop' => [
                                'stop_id',
                                'stop_name',
                                'stop_sequence',
                                'distance_m',
                            ],
                            'scheduled_departure_at',
                            'estimated_arrival_at',
                            'fare' => [
                                'amount',
                                'currency',
                            ],
                        ],
                    ],
                ],
            ]);

        $journeys = $response->json('data');
        expect($journeys)->not->toBeEmpty()
            ->and($journeys[0]['attributes']['route_code'])->toBe('R-SJ-ALJ')
            ->and($journeys[0]['attributes']['fare']['amount'])->toBe('650.00')
            ->and($journeys[0]['attributes']['fare']['currency'])->toBe('CRC');
    });

    it('respects direction ordering and handles loops with repeated stop occurrences', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $stopTerminal = Stop::factory()->for($company)->create(['latitude' => 9.9330, 'longitude' => -84.0830]);
        $stopMiddle = Stop::factory()->for($company)->create(['latitude' => 9.9500, 'longitude' => -84.1000]);

        // Loop: Terminal (1) -> Middle (2) -> Terminal (3)
        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopTerminal->id,
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);
        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopMiddle->id,
            'stop_sequence' => 2,
            'minutes_from_start' => 20,
        ]);
        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopTerminal->id,
            'stop_sequence' => 3,
            'minutes_from_start' => 40,
        ]);

        RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1, // Monday
            'departure_time' => '08:00:00',
            'valid_from' => '2026-01-01',
        ]);

        // Case A: Middle to Terminal (Valid forward: sequence 2 -> 3)
        $responseForward = $this->getJson('/api/v1/operations/journeys?'.http_build_query([
            'origin_lat' => 9.9500,
            'origin_lng' => -84.1000,
            'destination_lat' => 9.9330,
            'destination_lng' => -84.0830,
            'departure_at' => '2026-11-09T13:30:00Z',
        ]));

        $responseForward->assertOk();
        $results = $responseForward->json('data');
        expect($results)->not->toBeEmpty()
            ->and($results[0]['attributes']['boarding_stop']['stop_sequence'])->toBe(2)
            ->and($results[0]['attributes']['alighting_stop']['stop_sequence'])->toBe(3);
    });

    it('excludes departures that are suspended on the service date', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $stopA = Stop::factory()->for($company)->create(['latitude' => 10.0000, 'longitude' => -84.0000]);
        $stopB = Stop::factory()->for($company)->create(['latitude' => 10.0500, 'longitude' => -84.0500]);

        RoutePatternStop::factory()->create(['route_pattern_id' => $pattern->id, 'stop_id' => $stopA->id, 'stop_sequence' => 1]);
        RoutePatternStop::factory()->create(['route_pattern_id' => $pattern->id, 'stop_id' => $stopB->id, 'stop_sequence' => 2]);

        $schedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1, // Monday
            'departure_time' => '09:00:00',
            'valid_from' => '2026-01-01',
        ]);

        // Suspend this schedule for Monday 2026-11-09
        RouteScheduleException::factory()->create([
            'route_schedule_id' => $schedule->id,
            'service_date' => '2026-11-09',
        ]);

        $response = $this->getJson('/api/v1/operations/journeys?'.http_build_query([
            'origin_lat' => 10.0000,
            'origin_lng' => -84.0000,
            'destination_lat' => 10.0500,
            'destination_lng' => -84.0500,
            'departure_at' => '2026-11-09T14:30:00Z',
        ]));

        $response->assertOk();
        expect($response->json('data'))->toBeEmpty();
    });

    it('returns empty results when no direct connection exists within walking radius', function (): void {
        $response = $this->getJson('/api/v1/operations/journeys?'.http_build_query([
            'origin_lat' => 0.0000,
            'origin_lng' => 0.0000,
            'destination_lat' => 1.0000,
            'destination_lng' => 1.0000,
            'departure_at' => '2026-11-09T12:00:00Z',
        ]));

        $response->assertOk();
        expect($response->json('data'))->toBeEmpty();
    });

    it('validates input coordinate bounds and walking radius', function (): void {
        $response = $this->getJson('/api/v1/operations/journeys?'.http_build_query([
            'origin_lat' => 120.0, // Invalid latitude (> 90)
            'origin_lng' => -84.0,
            'destination_lat' => 10.0,
            'destination_lng' => -84.0,
            'walking_radius_m' => 5000, // Invalid radius (> 2000)
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['origin_lat', 'walking_radius_m']);
    });

    it('preserves null for estimated arrival and intermediate departure when minutes_from_start is unknown', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $stopOrigin = Stop::factory()->for($company)->create(['latitude' => 9.9333, 'longitude' => -84.0833]);
        $stopDest = Stop::factory()->for($company)->create(['latitude' => 10.0167, 'longitude' => -84.2167]);

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopOrigin->id,
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);
        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopDest->id,
            'stop_sequence' => 2,
            'minutes_from_start' => null, // Unknown arrival time
        ]);

        RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1, // Monday
            'departure_time' => '07:00:00',
            'valid_from' => '2026-01-01',
        ]);

        $response = $this->getJson('/api/v1/operations/journeys?'.http_build_query([
            'origin_lat' => 9.9333,
            'origin_lng' => -84.0833,
            'destination_lat' => 10.0167,
            'destination_lng' => -84.2167,
            'departure_at' => '2026-11-09T13:00:00Z',
        ]));

        $response->assertOk();
        $journey = $response->json('data.0');
        expect($journey['attributes']['scheduled_departure_at'])->not->toBeNull()
            ->and($journey['attributes']['estimated_arrival_at'])->toBeNull();
    });

    it('finds journeys departing after midnight into the next calendar day', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $stopOrigin = Stop::factory()->for($company)->create(['latitude' => 9.9333, 'longitude' => -84.0833]);
        $stopDest = Stop::factory()->for($company)->create(['latitude' => 10.0167, 'longitude' => -84.2167]);

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopOrigin->id,
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);
        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopDest->id,
            'stop_sequence' => 2,
            'minutes_from_start' => 30,
        ]);

        // Tuesday schedule: 00:30 (early morning)
        RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 2, // Tuesday
            'departure_time' => '00:30:00',
            'valid_from' => '2026-01-01',
        ]);

        // Query late Monday night: 2026-11-09 23:30 Costa Rica = 2026-11-10 05:30 UTC
        $response = $this->getJson('/api/v1/operations/journeys?'.http_build_query([
            'origin_lat' => 9.9333,
            'origin_lng' => -84.0833,
            'destination_lat' => 10.0167,
            'destination_lng' => -84.2167,
            'departure_at' => '2026-11-10T05:30:00Z',
        ]));

        $response->assertOk();
        $journeys = $response->json('data');
        expect($journeys)->not->toBeEmpty();
    });

    it('validates pagination parameters returning 422 for invalid values', function (): void {
        $response = $this->getJson('/api/v1/operations/journeys?'.http_build_query([
            'origin_lat' => 9.9333,
            'origin_lng' => -84.0833,
            'destination_lat' => 10.0167,
            'destination_lng' => -84.2167,
            'per_page' => -5,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    });
});
