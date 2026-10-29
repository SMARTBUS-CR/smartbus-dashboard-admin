<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\User;
use App\Traits\AuthorizesDriverWorkforce;
use Illuminate\Database\Eloquent\Builder;

class DriverSchedulePolicy
{
    use AuthorizesDriverWorkforce;

    public function viewAny(User $actor, Company $company): bool
    {
        return $this->allowsWorkforce($actor, $company, 'ViewAny:DriverSchedule');
    }

    public function create(User $actor, Company $company, Driver $driver): bool
    {
        return $this->allowsWorkforce($actor, $company, 'Create:DriverSchedule') && $this->driverInCompany($company, $driver);
    }

    public function view(User $actor, Company $company, DriverSchedule $schedule): bool
    {
        return $this->allowsWorkforce($actor, $company, 'View:DriverSchedule') && $this->scheduleInCompany($company, $schedule);
    }

    public function update(User $actor, Company $company, DriverSchedule $schedule): bool
    {
        return $this->allowsWorkforce($actor, $company, 'Update:DriverSchedule') && $this->scheduleInCompany($company, $schedule);
    }

    public function delete(User $actor, Company $company, DriverSchedule $schedule): bool
    {
        return $this->allowsWorkforce($actor, $company, 'Delete:DriverSchedule') && $this->scheduleInCompany($company, $schedule);
    }

    private function scheduleInCompany(Company $company, DriverSchedule $schedule): bool
    {
        return DriverSchedule::query()->whereKey($schedule->getKey())
            ->where('driver_id', $schedule->driver_id)
            ->whereHas('driver', fn (Builder $query): Builder => $query->where('company_id', $company->getKey()))->exists();
    }
}
