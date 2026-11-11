<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Traits\AuthorizesDriverWorkforce;
use Filament\Facades\Filament;

class DriverPolicy
{
    use AuthorizesDriverWorkforce;

    public function viewAny(User $actor, ?Company $company = null): bool
    {
        $company ??= Filament::getTenant();

        return $company instanceof Company
            && $this->allowsWorkforce($actor, $company, 'ViewAny:Driver');
    }

    public function create(User $actor, ?Company $company = null): bool
    {
        $company ??= Filament::getTenant();

        return $company instanceof Company
            && $this->allowsWorkforce($actor, $company, 'Create:Driver');
    }

    public function view(User $actor, Company|Driver $target, ?Driver $extra = null): bool
    {
        [$company, $driver] = $this->resolveContext($target, $extra);

        if (! $company instanceof Company || ! $driver instanceof Driver) {
            return false;
        }

        return $this->allowsWorkforce($actor, $company, 'View:Driver') && $this->driverInCompany($company, $driver);
    }

    public function update(User $actor, Company|Driver $target, ?Driver $extra = null): bool
    {
        [$company, $driver] = $this->resolveContext($target, $extra);

        if (! $company instanceof Company || ! $driver instanceof Driver) {
            return false;
        }

        return $this->allowsWorkforce($actor, $company, 'Update:Driver') && $this->driverInCompany($company, $driver);
    }

    public function delete(User $actor, Company|Driver $target, ?Driver $extra = null): bool
    {
        [$company, $driver] = $this->resolveContext($target, $extra);

        if (! $company instanceof Company || ! $driver instanceof Driver) {
            return false;
        }

        return $this->allowsWorkforce($actor, $company, 'Delete:Driver') && $this->driverInCompany($company, $driver);
    }

    public function restore(User $actor, Company|Driver $target, ?Driver $extra = null): bool
    {
        [$company, $driver] = $this->resolveContext($target, $extra);

        if (! $company instanceof Company || ! $driver instanceof Driver) {
            return false;
        }

        return $this->allowsWorkforce($actor, $company, 'Restore:Driver') && $this->driverInCompany($company, $driver);
    }

    public function viewSalary(User $actor, Company|Driver $target, ?Driver $extra = null): bool
    {
        [$company, $driver] = $this->resolveContext($target, $extra);

        if (! $company instanceof Company || ! $driver instanceof Driver) {
            return false;
        }

        return $this->allowsWorkforce($actor, $company, 'ViewSalary:Driver') && $this->driverInCompany($company, $driver);
    }

    public function viewAttendance(User $actor, Company|Driver $target, ?Driver $extra = null): bool
    {
        [$company, $driver] = $this->resolveContext($target, $extra);

        if (! $company instanceof Company || ! $driver instanceof Driver) {
            return false;
        }

        return $this->allowsWorkforce($actor, $company, 'ViewAttendance:Driver') && $this->driverInCompany($company, $driver);
    }

    /** @return array{0: Company|null, 1: Driver|null} */
    private function resolveContext(Company|Driver $target, ?Driver $extra): array
    {
        $driver = $target instanceof Driver ? $target : $extra;
        $company = $target instanceof Company
            ? $target
            : ($driver?->company ?? Filament::getTenant());

        return [$company instanceof Company ? $company : null, $driver];
    }
}
