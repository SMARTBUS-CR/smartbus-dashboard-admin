<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Role;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

use function in_array;

class RolePolicy
{
    use HandlesAuthorization;

    protected array $protectedRoles = [
        UserRole::SuperAdmin->value,
        UserRole::Admin->value,
        UserRole::Driver->value,
        UserRole::Passenger->value,
    ];

    private function ownsRole(Role $role): bool
    {
        return $role->company_id !== null
            && (string) $role->company_id === (string) Filament::getTenant()?->getKey();
    }

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Role');
    }

    public function view(AuthUser $authUser, Role $role): bool
    {
        return $this->ownsRole($role) && $authUser->can('View:Role');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Role');
    }

    public function update(AuthUser $authUser, Role $role): bool
    {
        return $this->ownsRole($role) && $authUser->can('Update:Role');
    }

    public function delete(AuthUser $authUser, Role $role): bool
    {
        // Block deletion of protected roles
        if (in_array($role->name, $this->protectedRoles, true)) {
            return false;
        }

        // Block deletion if the role has associated users
        if ($role->users()->withoutGlobalScopes()->exists()) {
            return false;
        }

        return $this->ownsRole($role) && $authUser->can('Delete:Role');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Role');
    }

    public function restore(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Restore:Role');
    }

    public function forceDelete(AuthUser $authUser, Role $role): bool
    {
        if (in_array($role->name, $this->protectedRoles, true)) {
            return false;
        }

        return $authUser->can('ForceDelete:Role');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Role');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Role');
    }

    public function replicate(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Replicate:Role');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Role');
    }
}
