<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\TripAssignment;
use App\Models\User;
use App\Traits\AuthorizesDriverWorkforce;
use Filament\Facades\Filament;

class TripAssignmentPolicy
{
    use AuthorizesDriverWorkforce;

    public function viewAny(User $actor, ?Company $company = null): bool
    {
        $company ??= Filament::getTenant();

        return $company instanceof Company
            && $this->allowsWorkforce($actor, $company, 'ViewAny:TripAssignment');
    }

    public function create(User $actor, ?Company $company = null): bool
    {
        $company ??= Filament::getTenant();

        return $company instanceof Company
            && $this->allowsWorkforce($actor, $company, 'Create:TripAssignment');
    }

    public function view(User $actor, Company|TripAssignment $target, ?TripAssignment $extra = null): bool
    {
        return $this->allowsRecord($actor, $target, $extra, 'View:TripAssignment');
    }

    public function update(User $actor, Company|TripAssignment $target, ?TripAssignment $extra = null): bool
    {
        return $this->allowsRecord($actor, $target, $extra, 'Update:TripAssignment');
    }

    public function delete(User $actor, Company|TripAssignment $target, ?TripAssignment $extra = null): bool
    {
        return $this->allowsRecord($actor, $target, $extra, 'Delete:TripAssignment');
    }

    public function restore(User $actor, Company|TripAssignment $target, ?TripAssignment $extra = null): bool
    {
        return $this->allowsRecord($actor, $target, $extra, 'Restore:TripAssignment');
    }

    public function forceDelete(User $actor): bool
    {
        return false;
    }

    private function allowsRecord(
        User $actor,
        Company|TripAssignment $target,
        ?TripAssignment $extra,
        string $permission,
    ): bool {
        $assignment = $target instanceof TripAssignment ? $target : $extra;
        $company = $target instanceof Company ? $target : ($assignment?->company ?? Filament::getTenant());

        return $company instanceof Company
            && $assignment instanceof TripAssignment
            && $this->assignmentInCompany($company, $assignment)
            && $this->allowsWorkforce($actor, $company, $permission);
    }

    private function assignmentInCompany(Company $company, TripAssignment $assignment): bool
    {
        return TripAssignment::withTrashed()->whereKey($assignment->getKey())
            ->where('company_id', $company->getKey())->exists();
    }
}
