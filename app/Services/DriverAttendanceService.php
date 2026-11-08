<?php

namespace App\Services;

use App\Enums\TripStatus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class DriverAttendanceService
{
    /**
     * Return read-only operational activity grouped by the Trip service date.
     *
     * This report describes persisted transportation records; it is not proof
     * of physical attendance, payroll eligibility, punctuality or absence. The
     * inclusive input instants are converted to calendar dates in the company
     * timezone. Every date is returned, including dates without Trips.
     *
     * `assigned_count` includes every recorded Trip. `started_count` counts a
     * real departure timestamp and therefore overlaps completed/in-progress
     * status counts. Pending, in-progress, completed and cancelled counts are
     * derived only from the current Trip status. First departure and last
     * arrival use real timestamps only. All timestamps are exact UTC ISO-8601
     * values; `company_timezone` supplies the local reporting context.
     *
     * @return array{
     *     company_timezone: string,
     *     from_date: string,
     *     until_date: string,
     *     days: list<array{
     *         service_date: string,
     *         assigned_count: int,
     *         started_count: int,
     *         completed_count: int,
     *         cancelled_count: int,
     *         pending_count: int,
     *         in_progress_count: int,
     *         first_departure_at: string|null,
     *         last_arrival_at: string|null,
     *         trips: list<array{
     *             id: string,
     *             service_date: string,
     *             route_schedule_id: string,
     *             bus_id: string,
     *             status: string,
     *             pattern_code: string|null,
     *             pattern_name: string|null,
     *             scheduled_departure_at: string,
     *             scheduled_arrival_at: string,
     *             actual_departure_at: string|null,
     *             actual_arrival_at: string|null,
     *             cancelled_at: string|null
     *         }>
     *     }>
     * }
     */
    public function summarize(
        User $actor,
        Company $company,
        Driver $driver,
        CarbonImmutable $fromDate,
        CarbonImmutable $untilDate,
    ): array {
        Gate::forUser($actor)->authorize('viewAttendance', [Driver::class, $company, $driver]);
        Validator::make(['timezone' => $company->timezone], ['timezone' => ['required', 'timezone']])->validate();

        $from = $fromDate->setTimezone($company->timezone)->startOfDay();
        $until = $untilDate->setTimezone($company->timezone)->startOfDay();
        Validator::make([
            'from_date' => $from->toDateString(),
            'until_date' => $until->toDateString(),
        ], [
            'from_date' => ['required', 'date_format:Y-m-d'],
            'until_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ])->validate();

        $persistedDriver = Driver::withTrashed()->where('company_id', $company->getKey())
            ->findOrFail($driver->getKey(), ['id', 'company_id']);
        $trips = Trip::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('driver_id', $persistedDriver->getKey())
            ->whereBetween('service_date', [$from->toDateString(), $until->toDateString()])
            ->orderBy('service_date')
            ->orderBy('scheduled_departure_at')
            ->orderBy('id')
            ->get([
                'id', 'service_date', 'route_schedule_id', 'bus_id', 'status', 'planned_route_snapshot',
                'scheduled_departure_at', 'scheduled_arrival_at', 'actual_departure_at',
                'actual_arrival_at', 'cancelled_at',
            ])->groupBy(fn (Trip $trip): string => $trip->service_date->toDateString());

        $days = [];
        for ($date = $from; $date <= $until; $date = $date->addDay()) {
            $serviceDate = $date->toDateString();
            $days[] = $this->summarizeDay($serviceDate, $trips->get($serviceDate, collect()));
        }

        return [
            'company_timezone' => $company->timezone,
            'from_date' => $from->toDateString(),
            'until_date' => $until->toDateString(),
            'days' => $days,
        ];
    }

    /**
     * @param  Collection<int, Trip>  $trips
     * @return array<string, mixed>
     */
    private function summarizeDay(string $serviceDate, Collection $trips): array
    {
        $summary = [
            'service_date' => $serviceDate,
            'assigned_count' => $trips->count(),
            'started_count' => 0,
            'completed_count' => 0,
            'cancelled_count' => 0,
            'pending_count' => 0,
            'in_progress_count' => 0,
            'first_departure_at' => null,
            'last_arrival_at' => null,
            'trips' => [],
        ];
        $firstDeparture = null;
        $lastArrival = null;

        foreach ($trips as $trip) {
            if ($trip->actual_departure_at !== null) {
                $summary['started_count']++;
                $firstDeparture = $firstDeparture === null || $trip->actual_departure_at < $firstDeparture
                    ? $trip->actual_departure_at : $firstDeparture;
            }
            if ($trip->actual_arrival_at !== null) {
                $lastArrival = $lastArrival === null || $trip->actual_arrival_at > $lastArrival
                    ? $trip->actual_arrival_at : $lastArrival;
            }

            match ($trip->status) {
                TripStatus::SCHEDULED => $summary['pending_count']++,
                TripStatus::IN_PROGRESS => $summary['in_progress_count']++,
                TripStatus::COMPLETED => $summary['completed_count']++,
                TripStatus::CANCELLED => $summary['cancelled_count']++,
            };
            $summary['trips'][] = $this->projectTrip($trip);
        }

        $summary['first_departure_at'] = $this->timestamp($firstDeparture);
        $summary['last_arrival_at'] = $this->timestamp($lastArrival);

        return $summary;
    }

    /** @return array<string, mixed> */
    private function projectTrip(Trip $trip): array
    {
        $snapshot = $trip->planned_route_snapshot;

        return [
            'id' => $trip->getKey(),
            'service_date' => $trip->service_date->toDateString(),
            'route_schedule_id' => $trip->route_schedule_id,
            'bus_id' => $trip->bus_id,
            'status' => $trip->status->value,
            'pattern_code' => $snapshot['pattern_code'] ?? null,
            'pattern_name' => $snapshot['pattern_name'] ?? null,
            'scheduled_departure_at' => $this->timestamp($trip->scheduled_departure_at),
            'scheduled_arrival_at' => $this->timestamp($trip->scheduled_arrival_at),
            'actual_departure_at' => $this->timestamp($trip->actual_departure_at),
            'actual_arrival_at' => $this->timestamp($trip->actual_arrival_at),
            'cancelled_at' => $this->timestamp($trip->cancelled_at),
        ];
    }

    private function timestamp(?CarbonImmutable $timestamp): ?string
    {
        return $timestamp?->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
