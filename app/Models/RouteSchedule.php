<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'route_pattern_id',
    'day_of_week',
    'departure_time',
    'valid_from',
    'valid_until',
])]
class RouteSchedule extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RouteSchedule $schedule): void {
            $attributes = $schedule->getAttributes();

            $rules = [
                'day_of_week' => ['required', 'integer', 'between:0,6'],
                'departure_time' => ['required', 'string', 'date_format:H:i:s'],
                'valid_from' => ['nullable', 'date'],
                'valid_until' => ['nullable', 'date'],
            ];

            if (filled($attributes['valid_from'] ?? null)) {
                $rules['valid_until'][] = 'after_or_equal:valid_from';
            }

            Validator::make([
                'day_of_week' => $attributes['day_of_week'] ?? null,
                'departure_time' => $attributes['departure_time'] ?? null,
                'valid_from' => $attributes['valid_from'] ?? null,
                'valid_until' => $attributes['valid_until'] ?? null,
            ], $rules)->validate();

            $pattern = RoutePattern::query()
                ->with('route')
                ->find($schedule->route_pattern_id);

            if (! $pattern || ! $pattern->route) {
                throw ValidationException::withMessages([
                    'route_pattern_id' => __('The selected route pattern is unavailable.'),
                ]);
            }

            if (
                $schedule->exists
                && $schedule->isDirty(['day_of_week', 'valid_from', 'valid_until'])
            ) {
                $exceptions = $schedule->exceptions()->get(['service_date']);

                foreach ($exceptions as $exception) {
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
                            'route_schedule_id' => __('The calendar change would invalidate an existing suspension.'),
                        ]);
                    }
                }
            }
        });
    }

    /**
     * @param  Builder<RouteSchedule>  $query
     */
    #[Scope]
    protected function scheduledOn(Builder $query, CarbonInterface $date): void
    {
        $localDate = $date->format('Y-m-d');

        $query
            ->where('day_of_week', $date->dayOfWeek)
            ->where(function (Builder $query) use ($localDate): void {
                $query
                    ->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $localDate);
            })
            ->where(function (Builder $query) use ($localDate): void {
                $query
                    ->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $localDate);
            })
            ->whereDoesntHave(
                'exceptions',
                function (Builder $query) use ($localDate): void {
                    $query->where('service_date', $localDate);
                },
            );
    }

    /**
     * @return BelongsTo<RoutePattern, $this>
     */
    public function pattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class, 'route_pattern_id');
    }

    /**
     * @return HasMany<RouteScheduleException, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(RouteScheduleException::class);
    }
}
