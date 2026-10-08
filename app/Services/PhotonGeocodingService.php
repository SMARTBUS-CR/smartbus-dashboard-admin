<?php

namespace App\Services;

use App\Traits\ApiLogger;
use App\Traits\HasHttpRequests;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\Address;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\Coordinate;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\GeoSearchResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PhotonGeocodingService
{
    use ApiLogger, HasHttpRequests;

    protected readonly string $baseUrl;

    protected readonly int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) config('services.photon.url'),
            '/',
        );

        $this->timeout = (int) config('services.photon.timeout', 8);
    }

    /**
     * @return list<GeoSearchResult>
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 3) {
            return [];
        }

        $cacheKey = 'photon.search.v2.'.hash(
            'sha256',
            $this->baseUrl.'|'.$query,
        );

        $cachedResults = Cache::remember(
            $cacheKey,
            (int) config('services.photon.cache_ttl', 3600),
            fn (): array => array_map(
                static fn (GeoSearchResult $result): array =>
                    $result->toArray(),
                $this->fetchResults($query),
            ),
        );

        return array_map(
            static fn (array $result): GeoSearchResult =>
                GeoSearchResult::fromArray($result),
            $cachedResults,
        );
    }

    /**
     * @return list<GeoSearchResult>
     */
    private function fetchResults(string $query): array
    {
        try {
            $response = $this->sendHttpRequest(
                method: 'GET',
                endpoint: '/api/',
                queryParams: [
                    'q' => $query,
                    'limit' => 5,
                ],
            );
        } catch (ConnectionException $exception) {
            $this->log('warning', 'Photon geocoding connection failed.');

            throw new RuntimeException(
                __('Location search is temporarily unavailable. Please try again.'),
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            $this->log('warning', 'Photon geocoding request failed.', [
                'status' => $response->status(),
            ]);

            throw new RuntimeException(
                __('Location search is temporarily unavailable. Please try again.'),
            );
        }

        $features = $response->json('features');

        if (! is_array($features)) {
            $this->log('warning', 'Photon returned an invalid response.');

            throw new RuntimeException(
                __('Location search is temporarily unavailable. Please try again.'),
            );
        }

        $results = [];

        foreach (array_slice($features, 0, 5) as $feature) {
            if (! is_array($feature)) {
                continue;
            }

            $geometry = $feature['geometry'] ?? [];
            $properties = $feature['properties'] ?? [];

            if (
                ! is_array($geometry)
                || ! is_array($properties)
                || ($geometry['type'] ?? null) !== 'Point'
            ) {
                continue;
            }

            $coordinates = $geometry['coordinates'] ?? [];

            if (
                ! is_array($coordinates)
                || ! is_numeric($coordinates[0] ?? null)
                || ! is_numeric($coordinates[1] ?? null)
            ) {
                continue;
            }

            $longitude = (float) $coordinates[0];
            $latitude = (float) $coordinates[1];

            if (
                ! is_finite($latitude)
                || ! is_finite($longitude)
                || $latitude < -90
                || $latitude > 90
                || $longitude < -180
                || $longitude > 180
            ) {
                continue;
            }

            $name = (string) (
                $properties['name']
                ?? $properties['street']
                ?? $properties['city']
                ?? $properties['country']
                ?? ''
            );

            if (blank($name)) {
                continue;
            }

            $displayName = collect([
                $name,
                $properties['street'] ?? null,
                $properties['city'] ?? null,
                $properties['state'] ?? null,
                $properties['country'] ?? null,
            ])
                ->filter(fn ($part): bool => is_string($part) && filled($part))
                ->unique()
                ->implode(', ');

            $results[] = new GeoSearchResult(
                coordinate: new Coordinate($latitude, $longitude),
                type: (string) ($properties['osm_value'] ?? ''),
                addresstype: (string) ($properties['osm_key'] ?? ''),
                name: $name,
                displayName: $displayName,
                address: Address::fromArray([
                    ...$properties,
                    'country_code' => $properties['countrycode'] ?? null,
                    'house_number' => $properties['housenumber'] ?? null,
                ]),
                boundingbox: [],
            );
        }

        return $results;
    }
}
