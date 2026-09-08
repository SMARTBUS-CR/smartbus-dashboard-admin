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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function count;
use function in_array;
use function is_array;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * User data resides strictly in MySQL.
     */
    protected $connection = 'mysql';

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string> The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get user roles from session or model attributes.
     *
     * @return array<string> The user's roles.
     */
    public function getRoles(): array
    {
        $sessionRoles = session('user_roles', []);

        if (! empty($sessionRoles) && is_array($sessionRoles)) {
            return $sessionRoles;
        }

        $roleAttr = $this->getAttribute('role');

        return $roleAttr ? [(string) $roleAttr] : [];
    }

    /**
     * Check if the user has a specific role or any of the specified roles.
     *
     * @param  string|UserRole|array  $roles  The role(s) to check for.
     * @return bool True if the user has the role or any of the roles, false otherwise.
     */
    public function hasRole(string|UserRole|array $roles): bool
    {
        $userRoles = $this->getRoles();
        $checkRoles = is_array($roles) ? $roles : [$roles];

        foreach ($checkRoles as $role) {
            $roleValue = $role instanceof UserRole ? $role->value : (string) $role;
            if (in_array($roleValue, $userRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user permissions from session or model attributes.
     *
     * @return array<string> The user's permissions.
     */
    public function getPermissions(): array
    {
        $sessionPermissions = session('user_permissions', []);

        if (! empty($sessionPermissions) && is_array($sessionPermissions)) {
            return $sessionPermissions;
        }

        return [];
    }

    /**
     * Check if the user has a specific permission.
     *
     * @param  string  $permission  The permission to check for.
     * @return bool True if the user has the permission, false otherwise.
     */
    public function hasPermissionTo(string $permission): bool
    {
        // Los Super Admin suelen tener bypass de permisos
        if ($this->isSuperAdmin()) {
            return true;
        }

        Log::debug('Checking permission for user', [
            'user_id' => $this->getKey(),
            'permission' => $permission,
            'user_permissions' => $this->getPermissions(),
        ]);

        return in_array($permission, $this->getPermissions(), true);
    }

    /**
     * Check if the user has any of the specified permissions.
     *
     * @param  array<string>  $permissions  The permissions to check for.
     * @return bool True if the user has any of the permissions, false otherwise.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return count(array_intersect($permissions, $this->getPermissions())) > 0;
    }

    /**
     * Check if the user is a Super Admin.
     *
     * @return bool True if the user is a Super Admin, false otherwise.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(UserRole::SuperAdmin);
    }

    /**
     * Check if the user is a Company Admin.
     *
     * @return bool True if the user is a Company Admin, false otherwise.
     */
    public function isCompanyAdmin(): bool
    {
        return $this->hasRole(UserRole::CompanyAdmin);
    }

    /**
     * Companies for this user in PostgreSQL.
     *
     * @return Builder The query builder for the user's companies.
     */
    public function companies(): Builder
    {
        $companyIds = DB::connection('pgsql')
            ->table('company_user')
            ->where('user_id', $this->getKey())
            ->pluck('company_id');

        return Company::on('pgsql')
            ->whereIn('id', $companyIds)
            ->where('is_active', true)
            ->orderBy('name');
    }

    /**
     * Restrict panel access to administrative roles.
     *
     * @param  Panel  $panel  The Filament panel to check access for.
     * @return bool True if the user can access the panel, false otherwise.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        foreach ($this->getRoles() as $role) {
            $roleEnum = UserRole::tryFrom($role);
            if ($roleEnum?->hasAdminAccess()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return accessible tenants for this user.
     *
     * @param  Panel  $panel  The Filament panel to get tenants for.
     * @return array|Collection The accessible tenants for the user.
     */
    public function getTenants(Panel $panel): array|Collection
    {
        if ($this->isSuperAdmin()) {
            return Company::query()->where('is_active', true)->get();
        }

        return $this->companies()->get();
    }

    /**
     * Determine whether the user can access the tenant.
     *
     * @param  Model  $tenant  The tenant model to check access for.
     * @return bool True if the user can access the tenant, false otherwise.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->companies()
            ->where('id', $tenant->getKey())
            ->exists();
    }

    /**
     * Get the user's name for Filament.
     *
     * @return string The user's name or email if name is not set.
     */
    public function getFilamentName(): string
    {
        return (string) ($this->name ?? $this->email);
    }
}
