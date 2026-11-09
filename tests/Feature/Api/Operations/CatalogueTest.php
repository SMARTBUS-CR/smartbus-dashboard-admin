<?php

use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\Stop;

describe('Route Catalogue Endpoints', function (): void {
    it('lists active routes in json:api format with pagination and filtering', function (): void {
        $companyA = createCompany();
        $companyB = createCompany();

        $routeA1 = Route::factory()->for($companyA)->create(['code' => 'R-101', 'name' => 'San Jose - Alajuela']);
        $routeA2 = Route::factory()->for($companyA)->create(['code' => 'R-102', 'name' => 'San Jose - Heredia']);
        $routeB1 = Route::factory()->for($companyB)->create(['code' => 'R-201', 'name' => 'Cartago - Turrialba']);
        $archivedRoute = Route::factory()->for($companyA)->create(['code' => 'R-999', 'name' => 'Archived Route', 'deleted_at' => now()]);

        // 1. Get all routes without filter
        $response = $this->getJson('/api/v1/operations/routes');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'type',
                        'id',
                        'attributes' => [
                            'code',
                            'name',
                            'company_id',
                        ],
                        'relationships' => [
                            'company' => [
                                'data' => [
                                    'type',
                                    'id',
                                ],
                            ],
                        ],
                    ],
                ],
                'links',
                'meta',
            ]);

        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toContain($routeA1->id, $routeA2->id, $routeB1->id)
            ->and($ids)->not->toContain($archivedRoute->id);

        // 2. Filter by company_id
        $filteredResponse = $this->getJson('/api/v1/operations/routes?filter[company_id]='.$companyA->id);
        $filteredResponse->assertOk();
        $filteredIds = collect($filteredResponse->json('data'))->pluck('id')->all();
        expect($filteredIds)->toContain($routeA1->id, $routeA2->id)
            ->and($filteredIds)->not->toContain($routeB1->id);

        // 3. Filter by search / code
        $codeFilteredResponse = $this->getJson('/api/v1/operations/routes?filter[code]=R-101');
        $codeFilteredResponse->assertOk();
        $codeIds = collect($codeFilteredResponse->json('data'))->pluck('id')->all();
        expect($codeIds)->toBe([$routeA1->id]);
    });

    it('retrieves a single route detail with company relationship', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create(['code' => 'R-400', 'name' => 'Sarapiqui - Puerto Viejo']);

        $response = $this->getJson('/api/v1/operations/routes/'.$route->id);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJson([
                'data' => [
                    'type' => 'routes',
                    'id' => $route->id,
                    'attributes' => [
                        'code' => 'R-400',
                        'name' => 'Sarapiqui - Puerto Viejo',
                    ],
                ],
            ]);

        // Non-existent route returns 404
        $notFound = $this->getJson('/api/v1/operations/routes/0192e2b3-0000-7000-8000-000000000000');
        $notFound->assertNotFound();
    });

    it('lists patterns belonging to a route', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        RoutePattern::factory()->for($route)->create(['code' => 'PATTERN-1', 'name' => 'Recorrido 1']);
        RoutePattern::factory()->for($route)->create(['code' => 'PATTERN-2', 'name' => 'Recorrido 2']);

        $response = $this->getJson("/api/v1/operations/routes/{$route->id}/patterns");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'type',
                        'id',
                        'attributes' => [
                            'code',
                            'name',
                        ],
                    ],
                ],
            ]);

        $codes = collect($response->json('data'))->pluck('attributes.code')->all();
        expect($codes)->toContain('PATTERN-1', 'PATTERN-2');
    });

    it('validates pagination parameters and returns 422 for negative or invalid limits', function (): void {
        $response = $this->getJson('/api/v1/operations/routes?page[size]=-5');
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['page.size']);

        $stopResponse = $this->getJson('/api/v1/operations/stops?page[size]=500');
        $stopResponse->assertStatus(422)
            ->assertJsonValidationErrors(['page.size']);
    });
});

