<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\TripStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\RouteScheduleException;
use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TripManagementService
{
    public function __construct(
        private readonly TripPlanSnapshotService $snapshots,
        private readonly TripDriverEligibilityService $drivers,
        private readonly DriverAvailabilityService $availability,
    ) {}

    /**
     * Internal planning entry point. Operational consumption remains gated on
     * completed HU #36/#38 integration and verification.
     *
     * @param array{
     *     route_pattern_id: string, route_schedule_id: string, bus_id: string, driver_id: string,
     *     service_date: string, arrival_at: string, departure_utc_offset?: string
     * } $data
     */
    public function schedule(User $actor, Company $company, array $data): Trip
    {
        return $this->plan($actor, $company, $data);
    }

    /**
     * Replace a complete scheduled plan; identity and execution history are immutable.
     * The input contract is identical to schedule(), including an explicit arrival.
     *
     * @param array{
     *     route_pattern_id: string, route_schedule_id: string, bus_id: string, driver_id: string,
     *     service_date: string, arrival_at: string, departure_utc_offset?: string
     * } $data
     */
    public function replan(User $actor, Company $company, string $tripId, array $data): Trip
    {
        return $this->plan($actor, $company, $data, $tripId);
    }

    public function start(User $actor, Company $company, string $tripId): Trip
    {
        return $this->withLockedTrip($actor, $company, $tripId, 'start', function (Company $company, Trip $trip): Trip {
            if ($trip->status === TripStatus::IN_PROGRESS) {
                return $trip;
            }
            if ($trip->status !== TripStatus::SCHEDULED) {
                $this->reject('status', 'Only scheduled trips can be started.');
            }
            [$bus, $driver] = $this->lockTripResources($company, $trip);
            $instant = CarbonImmutable::now('UTC');
            if ($company->status !== CompanyStatus::ACTIVE) {
                $this->reject('company', 'An active company context is required.');
            }
            if ($bus->trashed() || ! $bus->is_active) {
                $this->reject('bus_id', 'The selected bus is unavailable.');
            }
            Validator::make(['timezone' => $company->timezone], ['timezone' => ['required', 'timezone']])->validate();
            $driver = $this->drivers->validate($company, $driver->getKey(), [$instant->setTimezone($company->timezone)->toDateString()]);
            if (! $this->availability->covers($driver, $instant, $instant->addMicrosecond())) {
                $this->reject('driver_id', 'The assigned driver is unavailable at the actual start instant.');
            }
            if (RouteScheduleException::query()->where('route_schedule_id', $trip->route_schedule_id)
                ->where('service_date', $trip->service_date->toDateString())->exists()) {
                $this->reject('service_date', 'The selected departure is currently suspended.');
            }
            app(TripReservationService::class)->ensureNoOtherTripInProgress($trip, $driver);

            return $this->persistTransition($trip, [
                'status' => TripStatus::IN_PROGRESS,
                'actual_departure_at' => $instant->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }

    public function complete(User $actor, Company $company, string $tripId): Trip
    {
        return $this->withLockedTrip($actor, $company, $tripId, 'complete', function (Company $company, Trip $trip): Trip {
            if ($trip->status === TripStatus::COMPLETED) {
                return $trip;
            }
            if ($trip->status !== TripStatus::IN_PROGRESS) {
                $this->reject('status', 'Only in-progress trips can be completed.');
            }
            $this->lockTripResources($company, $trip);
            $instant = CarbonImmutable::now('UTC');
            if ($instant->lessThan($trip->actual_departure_at)) {
                $this->reject('actual_arrival_at', 'The actual arrival cannot be before the actual departure.');
            }

            return $this->persistTransition($trip, [
                'status' => TripStatus::COMPLETED,
                'actual_arrival_at' => $instant->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }

    public function cancel(User $actor, Company $company, string $tripId, string $reason): Trip
    {
        return $this->withLockedTrip($actor, $company, $tripId, 'cancel', function (Company $company, Trip $trip) use ($reason): Trip {
            $reason = trim($reason);
            Validator::make(['cancellation_reason' => $reason], [
                'cancellation_reason' => ['required', 'string', 'max:500'],
            ])->validate();
            if ($trip->status === TripStatus::CANCELLED) {
                return $trip;
            }
            if ($trip->status !== TripStatus::SCHEDULED) {
                $this->reject('status', 'Only scheduled trips can be cancelled.');
            }
            $this->lockTripResources($company, $trip);

            return $this->persistTransition($trip, [
                'status' => TripStatus::CANCELLED, 'cancellation_reason' => $reason,
                'cancelled_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }

    /** @param Closure(Company, Trip): Trip $operation */
    private function withLockedTrip(User $actor, Company $company, string $tripId, string $ability, Closure $operation): Trip
    {
        $previousTeam = getPermissionsTeamId();
        $persistedActor = null;
        try {
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $persistedActor = User::query()->find($actor->getKey());
            if ($persistedActor === null) {
                throw new AuthorizationException;
            }
            setPermissionsTeamId($company->getKey());
            Gate::forUser($persistedActor)->authorize($ability, [Trip::class, $company]);
            Validator::make(['trip_id' => $tripId], ['trip_id' => ['required', 'uuid']])->validate();

            return (new Trip)->getConnection()->transaction(function () use ($persistedActor, $company, $tripId, $ability, $operation): Trip {
                $company = Company::query()->whereKey($company->getKey())->sharedLock()->firstOrFail();
                $trip = Trip::query()->where('company_id', $company->getKey())->whereKey($tripId)->lockForUpdate()->firstOrFail();
                Gate::forUser($persistedActor)->authorize($ability, [Trip::class, $company, $trip]);

                return $operation($company, $trip);
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $persistedActor?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /** @return array{Bus, Driver} */
    private function lockTripResources(Company $company, Trip $trip): array
    {
        $identity = Driver::withTrashed()->where('company_id', $company->getKey())->findOrFail($trip->driver_id);
        app(TripReservationService::class)->lockPeople([$identity->user_id]);
        $bus = Bus::withTrashed()->where('company_id', $company->getKey())->whereKey($trip->bus_id)->lockForUpdate()->firstOrFail();
        $driver = Driver::withTrashed()->where('company_id', $company->getKey())->whereKey($trip->driver_id)->lockForUpdate()->firstOrFail();

        return [$bus, $driver];
    }

    /** @param array<string, mixed> $attributes Trusted transition columns only. */
    private function persistTransition(Trip $trip, array $attributes): Trip
    {
        $updated = Trip::query()->whereKey($trip->getKey())->where('company_id', $trip->company_id)
            ->where('status', $trip->status)->update($attributes);
        if ($updated !== 1) {
            throw new AuthorizationException;
        }

        return $trip->refresh();
    }

    /** @param array<string, mixed> $data */
    private function plan(User $actor, Company $company, array $data, ?string $tripId = null): Trip
    {
        $previousTeam = getPermissionsTeamId();
        $persistedActor = null;
        try {
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $persistedActor = User::query()->find($actor->getKey());
            if (! $persistedActor) {
                throw new AuthorizationException;
            }
            setPermissionsTeamId($company->getKey());
            $ability = $tripId === null ? 'create' : 'update';
            Gate::forUser($persistedActor)->authorize($ability, [Trip::class, $company]);
            if ($tripId !== null) {
                Validator::make(['trip_id' => $tripId], ['trip_id' => ['required', 'uuid']])->validate();
            }

            return (new Trip)->getConnection()->transaction(function () use ($persistedActor, $company, $data, $tripId): Trip {
                $company = Company::query()->whereKey($company->getKey())->sharedLock()->first();
                if (! $company || $company->status !== CompanyStatus::ACTIVE) {
                    $this->reject('company', 'An active company context is required.');
                }

                $trip = null;
                if ($tripId !== null) {
                    $trip = Trip::query()->where('company_id', $company->getKey())->whereKey($tripId)->lockForUpdate()->firstOrFail();
                    Gate::forUser($persistedActor)->authorize('update', [Trip::class, $company, $trip]);
                }
                $this->validatePayload($data);

                $proposedDriver = Driver::query()->where('company_id', $company->getKey())->find($data['driver_id']);
                if (! $proposedDriver) {
                    $this->reject('driver_id', 'The selected driver is unavailable or ineligible for the planned dates.');
                }
                $driverIds = array_values(array_unique(array_filter([$trip?->driver_id, $proposedDriver->getKey()])));
                $reservations = app(TripReservationService::class);
                $reservations->lockPeople(Driver::withTrashed()->whereIn('id', $driverIds)->pluck('user_id')->all());
                $buses = Bus::withTrashed()->whereIn('id', array_filter([$trip?->bus_id, $data['bus_id']]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $drivers = Driver::withTrashed()->whereIn('id', $driverIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                $bus = $buses->get($data['bus_id']);
                if (! $bus || $bus->company_id !== $company->getKey() || $bus->trashed() || ! $bus->is_active) {
                    $this->reject('bus_id', 'The selected bus is unavailable.');
                }
                $driver = $drivers->get($data['driver_id']);
                if (! $driver || $driver->company_id !== $company->getKey() || $driver->trashed()) {
                    $this->reject('driver_id', 'The selected driver is unavailable or ineligible for the planned dates.');
                }

                $plan = $this->snapshots->prepare($company, Arr::except($data, ['driver_id']));
                $driver = $this->drivers->validateForPlan($company, $driver->getKey(), $plan);
                $reservations->ensurePersonAvailable($driver, $plan['departure_at'], $plan['arrival_at'], $trip?->getKey());

                $attributes = [
                    'route_pattern_id' => $plan['pattern']->getKey(), 'route_schedule_id' => $plan['schedule']->getKey(),
                    'bus_id' => $bus->getKey(), 'driver_id' => $driver->getKey(),
                    'service_date' => $plan['service_date'],
                    'scheduled_departure_at' => $plan['departure_at'], 'scheduled_arrival_at' => $plan['arrival_at'],
                    'planned_route_snapshot' => $plan['planned_route_snapshot'],
                ];
                if ($trip === null) {
                    return Trip::query()->create(['company_id' => $company->getKey(), ...$attributes])->refresh();
                }

                return $this->persistReplan($trip, $attributes);
            });
        } catch (QueryException $exception) {
            $detail = $exception->errorInfo[2] ?? '';
            $code = $exception->errorInfo[0] ?? '';
            $field = match (true) {
                $code === '23505' && str_contains($detail, '"trips_schedule_service_date_unique"') => 'service_date',
                $code === '23P01' && str_contains($detail, '"trips_no_bus_overlap"') => 'bus_id',
                $code === '23P01' && str_contains($detail, '"trips_no_driver_overlap"') => 'driver_id',
                default => null,
            };
            if ($field === null) {
                throw $exception;
            }
            $this->reject($field, 'The selected departure or resource conflicts with an existing trip.');
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $persistedActor?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * The authorized transaction updates only validated plan columns. Using the
     * builder here deliberately preserves the model's blanket direct-save guard.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persistReplan(Trip $trip, array $attributes): Trip
    {
        $storedAttributes = (new Trip($attributes))->getAttributes();
        $updated = Trip::query()->whereKey($trip->getKey())->where('company_id', $trip->company_id)
            ->where('status', TripStatus::SCHEDULED)->update($storedAttributes);
        if ($updated !== 1) {
            throw new AuthorizationException;
        }

        return $trip->refresh();
    }

    /** @param array<string, mixed> $data */
    private function validatePayload(array $data): void
    {
        $allowed = ['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id', 'service_date', 'arrival_at', 'departure_utc_offset'];
        foreach (array_diff(array_keys($data), $allowed) as $field) {
            $this->reject($field, 'This field cannot be supplied when planning a trip.');
        }
        Validator::make($data, [
            'route_pattern_id' => ['required', 'uuid'], 'route_schedule_id' => ['required', 'uuid'],
            'bus_id' => ['required', 'uuid'], 'driver_id' => ['required', 'uuid'],
            'service_date' => ['required', 'string', 'date_format:Y-m-d'], 'arrival_at' => ['required', 'string'],
            'departure_utc_offset' => ['sometimes', 'required', 'string', 'regex:/\A[+-](?:0\d|1[0-4]):[0-5]\d\z/'],
        ])->validate();
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
