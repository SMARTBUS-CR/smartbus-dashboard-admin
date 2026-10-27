<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\Company;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class TripPlanSnapshotService
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(private readonly TripPlanPreparationService $preparation) {}

    /**
     * Build the proposed schema-v1 historical contract from persisted catalogue data.
     * This preparation step does not authorize an actor, validate a driver or create a trip.
     *
     * Metrics use decimal strings with two positions and stop coordinates use seven.
     * Geometry and nullable numeric leg lists retain their persisted values; occurrences
     * retain travel order, repeated stops and nullable timing estimates. No user or driver
     * data is projected. Team agreement is still required before operational consumption.
     *
     * @param array{
     *     route_pattern_id: string, route_schedule_id: string, bus_id: string,
     *     service_date: string, arrival_at: string, departure_utc_offset?: string
     * } $data
     * @return array{
     *     company: Company, pattern: RoutePattern, schedule: RouteSchedule, bus: Bus,
     *     occurrences: Collection<int, RoutePatternStop>, service_date: string,
     *     departure_at: CarbonImmutable, arrival_at: CarbonImmutable, local_dates: list<string>,
     *     planned_route_snapshot: array{
     *         schema_version: int, pattern_id: string, pattern_code: string, pattern_name: string,
     *         headsign: string, route_id: string, route_code: string, route_name: string,
     *         company_timezone: string, route_geometry: array<string, mixed>, distance_meters: string,
     *         driving_duration_seconds: string, routing_points_hash: string,
     *         routing_leg_distances: list<int|float|numeric-string>|null, revision_hash: string,
     *         occurrences: list<array{
     *             occurrence_id: string, stop_id: string, name: string, stop_sequence: int,
     *             lat: string, lng: string, minutes_from_start: int|null
     *         }>
     *     }
     * }
     */
    public function prepare(Company $company, array $data): array
    {
        $plan = $this->preparation->prepare($company, $data);
        $pattern = $plan['pattern'];
        $route = $pattern->route;
        $snapshot = [
            'schema_version' => 1,
            'pattern_id' => $pattern->getKey(), 'pattern_code' => $pattern->code,
            'pattern_name' => $pattern->name, 'headsign' => $pattern->headsign,
            'route_id' => $route->getKey(), 'route_code' => $route->code, 'route_name' => $route->name,
            'company_timezone' => $plan['company']->timezone,
            'route_geometry' => $pattern->route_geometry,
            'distance_meters' => $pattern->distance_meters,
            'driving_duration_seconds' => $pattern->driving_duration_seconds,
            'routing_points_hash' => $pattern->routing_points_hash,
            'routing_leg_distances' => $pattern->routing_leg_distances,
            'occurrences' => $plan['occurrences']->map(fn (RoutePatternStop $occurrence): array => [
                'occurrence_id' => $occurrence->getKey(), 'stop_id' => $occurrence->stop_id,
                'name' => $occurrence->stop->name, 'stop_sequence' => $occurrence->stop_sequence,
                'lat' => $occurrence->stop->latitude, 'lng' => $occurrence->stop->longitude,
                'minutes_from_start' => $occurrence->minutes_from_start,
            ])->all(),
        ];
        $snapshot['revision_hash'] = $this->revisionHash($snapshot);

        return [...$plan, 'planned_route_snapshot' => $this->canonicalize($snapshot)];
    }

    /**
     * SHA-256 of UTF-8 canonical JSON, excluding only the root revision_hash.
     * Object keys sort lexically at every depth; list order, scalar types and nulls
     * remain unchanged. Unicode and slashes are unescaped and zero fractions persist.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function revisionHash(array $snapshot): string
    {
        unset($snapshot['revision_hash']);

        return hash('sha256', json_encode($this->canonicalize($snapshot), self::JSON_FLAGS));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