describe('Route Pattern Details and Stop Occurrences', function (): void {
    it('returns pattern details with ordered stop sequences and geometry', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $stop1 = Stop::factory()->for($company)->create(['name' => 'Terminal San Jose', 'latitude' => 9.9333, 'longitude' => -84.0833]);
        $stop2 = Stop::factory()->for($company)->create(['name' => 'Parada UCR', 'latitude' => 9.9360, 'longitude' => -84.0510]);
        $stop3 = Stop::factory()->for($company)->create(['name' => 'Terminal Heredia', 'latitude' => 9.9980, 'longitude' => -84.1170]);

        $pattern = RoutePattern::factory()->for($route)->create([
            'code' => 'OUTBOUND',
            'name' => 'Salida Principal',
            'headsign' => 'Heredia Centro',
        ]);

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stop1->id,
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);
        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stop2->id,
            'stop_sequence' => 2,
            'minutes_from_start' => 15,
        ]);
        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stop3->id,
            'stop_sequence' => 3,
            'minutes_from_start' => 35,
        ]);

        $response = $this->getJson("/api/v1/operations/route-patterns/{$pattern->id}");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonStructure([
                'data' => [
                    'type',
                    'id',
                    'attributes' => [
                        'code',
                        'name',
                        'headsign',
                        'stops' => [
                            '*' => [
                                'stop_id',
                                'stop_name',
                                'stop_sequence',
                                'minutes_from_start',
                                'latitude',
                                'longitude',
                            ],
                        ],
                    ],
                ],
            ]);

        $stops = $response->json('data.attributes.stops');
        expect(count($stops))->toBe(3)
            ->and($stops[0]['stop_name'])->toBe('Terminal San Jose')
            ->and($stops[0]['stop_sequence'])->toBe(1)
            ->and($stops[1]['stop_name'])->toBe('Parada UCR')
            ->and($stops[1]['stop_sequence'])->toBe(2)
            ->and($stops[2]['stop_name'])->toBe('Terminal Heredia')
            ->and($stops[2]['stop_sequence'])->toBe(3);
    });

    it('lists weekly scheduled departures for a route pattern', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $schedule1 = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1, // Monday
            'departure_time' => '07:30:00',
            'valid_from' => '2026-01-01',
        ]);
        $schedule2 = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1,
            'departure_time' => '08:00:00',
            'valid_from' => '2026-01-01',
        ]);

        $response = $this->getJson("/api/v1/operations/route-patterns/{$pattern->id}/schedules");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'type',
                        'id',
                        'attributes' => [
                            'day_of_week',
                            'departure_time',
                            'valid_from',
                        ],
                    ],
                ],
            ]);

        $times = collect($response->json('data'))->pluck('attributes.departure_time')->all();
        expect($times)->toContain('07:30:00', '08:00:00');
    });

    it('filters pattern schedules by specific service date respecting company timezone and exceptions', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        // Monday schedule (day_of_week = 1)
        $scheduleMon = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1,
            'departure_time' => '07:00:00',
            'valid_from' => '2026-01-01',
        ]);
        // Tuesday schedule (day_of_week = 2)
        $scheduleTue = RouteSchedule::factory()->create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 2,
            'departure_time' => '08:00:00',
            'valid_from' => '2026-01-01',
        ]);

        // Monday 2026-11-09
        $responseMon = $this->getJson("/api/v1/operations/route-patterns/{$pattern->id}/schedules?date=2026-11-09");
        $responseMon->assertOk();
        $monTimes = collect($responseMon->json('data'))->pluck('attributes.departure_time')->all();
        expect($monTimes)->toBe(['07:00:00']);

        // Tuesday 2026-11-10
        $responseTue = $this->getJson("/api/v1/operations/route-patterns/{$pattern->id}/schedules?date=2026-11-10");
        $responseTue->assertOk();
        $tueTimes = collect($responseTue->json('data'))->pluck('attributes.departure_time')->all();
        expect($tueTimes)->toBe(['08:00:00']);
    });
});

