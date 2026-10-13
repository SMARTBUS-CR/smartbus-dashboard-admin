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
    'route_pattern_id',
    'stop_id',
    'stop_sequence',
    'minutes_from_start',
])]
class RoutePatternStop extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'stop_sequence' => 'integer',
            'minutes_from_start' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RoutePatternStop $occurrence): void {
            Validator::make([
                'stop_sequence' => $occurrence->getAttributes()['stop_sequence'] ?? null,
                'minutes_from_start' => $occurrence->getAttributes()['minutes_from_start'] ?? null,
            ], [
                'stop_sequence' => ['required', 'integer', 'min:1'],
                'minutes_from_start' => ['nullable', 'integer', 'min:0'],
            ])->validate();

            $pattern = RoutePattern::query()
                ->with('route')
                ->find($occurrence->route_pattern_id);

            $stop = Stop::query()->find($occurrence->stop_id);

            if (! $pattern || ! $pattern->route) {
                throw ValidationException::withMessages([
                    'route_pattern_id' => __('The selected route pattern is unavailable.'),
                ]);
            }

            if (! $stop) {
                throw ValidationException::withMessages([
                    'stop_id' => __('The selected stop is unavailable.'),
                ]);
            }

            if (
                $stop->company_id !== null
                && (string) $stop->company_id !== (string) $pattern->route->company_id
            ) {
                throw ValidationException::withMessages([
                    'stop_id' => __('The selected stop belongs to another company.'),
                ]);
            }

            if ($occurrence->minutes_from_start === null) {
                return;
            }

            $otherOccurrences = static::query()
                ->where('route_pattern_id', $occurrence->route_pattern_id)
                ->whereNotNull('minutes_from_start');

            if ($occurrence->exists) {
                $otherOccurrences->where(
                    'id',
                    '!=',
                    $occurrence->getKey(),
                );
            }

            $conflictsWithEarlierStop = (clone $otherOccurrences)
                ->where('stop_sequence', '<', $occurrence->stop_sequence)
                ->where('minutes_from_start', '>', $occurrence->minutes_from_start)
                ->exists();

            $conflictsWithLaterStop = (clone $otherOccurrences)
                ->where('stop_sequence', '>', $occurrence->stop_sequence)
                ->where('minutes_from_start', '<', $occurrence->minutes_from_start)
                ->exists();

            if ($conflictsWithEarlierStop || $conflictsWithLaterStop) {
                throw ValidationException::withMessages([
                    'minutes_from_start' => __('Estimated times must follow the stop sequence.'),
                ]);
            }
        });

        static::created(function (RoutePatternStop $occurrence): void {
            $occurrence->clearPatternRoutingData();
            $occurrence->removeObsoletePatternRoutingAdjustments();
        });

        static::updated(function (RoutePatternStop $occurrence): void {
            if ($occurrence->wasChanged(['stop_id', 'stop_sequence'])) {
                $occurrence->clearPatternRoutingData();
            }
        });

        static::deleted(function (RoutePatternStop $occurrence): void {
            $occurrence->clearPatternRoutingData();
            $occurrence->removeObsoletePatternRoutingAdjustments();
        });
    }

    /**
     * @return BelongsTo<RoutePattern, $this>
     */
    public function pattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class, 'route_pattern_id');
    }

    /**
     * @return BelongsTo<Stop, $this>
     */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            RoutePattern::withTrashed()
                ->whereKey($this->route_pattern_id)
                ->lockForUpdate()
                ->first();

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(function (): ?bool {
            RoutePattern::withTrashed()
                ->whereKey($this->route_pattern_id)
                ->lockForUpdate()
                ->first();

            return parent::delete();
        });
    }

    private function clearPatternRoutingData(): void
    {
        $pattern = RoutePattern::withTrashed()
            ->find($this->route_pattern_id);

        if ($pattern === null || $pattern->routing_points_hash === null) {
            return;
        }

        $pattern->update([
            'route_geometry' => null,
            'distance_meters' => null,
            'driving_duration_seconds' => null,
            'routing_points_hash' => null,
        ]);
    }

    private function removeObsoletePatternRoutingAdjustments(): void
    {
        $pattern = RoutePattern::withTrashed()
            ->find($this->route_pattern_id);

        $pattern?->removeObsoleteRoutingAdjustments();
    }
}
