<?php

namespace App\Services\Operations;

use App\Models\RouteFare;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\Stop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;

class DirectJourneySearchService
{
    /**
     * @param array{
     *     origin_lat: float|string,
     *     origin_lng: float|string,
     *     destination_lat: float|string,
     *     destination_lng: float|string,
     *     departure_at?: string|null,
     *     walking_radius_m?: int|string|null
     * } $params
     * @return Collection<int, Fluent>
     */
    public function search(array $params): Collection
    {
        $originLat = (float) $params['origin_lat'];
        $originLng = (float) $params['origin_lng'];
        $destLat = (float) $params['destination_lat'];
        $destLng = (float) $params['destination_lng'];
        $radius = (int) ($params['walking_radius_m'] ?? 500);

        $departureAtUtc = filled($params['departure_at'] ?? null)
            ? CarbonImmutable::parse($params['departure_at'])->setTimezone('UTC')
            : CarbonImmutable::now('UTC');

        // 1. Find candidate origin stops within walking radius
        $originStops = $this->findStopsNear($originLat, $originLng, $radius);
        if ($originStops->isEmpty()) {
            return collect();
        }

        // 2. Find candidate destination stops within walking radius
        $destStops = $this->findStopsNear($destLat, $destLng, $radius);
        if ($destStops->isEmpty()) {
            return collect();
        }

        $originStopIds = $originStops->keys()->all();
        $destStopIds = $destStops->keys()->all();

        // 3. Find candidate boarding and alighting pattern occurrences
        $boardingPatternStops = RoutePatternStop::query()
            ->with(['stop', 'pattern.route.company', 'pattern.route.fares'])
            ->whereIn('stop_id', $originStopIds)
            ->get();

        $alightingPatternStops = RoutePatternStop::query()
            ->with(['stop'])
            ->whereIn('stop_id', $destStopIds)
            ->get()
            ->groupBy('route_pattern_id');

        $journeys = collect();

        foreach ($boardingPatternStops as $boarding) {
            $matchingAlightings = $alightingPatternStops->get($boarding->route_pattern_id);
            if (! $matchingAlightings) {
                continue;
            }

            $pattern = $boarding->pattern;
            if (! $pattern || ! $pattern->route) {
                continue;
            }

            $route = $pattern->route;
            $company = $route->company;
            $companyTimezone = $company?->timezone ?? 'UTC';

            $departureInCompanyTz = $departureAtUtc->setTimezone($companyTimezone);
            $serviceDate = $departureInCompanyTz->format('Y-m-d');

            // Find valid schedules for this pattern on this service day
            $schedules = RouteSchedule::query()
                ->where('route_pattern_id', $pattern->id)
                ->scheduledOn($departureInCompanyTz)
                ->get();

            if ($schedules->isEmpty()) {
                continue;
            }

            // Find applicable route fare for this service date
            $fare = $route->fares
                ->filter(function (RouteFare $fare) use ($serviceDate): bool {
                    $validFromOk = $fare->valid_from === null || $fare->valid_from->format('Y-m-d') <= $serviceDate;
                    $validUntilOk = $fare->valid_until === null || $fare->valid_until->format('Y-m-d') >= $serviceDate;

                    return $validFromOk && $validUntilOk;
                })
                ->first();

            foreach ($matchingAlightings as $alighting) {
                // Must be forward in sequence to respect direction ordering
                if ($boarding->stop_sequence >= $alighting->stop_sequence) {
                    continue;
                }

                $originDistance = $originStops->get($boarding->stop_id);
                $destDistance = $destStops->get($alighting->stop_id);

                $boardingMinutes = $boarding->minutes_from_start ?? 0;
                $alightingMinutes = $alighting->minutes_from_start ?? $boardingMinutes;

                foreach ($schedules as $schedule) {
                    $scheduledBoardingAt = $departureInCompanyTz->copy()
                        ->setTimeFromTimeString($schedule->departure_time)
                        ->addMinutes($boardingMinutes);

                    // Only return departures on or after the requested departure time
                    if ($scheduledBoardingAt->lessThan($departureInCompanyTz)) {
                        continue;
                    }

                    $estimatedArrivalAt = $departureInCompanyTz->copy()
                        ->setTimeFromTimeString($schedule->departure_time)
                        ->addMinutes($alightingMinutes);

                    $journeys->push(new Fluent([
                        'id' => sprintf('%s-%s-%s-%s', $pattern->id, $schedule->id, $boarding->id, $alighting->id),
                        'route_id' => (string) $route->id,
                        'route_code' => $route->code,
                        'route_name' => $route->name,
                        'pattern_id' => (string) $pattern->id,
                        'pattern_name' => $pattern->name,
                        'headsign' => $pattern->headsign,
                        'company_id' => (string) $company?->id,
                        'company_name' => $company?->name,
                        'boarding_stop' => [
                            'stop_id' => (string) $boarding->stop_id,
                            'stop_name' => $boarding->stop?->name,
                            'stop_sequence' => $boarding->stop_sequence,
                            'distance_m' => (int) $originDistance,
                        ],
                        'alighting_stop' => [
                            'stop_id' => (string) $alighting->stop_id,
                            'stop_name' => $alighting->stop?->name,
                            'stop_sequence' => $alighting->stop_sequence,
                            'distance_m' => (int) $destDistance,
                        ],
                        'scheduled_departure_at' => $scheduledBoardingAt->toIso8601String(),
                        'estimated_arrival_at' => $estimatedArrivalAt->toIso8601String(),
                        'fare' => $fare ? [
                            'amount' => (string) $fare->amount,
                            'currency' => $fare->currency,
                        ] : null,
                    ]));
                }
            }
        }

        return $journeys
            ->sortBy('scheduled_departure_at')
            ->values()
            ->take(20);
    }

    /**
     * @return Collection<string, int> Map of stop_id => distance_m
     */
    private function findStopsNear(float $lat, float $lng, int $radiusM): Collection
    {
        $latDelta = $radiusM / 111320;
        $cosLat = max(0.01, cos(deg2rad($lat)));
        $lngDelta = $radiusM / (111320 * $cosLat);

        $candidates = Stop::query()
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->get(['id', 'latitude', 'longitude']);

        $result = collect();
        foreach ($candidates as $stop) {
            $dist = $this->haversineDistance($lat, $lng, (float) $stop->latitude, (float) $stop->longitude);
            if ($dist <= $radiusM) {
                $result->put((string) $stop->id, (int) round($dist));
            }
        }

        return $result;
    }

    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
