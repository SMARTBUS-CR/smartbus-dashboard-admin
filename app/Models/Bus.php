<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'company_id',
    'country_code',
    'plate_number',
    'unit_number',
    'brand',
    'model',
    'year',
    'capacity',
])]
class Bus extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (Bus $bus): void {
            if ($bus->isDirty('company_id')) {
                throw ValidationException::withMessages([
                    'company_id' => __('Bus ownership cannot be changed.'),
                ]);
            }
        });
    }

    public function forceDelete(): bool
    {
        throw ValidationException::withMessages([
            'bus' => __('Permanent bus deletion is disabled. Archive the bus instead.'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'year' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
