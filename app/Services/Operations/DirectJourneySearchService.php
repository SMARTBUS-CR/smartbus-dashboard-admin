<?php

namespace App\Services\Operations;

use App\Models\RouteFare;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\Stop;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
            ->whereHas('pattern', fn (Builder $pattern): Builder => $pattern->inPublicCatalogue())
            ->get();

        $alightingPatternStops = RoutePatternStop::query()
            ->with(['stop'])
            ->whereIn('stop_id', $destStopIds)
            ->whereHas('pattern', fn (Builder $pattern): Builder => $pattern->inPublicCatalogue())
            ->get()
            ->groupBy('route_pattern_id');

        $patternIds = $boardingPatternStops->pluck('route_pattern_id')->unique()->all();

        $datesByTimezone = [];
        $serviceDates = [];
        foreach ($boardingPatternStops as $boarding) {
            $timezone = $boarding->pattern?->route?->company?->timezone ?? 'UTC';
            if (isset($datesByTimezone[$timezone])) {
                continue;
            }

            $localDeparture = $departureAtUtc->setTimezone($timezone);
            $datesByTimezone[$timezone] = [
                $localDeparture->subDay()->startOfDay(),
                $localDeparture->startOfDay(),
                $localDeparture->addDay()->startOfDay(),
            ];
            foreach ($datesByTimezone[$timezone] as $date) {
                $serviceDates[$date->toDateString()] = $date;
            }
        }

        // Bulk load schedules for candidate patterns across target dates avoiding N+1
        $schedulesByPatternAndDate = [];
        foreach ($serviceDates as $date) {
            $dateStr = $date->format('Y-m-d');
            $schedules = RouteSchedule::query()
                ->whereIn('route_pattern_id', $patternIds)
                ->scheduledOn($date)
                ->get();

            foreach ($schedules as $sched) {
                $schedulesByPatternAndDate[$sched->route_pattern_id][$dateStr][] = $sched;
            }
        }

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

            foreach ($matchingAlightings as $alighting) {
                // Must be forward in sequence to respect direction ordering
                if ($boarding->stop_sequence >= $alighting->stop_sequence) {
                    continue;
                }

                $originDistance = $originStops->get($boarding->stop_id);
                $destDistance = $destStops->get($alighting->stop_id);

                // Preserve null when minutes_from_start is unknown on intermediate stops
                $boardingMinutes = $boarding->stop_sequence === 1
                    ? ($boarding->minutes_from_start ?? 0)
                    : $boarding->minutes_from_start;

                $alightingMinutes = $alighting->minutes_from_start;

                foreach ($datesByTimezone[$companyTimezone] as $date) {
                    $dateStr = $date->format('Y-m-d');

                    $schedules = $schedulesByPatternAndDate[$pattern->id][$dateStr] ?? [];

                    $fare = $route->fares
                        ->filter(function (RouteFare $f) use ($dateStr): bool {
                            $validFromOk = $f->valid_from === null || $f->valid_from->format('Y-m-d') <= $dateStr;
                            $validUntilOk = $f->valid_until === null || $f->valid_until->format('Y-m-d') >= $dateStr;

                            return $validFromOk && $validUntilOk;
                        })
                        ->first();

                    foreach ($schedules as $schedule) {
                        $originDepartureAt = $date
                            ->setTimeFromTimeString($schedule->departure_time);

                        $scheduledBoardingAt = $boardingMinutes !== null
                            ? $originDepartureAt->addMinutes($boardingMinutes)
                            : null;

                        if ($scheduledBoardingAt === null
                            || $scheduledBoardingAt->lessThan($departureInCompanyTz)
                            || $scheduledBoardingAt->greaterThan($departureInCompanyTz->addHours(24))) {
                            continue;
                        }

                        $estimatedArrivalAt = $alightingMinutes !== null
                            ? $originDepartureAt->addMinutes($alightingMinutes)
                            : null;

                        $journeys->push(new Fluent([
                            'id' => sprintf('%s-%s-%s-%s-%s', $pattern->id, $schedule->id, $boarding->id, $alighting->id, $dateStr),
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
                            'scheduled_departure_at' => $scheduledBoardingAt?->toIso8601String(),
                            'estimated_arrival_at' => $estimatedArrivalAt?->toIso8601String(),
                            'fare' => $fare ? [
                                'amount' => (string) $fare->amount,
                                'currency' => $fare->currency,
                            ] : null,
                        ]));
                    }
                }
            }
        }

        return $journeys
            ->sortBy(fn (Fluent $journey): int => CarbonImmutable::parse($journey->scheduled_departure_at)->getTimestamp())
            ->values();
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
            ->inPublicCatalogue()
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
