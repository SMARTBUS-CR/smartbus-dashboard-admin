<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    /**
     * Create a new policy instance.
     */
    public function __construct() {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('companies.view')
            || $user->hasPermissionTo('companies.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('companies.create')
            || $user->hasPermissionTo('companies.manage');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasPermissionTo('companies.update')
            || $user->hasPermissionTo('companies.manage');
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->hasPermissionTo('companies.delete');
    }
}
