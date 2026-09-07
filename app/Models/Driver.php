<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Driver extends Model
{
    use HasFactory, HasUuids;

    protected $connection = 'pgsql';

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (Driver $driver): void {
            $driver->id ??= (string) Str::uuid();
        });
    }

    protected $fillable = [
        'user_id',
        'company_id',
        'license',
        'status',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function user(): ?User
    {
        return User::on('mysql')->find($this->user_id);
    }
}
