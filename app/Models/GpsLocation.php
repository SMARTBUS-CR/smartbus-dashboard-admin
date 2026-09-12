<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GpsLocation extends Model
{
    use HasFactory, HasUuids;

    protected $connection = 'pgsql';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'trip_id',
        'latitude',
        'longitude',
        'speed_kmh',
        'recorded_at',
        'location',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'speed_kmh' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
