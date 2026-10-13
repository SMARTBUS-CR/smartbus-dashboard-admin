<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CompanyPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, ['forceDelete', 'forceDeleteAny'], true)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return false;
    }

    public function viewAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function view(AuthUser $authUser, Company $company): bool
    {
        return false;
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, Company $company): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, Company $company): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Company $company): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Company $company): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function replicate(AuthUser $authUser, Company $company): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
