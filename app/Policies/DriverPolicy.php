<?php

namespace App\Policies;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;

class DriverPolicy
{
    public function create(User $actor, Company $company): bool
    {
        $persistedActor = User::query()->find($actor->getKey());

        if ($persistedActor === null || ! Company::query()->whereKey($company->getKey())->where('status', CompanyStatus::ACTIVE)->exists()) {
            return false;
        }

        if (! CompanyUser::query()->where('company_id', $company->getKey())->where('user_id', $persistedActor->getKey())->exists()) {
            return false;
        }

        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId($company->getKey());
            $persistedActor->unsetRelation('roles')->unsetRelation('permissions');

            return $persistedActor->can('Create:Driver');
        } finally {
            setPermissionsTeamId($previousTeam);
            $persistedActor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
