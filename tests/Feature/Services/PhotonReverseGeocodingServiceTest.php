<?php

use App\Services\PhotonGeocodingService;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\GeoSearchResult;
use Illuminate\Support\Facades\Http;

describe('Photon Reverse Geocoding', function (): void {
    beforeEach(function (): void {
        config([
            'services.photon.url' => 'https://photon.test',
        ]);

        Http::preventStrayRequests();
    });

    test('describes a selected point without replacing its coordinates', function (): void {
        Http::fake([
            'https://photon.test/reverse*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'Point',
                            'coordinates' => [-84.0123, 10.4523],
                        ],
                        'properties' => [
                            'name' => 'Central Terminal',
                            'country' => 'Costa Rica',
                            'countrycode' => 'CR',
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(PhotonGeocodingService::class)->reverse(
            10.4523456,
            -84.0123456,
        );

        expect($result)->toBeInstanceOf(GeoSearchResult::class)
            ->and($result->name)->toBe('Central Terminal')
            ->and($result->coordinate->lat)->toBe(10.4523456)
            ->and($result->coordinate->lng)->toBe(-84.0123456);

        Http::assertSent(function ($request): bool {
            parse_str(
                (string) parse_url($request->url(), PHP_URL_QUERY),
                $query,
            );

            return parse_url($request->url(), PHP_URL_PATH) === '/reverse'
                && (float) ($query['lat'] ?? 0) === 10.4523456
                && (float) ($query['lon'] ?? 0) === -84.0123456;
        });
    });

    test('returns no description when no location is found', function (): void {
        Http::fake([
            'https://photon.test/reverse*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [],
            ]),
        ]);

        expect(
            app(PhotonGeocodingService::class)->reverse(
                10.4523456,
                -84.0123456,
            ),
        )->toBeNull();
    });

    test('caches empty results to avoid repeated requests', function (): void {
        Http::fake([
            'https://photon.test/reverse*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [],
            ]),
        ]);

        $service = app(PhotonGeocodingService::class);

        expect($service->reverse(10.4523456, -84.0123456))->toBeNull()
            ->and($service->reverse(10.4523456, -84.0123456))->toBeNull();

        Http::assertSentCount(1);
    });

    test('reports provider failures without caching them as empty results', function (): void {
        Http::fake([
            'https://photon.test/reverse*' => Http::response([], 503),
        ]);

        $service = app(PhotonGeocodingService::class);

        expect(fn () => $service->reverse(10.4523456, -84.0123456))
            ->toThrow(RuntimeException::class)
            ->and(fn () => $service->reverse(10.4523456, -84.0123456))->toThrow(RuntimeException::class);

        Http::assertSentCount(2);
    });

    test('rejects invalid coordinates without contacting the provider', function (float $latitude, float $longitude): void {
        Http::fake();

        expect(
            fn () => app(PhotonGeocodingService::class)->reverse(
                $latitude,
                $longitude,
            ),
        )->toThrow(InvalidArgumentException::class);

        Http::assertNothingSent();
    })->with([
        'latitude outside its range' => [91.0, -84.0],
        'longitude outside its range' => [10.0, -181.0],
        'non-finite latitude' => [INF, -84.0],
        'non-finite longitude' => [10.0, NAN],
    ]);
});
