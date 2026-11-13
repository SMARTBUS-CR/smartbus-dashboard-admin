<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Route;
use App\Models\User;
use Filament\Facades\Filament;

class RoutePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAccessCompany($user)
            && $user->can('ViewAny:Route');
    }

    public function view(User $user, Route $route): bool
    {
        return $this->belongsToSelectedCompany($user, $route)
            && $user->can('View:Route');
    }

    public function create(User $user): bool
    {
        return $this->canAccessCompany($user)
            && $user->can('Create:Route');
    }

    public function update(User $user, Route $route): bool
    {
        return $this->belongsToSelectedCompany($user, $route)
            && $user->can('Update:Route');
    }

    public function delete(User $user, Route $route): bool
    {
        return ! $route->trashed()
            && $this->belongsToSelectedCompany($user, $route)
            && $user->can('Delete:Route');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Route $route): bool
    {
        return $route->trashed()
            && $this->belongsToSelectedCompany($user, $route)
            && $user->can('Restore:Route');
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Route $route): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Route $route): bool
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
        Route $route,
    ): bool {
        $company = Filament::getTenant();

        return $this->canAccessCompany($user)
            && (string) $route->company_id === (string) $company->getKey();
    }
}
