<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Enums\DriverStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\RouteSchedule;
use App\Models\Trip;
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
    private const DEMO_TRIP_ID = 'b0700000-0000-4000-8000-000000000001';

    private const SERVICE_DATE = '2026-11-02';

    /**
     * Development fixture for a fixed local Monday, with an explicit one-hour
     * planned window. Reuse the first demo Monday schedule and active bus by ID,
     * and the first eligible covered Driver. Missing or conflicting resources
     * skip insertion. Never create identities, grant permissions or update trips.
     */
    public function run(TripPlanSnapshotService $snapshots, TripDriverEligibilityService $eligibility): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('TripSeeder skipped in production.');

            return;
        }
        if (Trip::query()->whereKey(self::DEMO_TRIP_ID)->exists()) {
            return;
        }

        try {
            $created = (new Trip)->getConnection()->transaction(function () use ($snapshots, $eligibility): bool {
                $company = Company::query()->where('slug', 'smartbus-demo')->where('status', CompanyStatus::ACTIVE)->lockForUpdate()->first();
                if (! $company) {
                    return false;
                }
                if (Trip::query()->whereKey(self::DEMO_TRIP_ID)->exists()) {
                    return true;
                }
                $schedule = RouteSchedule::query()->where('day_of_week', 1)
                    ->whereHas('pattern.route', fn (Builder $query) => $query->where('company_id', $company->getKey()))
                    ->orderBy('id')->first();
                $bus = Bus::query()->where('company_id', $company->getKey())->where('is_active', true)
                    ->orderBy('id')->lockForUpdate()->first();
                if (! $schedule || ! $bus) {
                    return false;
                }
                Validator::make(['timezone' => $company->timezone], ['timezone' => ['required', 'timezone']])->validate();
                $departure = CarbonImmutable::parse(self::SERVICE_DATE.' '.$schedule->departure_time, $company->timezone);
                $plan = $snapshots->prepare($company, [
                    'route_pattern_id' => $schedule->route_pattern_id, 'route_schedule_id' => $schedule->getKey(),
                    'bus_id' => $bus->getKey(), 'service_date' => self::SERVICE_DATE,
                    'arrival_at' => $departure->addHour()->format('Y-m-d\TH:i:sP'), 'departure_utc_offset' => $departure->format('P'),
                ]);

                $driver = null;
                foreach (Driver::query()->where('company_id', $company->getKey())->where('status', DriverStatus::ACTIVE)
                    ->orderBy('id')->lockForUpdate()->get() as $candidate) {
                    try {
                        $driver = $eligibility->validateForPlan($company, $candidate->getKey(), $plan);
                        break;
                    } catch (ValidationException) {
                        continue;
                    }
                }
                if (! $driver) {
                    return false;
                }

                $trip = new Trip([
                    'company_id' => $company->getKey(), 'route_pattern_id' => $plan['pattern']->getKey(),
                    'route_schedule_id' => $plan['schedule']->getKey(), 'bus_id' => $bus->getKey(), 'driver_id' => $driver->getKey(),
                    'service_date' => $plan['service_date'], 'scheduled_departure_at' => $plan['departure_at'],
                    'scheduled_arrival_at' => $plan['arrival_at'], 'planned_route_snapshot' => $plan['planned_route_snapshot'],
                ]);
                $trip->id = self::DEMO_TRIP_ID;
                $trip->save();

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
