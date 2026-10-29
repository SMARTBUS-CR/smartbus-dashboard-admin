<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\DriverStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TripDriverEligibilityService
{
    /**
     * Internal planning check, after actor authorization and before persistence.
     * Dates must come from TripPlanPreparationService's half-open local window.
     * Availability, locking and reservation checks belong to the management flow.
     *
     * @param  list<string>  $localDates
     */
    public function validate(Company $company, string $driverId, array $localDates): Driver
    {
        Validator::make(['driver_id' => $driverId, 'local_dates' => $localDates], [
            'driver_id' => ['required', 'uuid'],
            'local_dates' => ['required', 'array', 'list', 'min:1'],
            'local_dates.*' => ['required', 'string', 'date_format:Y-m-d'],
        ])->validate();

        $driver = Driver::query()->where('company_id', $company->getKey())
            ->where('status', DriverStatus::ACTIVE)->find($driverId);
        if (! Company::query()->whereKey($company->getKey())->where('status', CompanyStatus::ACTIVE)->exists() || ! $driver) {
            $this->reject();
        }
        $user = $driver->user;
        if (! $user || ! CompanyUser::query()->where('company_id', $company->getKey())->where('user_id', $user->getKey())->exists()) {
            $this->reject();
        }

        $hasDriverRole = $user->getConnection()->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_uuid', $user->getKey())
            ->where('assignments.model_type', $user->getMorphClass())
            ->where('assignments.company_id', $company->getKey())
            ->where('roles.company_id', $company->getKey())
            ->where('roles.name', UserRole::Driver->value)->where('roles.guard_name', 'web')->exists();
        if (! $hasDriverRole) {
            $this->reject();
        }

        foreach ($localDates as $date) {
            if ($driver->employment_starts_on->toDateString() > $date
                || ($driver->employment_ends_on !== null && $driver->employment_ends_on->toDateString() < $date)
                || $driver->license_expires_at->toDateString() < $date) {
                $this->reject();
            }
        }

        return $driver;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages([
            'driver_id' => __('The selected driver is unavailable or ineligible for the planned dates.'),
        ]);
    }
}
