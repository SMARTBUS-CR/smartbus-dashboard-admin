<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\TripAssignmentStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\RouteSchedule;
use App\Models\TripAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TripAssignmentManagementService
{
    public function __construct(
        private readonly TripDriverEligibilityService $eligibility,
        private readonly DriverAvailabilityService $availability,
        private readonly TripReservationService $reservations,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, Company $company, array $data): TripAssignment
    {
        return $this->persist($actor, $company, null, $data);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Company $company, TripAssignment $assignment, array $data): TripAssignment
    {
        return $this->persist($actor, $company, $assignment, $data);
    }

    public function setActive(User $actor, Company $company, TripAssignment $assignment, bool $active): TripAssignment
    {
        return $this->withLockedRecord($actor, $company, $assignment, 'update', function (Company $company, TripAssignment $assignment) use ($active): TripAssignment {
            if ($assignment->trashed()) {
                $this->reject('assignment', 'Restore the archived recurring assignment before changing it.');
            }

            $assignment->status = $active ? TripAssignmentStatus::ACTIVE : TripAssignmentStatus::INACTIVE;
            if ($active) {
                $this->lockAndValidateCandidate($company, $assignment);
            }
            $assignment->save();

            return $assignment->refresh();
        });
    }

    public function archive(User $actor, Company $company, TripAssignment $assignment): void
    {
        $this->withLockedRecord($actor, $company, $assignment, 'delete', function (Company $company, TripAssignment $assignment): TripAssignment {
            if (! $assignment->trashed()) {
                $assignment->delete();
            }

            return $assignment;
        });
    }

    public function restore(User $actor, Company $company, TripAssignment $assignment): TripAssignment
    {
        return $this->withLockedRecord($actor, $company, $assignment, 'restore', function (Company $company, TripAssignment $assignment): TripAssignment {
            if ($assignment->status === TripAssignmentStatus::ACTIVE) {
                $this->lockAndValidateCandidate($company, $assignment);
            }
            if ($assignment->trashed()) {
                $assignment->restore();
            }

            return $assignment->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    private function persist(User $actor, Company $company, ?TripAssignment $requested, array $data): TripAssignment
    {
        $previousTeam = getPermissionsTeamId();
        $persistedActor = null;

        try {
            $persistedActor = User::query()->find($actor->getKey());
            if ($persistedActor === null) {
                throw new AuthorizationException;
            }
            setPermissionsTeamId($company->getKey());
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $persistedActor->unsetRelation('roles')->unsetRelation('permissions');

            return DB::connection('pgsql')->transaction(function () use ($persistedActor, $company, $requested, $data): TripAssignment {
                $company = Company::query()->whereKey($company->getKey())->where('status', CompanyStatus::ACTIVE)
                    ->sharedLock()->firstOrFail();
                $assignment = $requested === null ? null : TripAssignment::withTrashed()
                    ->where('company_id', $company->getKey())->whereKey($requested->getKey())->lockForUpdate()->firstOrFail();
                $ability = $assignment === null ? 'create' : 'update';
                Gate::forUser($persistedActor)->authorize($ability, [TripAssignment::class, $company, ...($assignment === null ? [] : [$assignment])]);
                if ($assignment?->trashed()) {
                    $this->reject('assignment', 'Restore the archived recurring assignment before changing it.');
                }

                $values = $this->validatedValues($data, $assignment);
                $candidate = $assignment ?? new TripAssignment(['company_id' => $company->getKey()]);
                $candidate->fill($values);
                $candidate->company_id = $company->getKey();
                $candidate->status ??= TripAssignmentStatus::ACTIVE;
                $this->lockAndValidateCandidate($company, $candidate, $assignment);
                $candidate->save();

                return $candidate->refresh();
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $persistedActor?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /** @param Closure(Company, TripAssignment): TripAssignment $operation */
    private function withLockedRecord(
        User $actor,
        Company $company,
        TripAssignment $requested,
        string $ability,
        Closure $operation,
    ): TripAssignment {
        $previousTeam = getPermissionsTeamId();
        $persistedActor = null;

        try {
            $persistedActor = User::query()->find($actor->getKey());
            if ($persistedActor === null) {
                throw new AuthorizationException;
            }
            setPermissionsTeamId($company->getKey());
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $persistedActor->unsetRelation('roles')->unsetRelation('permissions');

            return DB::connection('pgsql')->transaction(function () use ($persistedActor, $company, $requested, $ability, $operation): TripAssignment {
                $company = Company::query()->whereKey($company->getKey())->where('status', CompanyStatus::ACTIVE)
                    ->sharedLock()->firstOrFail();
                $assignment = TripAssignment::withTrashed()->where('company_id', $company->getKey())
                    ->whereKey($requested->getKey())->lockForUpdate()->firstOrFail();
                Gate::forUser($persistedActor)->authorize($ability, [TripAssignment::class, $company, $assignment]);

                return $operation($company, $assignment);
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $persistedActor?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validatedValues(array $data, ?TripAssignment $assignment): array
    {
        $allowed = [
            'route_schedule_id', 'driver_id', 'bus_id', 'planned_arrival_time',
            'ends_next_day', 'valid_from', 'valid_until',
        ];
        $unexpected = array_diff(array_keys($data), $allowed);
        if ($unexpected !== []) {
            throw ValidationException::withMessages(array_fill_keys(
                $unexpected,
                __('This field cannot be changed through recurring assignment operations.'),
            ));
        }

        $stored = $assignment === null ? [] : Arr::only($assignment->getRawOriginal(), $allowed);
        $values = array_replace($stored, $data);
        Validator::make($values, [
            'route_schedule_id' => ['required', 'uuid'],
            'driver_id' => ['required', 'uuid'],
            'bus_id' => ['required', 'uuid'],
            'planned_arrival_time' => ['required', 'string', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/'],
            'ends_next_day' => ['required', 'boolean'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ])->validate();
        if (strlen($values['planned_arrival_time']) === 5) {
            $values['planned_arrival_time'] .= ':00';
        }

        return $values;
    }

    private function lockAndValidateCandidate(
        Company $company,
        TripAssignment $candidate,
        ?TripAssignment $original = null,
    ): void {
        $driverIds = array_values(array_unique(array_filter([$original?->driver_id, $candidate->driver_id])));
        $identities = Driver::withTrashed()->whereIn('id', $driverIds)->get(['id', 'user_id']);
        $this->reservations->lockPeople($identities->pluck('user_id')->all());

        Bus::withTrashed()->whereIn('id', array_values(array_unique(array_filter([$original?->bus_id, $candidate->bus_id]))))
            ->orderBy('id')->lockForUpdate()->get();
        Driver::withTrashed()->whereIn('id', $driverIds)->orderBy('id')->lockForUpdate()->get();
        RouteSchedule::query()->whereIn('id', array_values(array_unique(array_filter([
            $original?->route_schedule_id,
            $candidate->route_schedule_id,
        ]))))->orderBy('id')->lockForUpdate()->get();

        $bus = Bus::query()->where('company_id', $company->getKey())->whereKey($candidate->bus_id)->first();
        if ($bus === null || ! $bus->is_active) {
            $this->reject('bus_id', 'The selected bus is unavailable.');
        }
        $driver = Driver::query()->where('company_id', $company->getKey())->whereKey($candidate->driver_id)->first();
        if ($driver === null) {
            $this->reject('driver_id', 'The selected driver is unavailable or ineligible for the planned dates.');
        }
        $schedule = RouteSchedule::query()->with('pattern.route')->whereKey($candidate->route_schedule_id)->first();
        if ($schedule === null || $schedule->pattern === null || $schedule->pattern->route === null
            || (string) $schedule->pattern->route->company_id !== (string) $company->getKey()) {
            $this->reject('route_schedule_id', 'The selected recurring departure is unavailable for this company.');
        }

        $this->validateInterval($candidate, $schedule);
        [$from, $until] = $this->effectiveBounds($candidate, $schedule);
        $dates = $this->sampleOccurrenceDates($company, $driver, $schedule, $from, $until);
        if (! $this->eligibility->isEligibleForDates($company, $driver, $dates)) {
            $this->reject('driver_id', 'The selected driver is unavailable or ineligible for the planned dates.');
        }
        foreach ($dates as $date) {
            [$start, $end] = $this->utcWindow($company, $schedule, $candidate, $date);
            if (! $this->availability->covers($driver, $start, $end)) {
                $this->reject('availability', 'The selected driver does not cover the complete recurring interval.');
            }
        }

        if ($candidate->status === TripAssignmentStatus::ACTIVE) {
            $this->ensureNoConflict($company, $candidate, $schedule);
        }
    }

    private function validateInterval(TripAssignment $candidate, RouteSchedule $schedule): void
    {
        $arrival = $candidate->planned_arrival_time;
        $departure = $schedule->departure_time;
        if ((! $candidate->ends_next_day && $arrival <= $departure)
            || ($candidate->ends_next_day && $arrival > $departure)) {
            $this->reject('planned_arrival_time', 'The planned interval does not match its overnight flag.');
        }
    }

    /** @return array{string|null, string|null} */
    private function effectiveBounds(TripAssignment $assignment, RouteSchedule $schedule): array
    {
        $attributes = $assignment->getAttributes();
        $from = $this->laterDate($attributes['valid_from'] ?? null, $schedule->getRawOriginal('valid_from'));
        $until = $this->earlierDate($attributes['valid_until'] ?? null, $schedule->getRawOriginal('valid_until'));
        if ($from !== null && $until !== null && $from > $until) {
            $this->reject('valid_from', 'The assignment validity does not include an active recurring departure.');
        }

        return [$from, $until];
    }

    /** @return list<string> */
    private function sampleOccurrenceDates(
        Company $company,
        Driver $driver,
        RouteSchedule $schedule,
        ?string $from,
        ?string $until,
    ): array {
        $lower = CarbonImmutable::parse($from ?? CarbonImmutable::now($company->timezone)->toDateString(), $company->timezone);
        $upper = $until === null ? null : CarbonImmutable::parse($until, $company->timezone);
        $dates = [];
        $addOccurrence = function (CarbonImmutable $boundary) use (&$dates, $schedule, $lower, $upper): void {
            $date = $boundary->addDays(($schedule->day_of_week - $boundary->dayOfWeek + 7) % 7);
            if ($date >= $lower && ($upper === null || $date <= $upper)) {
                $dates[$date->toDateString()] = true;
            }
        };
        $addOccurrence($lower);
        if ($upper !== null) {
            $last = $upper->subDays(($upper->dayOfWeek - $schedule->day_of_week + 7) % 7);
            if ($last >= $lower) {
                $dates[$last->toDateString()] = true;
            }
        }
        foreach (DriverSchedule::query()->where('driver_id', $driver->getKey())->get(['valid_from', 'valid_until']) as $availability) {
            foreach (array_filter([
                $availability->valid_from,
                $availability->valid_until?->addDay(),
            ]) as $boundary) {
                $addOccurrence(CarbonImmutable::parse($boundary->toDateString(), $company->timezone));
            }
        }
        if ($dates === []) {
            $this->reject('valid_from', 'The assignment validity does not include an active recurring departure.');
        }

        return array_keys($dates);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function utcWindow(
        Company $company,
        RouteSchedule $schedule,
        TripAssignment $assignment,
        string $date,
    ): array {
        $start = CarbonImmutable::parse($date.' '.$schedule->departure_time, $company->timezone);
        $endDate = $assignment->ends_next_day ? $start->addDay()->toDateString() : $date;
        $end = CarbonImmutable::parse($endDate.' '.$assignment->planned_arrival_time, $company->timezone);

        return [$start->utc(), $end->utc()];
    }

    private function ensureNoConflict(Company $company, TripAssignment $candidate, RouteSchedule $schedule): void
    {
        $existingAssignments = TripAssignment::query()->with('schedule')->where('company_id', $company->getKey())
            ->where('status', TripAssignmentStatus::ACTIVE)
            ->whereKeyNot($candidate->getKey())
            ->where(function (Builder $query) use ($candidate): void {
                $query->where('route_schedule_id', $candidate->route_schedule_id)
                    ->orWhere('driver_id', $candidate->driver_id)
                    ->orWhere('bus_id', $candidate->bus_id);
            })->orderBy('id')->lockForUpdate()->get();

        foreach ($existingAssignments as $existing) {
            if (! $this->recurringIntervalsOverlap($candidate, $schedule, $existing, $existing->schedule, $company->timezone)) {
                continue;
            }
            $field = match (true) {
                $existing->route_schedule_id === $candidate->route_schedule_id => 'route_schedule_id',
                $existing->driver_id === $candidate->driver_id => 'driver_id',
                default => 'bus_id',
            };
            $this->reject($field, 'This recurring assignment conflicts with an existing active assignment.');
        }
    }

    private function recurringIntervalsOverlap(
        TripAssignment $first,
        RouteSchedule $firstSchedule,
        TripAssignment $second,
        RouteSchedule $secondSchedule,
        string $timezone,
    ): bool {
        [$firstFrom, $firstUntil] = $this->effectiveBounds($first, $firstSchedule);
        [$secondFrom, $secondUntil] = $this->effectiveBounds($second, $secondSchedule);
        $firstStart = $this->seconds($firstSchedule->departure_time);
        $firstEnd = $this->seconds($first->planned_arrival_time) + ($first->ends_next_day ? 86400 : 0);
        foreach ([-7, 0, 7] as $weekOffset) {
            $dayOffset = $secondSchedule->day_of_week - $firstSchedule->day_of_week + $weekOffset;
            if ($dayOffset < -1 || $dayOffset > 1) {
                continue;
            }
            $secondStart = $dayOffset * 86400 + $this->seconds($secondSchedule->departure_time);
            $secondEnd = $dayOffset * 86400 + $this->seconds($second->planned_arrival_time)
                + ($second->ends_next_day ? 86400 : 0);
            if ($firstStart < $secondEnd && $secondStart < $firstEnd
                && $this->validitySharesOccurrence(
                    $firstFrom,
                    $firstUntil,
                    $secondFrom,
                    $secondUntil,
                    $dayOffset,
                    $firstSchedule->day_of_week,
                    $timezone,
                )) {
                return true;
            }
        }

        return false;
    }

    private function validitySharesOccurrence(
        ?string $firstFrom,
        ?string $firstUntil,
        ?string $secondFrom,
        ?string $secondUntil,
        int $secondDayOffset,
        int $firstWeekday,
        string $timezone,
    ): bool {
        $lower = $firstFrom === null ? null : CarbonImmutable::parse($firstFrom, $timezone);
        $upper = $firstUntil === null ? null : CarbonImmutable::parse($firstUntil, $timezone);
        if ($secondFrom !== null) {
            $date = CarbonImmutable::parse($secondFrom, $timezone)->subDays($secondDayOffset);
            $lower = $lower === null || $date > $lower ? $date : $lower;
        }
        if ($secondUntil !== null) {
            $date = CarbonImmutable::parse($secondUntil, $timezone)->subDays($secondDayOffset);
            $upper = $upper === null || $date < $upper ? $date : $upper;
        }
        if ($lower === null || $upper === null) {
            return true;
        }

        return $lower->addDays(($firstWeekday - $lower->dayOfWeek + 7) % 7) <= $upper;
    }

    private function seconds(string $time): int
    {
        [$hours, $minutes, $seconds] = array_map('intval', explode(':', $time));

        return $hours * 3600 + $minutes * 60 + $seconds;
    }

    private function laterDate(?string $first, ?string $second): ?string
    {
        return match (true) {
            $first === null => $second,
            $second === null => $first,
            default => max($first, $second),
        };
    }

    private function earlierDate(?string $first, ?string $second): ?string
    {
        return match (true) {
            $first === null => $second,
            $second === null => $first,
            default => min($first, $second),
        };
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
