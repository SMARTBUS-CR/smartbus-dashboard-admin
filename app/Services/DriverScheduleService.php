<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DriverScheduleService
{
    /** @param array<string, mixed> $data */
    public function create(User $actor, Company $company, Driver $driver, array $data): DriverSchedule
    {
        return $this->withLockedDriver($actor, $company, $driver, 'create', null, function (Company $company, Driver $driver) use ($data): DriverSchedule {
            $this->validatePayload($data);
            $schedule = new DriverSchedule($data);
            $schedule->driver_id = $driver->getKey();
            $this->saveSchedule($company, $driver, $schedule);

            return $schedule;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Company $company, Driver $driver, DriverSchedule $schedule, array $data): DriverSchedule
    {
        return $this->withLockedDriver($actor, $company, $driver, 'update', $schedule, function (Company $company, Driver $driver, DriverSchedule $schedule) use ($data): DriverSchedule {
            $this->validatePayload($data);
            $schedule->fill($data);
            $this->saveSchedule($company, $driver, $schedule);

            return $schedule;
        });
    }

    public function delete(User $actor, Company $company, Driver $driver, DriverSchedule $schedule): void
    {
        $this->withLockedDriver($actor, $company, $driver, 'delete', $schedule, function (Company $company, Driver $driver, DriverSchedule $schedule): DriverSchedule {
            $this->ensureScheduleOperationAllowed($company, $driver, $schedule, 'delete');
            if (! $schedule->delete()) {
                throw ValidationException::withMessages(['schedule' => __('The availability operation was cancelled.')]);
            }

            return $schedule;
        });
    }

    /** Subtask 09 will add checks against the real committed Trip assignments. */
    protected function ensureScheduleOperationAllowed(Company $company, Driver $driver, DriverSchedule $schedule, string $operation): void {}

    /** @param Closure(Company, Driver, ?DriverSchedule): DriverSchedule $operation */
    private function withLockedDriver(User $actor, Company $company, Driver $driver, string $ability, ?DriverSchedule $schedule, Closure $operation): DriverSchedule
    {
        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId($company->getKey());
            $actor->unsetRelation('roles')->unsetRelation('permissions');

            return DB::connection('pgsql')->transaction(function () use ($actor, $company, $driver, $ability, $schedule, $operation): DriverSchedule {
                $company = Company::query()->whereKey($company->getKey())->sharedLock()->firstOrFail();
                $driver = Driver::withTrashed()->where('company_id', $company->getKey())->whereKey($driver->getKey())->lockForUpdate()->firstOrFail();
                $record = $schedule === null ? null : DriverSchedule::query()->where('driver_id', $driver->getKey())->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize($ability, [DriverSchedule::class, $company, $record ?? $driver]);
                if ($driver->trashed()) {
                    throw ValidationException::withMessages(['driver' => __('Restore the archived driver before changing employment.')]);
                }
                Validator::make(['timezone' => $company->timezone], ['timezone' => ['required', 'timezone']])->validate();

                return $operation($company, $driver, $record);
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /** @param array<string, mixed> $data */
    private function validatePayload(array $data): void
    {
        $allowed = ['day_of_week', 'start_time', 'end_time', 'ends_next_day', 'valid_from', 'valid_until'];
        $errors = [];
        foreach (array_diff(array_keys($data), $allowed) as $field) {
            $errors[$field] = __('This field cannot be changed through availability operations.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function saveSchedule(Company $company, Driver $driver, DriverSchedule $schedule): void
    {
        $schedule->validateValues();
        foreach (DriverSchedule::query()->where('driver_id', $driver->getKey())->orderBy('id')->get() as $existing) {
            if ($existing->getKey() !== $schedule->getKey() && $this->overlaps($schedule, $existing, $company->timezone)) {
                throw ValidationException::withMessages(['schedule' => __('This availability overlaps an existing schedule for the driver.')]);
            }
        }
        $this->ensureScheduleOperationAllowed($company, $driver, $schedule, $schedule->exists ? 'update' : 'create');
        if (! $schedule->save()) {
            throw ValidationException::withMessages(['schedule' => __('The availability operation was cancelled.')]);
        }
    }

    private function overlaps(DriverSchedule $first, DriverSchedule $second, string $timezone): bool
    {
        $firstStart = $this->seconds($first->start_time);
        $firstEnd = $this->seconds($first->end_time) + ($first->ends_next_day ? 86400 : 0);
        foreach ([-7, 0, 7] as $weekOffset) {
            $dayOffset = $second->day_of_week - $first->day_of_week + $weekOffset;
            if ($dayOffset < -1 || $dayOffset > 1) {
                continue;
            }
            $secondStart = $dayOffset * 86400 + $this->seconds($second->start_time);
            $secondEnd = $dayOffset * 86400 + $this->seconds($second->end_time) + ($second->ends_next_day ? 86400 : 0);
            if ($firstStart < $secondEnd && $secondStart < $firstEnd
                && $this->hasSharedStartingDay($first, $second, $dayOffset, $timezone)) {
                return true;
            }
        }

        return false;
    }

    private function seconds(string $time): int
    {
        [$hours, $minutes, $seconds] = array_map('intval', explode(':', $time));

        return $hours * 3600 + $minutes * 60 + $seconds;
    }

    /** Compare validity in the first schedule's starting-day frame, including overnight spillover. */
    private function hasSharedStartingDay(DriverSchedule $first, DriverSchedule $second, int $dayOffset, string $timezone): bool
    {
        $lower = null;
        $upper = null;
        foreach ([[$first, 0], [$second, $dayOffset]] as [$schedule, $offset]) {
            if ($schedule->valid_from !== null) {
                $date = CarbonImmutable::parse($schedule->valid_from->toDateString(), $timezone)->subDays($offset);
                $lower = $lower === null || $date->greaterThan($lower) ? $date : $lower;
            }
            if ($schedule->valid_until !== null) {
                $date = CarbonImmutable::parse($schedule->valid_until->toDateString(), $timezone)->subDays($offset);
                $upper = $upper === null || $date->lessThan($upper) ? $date : $upper;
            }
        }
        if ($lower === null || $upper === null) {
            return true;
        }

        return $lower->addDays(($first->day_of_week - $lower->dayOfWeek + 7) % 7)->lessThanOrEqualTo($upper);
    }
}
