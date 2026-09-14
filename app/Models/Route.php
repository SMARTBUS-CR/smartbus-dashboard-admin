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
        'path',
        'waypoints',
        'is_active',
    ];

    protected $casts = [
        'path' => 'string', // PostGIS LineString stored as WKT (Well-Known Text)
        'waypoints' => 'array',
        'is_active' => 'boolean',
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
     * Mutator to synchronize the 'path' column in PostGIS from overview_polyline.
     *
     * This ensures that whenever the overview_polyline is set, the corresponding
     * LineString geometry is updated in the database for spatial queries.
     *
     * @param  string|null  $value  The encoded polyline string
     */
    public function setOverviewPolylineAttribute(?string $value): void
    {
        $this->attributes['overview_polyline'] = $value;

        if ($value) {
            // Converts the encoded polyline to a LineString geometry in PostGIS
            $this->attributes['path'] = DB::raw("ST_LineFromEncodedPolyline('{$value}', 6)");
        }
    }
}
