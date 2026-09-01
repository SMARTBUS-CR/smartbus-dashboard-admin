<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $connection = 'pgsql';

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
     * Users associated with this tenant from MySQL database.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'company_user',
            'company_id',
            'user_id'
        )->withTimestamps();
    }

    public function buses(): HasMany
    {
        return $this->hasMany(Bus::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }
}
