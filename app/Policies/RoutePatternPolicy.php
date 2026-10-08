<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\RoutePattern;
use App\Models\User;
use Filament\Facades\Filament;

class RoutePatternPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAccessCompany($user)
            && $user->can('ViewAny:RoutePattern');
    }

    public function view(User $user, RoutePattern $pattern): bool
    {
        return $this->belongsToSelectedCompany($user, $pattern)
            && $user->can('View:RoutePattern');
    }

    public function create(User $user): bool
    {
        return $this->canAccessCompany($user)
            && $user->can('Create:RoutePattern');
    }

    public function update(User $user, RoutePattern $pattern): bool
    {
        return $this->belongsToSelectedCompany($user, $pattern)
            && $user->can('Update:RoutePattern');
    }

    public function delete(User $user, RoutePattern $pattern): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, RoutePattern $pattern): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, RoutePattern $pattern): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, RoutePattern $pattern): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }

    private function canAccessCompany(User $user): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company
            && $user->canAccessTenant($company);
    }

    private function belongsToSelectedCompany(
        User $user,
        RoutePattern $pattern,
    ): bool {
        $company = Filament::getTenant();
        $route = $pattern->route;

        return $this->canAccessCompany($user)
            && $route !== null
            && (string) $route->company_id === (string) $company->getKey();
    }
}
