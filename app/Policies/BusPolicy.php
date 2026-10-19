<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class BusPolicy
{
    public function create(User $user, Company $company): bool
    {
        return $user->canAccessTenant($company)
            && $user->can('Create:Bus');
    }
}
