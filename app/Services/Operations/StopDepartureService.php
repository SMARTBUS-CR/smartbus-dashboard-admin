<?php

namespace App\Services\Operations;

use App\Models\RouteFare;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\Stop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;

class StopDepartureService
{
    /**
     * @return Collection<int, Fluent>
     */
    public function getUpcomingDepartures(Stop $stop, ?string $departureAt = null, int $windowMinutes = 120): Collection
    {
        $departureAtUtc = filled($departureAt)
            ? CarbonImmutable::parse($departureAt)->setTimezone('UTC')
            : CarbonImmutable::now('UTC');

        $patternStops = RoutePatternStop::query()
            ->with(['pattern.route.company', 'pattern.route.fares'])
            ->where('stop_id', $stop->id)
            ->get();

        $departures = collect();

        foreach ($patternStops as $patternStop) {
            $pattern = $patternStop->pattern;
            if (! $pattern || ! $pattern->route) {
                continue;
            }

            $route = $pattern->route;
            $company = $route->company;
            $companyTimezone = $company?->timezone ?? 'UTC';

            $departureInCompanyTz = $departureAtUtc->setTimezone($companyTimezone);
            $serviceDate = $departureInCompanyTz->format('Y-m-d');
            $windowEnd = $departureInCompanyTz->addMinutes($windowMinutes);

            $schedules = RouteSchedule::query()
                ->where('route_pattern_id', $pattern->id)
                ->scheduledOn($departureInCompanyTz)
                ->get();

            if ($schedules->isEmpty()) {
                continue;
            }

            $fare = $route->fares
                ->filter(function (RouteFare $fare) use ($serviceDate): bool {
                    $validFromOk = $fare->valid_from === null || $fare->valid_from->format('Y-m-d') <= $serviceDate;
                    $validUntilOk = $fare->valid_until === null || $fare->valid_until->format('Y-m-d') >= $serviceDate;

                    return $validFromOk && $validUntilOk;
                })
                ->first();

            $minutesFromStart = $patternStop->minutes_from_start ?? 0;

            foreach ($schedules as $schedule) {
                $stopDepartureAt = $departureInCompanyTz->copy()
                    ->setTimeFromTimeString($schedule->departure_time)
                    ->addMinutes($minutesFromStart);

                if ($stopDepartureAt->greaterThanOrEqualTo($departureInCompanyTz) && $stopDepartureAt->lessThanOrEqualTo($windowEnd)) {
                    $departures->push(new Fluent([
                        'id' => sprintf('%s-%s', $schedule->id, $patternStop->id),
                        'route_id' => (string) $route->id,
                        'route_code' => $route->code,
                        'route_name' => $route->name,
                        'pattern_id' => (string) $pattern->id,
                        'headsign' => $pattern->headsign,
                        'stop_id' => (string) $stop->id,
                        'stop_name' => $stop->name,
                        'stop_sequence' => $patternStop->stop_sequence,
                        'scheduled_stop_departure_at' => $stopDepartureAt->toIso8601String(),
                        'fare' => $fare ? [
                            'amount' => (string) $fare->amount,
                            'currency' => $fare->currency,
                        ] : null,
                    ]));
                }
            }
        }

        return $departures
            ->sortBy('scheduled_stop_departure_at')
            ->values();
    }
}
