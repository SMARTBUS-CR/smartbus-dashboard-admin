<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * User data resides strictly in MySQL.
     */
    protected $connection = 'mysql';

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
     * Cross-database Eloquent relationship to companies in PostgreSQL.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            Company::class,
            'company_user',
            'user_id',
            'company_id'
        )->withTimestamps();
    }

    /**
     * Restrict panel access to super-admin and company-admin only.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        $role = $this->getAttribute('role');

        return in_array($role, UserRole::adminRoles(), true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->getAttribute('role') === UserRole::SuperAdmin->value;
    }

    public function isCompanyAdmin(): bool
    {
        return $this->getAttribute('role') === UserRole::CompanyAdmin->value;
    }

    /**
     * Return accessible tenants for this user.
     */
    public function getTenants(Panel $panel): array|Collection
    {
        if ($this->isSuperAdmin()) {
            return Company::query()->where('is_active', true)->get();
        }

        return $this->companies()->where('is_active', true)->get();
    }

    /**
     * Determine whether the user can access the tenant.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->companies()
            ->where('companies.id', $tenant->getKey())
            ->where('is_active', true)
            ->exists();
    }

    public function getFilamentName(): string
    {
        return (string) ($this->name ?? $this->email);
    }
}
