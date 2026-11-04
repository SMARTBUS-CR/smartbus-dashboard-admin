<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TripReservationService
{
    /** @param list<string> $userIds */
    public function lockPeople(array $userIds): void
    {
        $connection = DB::connection('pgsql');
        if ($connection->transactionLevel() === 0) {
            throw new \LogicException('Person reservation locks require a transaction.');
        }
        $keys = $connection->select(
            "SELECT DISTINCT hashtextextended('smartbus:trip-user:v1:' || lower(value), 0) AS key FROM jsonb_array_elements_text(CAST(? AS jsonb)) ORDER BY key",
            [json_encode($userIds, JSON_THROW_ON_ERROR)],
        );
        foreach ($keys as $key) {
            $connection->selectOne('SELECT pg_advisory_xact_lock(CAST(? AS bigint))', [$key->key]);
        }
    }

    public function ensurePersonAvailable(Driver $driver, CarbonImmutable $start, CarbonImmutable $end, ?string $exceptTripId): void
    {
        $conflict = Trip::withTrashed()->whereIn('driver_id', Driver::withTrashed()->select('id')
            ->where('user_id', $driver->user_id)->whereKeyNot($driver->getKey()))
            ->whereIn('status', [TripStatus::SCHEDULED, TripStatus::IN_PROGRESS])
            ->when($exceptTripId !== null, fn (Builder $query) => $query->whereKeyNot($exceptTripId))
            ->where('scheduled_departure_at', '<', $end->format('Y-m-d H:i:s.uP'))
            ->where('scheduled_arrival_at', '>', $start->format('Y-m-d H:i:s.uP'))->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['driver_id' => __('The selected departure or resource conflicts with an existing trip.')]);
        }
    }

    public function ensureEmploymentAllowed(Company $company, Driver $driver, string $operation): void
    {
        if ($operation === 'restore' || ($operation === 'setActive' && $driver->status === DriverStatus::ACTIVE)
            || ($operation === 'updateEmployment' && ! $driver->isDirty(['employment_starts_on', 'employment_ends_on', 'license_expires_at']))) {
            return;
        }
        foreach ($this->assignments($driver)->get() as $trip) {
            if ($operation === 'archive' || $driver->status !== DriverStatus::ACTIVE) {
                $this->rejectAssignment();
            }
            $end = $trip->scheduled_arrival_at->setTimezone($company->timezone);
            for ($day = $trip->scheduled_departure_at->setTimezone($company->timezone)->startOfDay(); $day < $end; $day = $day->addDay()) {
                $date = $day->toDateString();
                if ($driver->employment_starts_on->toDateString() > $date
                    || ($driver->employment_ends_on !== null && $driver->employment_ends_on->toDateString() < $date)
                    || $driver->license_expires_at->toDateString() < $date) {
                    $this->rejectAssignment();
                }
            }
        }
    }

    /** Check persisted proposed changes inside the transaction; rejection rolls them back. */
    public function ensureAvailabilityAllowed(Driver $driver): void
    {
        foreach ($this->assignments($driver)->get() as $trip) {
            if (! app(DriverAvailabilityService::class)->covers($driver, $trip->scheduled_departure_at->utc(), $trip->scheduled_arrival_at->utc())) {
                $this->rejectAssignment();
            }
        }
    }

    /** @return Builder<Trip> */
    private function assignments(Driver $driver): Builder
    {
        return Trip::withTrashed()->select(['id', 'scheduled_departure_at', 'scheduled_arrival_at'])
            ->where('driver_id', $driver->getKey())->whereIn('status', [TripStatus::SCHEDULED, TripStatus::IN_PROGRESS]);
    }

    private function rejectAssignment(): never
    {
        throw ValidationException::withMessages(['driver' => __('This change would invalidate an assigned trip.')]);
    }
}
