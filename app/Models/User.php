<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

#[Connection('mysql')]
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the companies that this user belongs to.
     *
     * @return BelongsToMany<Company, User>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->using(CompanyUser::class)
            ->withTimestamps()
            ->chaperone();
    }

    /**
     * Check if the user is a Super Admin.
     *
     * @return bool True if the user is a Super Admin, false otherwise.
     */
    public function isSuperAdmin(): bool
    {
        return ! $this->trashed() && $this->getConnection()
            ->table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->join('users', 'users.id', '=', 'mhr.model_uuid')
            ->whereNull('users.deleted_at')
            ->where('mhr.model_uuid', $this->getKey())
            ->where('mhr.model_type', $this->getMorphClass())
            ->where('r.name', UserRole::SuperAdmin->value)
            ->where('r.guard_name', 'web')
            ->whereNull('r.company_id')
            ->whereNull('mhr.company_id')
            ->exists();
    }

    /**
     * Check if the user is a Company Admin.
     *
     * @return bool True if the user is a Company Admin, false otherwise.
     */
    public function isCompanyAdmin(): bool
    {
        return ! $this->trashed() && $this->getConnection()
            ->table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->join('users', 'users.id', '=', 'mhr.model_uuid')
            ->whereNull('users.deleted_at')
            ->where('mhr.model_uuid', $this->getKey())
            ->where('mhr.model_type', $this->getMorphClass())
            ->where('r.name', UserRole::Admin->value)
            ->where('r.guard_name', 'web')
            ->whereColumn('r.company_id', 'mhr.company_id')
            ->exists();
    }

    /**
     * Determine if the user can access the given panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return ! $this->trashed() && $this->dashboardCompanies()->exists();
    }

    /**
     * Get the user's name for Filament display purposes.
     * If the name is not set, it will fall back to the email address.
     */
    public function getFilamentName(): string
    {
        return (string) ($this->name ?? $this->email);
    }

    /**
     * Determine if the user can access the given tenant (company).
     */
    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $tenant instanceof Company && $this->hasCompanyDashboardAccess($tenant);
    }

    /**
     * Get the tenants that this user belongs to.
     *
     * @return array<int, Model>|Collection<int, Model>
     */
    public function getTenants(Panel $panel): array|Collection
    {
        if ($this->isSuperAdmin()) {
            return Company::all();
        }

        return $this->trashed() ? collect() : $this->dashboardCompanies()->get();
    }

    public function hasCompanyDashboardAccess(Company $company): bool
    {
        return ! $this->trashed() && ! $company->trashed()
            && $this->dashboardCompanies()->whereKey($company->getKey())->exists();
    }

    /** @return Builder<Company> */
    protected function dashboardCompanies(): Builder
    {
        $assignments = $this->getConnection()->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->join('users', 'users.id', '=', 'assignments.model_uuid')
            ->whereNull('users.deleted_at')
            ->where('assignments.model_uuid', $this->getKey())
            ->where('assignments.model_type', $this->getMorphClass())
            ->where('roles.guard_name', 'web')
            ->whereColumn('roles.company_id', 'assignments.company_id');

        $excludedCompanyIds = (clone $assignments)
            ->whereIn('roles.name', [UserRole::Driver->value, UserRole::Passenger->value])
            ->select('roles.company_id');

        $roleCompanyIds = $assignments
            ->whereNotIn('roles.company_id', $excludedCompanyIds)
            ->whereNotIn('roles.name', [UserRole::SuperAdmin->value, UserRole::Driver->value, UserRole::Passenger->value])
            ->distinct()->pluck('roles.company_id');

        $memberCompanyIds = CompanyUser::query()->where('user_id', $this->getKey())
            ->whereIn('company_id', $roleCompanyIds)->pluck('company_id');

        return Company::query()->whereIn('id', $memberCompanyIds)->orderBy('legal_name');
    }
}
