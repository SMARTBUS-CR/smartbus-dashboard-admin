<?php

namespace App\Services\Operations;

use App\Models\Trip;
use App\Services\TripPlanSnapshotService;
use DomainException;
use Illuminate\Support\Str;

class EtaTripContext
{
    private const EARTH_RADIUS_METERS = 6371000;

    private const DISTANCE_PRECISION = 3;

    private const SNAP_TOLERANCE_METERS = 0.001;

    public function __construct(private readonly TripPlanSnapshotService $snapshots) {}

    /** @return array<string, mixed> */
    public function project(Trip $trip): array
    {
        $snapshot = $trip->planned_route_snapshot;
        $geometry = $snapshot['route_geometry'] ?? null;
        $occurrences = $snapshot['occurrences'] ?? null;
        $timezone = $snapshot['company_timezone'] ?? null;
        $departure = $trip->scheduled_departure_at;
        $arrival = $trip->scheduled_arrival_at;

        if (($snapshot['schema_version'] ?? null) !== 1
            || ! is_string($timezone) || $timezone === ''
            || $departure === null || $arrival === null || $arrival <= $departure
            || $trip->service_date === null
            || ! is_array($geometry) || ($geometry['type'] ?? null) !== 'LineString'
            || ! is_array($geometry['coordinates'] ?? null) || ! array_is_list($geometry['coordinates'])
            || count($geometry['coordinates']) < 2
            || ! is_array($occurrences) || ! array_is_list($occurrences) || count($occurrences) < 2) {
            throw new DomainException('Invalid saved trip plan.');
        }

        $coordinates = array_map($this->coordinates(...), $geometry['coordinates']);
        $segments = [];
        $routeLength = 0.0;
        for ($index = 1; $index < count($coordinates); $index++) {
            $start = $coordinates[$index - 1];
            $end = $coordinates[$index];
            $length = $this->distance($start, $end);
            if ($length > 0) {
                $segments[] = ['start' => $start, 'end' => $end, 'length' => $length, 'offset' => $routeLength];
                $routeLength += $length;
            }
        }
        $routeDistance = round($routeLength, self::DISTANCE_PRECISION);
        if ($routeDistance <= 0) {
            throw new DomainException('Saved route has no length.');
        }

        $stops = [];
        $candidates = [];
        $seen = [];
        $previousSequence = 0;
        $previousTime = $departure;
        $last = count($occurrences) - 1;
        foreach ($occurrences as $index => $occurrence) {
            $id = $occurrence['occurrence_id'] ?? null;
            $stopId = $occurrence['stop_id'] ?? null;
            $sequence = $occurrence['stop_sequence'] ?? null;
            $name = $occurrence['name'] ?? null;
            $minutes = $occurrence['minutes_from_start'] ?? null;
            if (! is_string($id) || ! Str::isUuid($id) || isset($seen[strtolower($id)])
                || ! is_string($stopId) || ! Str::isUuid($stopId)
                || ! is_int($sequence) || $sequence <= $previousSequence || ! is_string($name)
                || ($minutes !== null && (! is_int($minutes) || $minutes < 0))) {
                throw new DomainException('Invalid saved stop occurrence.');
            }

            $point = $this->coordinates([$occurrence['lng'] ?? null, $occurrence['lat'] ?? null]);
            $plannedTime = $minutes === null ? null : $departure->addMinutes($minutes);
            if ($plannedTime !== null && ($plannedTime < $previousTime || $plannedTime > $arrival)) {
                throw new DomainException('Saved stop times are outside the ordered trip window.');
            }

            $stops[] = [
                'occurrence_id' => $id,
                'stop_id' => $stopId,
                'stop_sequence' => $sequence,
                'name' => $name,
                'latitude' => $point[1],
                'longitude' => $point[0],
                'planned_arrival_at' => $plannedTime?->toISOString(),
            ];
            $candidates[] = match ($index) {
                0 => [0.0],
                $last => [$routeDistance],
                default => $this->nearestDistances($point, $segments),
            };
            $seen[strtolower($id)] = true;
            $previousSequence = $sequence;
            $previousTime = $plannedTime ?? $previousTime;
        }

        $distances = $this->orderedDistances($candidates);
        if ($distances !== $this->orderedDistances($candidates, backwards: true)) {
            throw new DomainException('Saved stop locations are ambiguous along the route.');
        }
        foreach ($distances as $index => $distance) {
            $stops[$index]['distance_along_route_m'] = $distance;
        }

        $plan = [
            'contract_version' => 1,
            'trip_id' => (string) $trip->id,
            'company_id' => (string) $trip->company_id,
            'route_pattern_id' => (string) $trip->route_pattern_id,
            'service_date' => $trip->service_date->toDateString(),
            'timezone' => $timezone,
            'planned_departure_at' => $departure->toISOString(),
            'planned_arrival_at' => $arrival->toISOString(),
            'route_geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
            'route_distance_m' => $routeDistance,
            'stop_occurrences' => $stops,
        ];

        return [
            ...$plan,
            'route_revision' => $this->snapshots->revisionHash($plan),
            'status' => $trip->status->value,
        ];
    }

