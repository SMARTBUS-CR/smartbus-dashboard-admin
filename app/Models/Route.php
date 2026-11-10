<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

#[Fillable(['company_id', 'code', 'name'])]
class Route extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /** @param Builder<Route> $query */
    #[Scope]
    protected function inPublicCatalogue(Builder $query): void
    {
        $query->whereHas('company', fn (Builder $company): Builder => $company->where('status', CompanyStatus::ACTIVE));
    }

    protected static function booted(): void
    {
        static::updating(function (Route $route): void {
            if (! $route->isDirty('company_id')) {
                return;
            }

            $hasIncompatibleStops = RoutePatternStop::query()
                ->join(
                    'route_patterns',
                    'route_patterns.id',
                    '=',
                    'route_pattern_stops.route_pattern_id',
                )
                ->join(
                    'stops',
                    'stops.id',
                    '=',
                    'route_pattern_stops.stop_id',
                )
                ->where('route_patterns.route_id', $route->getKey())
                ->whereNotNull('stops.company_id')
                ->where('stops.company_id', '!=', $route->company_id)
                ->exists();

            if ($hasIncompatibleStops) {
                throw ValidationException::withMessages([
                    'company_id' => __('The route uses private stops belonging to another company.'),
                ]);
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<RoutePattern, $this>
     */
    public function patterns(): HasMany
    {
        return $this->hasMany(RoutePattern::class);
    }

    /**
     * @return HasMany<RouteFare, $this>
     */
    public function fares(): HasMany
    {
        return $this->hasMany(RouteFare::class);
    }
}
