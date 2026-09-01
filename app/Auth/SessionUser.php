<?php

namespace App\Auth;

use App\Enums\UserRole;
use App\Models\Company;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class SessionUser implements Authenticatable, FilamentUser, HasName, HasTenants
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(protected array $attributes) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->attributes['id'] ?? null;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * Strict check: only super-admin and company-admin can access the dashboard.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        foreach ($this->getRoleEnums() as $roleEnum) {
            if ($roleEnum->hasAdminAccess()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get array of assigned role strings from API Gateway.
     *
     * @return array<string>
     */
    public function getRoles(): array
    {
        $roles = $this->attributes['roles'] ?? [];

        if (is_string($roles)) {
            return [$roles];
        }

        if (is_array($roles)) {
            return array_values(array_filter(array_map(function ($role) {
                if ($role instanceof UserRole) {
                    return $role->value;
                }

                return is_array($role) ? ($role['name'] ?? $role['value'] ?? '') : (string) $role;
            }, $roles)));
        }

        return [];
    }

    /**
     * Get array of UserRole enums.
     *
     * @return array<UserRole>
     */
    public function getRoleEnums(): array
    {
        $enums = [];
        foreach ($this->getRoles() as $roleString) {
            $roleEnum = UserRole::tryFrom($roleString);
            if ($roleEnum !== null) {
                $enums[] = $roleEnum;
            }
        }

        return $enums;
    }

    /**
     * Check if user has a given role (by string, UserRole enum, or list).
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

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(UserRole::SuperAdmin);
    }

    public function isCompanyAdmin(): bool
    {
        return $this->hasRole(UserRole::CompanyAdmin);
    }


    /**
     * Tenants available for this user in Filament.
     */
    public function getTenants(Panel $panel): array|Collection
    {
        if ($this->isSuperAdmin()) {
            return Company::query()->where('is_active', true)->get();
        }

        $userId = $this->getAuthIdentifier();

        return Company::query()
            ->where('is_active', true)
            ->whereHas('users', fn ($query) => $query->where('users.id', $userId))
            ->get();
    }

    /**
     * Determine whether the user can access a given tenant.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $userId = $this->getAuthIdentifier();

        return Company::query()
            ->where('id', $tenant->getKey())
            ->where('is_active', true)
            ->whereHas('users', fn ($query) => $query->where('users.id', $userId))
            ->exists();
    }

    public function getFilamentName(): string
    {
        return $this->getName();
    }

    public function getName(): string
    {
        return (string) ($this->attributes['name'] ?? $this->attributes['username'] ?? $this->getEmail());
    }

    public function getEmail(): string
    {
        return (string) ($this->attributes['email'] ?? '');
    }

    public function getKey(): mixed
    {
        return $this->getAuthIdentifier();
    }

    public function __get(string $key): mixed
    {
        return $this->getAttributeValue($key);
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function getAttributeValue(string $key): mixed
    {
        if ($key === 'name' && empty($this->attributes['name'])) {
            return $this->attributes['username'] ?? null;
        }

        return $this->attributes[$key] ?? null;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->getAttributeValue($key);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}

