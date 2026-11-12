<?php

namespace App\Models;

use App\Enums\TripAssignmentStatus;
use Database\Factories\TripAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'company_id',
    'route_schedule_id',
    'driver_id',
    'bus_id',
    'planned_arrival_time',
    'ends_next_day',
    'valid_from',
    'valid_until',
    'status',
])]
class TripAssignment extends Model
{
    /** @use HasFactory<TripAssignmentFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'ends_next_day' => false,
        'status' => TripAssignmentStatus::ACTIVE->value,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ends_next_day' => 'boolean',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'status' => TripAssignmentStatus::class,
        ];
    }

    public function forceDelete(): bool
    {
        throw ValidationException::withMessages([
            'assignment' => __('Permanent recurring assignment deletion is disabled. Archive the assignment instead.'),
        ]);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /** @return BelongsTo<RouteSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RouteSchedule::class, 'route_schedule_id');
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    /** @return BelongsTo<Bus, $this> */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class)->withTrashed();
    }
}
