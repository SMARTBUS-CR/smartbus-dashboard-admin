<?php

namespace App\Traits;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\User;

trait AuthorizesDriverWorkforce
{
    private function allowsWorkforce(User $actor, Company $company, string $permission): bool
    {
        $previousTeam = getPermissionsTeamId();
        $persistedActor = null;

        try {
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $persistedActor = User::query()->find($actor->getKey());

            if ($persistedActor === null || ! Company::query()->whereKey($company->getKey())
                ->where('status', CompanyStatus::ACTIVE)->exists()) {
                return false;
            }

            if (! $persistedActor->isSuperAdmin() && ! CompanyUser::query()
                ->where('company_id', $company->getKey())->where('user_id', $persistedActor->getKey())->exists()) {
                return false;
            }

            setPermissionsTeamId($company->getKey());
            $persistedActor->unsetRelation('roles')->unsetRelation('permissions');

            return $persistedActor->can($permission);
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $persistedActor?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    private function driverInCompany(Company $company, Driver $driver): bool
    {
        return (string) $driver->company_id === (string) $company->getKey()
            && Driver::withTrashed()->whereKey($driver->getKey())
                ->where('company_id', $company->getKey())->exists();
    }
}
