<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\UserManagementService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->canAccessAdministration($actor)
            && $actor->can('ViewAny:User');
    }

    public function create(User $actor): bool
    {
        return $this->canAccessAdministration($actor)
            && $actor->can('Create:User');
    }

    public function view(User $actor, User $target): bool
    {
        return $this->canManageTarget($actor, $target)
            && $actor->can('View:User');
    }

    public function update(User $actor, User $target): bool
    {
        return $this->canManageTarget($actor, $target)
            && $actor->can('Update:User');
    }

    public function removeCompanyAccess(User $actor, User $target): bool
    {
        return ! $target->isSuperAdmin()
            && $this->canManageTarget($actor, $target)
            && $actor->can('Delete:User');
    }

    public function delete(User $actor, User $target): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, User $target): bool
    {
        return false;
    }

    public function restoreAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, User $target): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    public function replicate(User $actor, User $target): bool
    {
        return false;
    }

    public function reorder(User $actor): bool
    {
        return false;
    }

    private function canAccessAdministration(User $actor): bool
    {
        if ($actor->trashed()) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && ! $tenant->trashed()
            && $actor->hasCompanyDashboardAccess($tenant);
    }

    private function canManageTarget(User $actor, User $target): bool
    {
        if ($target->trashed() || ! $this->canAccessAdministration($actor)) {
            return false;
        }

        if ($target->isSuperAdmin()) {
            return $actor->isSuperAdmin();
        }

        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company || $tenant->trashed() || ! $this->isManageableMemberInCompany($target, $tenant)) {
            return false;
        }

        return $actor->isSuperAdmin() || $this->isAdminInCompany($actor, $tenant)
            || ($target->roles()->where('roles.company_id', $tenant->getKey())->get()
                ->every(fn (Role $role): bool => app(UserManagementService::class)->canAssignCompanyRole($actor, $role))
                && $target->getAllPermissions()->every(fn (Permission $permission): bool => $actor->can($permission->name)));
    }

    private function isAdminInCompany(User $user, Company $company): bool
    {
        $hasMembership = CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        if (! $hasMembership) {
            return false;
        }

        return DB::connection('mysql')
            ->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_uuid', $user->getKey())
            ->where('assignments.model_type', $user->getMorphClass())
            ->where('assignments.company_id', $company->getKey())
            ->where('roles.company_id', $company->getKey())
            ->where('roles.guard_name', 'web')
            ->where('roles.name', UserRole::Admin->value)
            ->exists();
    }

    private function isManageableMemberInCompany(User $user, Company $company): bool
    {
        $hasMembership = CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        if (! $hasMembership) {
            return false;
        }

        return DB::connection('mysql')
            ->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_uuid', $user->getKey())
            ->where('assignments.model_type', $user->getMorphClass())
            ->where('assignments.company_id', $company->getKey())
            ->where('roles.company_id', $company->getKey())
            ->where('roles.guard_name', 'web')
            ->whereNotIn('roles.name', [
                UserRole::Driver->value,
                UserRole::Passenger->value,
                UserRole::SuperAdmin->value,
            ])
            ->exists();
    }
}
