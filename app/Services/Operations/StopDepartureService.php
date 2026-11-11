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
            ->whereHas('pattern', fn (Builder $pattern): Builder => $pattern->inPublicCatalogue())
            ->get();

        if ($patternStops->isEmpty()) {
            return collect();
        }

        $patternIds = $patternStops->pluck('route_pattern_id')->unique()->all();

        $datesByTimezone = [];
        $serviceDates = [];
        foreach ($patternStops as $patternStop) {
            $timezone = $patternStop->pattern?->route?->company?->timezone ?? 'UTC';
            if (isset($datesByTimezone[$timezone])) {
                continue;
            }

            $localDeparture = $departureAtUtc->setTimezone($timezone);
            $datesByTimezone[$timezone] = [
                $localDeparture->subDay()->startOfDay(),
                $localDeparture->startOfDay(),
            ];
            if ($localDeparture->addMinutes($windowMinutes)->toDateString() > $localDeparture->toDateString()) {
                $datesByTimezone[$timezone][] = $localDeparture->addDay()->startOfDay();
            }
            foreach ($datesByTimezone[$timezone] as $date) {
                $serviceDates[$date->toDateString()] = $date;
            }
        }

        // Bulk-load schedules by pattern and service date avoiding N+1 queries
        $schedulesByPatternAndDate = [];
        foreach ($serviceDates as $date) {
            $dateStr = $date->format('Y-m-d');
            $schedules = RouteSchedule::query()
                ->whereIn('route_pattern_id', $patternIds)
                ->scheduledOn($date)
                ->get();

            foreach ($schedules as $schedule) {
                $schedulesByPatternAndDate[$schedule->route_pattern_id][$dateStr][] = $schedule;
            }
        }

        $departures = collect();

        foreach ($patternStops as $patternStop) {
            $pattern = $patternStop->pattern;
            if (! $pattern || ! $pattern->route) {
                continue;
            }

            $route = $pattern->route;
            $companyTimezone = $route->company?->timezone ?? 'UTC';
            $departureInCompanyTz = $departureAtUtc->setTimezone($companyTimezone);
            $windowEnd = $departureInCompanyTz->addMinutes($windowMinutes);

            // Unknown intermediate stop departure time cannot be placed in a scheduled window
            if ($patternStop->stop_sequence > 1 && $patternStop->minutes_from_start === null) {
                continue;
            }

            $minutesFromStart = $patternStop->stop_sequence === 1
                ? ($patternStop->minutes_from_start ?? 0)
                : (int) $patternStop->minutes_from_start;

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
                    $stopDepartureAt = $date
                        ->setTimeFromTimeString($schedule->departure_time)
                        ->addMinutes($minutesFromStart);

                    if ($stopDepartureAt->greaterThanOrEqualTo($departureInCompanyTz) && $stopDepartureAt->lessThanOrEqualTo($windowEnd)) {
                        $departures->push(new Fluent([
                            'id' => sprintf('%s-%s-%s', $schedule->id, $patternStop->id, $dateStr),
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
        }

        return $departures
            ->sortBy(fn (Fluent $departure): int => CarbonImmutable::parse($departure->scheduled_stop_departure_at)->getTimestamp())
            ->values();
    }
}
