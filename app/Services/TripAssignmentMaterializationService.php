<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\TripAssignmentStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\RouteSchedule;
use App\Models\Trip;
use App\Models\TripAssignment;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TripAssignmentMaterializationService
{
    public function __construct(
        private readonly TripPlanSnapshotService $snapshots,
        private readonly TripDriverEligibilityService $eligibility,
        private readonly TripReservationService $reservations,
    ) {}

    /**
     * Materialize one Company-local service date without impersonating a User.
     * Ambiguous local clocks deterministically use their earliest real UTC
     * instant; nonexistent local clocks fail only the affected assignment.
     *
     * @return array{
     *     service_date: string,
     *     created: list<array{assignment_id: string, trip_id: string}>,
     *     skipped: list<array{assignment_id: string, reason: string}>,
     *     failed: list<array{assignment_id: string, errors: array<string, list<string>>}>
     * }
     */
    public function materialize(Company $company, CarbonImmutable $serviceDate, int $chunkSize = 100): array
    {
        Validator::make(['chunk_size' => $chunkSize], [
            'chunk_size' => ['required', 'integer', 'between:1,500'],
        ])->validate();
        $company = Company::query()->whereKey($company->getKey())->where('status', CompanyStatus::ACTIVE)->first();
        if ($company === null) {
            throw ValidationException::withMessages(['company' => __('An active company context is required.')]);
        }
        Validator::make(['timezone' => $company->timezone], ['timezone' => ['required', 'timezone']])->validate();
        $date = $serviceDate->toDateString();
        $localDate = CarbonImmutable::parse($date, $company->timezone);
        $result = ['service_date' => $date, 'created' => [], 'skipped' => [], 'failed' => []];

        TripAssignment::query()
            ->select('id')
            ->where('company_id', $company->getKey())
            ->where('status', TripAssignmentStatus::ACTIVE)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $date))
            ->whereHas('schedule', fn ($query) => $query->scheduledOn($localDate))
            ->orderBy('id')
            ->chunkById($chunkSize, function ($assignments) use ($company, $date, $localDate, &$result): void {
                foreach ($assignments as $assignment) {
                    try {
                        $outcome = $this->materializeOne($company, $assignment->getKey(), $date, $localDate);
                        $result[$outcome['status']][] = $outcome['value'];
                    } catch (ValidationException $exception) {
                        $result['failed'][] = [
                            'assignment_id' => $assignment->getKey(),
                            'errors' => $exception->errors(),
                        ];
                    } catch (ModelNotFoundException) {
                        $result['failed'][] = [
                            'assignment_id' => $assignment->getKey(),
                            'errors' => ['assignment' => [__('The recurring assignment became unavailable during materialization.')]],
                        ];
                    } catch (QueryException $exception) {
                        if (! $this->isMaterializationUniquenessConflict($exception)) {
                            throw $exception;
                        }
                        $result['skipped'][] = [
                            'assignment_id' => $assignment->getKey(),
                            'reason' => 'already_materialized',
                        ];
                    }
                }
            });

        return $result;
    }

    /** @return array{status: 'created'|'skipped', value: array<string, string>} */
    private function materializeOne(
        Company $company,
        string $assignmentId,
        string $date,
        CarbonImmutable $localDate,
    ): array {
        return DB::connection('pgsql')->transaction(function () use ($company, $assignmentId, $date, $localDate): array {
            $company = Company::query()->whereKey($company->getKey())->where('status', CompanyStatus::ACTIVE)
                ->sharedLock()->firstOrFail();
            $assignment = TripAssignment::query()->where('company_id', $company->getKey())
                ->where('status', TripAssignmentStatus::ACTIVE)->whereKey($assignmentId)->lockForUpdate()->firstOrFail();
            if (($assignment->valid_from !== null && $assignment->valid_from->toDateString() > $date)
                || ($assignment->valid_until !== null && $assignment->valid_until->toDateString() < $date)) {
                throw (new ModelNotFoundException)->setModel(TripAssignment::class, [$assignmentId]);
            }
            $schedule = RouteSchedule::query()->whereKey($assignment->route_schedule_id)
                ->scheduledOn($localDate)->firstOrFail();

            if (Trip::withTrashed()->where('route_schedule_id', $schedule->getKey())
                ->where('service_date', $date)->exists()) {
                return [
                    'status' => 'skipped',
                    'value' => ['assignment_id' => $assignmentId, 'reason' => 'already_materialized'],
                ];
            }

            $identity = Driver::withTrashed()->whereKey($assignment->driver_id)->firstOrFail(['id', 'user_id']);
            $this->reservations->lockPeople([$identity->user_id]);
            $bus = Bus::withTrashed()->whereKey($assignment->bus_id)->lockForUpdate()->firstOrFail();
            $driver = Driver::withTrashed()->whereKey($assignment->driver_id)->lockForUpdate()->firstOrFail();
            $schedule = RouteSchedule::query()->whereKey($schedule->getKey())
                ->scheduledOn($localDate)->lockForUpdate()->firstOrFail();
            if ($bus->trashed() || ! $bus->is_active || (string) $bus->company_id !== (string) $company->getKey()) {
                throw ValidationException::withMessages(['bus_id' => __('The selected bus is unavailable.')]);
            }

            $departure = $this->resolveLocal($date, $schedule->departure_time, $company->timezone, 'service_date');
            $arrivalDate = $assignment->ends_next_day ? $localDate->addDay()->toDateString() : $date;
            $arrival = $this->resolveLocal(
                $arrivalDate,
                $assignment->planned_arrival_time,
                $company->timezone,
                'arrival_at',
            );
            $plan = $this->snapshots->prepare($company, [
                'route_pattern_id' => $schedule->route_pattern_id,
                'route_schedule_id' => $schedule->getKey(),
                'bus_id' => $bus->getKey(),
                'service_date' => $date,
                'arrival_at' => $arrival->setTimezone($company->timezone)->format('Y-m-d\TH:i:sP'),
                'departure_utc_offset' => $departure->setTimezone($company->timezone)->format('P'),
            ]);
            $driver = $this->eligibility->validateForPlan($company, $driver->getKey(), $plan);
            $this->reservations->ensurePersonAvailable($driver, $plan['departure_at'], $plan['arrival_at'], null);

            $trip = Trip::query()->create([
                'company_id' => $company->getKey(),
                'route_pattern_id' => $plan['pattern']->getKey(),
                'route_schedule_id' => $plan['schedule']->getKey(),
                'bus_id' => $bus->getKey(),
                'driver_id' => $driver->getKey(),
                'service_date' => $date,
                'scheduled_departure_at' => $plan['departure_at'],
                'scheduled_arrival_at' => $plan['arrival_at'],
                'planned_route_snapshot' => $plan['planned_route_snapshot'],
            ]);

            return [
                'status' => 'created',
                'value' => ['assignment_id' => $assignmentId, 'trip_id' => $trip->getKey()],
            ];
        });
    }

    private function resolveLocal(string $date, string $time, string $timezone, string $field): CarbonImmutable
    {
        $local = $date.' '.$time;
        $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $local, 'UTC');
        $zone = new DateTimeZone($timezone);
        $transitions = $zone->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800);
        $candidates = [];
        foreach (array_unique(array_column($transitions ?: [], 'offset')) as $offset) {
            $candidate = $wall->subSeconds($offset);
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i:s') === $local) {
                $candidates[] = $candidate;
            }
        }
        if ($candidates === []) {
            throw ValidationException::withMessages([
                $field => __('The scheduled local time does not exist in the company timezone.'),
            ]);
        }
        usort(
            $candidates,
            fn (CarbonImmutable $first, CarbonImmutable $second): int => $first->getTimestamp() <=> $second->getTimestamp(),
        );

        return $candidates[0]->utc();
    }

    private function isMaterializationUniquenessConflict(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505'
            && str_contains($exception->errorInfo[2] ?? '', 'trips_schedule_service_date_unique');
    }
}