describe('Stop Catalogue Retrieval', function (): void {
    it('lists geographic bus stops and handles shared municipal stops', function (): void {
        $company = createCompany();
        $privateStop = Stop::factory()->for($company)->create([
            'name' => 'Parada Empresa A',
            'latitude' => 10.1234,
            'longitude' => -84.1234,
        ]);
        $sharedStop = Stop::factory()->create([
            'company_id' => null, // Public shared stop
            'name' => 'Parada Municipal Central',
            'latitude' => 10.5000,
            'longitude' => -84.5000,
        ]);

        $response = $this->getJson('/api/v1/operations/stops');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'type',
                        'id',
                        'attributes' => [
                            'name',
                            'latitude',
                            'longitude',
                            'company_id',
                        ],
                    ],
                ],
            ]);

        $names = collect($response->json('data'))->pluck('attributes.name')->all();
        expect($names)->toContain('Parada Empresa A', 'Parada Municipal Central');
    });

    it('retrieves single bus stop detail', function (): void {
        $company = createCompany();
        $stop = Stop::factory()->for($company)->create([
            'name' => 'Estacion Central',
            'latitude' => 10.0001,
            'longitude' => -84.0001,
        ]);

        $response = $this->getJson('/api/v1/operations/stops/'.$stop->id);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJson([
                'data' => [
                    'type' => 'stops',
                    'id' => $stop->id,
                    'attributes' => [
                        'name' => 'Estacion Central',
                    ],
                ],
            ]);
    });
});

describe('Route Fare Retrieval', function (): void {
    it('lists effective route fares within valid date ranges', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $fare1 = RouteFare::factory()->for($route)->create([
            'amount' => 550.00,
            'currency' => 'CRC',
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-06-30',
        ]);
        $fare2 = RouteFare::factory()->for($route)->create([
            'amount' => 600.00,
            'currency' => 'CRC',
            'valid_from' => '2026-07-01',
            'valid_until' => '2026-12-31',
        ]);

        $response = $this->getJson("/api/v1/operations/routes/{$route->id}/fares");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'type',
                        'id',
                        'attributes' => [
                            'amount',
                            'currency',
                            'valid_from',
                        ],
                    ],
                ],
            ]);

        $amounts = collect($response->json('data'))->pluck('attributes.amount')->all();
        expect($amounts)->toContain('550.00', '600.00');

        // Filter by date during first fare period
        $resEarly = $this->getJson("/api/v1/operations/routes/{$route->id}/fares?date=2026-03-15");
        $resEarly->assertOk();
        $earlyAmounts = collect($resEarly->json('data'))->pluck('attributes.amount')->all();
        expect($earlyAmounts)->toBe(['550.00']);

        // Filter by date during second fare period
        $resLate = $this->getJson("/api/v1/operations/routes/{$route->id}/fares?date=2026-08-15");
        $resLate->assertOk();
        $lateAmounts = collect($resLate->json('data'))->pluck('attributes.amount')->all();
        expect($lateAmounts)->toBe(['600.00']);
    });
});

describe('Catalogue Internationalization and Errors', function (): void {
    it('returns bilingual 404 errors using accept-language header', function (): void {
        // 1. English
        $responseEn = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/operations/routes/0192e2b3-0000-7000-8000-000000000000');

        $responseEn->assertNotFound();
        expect($responseEn->json('message'))->toBe('The requested route does not exist or has been archived.');

        // 2. Spanish
        $responseEs = $this->withHeader('Accept-Language', 'es')
            ->getJson('/api/v1/operations/routes/0192e2b3-0000-7000-8000-000000000000');

        $responseEs->assertNotFound();
        expect($responseEs->json('message'))->toBe('La ruta solicitada no existe o ha sido archivada.');
    });
});
