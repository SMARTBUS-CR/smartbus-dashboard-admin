<?php

namespace App\Policies;

use App\Models\Bus;
use App\Models\Company;
use App\Models\User;

class BusPolicy
{
    public function forceDelete(User $user, Company $company, ?Bus $bus = null): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user, ?Company $company = null): bool
    {
        return false;
    }

    public function restore(User $user, Company $company, ?Bus $bus = null): bool
    {
        return $user->canAccessTenant($company)
            && $user->can('Restore:Bus')
            && ($bus === null || (
                (string) $bus->company_id === (string) $company->getKey()
                && $bus->trashed()
            ));
    }

    public function viewAny(User $user, Company $company): bool
    {
        return $user->canAccessTenant($company)
            && $user->can('ViewAny:Bus');
    }

    public function view(User $user, Company $company, ?Bus $bus = null): bool
    {
        return $user->canAccessTenant($company)
            && $user->can('View:Bus')
            && ($bus === null || (string) $bus->company_id === (string) $company->getKey());
    }

    public function update(User $user, Company $company, ?Bus $bus = null): bool
    {
        return $user->canAccessTenant($company)
            && $user->can('Update:Bus')
            && ($bus === null || (string) $bus->company_id === (string) $company->getKey());
    }

    public function create(User $user, Company $company): bool
    {
        return $user->canAccessTenant($company)
            && $user->can('Create:Bus');
    }

    public function delete(
        User $user,
        Company $company,
        ?Bus $bus = null,
    ): bool {
        return $user->canAccessTenant($company)
            && $user->can('Delete:Bus')
            && ($bus === null || (
                (string) $bus->company_id === (string) $company->getKey()
                && ! $bus->trashed()
            ));
    }
}
