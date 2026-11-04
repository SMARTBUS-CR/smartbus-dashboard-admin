<?php

namespace App\Models;

use App\Enums\TripStatus;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

#[DateFormat('Y-m-d H:i:s.uP')]
#[Fillable([
    'company_id', 'route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id',
    'service_date', 'scheduled_departure_at', 'scheduled_arrival_at', 'planned_route_snapshot',
])]
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Trip $trip): void {
            $trip->status ??= TripStatus::SCHEDULED;
            if ($trip->status !== TripStatus::SCHEDULED) {
                throw ValidationException::withMessages([
                    'status' => __('New trips must be scheduled without execution data.'),
                ]);
            }
            foreach (['actual_departure_at', 'actual_arrival_at', 'cancelled_at', 'cancellation_reason'] as $field) {
                if ($trip->getAttribute($field) !== null) {
                    throw ValidationException::withMessages([
                        $field => __('New trips must be scheduled without execution data.'),
                    ]);
                }
            }
        });

        static::updating(function (Trip $trip): void {
            $changedFields = array_keys($trip->getDirty());
            if ($changedFields === ['deleted_at'] && $trip->deleted_at === null && $trip->getRawOriginal('deleted_at') !== null) {
                return;
            }

            $errors = [];
            foreach ($changedFields as $field) {
                $errors[$field] = __('Trip history cannot be changed directly.');
            }
            throw ValidationException::withMessages($errors);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'service_date' => 'immutable_date',
            'scheduled_departure_at' => 'immutable_datetime',
            'scheduled_arrival_at' => 'immutable_datetime',
            'actual_departure_at' => 'immutable_datetime',
            'actual_arrival_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'planned_route_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /** @return BelongsTo<RoutePattern, $this> */
    public function pattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class, 'route_pattern_id')->withTrashed();
    }

    /** @return BelongsTo<RouteSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RouteSchedule::class, 'route_schedule_id');
    }

    /** @return BelongsTo<Bus, $this> */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class)->withTrashed();
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }
}
