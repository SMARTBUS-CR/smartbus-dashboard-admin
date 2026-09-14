<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class RoutingService
{
    /**
     * Generates an encoded polyline connecting origin, waypoints, and destination via real streets.
     */
    public function calculateRoutePolyline(array $origin, array $destination, array $waypoints = []): ?string
    {
        $coordinates = ["{$origin['lng']},{$origin['lat']}"];

        foreach ($waypoints as $waypoint) {
            if (isset($waypoint['lat'], $waypoint['lng'])) {
                $coordinates[] = "{$waypoint['lng']},{$waypoint['lat']}";
            }
        }

        $coordinates[] = "{$destination['lng']},{$destination['lat']}";
        $coordsString = implode(';', $coordinates);

        $url = "https://router.project-osrm.org/route/v1/driving/{$coordsString}?overview=full&geometries=polyline";

        $response = Http::timeout(10)->get($url);

        if ($response->successful() && isset($response->json()['routes'][0]['geometry'])) {
            return $response->json()['routes'][0]['geometry'];
        }

        return null;
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
