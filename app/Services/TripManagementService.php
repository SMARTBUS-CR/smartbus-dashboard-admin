<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
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
    ) {}

    /**
     * Internal planning entry point. Operational consumption remains pending the
     * shared reservation protocol and bus/workforce assignment safeguards.
     *
     * @param array{
     *     route_pattern_id: string, route_schedule_id: string, bus_id: string, driver_id: string,
     *     service_date: string, arrival_at: string, departure_utc_offset?: string
     * } $data
     */
    public function schedule(User $actor, Company $company, array $data): Trip
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
            Gate::forUser($persistedActor)->authorize('create', [Trip::class, $company]);
            $this->validatePayload($data);

            return (new Trip)->getConnection()->transaction(function () use ($company, $data): Trip {
                $company = Company::query()->whereKey($company->getKey())->lockForUpdate()->first();
                if (! $company || $company->status !== CompanyStatus::ACTIVE) {
                    $this->reject('company', 'An active company context is required.');
                }

                $bus = Bus::query()->where('company_id', $company->getKey())->whereKey($data['bus_id'])->lockForUpdate()->first();
                if (! $bus || ! $bus->is_active) {
                    $this->reject('bus_id', 'The selected bus is unavailable.');
                }
                $driver = Driver::query()->where('company_id', $company->getKey())->whereKey($data['driver_id'])->lockForUpdate()->first();
                if (! $driver) {
                    $this->reject('driver_id', 'The selected driver is unavailable or ineligible for the planned dates.');
                }

                $plan = $this->snapshots->prepare($company, Arr::except($data, ['driver_id']));
                $driver = $this->drivers->validateForPlan($company, $driver->getKey(), $plan);

                return Trip::query()->create([
                    'company_id' => $company->getKey(),
                    'route_pattern_id' => $plan['pattern']->getKey(), 'route_schedule_id' => $plan['schedule']->getKey(),
                    'bus_id' => $bus->getKey(), 'driver_id' => $driver->getKey(),
                    'service_date' => $plan['service_date'],
                    'scheduled_departure_at' => $plan['departure_at'], 'scheduled_arrival_at' => $plan['arrival_at'],
                    'planned_route_snapshot' => $plan['planned_route_snapshot'],
                ])->refresh();
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
