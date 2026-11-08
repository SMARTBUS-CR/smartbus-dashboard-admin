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

    /**
     * Domain invariants must also run when Eloquent events are suppressed.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            $this->status ??= TripStatus::SCHEDULED;
            if ($this->status !== TripStatus::SCHEDULED) {
                throw ValidationException::withMessages([
                    'status' => __('New trips must be scheduled without execution data.'),
                ]);
            }
            foreach (['actual_departure_at', 'actual_arrival_at', 'cancelled_at', 'cancellation_reason'] as $field) {
                if ($this->getAttribute($field) !== null) {
                    throw ValidationException::withMessages([
                        $field => __('New trips must be scheduled without execution data.'),
                    ]);
                }
            }
        } elseif ($this->isDirty()) {
            $changedFields = array_keys($this->getDirty());
            $restoring = $changedFields === ['deleted_at']
                && $this->deleted_at === null && $this->getRawOriginal('deleted_at') !== null;
            if (! $restoring) {
                $errors = [];
                foreach ($changedFields as $field) {
                    $errors[$field] = __('Trip history cannot be changed directly.');
                }
                throw ValidationException::withMessages($errors);
            }
        }

        return parent::save($options);
    }

    /** Preserve physical history even when forceDeleteQuietly suppresses events. */
    public function forceDelete(): bool
    {
        throw ValidationException::withMessages([
            'trip' => __('Trip deletion is disabled. Preserve the trip history.'),
        ]);
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
