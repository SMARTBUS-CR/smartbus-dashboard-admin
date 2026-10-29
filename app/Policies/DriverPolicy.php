<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Traits\AuthorizesDriverWorkforce;

class DriverPolicy
{
    use AuthorizesDriverWorkforce;

    public function viewAny(User $actor, Company $company): bool
    {
        return $this->allowsWorkforce($actor, $company, 'ViewAny:Driver');
    }

    public function create(User $actor, Company $company): bool
    {
        return $this->allowsWorkforce($actor, $company, 'Create:Driver');
    }

    public function view(User $actor, Company $company, Driver $driver): bool
    {
        return $this->allowsWorkforce($actor, $company, 'View:Driver') && $this->driverInCompany($company, $driver);
    }

    public function update(User $actor, Company $company, Driver $driver): bool
    {
        return $this->allowsWorkforce($actor, $company, 'Update:Driver') && $this->driverInCompany($company, $driver);
    }

    public function delete(User $actor, Company $company, Driver $driver): bool
    {
        return $this->allowsWorkforce($actor, $company, 'Delete:Driver') && $this->driverInCompany($company, $driver);
    }

    public function restore(User $actor, Company $company, Driver $driver): bool
    {
        return $this->allowsWorkforce($actor, $company, 'Restore:Driver') && $this->driverInCompany($company, $driver);
    }

    public function viewSalary(User $actor, Company $company, Driver $driver): bool
    {
        return $this->allowsWorkforce($actor, $company, 'ViewSalary:Driver') && $this->driverInCompany($company, $driver);
    }

    public function viewAttendance(User $actor, Company $company, Driver $driver): bool
    {
        return $this->allowsWorkforce($actor, $company, 'ViewAttendance:Driver') && $this->driverInCompany($company, $driver);
    }
}
