<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'route_schedule_id',
    'service_date',
])]
class RouteScheduleException extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'service_date' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RouteScheduleException $exception): void {
            Validator::make([
                'service_date' => $exception->getAttributes()['service_date'] ?? null,
            ], [
                'service_date' => ['required', 'date'],
            ])->validate();

            $schedule = RouteSchedule::query()
                ->with('pattern.route')
                ->find($exception->route_schedule_id);

            if (! $schedule || ! $schedule->pattern || ! $schedule->pattern->route) {
                throw ValidationException::withMessages([
                    'route_schedule_id' => __('The selected departure is unavailable.'),
                ]);
            }

            $serviceDate = $exception->service_date;
            $localDate = $serviceDate->format('Y-m-d');

            $isBeforeValidity = $schedule->valid_from !== null
                && $localDate < $schedule->valid_from->format('Y-m-d');

            $isAfterValidity = $schedule->valid_until !== null
                && $localDate > $schedule->valid_until->format('Y-m-d');

            if (
                $serviceDate->dayOfWeek !== $schedule->day_of_week
                || $isBeforeValidity
                || $isAfterValidity
            ) {
                throw ValidationException::withMessages([
                    'service_date' => __('The departure is not scheduled on the selected date.'),
                ]);
            }
        });
    }

    /**
     * @return BelongsTo<RouteSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RouteSchedule::class, 'route_schedule_id');
    }
}
