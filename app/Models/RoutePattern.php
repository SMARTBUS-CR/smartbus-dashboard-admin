<?php

namespace App\Models;

use App\Services\RouteRoutingPointsService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

#[Fillable([
    'route_id',
    'code',
    'name',
    'headsign',
    'route_geometry',
    'routing_adjustments',
    'distance_meters',
    'driving_duration_seconds',
    'routing_points_hash',
])]
class RoutePattern extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'route_geometry' => 'array',
            'routing_adjustments' => 'array',
            'distance_meters' => 'decimal:2',
            'driving_duration_seconds' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RoutePattern $pattern): void {
            if (! $pattern->isDirty('routing_adjustments')) {
                return;
            }

            $adjustments = $pattern->routing_adjustments;

            Validator::make([
                'routing_adjustments' => $adjustments,
            ], [
                'routing_adjustments' => ['present', 'array', 'list', 'max:100'],
                'routing_adjustments.*' => [
                    'required',
                    'array:from_occurrence_id,to_occurrence_id,points',
                ],
                'routing_adjustments.*.from_occurrence_id' => [
                    'required',
                    'uuid',
                ],
                'routing_adjustments.*.to_occurrence_id' => [
                    'required',
                    'uuid',
                ],
                'routing_adjustments.*.points' => [
                    'required',
                    'array',
                    'list',
                    'min:1',
                    'max:98',
                ],
                'routing_adjustments.*.points.*' => [
                    'required',
                    'array:lat,lng',
                ],
                'routing_adjustments.*.points.*.lat' => [
                    'required',
                    'numeric',
                    'between:-90,90',
                ],
                'routing_adjustments.*.points.*.lng' => [
                    'required',
                    'numeric',
                    'between:-180,180',
                ],
            ])->validate();

            if ($adjustments === []) {
                return;
            }

            $occurrences = $pattern->stopOccurrences()
                ->with('stop')
                ->orderBy('stop_sequence')
                ->get();

            if ($occurrences->contains(
                fn (RoutePatternStop $occurrence): bool => $occurrence->stop === null,
            )) {
                throw ValidationException::withMessages([
                    'routing_adjustments' => __(
                        'The selected stop is unavailable.',
                    ),
                ]);
            }

            $stops = $occurrences
                ->map(fn (RoutePatternStop $occurrence): array => [
                    'occurrence_id' => $occurrence->getKey(),
                    'lat' => (float) $occurrence->stop->latitude,
                    'lng' => (float) $occurrence->stop->longitude,
                ])
                ->all();

            try {
                app(RouteRoutingPointsService::class)->build(
                    $stops,
                    $adjustments,
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'routing_adjustments' => __(
                        'Review the route adjustment points and available stops before calculating.',
                    ),
                ]);
            }
        });

        static::saving(function (RoutePattern $pattern): void {
            if (! $pattern->isDirty('routing_adjustments')) {
                return;
            }

            $pattern->fill([
                'route_geometry' => null,
                'distance_meters' => null,
                'driving_duration_seconds' => null,
                'routing_points_hash' => null,
            ]);
        });

        static::saving(function (RoutePattern $pattern): void {
            $fields = [
                'route_geometry',
                'distance_meters',
                'driving_duration_seconds',
                'routing_points_hash',
            ];

            if (! $pattern->isDirty($fields)) {
                return;
            }

            $attributes = $pattern->getAttributes();

            $data = [
                'route_geometry' => $pattern->route_geometry,
                'distance_meters' => $attributes['distance_meters'] ?? null,
                'driving_duration_seconds' => $attributes['driving_duration_seconds'] ?? null,
                'routing_points_hash' => $attributes['routing_points_hash'] ?? null,
            ];

            if (collect($data)->every(
                static fn ($value): bool => $value === null,
            )) {
                return;
            }

            Validator::make($data, [
                'route_geometry' => ['required', 'array'],
                'route_geometry.type' => ['required', 'in:LineString'],
                'route_geometry.coordinates' => [
                    'required',
                    'array',
                    'list',
                    'min:2',
                ],
                'route_geometry.coordinates.*' => [
                    'required',
                    'array',
                    'list',
                    'size:2',
                ],
                'route_geometry.coordinates.*.0' => [
                    'required',
                    'numeric',
                    'between:-180,180',
                ],
                'route_geometry.coordinates.*.1' => [
                    'required',
                    'numeric',
                    'between:-90,90',
                ],
                'distance_meters' => [
                    'required',
                    'numeric',
                    'decimal:0,2',
                    'min:0',
                    'max:9999999999.99',
                ],
                'driving_duration_seconds' => [
                    'required',
                    'numeric',
                    'decimal:0,2',
                    'min:0',
                    'max:9999999999.99',
                ],
                'routing_points_hash' => [
                    'required',
                    'string',
                    'regex:/\A[a-f0-9]{64}\z/',
                ],
            ])->validate();

            if (! $pattern->route()->exists()) {
                throw ValidationException::withMessages([
                    'route_id' => __('The selected route is unavailable.'),
                ]);
            }
        });

        static::saving(function (RoutePattern $pattern): void {
            if ($pattern->exists && ! $pattern->isDirty('route_id')) {
                return;
            }

            $destinationRoute = Route::query()->find($pattern->route_id);

            if (! $destinationRoute) {
                throw ValidationException::withMessages([
                    'route_id' => __('The selected route is unavailable.'),
                ]);
            }

            if (! $pattern->exists) {
                return;
            }

            $hasIncompatibleStops = RoutePatternStop::query()
                ->join(
                    'stops',
                    'stops.id',
                    '=',
                    'route_pattern_stops.stop_id',
                )
                ->where('route_pattern_stops.route_pattern_id', $pattern->getKey())
                ->whereNotNull('stops.company_id')
                ->where('stops.company_id', '!=', $destinationRoute->company_id)
                ->exists();

            if ($hasIncompatibleStops) {
                throw ValidationException::withMessages([
                    'route_id' => __('The pattern uses private stops belonging to another company.'),
                ]);
            }
        });
    }

    public function removeObsoleteRoutingAdjustments(): void
    {
        $this->refresh();

        $adjustments = $this->routing_adjustments ?? [];

        if ($adjustments === []) {
            return;
        }

        $orderedIds = $this->stopOccurrences()
            ->pluck('id')
            ->all();

        $adjustmentsByOrigin = [];

        foreach ($adjustments as $adjustment) {
            $adjustmentsByOrigin[$adjustment['from_occurrence_id']] = $adjustment;
        }

        $retainedAdjustments = [];

        for ($index = 0; $index < count($orderedIds) - 1; $index++) {
            $adjustment = $adjustmentsByOrigin[$orderedIds[$index]] ?? null;

            if (
                $adjustment === null
                || $adjustment['to_occurrence_id'] !== $orderedIds[$index + 1]
            ) {
                continue;
            }

            $retainedAdjustments[] = $adjustment;
        }

        if ($retainedAdjustments === $adjustments) {
            return;
        }

        $this->update([
            'routing_adjustments' => $retainedAdjustments,
        ]);
    }

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * @return HasMany<RoutePatternStop, $this>
     */
    public function stopOccurrences(): HasMany
    {
        return $this->hasMany(RoutePatternStop::class)
            ->orderBy('stop_sequence');
    }

    /**
     * @return HasMany<RouteSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(RouteSchedule::class);
    }
}
