<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Stop;
use App\Models\User;
use Filament\Facades\Filament;

class StopPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAccessCompany($user)
            && $user->can('ViewAny:Stop');
    }

    public function view(User $user, Stop $stop): bool
    {
        $company = Filament::getTenant();

        return $this->canAccessCompany($user)
            && (
                $stop->company_id === null
                || (string) $stop->company_id === (string) $company->getKey()
            )
            && $user->can('View:Stop');
    }

    public function create(User $user): bool
    {
        return $this->canAccessCompany($user)
            && $user->can('Create:Stop');
    }

    public function update(User $user, Stop $stop): bool
    {
        $company = Filament::getTenant();

        return $this->canAccessCompany($user)
            && ! $stop->trashed()
            && $stop->company_id !== null
            && (string) $stop->company_id === (string) $company->getKey()
            && $user->can('Update:Stop');
    }

    private function canAccessCompany(User $user): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company
            && $user->canAccessTenant($company);
    }
}
