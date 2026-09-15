<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

use function ord;
use function strlen;

class RoutingService
{
    /**
     * The Mapbox API token used for routing requests.
     */
    protected string $token;

    public function __construct()
    {
        $this->token = config('services.mapbox.token');
    }

    /**
     * Generates an encoded polyline connecting origin, waypoints, and destination via real streets.
     *
     * @param  array|object  $origin  The starting point with 'lat' and 'lng'.
     * @param  array|object  $destination  The ending point with 'lat' and 'lng'.
     * @param  array<int, array|object>  $waypoints  Optional intermediate points.
     * @return array{polyline: string, distance_meters: float, duration_seconds: float}|null Returns routing data or null on failure.
     */
    public function calculateRoute(array|object $origin, array|object $destination, array $waypoints = []): ?array
    {
        $originArr = (array) $origin;
        $destArr = (array) $destination;

        if (empty($originArr['lat']) || empty($destArr['lat'])) {
            return null;
        }

        $coordinates = [
            "{$originArr['lng']},{$originArr['lat']}",
        ];

        foreach ($waypoints as $wp) {
            $wpArr = (array) $wp;
            if (! empty($wpArr['lat']) && ! empty($wpArr['lng'])) {
                $coordinates[] = "{$wpArr['lng']},{$wpArr['lat']}";
            }
        }

        $coordinates[] = "{$destArr['lng']},{$destArr['lat']}";
        $coordString = implode(';', $coordinates);

        $response = Http::get("https://api.mapbox.com/directions/v5/mapbox/driving/{$coordString}", [
            'geometries' => 'polyline',
            'overview' => 'full',
            'access_token' => $this->token,
        ]);

        if ($response->failed() || empty($response->json('routes.0'))) {
            \Log::error('Error Mapbox Directions API: '.$response->body());

            return null;
        }

        $route = $response->json('routes.0');

        return [
            'polyline' => $route['geometry'],
            'distance_meters' => (float) $route['distance'],
            'duration_seconds' => (float) $route['duration'],
        ];
    }

    /**
     * Decodes a Google Encoded Polyline string into an array of [lat, lng] points.
     * Standard polyline algorithm (precision 5, matching OSRM's default 'geometries=polyline').
     *
     * @return array<int, array{0: float, 1: float}>
     */
    public function decodePolyline(string $encoded, int $precision = 5): array
    {
        $points = [];
        $index = 0;
        $lat = 0;
        $lng = 0;
        $factor = 10 ** $precision;
        $length = strlen($encoded);

        while ($index < $length) {
            $shift = 0;
            $result = 0;

            do {
                $byte = ord($encoded[$index++]) - 63;
                $result |= ($byte & 0x1F) << $shift;
                $shift += 5;
            } while ($byte >= 0x20);

            $deltaLat = ($result & 1) ? ~($result >> 1) : ($result >> 1);
            $lat += $deltaLat;

            $shift = 0;
            $result = 0;

            do {
                $byte = ord($encoded[$index++]) - 63;
                $result |= ($byte & 0x1F) << $shift;
                $shift += 5;
            } while ($byte >= 0x20);

            $deltaLng = ($result & 1) ? ~($result >> 1) : ($result >> 1);
            $lng += $deltaLng;

            $points[] = [$lat / $factor, $lng / $factor];
        }

        return $points;
    }
}
