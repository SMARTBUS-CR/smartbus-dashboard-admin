<?php

namespace App\Models;

use Filament\Models\Contracts\HasCurrentTenantLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model implements HasCurrentTenantLabel
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $connection = 'pgsql';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'slug',
        'legal_id',
        'phone',
        'email',
        'address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }

    /**
     * Users associated with this tenant from the MySQL users database.
     */
    public function users(): Builder
    {
        $userIds = CompanyUser::where('company_id', $this->getKey())
            ->pluck('user_id');

        return User::on('mysql')
            ->whereIn('id', $userIds)
            ->orderBy('name');
    }

    public function attachUser(User $user): void
    {
        $pivot = CompanyUser::withTrashed()
            ->where('company_id', $this->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($pivot?->trashed()) {
            $pivot->restore();
        } else {
            CompanyUser::create([
                'company_id' => $this->getKey(),
                'user_id' => $user->getKey(),
            ]);
        }
    }

    /**
     * Detach a user from this company.
     *
     * @param  User  $user  The user to detach from the company.
     */
    public function detachUser(User $user): void
    {
        CompanyUser::where('company_id', $this->getKey())
            ->where('user_id', $user->getKey())
            ->delete();
    }

    public function buses(): HasMany
    {
        return $this->hasMany(Bus::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    public function getCurrentTenantLabel(): string
    {
        return __('Active');
    }
}
