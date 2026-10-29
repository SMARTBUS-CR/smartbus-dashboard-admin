<?php

namespace App\Services;

use App\Traits\ApiLogger;
use App\Traits\HasHttpRequests;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class OsrmRoutingService
{
    use ApiLogger;
    use HasHttpRequests {
        buildHttpClient as protected buildBaseHttpClient;
    }

    protected readonly string $baseUrl;

    protected readonly int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) config('services.osrm.url'),
            '/',
        );

        $this->timeout = (int) config('services.osrm.timeout', 10);
    }

    /**
     * @param  list<array{lat: float|int|string, lng: float|int|string}>  $points
     * @return array{distance_meters: float, duration_seconds: float, geometry: array, leg_distances_meters: list<float>}
     */
    public function calculate(array $points): array
    {
        $coordinates = $this->serializePoints($points);

        return Cache::remember(
            $this->routeCacheKey($coordinates),
            (int) config('services.osrm.cache_ttl', 3600),
            fn (): array => $this->fetchRoute($coordinates),
        );
    }

    /**
     * @param  list<array{lat: float|int|string, lng: float|int|string}>  $points
     * @return array{distance_meters: float, duration_seconds: float, geometry: array, leg_distances_meters: list<float>}|null
     */
    public function getCachedRoute(array $points): ?array
    {
        $coordinates = $this->serializePoints($points);

        return Cache::get($this->routeCacheKey($coordinates));
    }

    protected function buildHttpClient(?string $token = null): PendingRequest
    {
        return $this->buildBaseHttpClient($token)
            ->withHeaders([
                'User-Agent' => (string) config('services.osrm.user_agent'),
            ]);
    }

    private function reserveRequest(): void
    {
        $rateKey = 'osrm.requests.'.hash('sha256', $this->baseUrl);

        $reserved = Cache::lock($rateKey.'.lock', 2)
            ->get(function () use ($rateKey): bool {
                if (RateLimiter::tooManyAttempts($rateKey, 1)) {
                    return false;
                }

                RateLimiter::hit($rateKey, 1);

                return true;
            });

        if (! $reserved) {
            throw new RuntimeException(
                __('Route calculation is busy. Please try again in a moment.'),
            );
        }
    }

    /**
     * Fetches a route from the OSRM API.
     *
     * @return array{
     *     distance_meters: float,
     *     duration_seconds: float,
     *     geometry: array,
     *     leg_distances_meters: list<float>
     * }
     */
    private function fetchRoute(string $coordinates): array
    {
        $this->reserveRequest();

        try {
            $response = $this->sendHttpRequest(
                method: 'GET',
                endpoint: '/route/v1/driving/'.$coordinates,
                queryParams: [
                    'geometries' => 'geojson',
                    'overview' => 'full',
                    'alternatives' => 'false',
                    'steps' => 'false',
                ],
            );
        } catch (ConnectionException $exception) {
            $this->log('warning', 'OSRM routing connection failed.');

            throw new RuntimeException(
                __('Route calculation is temporarily unavailable. Please try again.'),
                previous: $exception,
            );
        }

        if ($response->json('code') === 'NoRoute') {
            throw new RuntimeException(
                __('No road route was found between the selected stops.'),
            );
        }

        if (
            ! $response->successful()
            || $response->json('code') !== 'Ok'
        ) {
            $this->log('warning', 'OSRM routing request failed.', [
                'status' => $response->status(),
            ]);

            throw new RuntimeException(
                __('Route calculation is temporarily unavailable. Please try again.'),
            );
        }

        $route = $response->json('routes.0');

        if (! is_array($route)) {
            throw new RuntimeException(
                __('Route calculation is temporarily unavailable. Please try again.'),
            );
        }

        $validator = Validator::make(['route' => $route], [
            'route.distance' => ['required', 'numeric', 'min:0'],
            'route.duration' => ['required', 'numeric', 'min:0'],
            'route.geometry.type' => ['required', 'in:LineString'],
            'route.geometry.coordinates' => ['required', 'array', 'list', 'min:2'],
            'route.geometry.coordinates.*' => ['required', 'array', 'list', 'size:2'],
            'route.geometry.coordinates.*.0' => ['required', 'numeric', 'between:-180,180'],
            'route.geometry.coordinates.*.1' => ['required', 'numeric', 'between:-90,90'],
            'route.legs' => ['sometimes', 'array', 'list'],
            'route.legs.*' => ['required', 'array'],
            'route.legs.*.distance' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            $this->log('warning', 'OSRM returned an invalid route.');

            throw new RuntimeException(
                __('Route calculation is temporarily unavailable. Please try again.'),
            );
        }

        return [
            'distance_meters' => (float) $route['distance'],
            'duration_seconds' => (float) $route['duration'],
            'geometry' => $route['geometry'],
            'leg_distances_meters' => array_map(
                static fn (array $leg): float => (float) $leg['distance'],
                $route['legs'] ?? [],
            ),
        ];
    }

    /**
     * @param  list<array{lat: float|int|string, lng: float|int|string}>  $points
     */
    private function serializePoints(array $points): string
    {
        Validator::make(['points' => $points], [
            'points' => ['required', 'array', 'list', 'min:2', 'max:100'],
            'points.*' => ['required', 'array'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ])->validate();

        return implode(';', array_map(
            static fn (array $point): string => sprintf(
                '%.7F,%.7F',
                (float) $point['lng'],
                (float) $point['lat'],
            ),
            $points,
        ));
    }

    private function routeCacheKey(string $coordinates): string
    {
        return 'osrm.route.v2.'.hash(
            'sha256',
            $this->baseUrl.'|'.$coordinates,
        );
    }
}
