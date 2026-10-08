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
    'name',
    'description',
    'latitude',
    'longitude',
])]
class Stop extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Stop $stop): void {
            $isUsedByPattern = RoutePatternStop::query()
                ->where('stop_id', $stop->getKey())
                ->exists();

            if ($isUsedByPattern) {
                throw ValidationException::withMessages([
                    'stop' => __(
                        'This stop is used by a route pattern. Remove it from all patterns before archiving it.',
                    ),
                ]);
            }
        });
        
        static::updating(function (Stop $stop): void {
            if (! $stop->isDirty('company_id') || $stop->company_id === null) {
                return;
            }

            $hasIncompatiblePatterns = RoutePatternStop::query()
                ->join(
                    'route_patterns',
                    'route_patterns.id',
                    '=',
                    'route_pattern_stops.route_pattern_id',
                )
                ->join(
                    'routes',
                    'routes.id',
                    '=',
                    'route_patterns.route_id',
                )
                ->where('route_pattern_stops.stop_id', $stop->getKey())
                ->where('routes.company_id', '!=', $stop->company_id)
                ->exists();

            if ($hasIncompatiblePatterns) {
                throw ValidationException::withMessages([
                    'company_id' => __('The stop is used by routes belonging to another company.'),
                ]);
            }
        });

        static::updated(function (Stop $stop): void {
            if (! $stop->wasChanged(['latitude', 'longitude'])) {
                return;
            }

            $patternIds = RoutePatternStop::query()
                ->where('stop_id', $stop->getKey())
                ->select('route_pattern_id');

            RoutePattern::withTrashed()
                ->whereIn('id', $patternIds)
                ->whereNotNull('routing_points_hash')
                ->each(function (RoutePattern $pattern): void {
                    $pattern->update([
                        'route_geometry' => null,
                        'distance_meters' => null,
                        'driving_duration_seconds' => null,
                        'routing_points_hash' => null,
                    ]);
                });
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
