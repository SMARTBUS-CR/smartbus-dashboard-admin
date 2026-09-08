<?php

namespace App\Models;

use Filament\Models\Contracts\HasCurrentTenantLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Company extends Model implements HasCurrentTenantLabel
{
    use HasFactory, HasUuids;

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
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Users associated with this tenant from the MySQL users database.
     */
    public function users(): Builder
    {
        $userIds = DB::connection('pgsql')
            ->table('company_user')
            ->where('company_id', $this->getKey())
            ->pluck('user_id');

        return User::on('mysql')
            ->whereIn('id', $userIds)
            ->orderBy('name');
    }

    public function attachUser(User $user): void
    {
        DB::connection('pgsql')->table('company_user')->updateOrInsert([
            'company_id' => $this->getKey(),
            'user_id' => $user->getKey(),
        ], []);
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
        return 'Activo';
    }
}
