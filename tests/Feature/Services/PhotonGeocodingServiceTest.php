<?php

use App\Services\PhotonGeocodingService;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\GeoSearchResult;
use Illuminate\Support\Facades\Http;

describe('Photon Geocoding Service', function (): void {
    beforeEach(function (): void {
        config(['services.photon.url' => 'https://photon.test']);

        Http::preventStrayRequests();
    });

    test('converts a search result into a location with correctly ordered coordinates', function (): void {
        Http::fake([
            'https://photon.test/api/*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'Point',
                            'coordinates' => [-84.0123456, 10.4523456],
                        ],
                        'properties' => [
                            'name' => 'Central Terminal',
                            'city' => 'Sarapiquí',
                            'country' => 'Costa Rica',
                            'countrycode' => 'CR',
                            'osm_key' => 'amenity',
                            'osm_value' => 'bus_station',
                        ],
                    ],
                ],
            ]),
        ]);

        $results = app(PhotonGeocodingService::class)
            ->search('Central Terminal');

        expect($results)->toHaveCount(1)
            ->and($results[0])->toBeInstanceOf(GeoSearchResult::class)
            ->and($results[0]->name)->toBe('Central Terminal')
            ->and($results[0]->coordinate->lat)->toBe(10.4523456)
            ->and($results[0]->coordinate->lng)->toBe(-84.0123456)
            ->and($results[0]->displayName)->toContain(
                'Central Terminal',
                'Sarapiquí',
                'Costa Rica',
            );

        Http::assertSent(function ($request): bool {
            parse_str(
                (string) parse_url($request->url(), PHP_URL_QUERY),
                $query,
            );

            return parse_url($request->url(), PHP_URL_PATH) === '/api/'
                && ($query['q'] ?? null) === 'Central Terminal'
                && (int) ($query['limit'] ?? 0) === 5;
        });

        Http::assertSentCount(1);
    });

    test('does not request results for a query shorter than three characters', function (): void {
        Http::fake();

        $results = app(PhotonGeocodingService::class)
            ->search('  ab  ');

        expect($results)->toBeEmpty();

        Http::assertNothingSent();
    });

    test('reuses cached results for the same search', function (): void {
        Http::fake([
            'https://photon.test/api/*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'Point',
                            'coordinates' => [-84.0123456, 10.4523456],
                        ],
                        'properties' => [
                            'name' => 'Central Terminal',
                            'city' => 'Sarapiquí',
                            'country' => 'Costa Rica',
                            'countrycode' => 'CR',
                        ],
                    ],
                ],
            ]),
        ]);

        $service = app(PhotonGeocodingService::class);

        $firstResults = $service->search('Central Terminal');
        $secondResults = $service->search('Central Terminal');

        expect($firstResults)->toHaveCount(1)
            ->and($secondResults)->toHaveCount(1)
            ->and($secondResults[0]->coordinate->toArray())
            ->toBe($firstResults[0]->coordinate->toArray());

        Http::assertSentCount(1);
    });

    test('reports a provider failure instead of treating it as an empty search', function (): void {
        Http::fake([
            'https://photon.test/api/*' => Http::response([], 429),
        ]);

        expect(
            fn () => app(PhotonGeocodingService::class)
                ->search('Central Terminal'),
        )->toThrow(
            RuntimeException::class,
            __('Location search is temporarily unavailable. Please try again.'),
        );
    });

    test('restores cached search results when PHP class deserialization is disabled', function (): void {
        config([
            'cache.default' => 'photon-serialized-test',
            'cache.serializable_classes' => false,
            'cache.stores.photon-serialized-test' => [
                'driver' => 'array',
                'serialize' => true,
            ],
        ]);

        Http::fake([
            'https://photon.test/api/*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'Point',
                            'coordinates' => [-84.0123456, 10.4523456],
                        ],
                        'properties' => [
                            'name' => 'Parada',
                            'country' => 'Costa Rica',
                            'countrycode' => 'CR',
                        ],
                    ],
                ],
            ]),
        ]);

        $service = app(PhotonGeocodingService::class);

        $firstResults = $service->search('Parada');

        expect($firstResults)->toHaveCount(1)
            ->and($firstResults[0])->toBeInstanceOf(GeoSearchResult::class)
            ->and($service->search(''))->toBeEmpty();

        $cachedResults = $service->search('Parada');

        expect($cachedResults)->toHaveCount(1)
            ->and($cachedResults[0])->toBeInstanceOf(GeoSearchResult::class)
            ->and($cachedResults[0]->name)->toBe('Parada')
            ->and($cachedResults[0]->coordinate->lat)->toBe(10.4523456)
            ->and($cachedResults[0]->coordinate->lng)->toBe(-84.0123456);

        Http::assertSentCount(1);
    });
});
