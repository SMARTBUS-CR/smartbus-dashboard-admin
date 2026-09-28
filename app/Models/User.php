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
        return self::hasRole(UserRole::SuperAdmin);
    }

    /**
     * Check if the user is a Company Admin.
     *
     * @return bool True if the user is a Company Admin, false otherwise.
     */
    public function isCompanyAdmin(): bool
    {
        return self::hasRole(UserRole::CompanyAdmin);
    }

    /**
     * Determine if the user can access the given panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return self::hasAnyRole(UserRole::adminRoles() ?? []);
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
        if (self::isSuperAdmin()) {
            return true;
        }

        if (self::isCompanyAdmin()) {
            return $this->companies()
                ->where('companies.id', $tenant->getKey())
                ->exists();
        }

        return false;
    }

    /**
     * Get the tenants that this user belongs to.
     *
     * @return array<int, Model>|Collection<int, Model>
     */
    public function getTenants(Panel $panel): array|Collection
    {
        return $this->companies;
    }
}
