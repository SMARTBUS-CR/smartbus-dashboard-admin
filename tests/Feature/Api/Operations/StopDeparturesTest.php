<?php

use App\Enums\CompanyStatus;
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

    it('captures departures crossing midnight into next day and from previous day late service', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create(['headsign' => 'Nocturno']);
        $stop = Stop::factory()->for($company)->create(['name' => 'Parada Nocturna']);

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stop->id,
            'stop_sequence' => 2,
            'minutes_from_start' => 30, // 30 mins after departure
        ]);

        // Trip 1: Dispatched Monday 23:45 -> arrives at stop at Tuesday 00:15
        RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1, // Monday
            'departure_time' => '23:45:00',
            'valid_from' => '2026-01-01',
        ]);

        // Trip 2: Dispatched Tuesday 00:30 -> arrives at stop at Tuesday 01:00
        RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 2, // Tuesday
            'departure_time' => '00:30:00',
            'valid_from' => '2026-01-01',
        ]);

        // Query Monday 23:30 Costa Rica (2026-11-10 05:30 UTC), window 120 minutes (until 01:30 Tuesday)
        $response = $this->getJson("/api/v1/operations/stops/{$stop->id}/departures?".http_build_query([
            'departure_at' => '2026-11-10T05:30:00Z',
            'window_minutes' => 120,
        ]));

        $response->assertOk();
        $departures = $response->json('data');
        expect(count($departures))->toBe(2);
    });

    it('paginates stop departures and validates pagination input with 422', function (): void {
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

        for ($i = 0; $i < 5; $i++) {
            RouteSchedule::factory()->create([
                'route_pattern_id' => $pattern->id,
                'day_of_week' => 1,
                'departure_time' => sprintf('%02d:00:00', $i + 7),
                'valid_from' => '2026-01-01',
            ]);
        }

        // Test pagination page[size]=2
        $response = $this->getJson("/api/v1/operations/stops/{$stop->id}/departures?".http_build_query([
            'departure_at' => '2026-11-09T12:00:00Z',
            'window_minutes' => 300,
            'page' => ['size' => 2, 'number' => 1],
        ]));

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
            ]);

        expect(count($response->json('data')))->toBe(2)
            ->and($response->json('meta.total'))->toBe(5);

        // Validation rejects negative or invalid pagination with 422
        $this->getJson("/api/v1/operations/stops/{$stop->id}/departures?per_page=-1")->assertStatus(422);
        $this->getJson("/api/v1/operations/stops/{$stop->id}/departures?per_page=0")->assertStatus(422);
        $this->getJson("/api/v1/operations/stops/{$stop->id}/departures?per_page=500")->assertStatus(422);
    });

    it('hides departures of inactive companies at shared stops', function (): void {
        $company = createCompany(['timezone' => 'UTC']);
        $pattern = RoutePattern::factory()->for(Route::factory()->for($company))->create();
        $stop = Stop::factory()->create(['company_id' => null]);
        $companyStop = Stop::factory()->for($company)->create();
        RoutePatternStop::factory()->create(['route_pattern_id' => $pattern->id, 'stop_id' => $stop->id, 'stop_sequence' => 1, 'minutes_from_start' => 0]);
        RouteSchedule::factory()->create(['route_pattern_id' => $pattern->id, 'day_of_week' => 1, 'departure_time' => '10:00:00']);
        $url = "/api/v1/operations/stops/{$stop->id}/departures?departure_at=2026-11-09T09:00:00Z";

        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $company->update(['status' => CompanyStatus::INACTIVE]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/operations/stops/{$companyStop->id}/departures")->assertNotFound();
    });

    it('uses each route local date for departures near the date line', function (): void {
        $stop = Stop::factory()->create(['company_id' => null]);
        foreach (['Pacific/Pago_Pago', 'Pacific/Kiritimati'] as $timezone) {
            $company = createCompany(['timezone' => $timezone]);
            $pattern = RoutePattern::factory()->for(Route::factory()->for($company))->create();
            RoutePatternStop::factory()->create(['route_pattern_id' => $pattern->id, 'stop_id' => $stop->id, 'stop_sequence' => 1, 'minutes_from_start' => 0]);
            RouteSchedule::factory()->create(['route_pattern_id' => $pattern->id, 'day_of_week' => $timezone === 'Pacific/Pago_Pago' ? 0 : 2, 'departure_time' => $timezone === 'Pacific/Pago_Pago' ? '23:30:00' : '01:00:00']);
        }

        $response = $this->getJson("/api/v1/operations/stops/{$stop->id}/departures?".http_build_query(['departure_at' => '2026-11-09T10:00:00Z', 'window_minutes' => 120]));
        $response->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.attributes.scheduled_stop_departure_at', '2026-11-08T23:30:00-11:00')
            ->assertJsonPath('data.1.attributes.scheduled_stop_departure_at', '2026-11-10T01:00:00+14:00');
    });
});
