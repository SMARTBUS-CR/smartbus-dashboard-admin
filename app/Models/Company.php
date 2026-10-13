<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use Database\Factories\CompanyFactory;
use Filament\Models\Contracts\HasCurrentTenantLabel;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

#[Connection('pgsql')]
class Company extends Model implements HasCurrentTenantLabel, HasName
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'legal_name',
        'trade_name',
        'slug',
        'country_code',
        'legal_id',
        'operator_number',
        'phone',
        'email',
        'address',
        'timezone',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
        ];
    }

    /** Keep company insertion and default role provisioning together on failures. */
    protected function performInsert(Builder $query): bool
    {
        try {
            return $this->getConnection()->transaction(fn (): bool => DB::connection('mysql')->transaction(fn (): bool => parent::performInsert($query))
            );
        } catch (Throwable $exception) {
            // Compensate a committed MySQL write if the PostgreSQL commit fails.
            if ($this->getKey() !== null && ! static::withTrashed()->whereKey($this->getKey())->exists()) {
                Role::withoutGlobalScopes()->where('company_id', $this->getKey())->delete();
            }
            $this->exists = false;
            $this->wasRecentlyCreated = false;

            throw $exception;
        }
    }

    public function forceDelete(): bool
    {
        throw ValidationException::withMessages([
            'company' => __('Permanent company deletion is disabled. Archive the company instead.'),
        ]);
    }

    /**
     * Get the users that belong to this company.
     *
     * @return Builder<User>
     */
    public function users(): Builder
    {
        $userIds = CompanyUser::where('company_id', $this->getKey())
            ->pluck('user_id');

        return User::on('mysql')
            ->whereIn('id', $userIds)
            ->orderBy('name');
    }

    /**
     * Get the roles that belong to this company.
     *
     * @return HasMany<Role>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * Get the routes that belong to this company.
     *
     * @return HasMany<Route, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    /**
     * @return HasMany<Stop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(Stop::class);
    }

    /**
     * Generate a unique slug for the company based on the provided name.
     */
    public static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $suffix = 2;

        while (self::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * The "booted" method of the model.
     *
     * {@inheritDoc}
     */
    protected static function booted(): void
    {
        /**
         * The slug is the tenant key in the URL: it is generated only once, when the
         * company is created. It is not updated when the legal name or trade name changes.
         *
         * @see Company::creating
         * */
        static::creating(function (Company $company): void {
            if (blank($company->slug)) {
                $company->slug = self::generateUniqueSlug(
                    (string) ($company->trade_name ?: $company->legal_name)
                );
            }
        });

        /**
         * When a company is created, we need to create the default roles for that company.
         * Role creation must bypass Filament's tenant scope and creating observer,
         * which would otherwise assign these roles to the currently selected company.
         * MySQL roles and the PostgreSQL company do not share a transaction.
         *
         * @see Company::created
         * */
        static::created(function (Company $company): void {
            Role::withoutEvents(function () use ($company): void {
                foreach ([UserRole::Admin, UserRole::Driver] as $role) {
                    $role = Role::withoutGlobalScopes()->firstOrCreate(
                        [
                            'company_id' => $company->getKey(),
                            'name' => $role->value,
                            'guard_name' => 'web',
                        ],
                        ['color' => Role::generateColor()],
                    );
                    if (! $role->exists) {
                        throw new \RuntimeException('Default company role creation was cancelled.');
                    }
                }
            });

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    /**
     * Returns a label for the current tenant, which is used in the Filament admin panel.
     *
     * {@inheritDoc}
     */
    public function getCurrentTenantLabel(): string
    {
        return __('Current Company');
    }

    /**
     * Returns the name of the company, which is used in the Filament admin panel.
     *
     * {@inheritDoc}
     */
    public function getFilamentName(): string
    {
        return $this->legal_name ?: $this->trade_name ?: $this->slug ?? __('Unknown');
    }
}
