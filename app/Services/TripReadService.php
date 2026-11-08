<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class TripReadService
{
    /** @var list<string> */
    private const TRIP_FIELDS = [
        'id', 'company_id', 'bus_id', 'driver_id', 'route_pattern_id', 'route_schedule_id',
        'status', 'service_date', 'scheduled_departure_at', 'scheduled_arrival_at',
        'actual_departure_at', 'actual_arrival_at', 'cancelled_at', 'cancellation_reason',
        'deleted_at', 'planned_route_snapshot',
    ];

    /**
     * Inclusive local service dates; all states and archived history are readable.
     * This proposed read contract is pending confirmation by the consuming contributor.
     *
     * @return Collection<int, array{
     *     id: string, status: string, service_date: string,
     *     scheduled_departure_at: string, scheduled_arrival_at: string,
     *     actual_departure_at: string|null, actual_arrival_at: string|null,
     *     cancelled_at: string|null, cancellation_reason: string|null, deleted_at: string|null,
     *     route_pattern_id: string, route_schedule_id: string,
     *     company: array{id: string, name: string, deleted_at: string|null},
     *     bus: array{id: string, country_code: string, plate_number: string, unit_number: string, deleted_at: string|null},
     *     driver: array{id: string, user_id: string, deleted_at: string|null},
     *     planned_route_snapshot: array<string, mixed>
     * }>
     */
    public function listFor(User $actor, Company $company, string $fromDate, string $untilDate): Collection
    {
        $previousTeam = getPermissionsTeamId();
        $currentActor = null;

        try {
            setPermissionsTeamId($company->getKey());
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $currentActor = User::query()->find($actor->getKey());
            if ($currentActor === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($currentActor)->authorize('viewAny', [Trip::class, $company]);

            Validator::make(['from_date' => $fromDate, 'until_date' => $untilDate], [
                'from_date' => ['required', 'string', 'date_format:Y-m-d'],
                'until_date' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            ])->validate();

            $query = Trip::withTrashed()->where('company_id', $company->getKey())
                ->whereBetween('service_date', [$fromDate, $untilDate]);

            if (! $currentActor->isSuperAdmin() && $currentActor->roles()
                ->where('roles.company_id', $company->getKey())->where('guard_name', 'web')
                ->where('name', UserRole::Driver->value)->exists()) {
                $query->whereIn('driver_id', Driver::withTrashed()->select('id')
                    ->where('company_id', $company->getKey())->where('user_id', $currentActor->getKey()));
            }

            return $query->with([
                'company:id,legal_name,deleted_at',
                'bus:id,country_code,plate_number,unit_number,deleted_at',
                'driver:id,user_id,deleted_at',
            ])->orderBy('service_date')->orderBy('scheduled_departure_at')->orderBy('id')
                ->get(self::TRIP_FIELDS)->map(fn (Trip $trip): array => $this->project($trip));
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $currentActor?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /** @return array<string, mixed> */
    private function project(Trip $trip): array
    {
        return [
            'id' => $trip->getKey(), 'status' => $trip->status->value,
            'service_date' => $trip->service_date->toDateString(),
            'scheduled_departure_at' => $trip->scheduled_departure_at->toJSON(),
            'scheduled_arrival_at' => $trip->scheduled_arrival_at->toJSON(),
            'actual_departure_at' => $trip->actual_departure_at?->toJSON(),
            'actual_arrival_at' => $trip->actual_arrival_at?->toJSON(),
            'cancelled_at' => $trip->cancelled_at?->toJSON(),
            'cancellation_reason' => $trip->cancellation_reason,
            'deleted_at' => $trip->deleted_at?->toJSON(),
            'route_pattern_id' => $trip->route_pattern_id, 'route_schedule_id' => $trip->route_schedule_id,
            'company' => [
                'id' => $trip->company->getKey(), 'name' => $trip->company->legal_name,
                'deleted_at' => $trip->company->deleted_at?->toJSON(),
            ],
            'bus' => [
                'id' => $trip->bus->getKey(), 'country_code' => $trip->bus->country_code,
                'plate_number' => $trip->bus->plate_number, 'unit_number' => $trip->bus->unit_number,
                'deleted_at' => $trip->bus->deleted_at?->toJSON(),
            ],
            'driver' => [
                'id' => $trip->driver->getKey(), 'user_id' => $trip->driver->user_id,
                'deleted_at' => $trip->driver->deleted_at?->toJSON(),
            ],
            'planned_route_snapshot' => $this->projectSnapshot($trip->planned_route_snapshot),
        ];
    }

    /**
     * Project saved schema-v1 data without rebuilding the plan or serializing related models.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function projectSnapshot(array $snapshot): array
    {
        $projection = Arr::only($snapshot, [
            'schema_version', 'pattern_id', 'pattern_code', 'pattern_name', 'headsign',
            'route_id', 'route_code', 'route_name', 'company_timezone', 'route_geometry',
            'distance_meters', 'driving_duration_seconds', 'routing_points_hash',
            'routing_leg_distances', 'revision_hash', 'occurrences',
        ]);
        $projection['route_geometry'] = Arr::only($snapshot['route_geometry'], ['type', 'coordinates']);
        $projection['occurrences'] = array_map(fn (array $occurrence): array => Arr::only($occurrence, [
            'occurrence_id', 'stop_id', 'name', 'stop_sequence', 'lat', 'lng', 'minutes_from_start',
        ]), $snapshot['occurrences']);

        return $projection;
    }
}
