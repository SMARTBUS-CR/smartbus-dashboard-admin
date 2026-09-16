<?php

namespace App\Models;

use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Route extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'origin',
        'origin_lat',
        'origin_lng',
        'destination',
        'destination_lat',
        'destination_lng',
        'overview_polyline',
        'distance_km',
        'duration_minutes',
        'waypoints',
        'is_active',
    ];

    protected $casts = [
        'waypoints' => 'array',
        'is_active' => 'boolean',
        'distance_km' => 'decimal:2',
        'duration_minutes' => 'integer',
        'origin' => Coordinate::class.':origin_lat,origin_lng',
        'destination' => Coordinate::class.':destination_lat,destination_lng',
    ];

    /**
     * Get the company that owns the route.
     *
     * @return BelongsTo<Company, Route>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The "booted" method of the model is called after the model is booted and ready for use.
     * It allows you to define model event listeners, such as "saving", "creating", "updating", etc.
     *
     * In this case, we are listening for the "saving" event to automatically update the "path" attribute
     * based on the "overview_polyline" attribute.
     */
    protected static function booted(): void
    {
        static::saving(function (Route $route) {
            if ($route->isDirty('overview_polyline') && ! empty($route->overview_polyline)) {
                $route->path = DB::raw("ST_LineFromEncodedPolyline('{$route->overview_polyline}', 6)");
            } elseif (empty($route->overview_polyline)) {
                $route->path = null;
            }
        });
    }

    /**
     * Formats raw duration seconds into a human-readable string.
     *
     * @param  float  $seconds  Total duration in seconds.
     * @return string Formatted string representation (e.g., "1 h 15 min").
     */
    public static function formatDuration(float $seconds): string
    {
        $minutes = (int) round($seconds / 60);

        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes === 0
            ? "{$hours} h"
            : "{$hours} h {$remainingMinutes} min";
    }
}
