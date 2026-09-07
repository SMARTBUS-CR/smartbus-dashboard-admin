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
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     * @var 
     */
    public $incrementing = false;

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
     * Companies for this user in PostgreSQL.
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

        return $this->companies()->get();
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
            ->where('id', $tenant->getKey())
            ->exists();
    }

    public function getFilamentName(): string
    {
        return (string) ($this->name ?? $this->email);
    }
}
