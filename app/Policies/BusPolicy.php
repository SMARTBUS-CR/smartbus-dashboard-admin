<?php

namespace App\Policies;

use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;

class BusPolicy
{
    public function forceDelete(User $user, Company|Bus $target, Company|Bus|null $extra = null): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user, Company|Bus|null $target = null): bool
    {
        return false;
    }

    public function restore(User $user, Company|Bus $target, Company|Bus|null $extra = null): bool
    {
        [$company, $bus] = $this->resolveContext($target, $extra);

        if (! $company instanceof Company) {
            return false;
        }

        return $user->canAccessTenant($company)
            && $user->can('Restore:Bus')
            && ($bus === null || (
                (string) $bus->company_id === (string) $company->getKey()
                && $bus->trashed()
            ));
    }

    public function viewAny(User $user, Company $company): bool
    {
        return $user->canAccessTenant($company)
            && $user->can('ViewAny:Bus');
    }

    public function view(User $user, Company|Bus $target, Company|Bus|null $extra = null): bool
    {
        [$company, $bus] = $this->resolveContext($target, $extra);

        if (! $company instanceof Company) {
            return false;
        }

        return $user->canAccessTenant($company)
            && $user->can('View:Bus')
            && ($bus === null || (string) $bus->company_id === (string) $company->getKey());
    }

    public function update(User $user, Company|Bus $target, Company|Bus|null $extra = null): bool
    {
        [$company, $bus] = $this->resolveContext($target, $extra);

        if (! $company instanceof Company) {
            return false;
        }

        return $user->canAccessTenant($company)
            && $user->can('Update:Bus')
            && ($bus === null || (string) $bus->company_id === (string) $company->getKey());
    }

    public function create(User $user, Company $company): bool
    {
        return $user->canAccessTenant($company)
            && $user->can('Create:Bus');
    }

    public function delete(
        User $user,
        Company|Bus $target,
        Company|Bus|null $extra = null,
    ): bool {
        [$company, $bus] = $this->resolveContext($target, $extra);

        if (! $company instanceof Company) {
            return false;
        }

        return $user->canAccessTenant($company)
            && $user->can('Delete:Bus')
            && ($bus === null || (
                (string) $bus->company_id === (string) $company->getKey()
                && ! $bus->trashed()
            ));
    }

    /** @return array{0: Company|null, 1: Bus|null} */
    private function resolveContext(Company|Bus $target, Company|Bus|null $extra): array
    {
        $bus = $target instanceof Bus
            ? $target
            : ($extra instanceof Bus ? $extra : null);

        $company = $target instanceof Company
            ? $target
            : ($extra instanceof Company ? $extra : $bus?->company);

        if (! $company instanceof Company) {
            $tenant = Filament::getTenant();
            $company = $tenant instanceof Company ? $tenant : null;
        }

        return [$company, $bus];
    }
}
