<?php

namespace App\Policies;

use App\Enums\TripStatus;
use App\Models\Company;
use App\Models\Trip;
use App\Models\User;

class TripPolicy
{
    public function create(User $user, Company $company): bool
    {
        return $user->canAccessTenant($company) && $user->can('Create:Trip');
    }

    public function update(User $user, Company $company, ?Trip $trip = null): bool
    {
        return $user->canAccessTenant($company)
            && $user->can('Update:Trip')
            && ($trip === null || (
                (string) $trip->company_id === (string) $company->getKey()
                && $trip->status === TripStatus::SCHEDULED
            ));
    }

    public function delete(User $user): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
