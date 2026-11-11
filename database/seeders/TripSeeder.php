<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\RouteSchedule;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripDriverEligibilityService;
use App\Services\TripPlanSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TripSeeder extends Seeder
{
    /**
     * Development fixtures for fixed local Mondays, with explicit one-hour
     * planned window. Reuse the dedicated DEMO-CITY/OUTBOUND Monday 09:00
     * schedule, the first active demo bus by ID, and the existing user@driver.com
     * driver. Missing or conflicting resources skip insertion. Execution examples
     * use deterministic fixture timestamps. Never create identities, grant
     * permissions or update existing trips.
     */
    public function run(TripPlanSnapshotService $snapshots, TripDriverEligibilityService $eligibility): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('TripSeeder skipped in production.');

            return;
        }
        foreach ([
            ['b0700000-0000-4000-8000-000000000001', '2026-11-02', TripStatus::SCHEDULED],
            ['b0700000-0000-4000-8000-000000000002', '2026-11-09', TripStatus::IN_PROGRESS],
            ['b0700000-0000-4000-8000-000000000003', '2026-11-16', TripStatus::COMPLETED],
            ['b0700000-0000-4000-8000-000000000004', '2026-11-23', TripStatus::CANCELLED],
        ] as [$id, $serviceDate, $status]) {
            $this->seedTrip($snapshots, $eligibility, $id, $serviceDate, $status);
        }
    }

    private function seedTrip(TripPlanSnapshotService $snapshots, TripDriverEligibilityService $eligibility, string $id, string $serviceDate, TripStatus $status): void
    {
        if (Trip::query()->whereKey($id)->exists()) {
            return;
        }

        try {
            $created = (new Trip)->getConnection()->transaction(function () use ($snapshots, $eligibility, $id, $serviceDate, $status): bool {
                $company = Company::query()->where('slug', 'smartbus-demo')->where('status', CompanyStatus::ACTIVE)->lockForUpdate()->first();
                if (! $company) {
                    return false;
                }
                if (Trip::query()->whereKey($id)->exists()) {
                    return true;
                }
                $schedule = RouteSchedule::query()
                    ->where('day_of_week', 1)
                    ->where('departure_time', '09:00:00')
                    ->whereNull('valid_from')
                    ->whereNull('valid_until')
                    ->whereHas('pattern', fn (Builder $query): Builder => $query
                        ->where('code', 'OUTBOUND')
                        ->whereHas('route', fn (Builder $query): Builder => $query
                            ->where('company_id', $company->getKey())
                            ->where('code', 'DEMO-CITY')))
                    ->first();
                $bus = Bus::query()->where('company_id', $company->getKey())->where('is_active', true)
                    ->orderBy('id')->lockForUpdate()->first();
                if (! $schedule || ! $bus) {
                    return false;
                }
                Validator::make(['timezone' => $company->timezone], ['timezone' => ['required', 'timezone']])->validate();
                $departure = CarbonImmutable::parse($serviceDate.' '.$schedule->departure_time, $company->timezone);
                $plan = $snapshots->prepare($company, [
                    'route_pattern_id' => $schedule->route_pattern_id, 'route_schedule_id' => $schedule->getKey(),
                    'bus_id' => $bus->getKey(), 'service_date' => $serviceDate,
                    'arrival_at' => $departure->addHour()->format('Y-m-d\TH:i:sP'), 'departure_utc_offset' => $departure->format('P'),
                ]);

                $user = User::query()->where('email', 'user@driver.com')->first();
                if ($user === null) {
                    return false;
                }
                $candidate = Driver::query()->where('company_id', $company->getKey())
                    ->where('user_id', $user->getKey())->where('status', DriverStatus::ACTIVE)
                    ->lockForUpdate()->first();
                if ($candidate === null) {
                    return false;
                }
                $driver = $eligibility->validateForPlan($company, $candidate->getKey(), $plan);

                $trip = new Trip([
                    'company_id' => $company->getKey(), 'route_pattern_id' => $plan['pattern']->getKey(),
                    'route_schedule_id' => $plan['schedule']->getKey(), 'bus_id' => $bus->getKey(), 'driver_id' => $driver->getKey(),
                    'service_date' => $plan['service_date'], 'scheduled_departure_at' => $plan['departure_at'],
                    'scheduled_arrival_at' => $plan['arrival_at'], 'planned_route_snapshot' => $plan['planned_route_snapshot'],
                ]);
                $trip->id = $id;
                $trip->save();

                if ($status !== TripStatus::SCHEDULED) {
                    $execution = match ($status) {
                        TripStatus::IN_PROGRESS => [
                            'actual_departure_at' => $plan['departure_at']->utc()->format('Y-m-d H:i:s.uP'),
                        ],
                        TripStatus::COMPLETED => [
                            'actual_departure_at' => $plan['departure_at']->utc()->format('Y-m-d H:i:s.uP'),
                            'actual_arrival_at' => $plan['arrival_at']->utc()->format('Y-m-d H:i:s.uP'),
                        ],
                        TripStatus::CANCELLED => [
                            'cancelled_at' => $plan['departure_at']->subHour()->utc()->format('Y-m-d H:i:s.uP'),
                            'cancellation_reason' => 'Demo cancellation',
                        ],
                    };
                    Trip::query()->whereKey($id)->update(['status' => $status->value, ...$execution]);
                }

                return true;
            });
        } catch (ValidationException) {
            $created = false;
        } catch (QueryException $exception) {
            $code = $exception->errorInfo[0] ?? '';
            $detail = $exception->errorInfo[2] ?? '';
            $knownConflict = ($code === '23505' && (str_contains($detail, '"trips_pkey"') || str_contains($detail, '"trips_schedule_service_date_unique"')))
                || ($code === '23P01' && (str_contains($detail, '"trips_no_bus_overlap"') || str_contains($detail, '"trips_no_driver_overlap"')));
            if (! $knownConflict) {
                throw $exception;
            }
            $created = false;
        }
        if (! $created) {
            $this->command?->warn('TripSeeder skipped: complete eligible demo planning resources are required.');
        }
    }
}
