<?php

use App\Services\OsrmRoutingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

describe('OSRM Routing Service', function (): void {
    beforeEach(function (): void {
        config(['services.osrm.url' => 'https://osrm.test']);

        Http::preventStrayRequests();
    });

    test('calculates a road route through the stops in their configured order', function (): void {
        $geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [-84.0123456, 10.4523456],
                [-84.0150000, 10.4550000],
                [-84.0223456, 10.4623456],
            ],
        ];

        Http::fake([
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [
                    [
                        'distance' => 1200.5,
                        'duration' => 180.0,
                        'geometry' => $geometry,
                        'legs' => [
                            [
                                'distance' => 500.0,
                                'duration' => 75.0,
                            ],
                            [
                                'distance' => 700.5,
                                'duration' => 105.0,
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(OsrmRoutingService::class)->calculate([
            ['lat' => 10.4523456, 'lng' => -84.0123456],
            ['lat' => 10.4550000, 'lng' => -84.0150000],
            ['lat' => 10.4623456, 'lng' => -84.0223456],
        ]);

        expect($result['distance_meters'])->toBe(1200.5)
            ->and($result['duration_seconds'])->toBe(180.0)
            ->and($result['geometry'])->toBe($geometry)
            ->and($result['leg_distances_meters'])->toBe([
                500.0,
                700.5,
            ]);

        Http::assertSent(function ($request): bool {
            parse_str(
                (string) parse_url($request->url(), PHP_URL_QUERY),
                $query,
            );

            $path = rawurldecode(
                (string) parse_url($request->url(), PHP_URL_PATH),
            );

            return $path === '/route/v1/driving/'
                .'-84.0123456,10.4523456;'
                .'-84.0150000,10.4550000;'
                .'-84.0223456,10.4623456'
                && ($query['geometries'] ?? null) === 'geojson'
                && ($query['overview'] ?? null) === 'full'
                && $request->hasHeader('User-Agent');
        });

        Http::assertSentCount(1);
    });

    test('requires at least two stop locations without contacting the provider', function (): void {
        Http::fake();

        expect(
            fn () => app(OsrmRoutingService::class)->calculate([
                ['lat' => 10.4523456, 'lng' => -84.0123456],
            ]),
        )->toThrow(ValidationException::class);

        Http::assertNothingSent();
    });

    test('reuses a cached route for the same ordered locations', function (): void {
        Http::fake([
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [
                    [
                        'distance' => 1200.5,
                        'duration' => 180.0,
                        'geometry' => [
                            'type' => 'LineString',
                            'coordinates' => [
                                [-84.0123456, 10.4523456],
                                [-84.0223456, 10.4623456],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $points = [
            ['lat' => 10.4523456, 'lng' => -84.0123456],
            ['lat' => 10.4623456, 'lng' => -84.0223456],
        ];

        $service = app(OsrmRoutingService::class);

        $firstResult = $service->calculate($points);
        $secondResult = $service->calculate($points);

        expect($secondResult)->toBe($firstResult);

        Http::assertSentCount(1);
    });

    test('reports when the provider cannot find a road route', function (): void {
        Http::fake([
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'NoRoute',
                'routes' => [],
            ]),
        ]);

        expect(
            fn () => app(OsrmRoutingService::class)->calculate([
                ['lat' => 10.4523456, 'lng' => -84.0123456],
                ['lat' => 10.4623456, 'lng' => -84.0223456],
            ]),
        )->toThrow(
            RuntimeException::class,
            __('No road route was found between the selected stops.'),
        );
    });

    test('rejects invalid coordinates without contacting the provider', function (array $invalidPoint): void {
        Http::fake();

        expect(
            fn () => app(OsrmRoutingService::class)->calculate([
                ['lat' => 10.4523456, 'lng' => -84.0123456],
                $invalidPoint,
            ]),
        )->toThrow(ValidationException::class);

        Http::assertNothingSent();
    })->with([
        'missing latitude' => [
            ['lng' => -84.0223456],
        ],
        'missing longitude' => [
            ['lat' => 10.4623456],
        ],
        'non-numeric latitude' => [
            ['lat' => 'invalid', 'lng' => -84.0223456],
        ],
        'latitude outside its range' => [
            ['lat' => 90.1, 'lng' => -84.0223456],
        ],
        'longitude outside its range' => [
            ['lat' => 10.4623456, 'lng' => -180.1],
        ],
    ]);

    test('reports provider errors without returning a calculated route', function (int $status): void {
        Http::fake([
            'https://osrm.test/route/v1/driving/*' => Http::response([], $status),
        ]);

        expect(
            fn () => app(OsrmRoutingService::class)->calculate([
                ['lat' => 10.4523456, 'lng' => -84.0123456],
                ['lat' => 10.4623456, 'lng' => -84.0223456],
            ]),
        )->toThrow(
            RuntimeException::class,
            __('Route calculation is temporarily unavailable. Please try again.'),
        );
    })->with([
        'server failure' => [500],
        'provider rate limit' => [429],
    ]);

    test('limits uncached requests to one per second', function (): void {
        $this->freezeTime();

        Http::fake([
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [
                    [
                        'distance' => 1200.5,
                        'duration' => 180.0,
                        'geometry' => [
                            'type' => 'LineString',
                            'coordinates' => [
                                [-84.0123456, 10.4523456],
                                [-84.0223456, 10.4623456],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $service = app(OsrmRoutingService::class);

        $service->calculate([
            ['lat' => 10.4523456, 'lng' => -84.0123456],
            ['lat' => 10.4623456, 'lng' => -84.0223456],
        ]);

        expect(
            fn () => $service->calculate([
                ['lat' => 10.4523456, 'lng' => -84.0123456],
                ['lat' => 10.4723456, 'lng' => -84.0323456],
            ]),
        )->toThrow(
            RuntimeException::class,
            __('Route calculation is busy. Please try again in a moment.'),
        );

        Http::assertSentCount(1);
    });

    test('retrieves a cached calculation only for the same ordered locations without another request', function (): void {
        Http::fake([
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [
                    [
                        'distance' => 1200.5,
                        'duration' => 180.0,
                        'geometry' => [
                            'type' => 'LineString',
                            'coordinates' => [
                                [-84.0123456, 10.4523456],
                                [-84.0223456, 10.4623456],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $points = [
            ['lat' => 10.4523456, 'lng' => -84.0123456],
            ['lat' => 10.4623456, 'lng' => -84.0223456],
        ];

        $service = app(OsrmRoutingService::class);
        $calculatedRoute = $service->calculate($points);

        expect($service->getCachedRoute($points))
            ->toBe($calculatedRoute)
            ->and($service->getCachedRoute(array_reverse($points)))
            ->toBeNull();

        Http::assertSentCount(1);
    });

    test('returns no cached calculation without contacting the provider', function (): void {
        Http::fake();

        $result = app(OsrmRoutingService::class)->getCachedRoute([
            ['lat' => 10.4523456, 'lng' => -84.0123456],
            ['lat' => 10.4623456, 'lng' => -84.0223456],
        ]);

        expect($result)->toBeNull();

        Http::assertNothingSent();
    });
});
