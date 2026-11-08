<?php

namespace App\Policies;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
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

    public function start(User $user, Company $company, ?Trip $trip = null): bool
    {
        return $this->allowsExecution($user, $company, $trip, 'Start:Trip', true);
    }

    public function complete(User $user, Company $company, ?Trip $trip = null): bool
    {
        return $this->allowsExecution($user, $company, $trip, 'Complete:Trip', true);
    }

    public function cancel(User $user, Company $company, ?Trip $trip = null): bool
    {
        return $this->allowsExecution($user, $company, $trip, 'Cancel:Trip', false);
    }

    private function allowsExecution(User $user, Company $company, ?Trip $trip, string $permission, bool $allowDriver): bool
    {
        if (($trip !== null && (string) $trip->company_id !== (string) $company->getKey())
            || ! Company::query()->whereKey($company->getKey())->exists()) {
            return false;
        }
        $user = User::query()->find($user->getKey());
        if ($user === null) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }
        if (! CompanyUser::query()->where('company_id', $company->getKey())->where('user_id', $user->getKey())->exists()) {
            return false;
        }
        $roles = $user->getConnection()->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_uuid', $user->getKey())->where('assignments.model_type', $user->getMorphClass())
            ->where('assignments.company_id', $company->getKey())->where('roles.company_id', $company->getKey())
            ->where('roles.guard_name', 'web')->pluck('roles.name')->all();
        if (in_array(UserRole::Driver->value, $roles, true)) {
            return $allowDriver && ($trip === null || Driver::withTrashed()->whereKey($trip->driver_id)
                ->where('company_id', $company->getKey())->where('user_id', $user->getKey())->exists());
        }

        return $user->canAccessTenant($company) && $user->can($permission);
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
