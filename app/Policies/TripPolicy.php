<?php

namespace App\Policies;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use Filament\Facades\Filament;

class TripPolicy
{
    public function viewAny(User $user, ?Company $company = null): bool
    {
        $company ??= Filament::getTenant();

        if (! $company instanceof Company) {
            return false;
        }

        return $this->allowsReading($user, $company, null, 'ViewAny:Trip');
    }

    public function view(User $user, Company|Trip $target, ?Trip $extra = null): bool
    {
        [$company, $trip] = $this->resolveTripContext($target, $extra);

        if (! $company instanceof Company) {
            return false;
        }

        return $this->allowsReading($user, $company, $trip, 'View:Trip');
    }

    private function allowsReading(User $user, Company $company, ?Trip $trip, string $permission): bool
    {
        if (($trip !== null && (string) $trip->company_id !== (string) $company->getKey())
            || ! Company::withTrashed()->whereKey($company->getKey())->exists()) {
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

        $roles = $this->companyRoles($user, $company);
        if (in_array(UserRole::Driver->value, $roles, true)) {
            return $trip === null || Driver::withTrashed()->whereKey($trip->driver_id)
                ->where('company_id', $company->getKey())->where('user_id', $user->getKey())->exists();
        }

        return $roles !== []
            && ! in_array(UserRole::Passenger->value, $roles, true)
            && $user->can($permission);
    }

    public function create(User $user, ?Company $company = null): bool
    {
        $company ??= Filament::getTenant();

        if (! $company instanceof Company) {
            return false;
        }

        return $user->canAccessTenant($company) && $user->can('Create:Trip');
    }

    public function update(User $user, Company|Trip $target, ?Trip $extra = null): bool
    {
        [$company, $trip] = $this->resolveTripContext($target, $extra);

        if (! $company instanceof Company) {
            return false;
        }

        return $user->canAccessTenant($company)
            && $user->can('Update:Trip')
            && ($trip === null || (
                (string) $trip->company_id === (string) $company->getKey()
                && $trip->status === TripStatus::SCHEDULED
            ));
    }

    /** @return array{Company|null, Trip|null} */
    private function resolveTripContext(Company|Trip $target, ?Trip $extra): array
    {
        $trip = $target instanceof Trip ? $target : $extra;
        $company = $target instanceof Company ? $target : $trip?->company;

        return [$company, $trip];
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
        $roles = $this->companyRoles($user, $company);
        if (in_array(UserRole::Driver->value, $roles, true)) {
            return $allowDriver && ($trip === null || Driver::withTrashed()->whereKey($trip->driver_id)
                ->where('company_id', $company->getKey())->where('user_id', $user->getKey())->exists());
        }

        return $user->canAccessTenant($company) && $user->can($permission);
    }

    /** @return list<string> */
    private function companyRoles(User $user, Company $company): array
    {
        return $user->getConnection()->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_uuid', $user->getKey())->where('assignments.model_type', $user->getMorphClass())
            ->where('assignments.company_id', $company->getKey())->where('roles.company_id', $company->getKey())
            ->where('roles.guard_name', 'web')->pluck('roles.name')->all();
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