    /** @return array{float, float} */
    private function coordinates(mixed $point): array
    {
        if (! is_array($point) || ! array_is_list($point) || count($point) !== 2
            || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
            throw new DomainException('Invalid saved coordinates.');
        }

        [$longitude, $latitude] = [(float) $point[0], (float) $point[1]];
        if (! is_finite($longitude) || abs($longitude) > 180 || ! is_finite($latitude) || abs($latitude) > 90) {
            throw new DomainException('Invalid saved coordinates.');
        }

        return [$longitude, $latitude];
    }

    /**
     * @param  array{float, float}  $point
     * @param  list<array{start: array{float, float}, end: array{float, float}, length: float, offset: float}>  $segments
     * @return list<float>
     */
    private function nearestDistances(array $point, array $segments): array
    {
        $best = INF;
        $projections = [];
        foreach ($segments as ['start' => $start, 'end' => $end, 'length' => $length, 'offset' => $offset]) {
            $longitudeDelta = $this->longitudeDelta($end[0] - $start[0]);
            $latitudeDelta = $end[1] - $start[1];
            $scale = cos(deg2rad(($start[1] + $end[1]) / 2));
            $x = $longitudeDelta * $scale;
            $y = $latitudeDelta;
            $pointX = $this->longitudeDelta($point[0] - $start[0]) * $scale;
            $pointY = $point[1] - $start[1];
            $fraction = max(0.0, min(1.0, ($x * $pointX + $y * $pointY) / ($x * $x + $y * $y)));
            $snapped = [$start[0] + $fraction * $longitudeDelta, $start[1] + $fraction * $latitudeDelta];
            $error = $this->distance($point, $snapped);
            $best = min($best, $error);
            $projections[] = [$error, round($offset + $fraction * $length, self::DISTANCE_PRECISION)];
        }

        $distances = [];
        foreach ($projections as [$error, $distance]) {
            if ($error <= $best + self::SNAP_TOLERANCE_METERS && end($distances) !== $distance) {
                $distances[] = $distance;
            }
        }

        return $distances;
    }

    /**
     * Find the earliest or latest feasible assignment in route order. If both agree,
     * the stop sequence disambiguates all equidistant projections.
     *
     * @param  list<list<float>>  $candidates
     * @return list<float>
     */
    private function orderedDistances(array $candidates, bool $backwards = false): array
    {
        $selected = [];
        $previous = null;
        foreach ($backwards ? array_reverse($candidates) : $candidates as $distances) {
            foreach ($backwards ? array_reverse($distances) : $distances as $distance) {
                if ($previous === null || ($backwards ? $distance <= $previous : $distance >= $previous)) {
                    $selected[] = $previous = $distance;

                    continue 2;
                }
            }
            throw new DomainException('Saved stop locations do not follow the route order.');
        }

        return $backwards ? array_reverse($selected) : $selected;
    }

    private function longitudeDelta(float $degrees): float
    {
        return fmod($degrees + 540, 360) - 180;
    }

    /**
     * @param  array{float, float}  $start
     * @param  array{float, float}  $end
     */
    private function distance(array $start, array $end): float
    {
        $latitudeDelta = deg2rad($end[1] - $start[1]);
        $longitudeDelta = deg2rad($this->longitudeDelta($end[0] - $start[0]));
        $haversine = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($start[1])) * cos(deg2rad($end[1])) * sin($longitudeDelta / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(sqrt(min(1.0, $haversine)));
    }
}
