<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Operations\RoutePatternResource;
use App\Http\Resources\Api\V1\Operations\RouteScheduleResource;
use App\Models\RoutePattern;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoutePatternController extends Controller
{
    /**
     * Get pattern details including ordered stops and GeoJSON geometry.
     */
    public function show(string $id): RoutePatternResource
    {
        $pattern = RoutePattern::query()
            ->inPublicCatalogue()
            ->with(['stopOccurrences' => function ($query) {
                $query->orderBy('stop_sequence')->with('stop');
            }])
            ->find($id);

        if (! $pattern) {
            abort(404, __('The requested route pattern does not exist or has been archived.'));
        }

        return new RoutePatternResource($pattern);
    }

    /**
     * List scheduled weekly departures for a pattern.
     *
     * @response AnonymousResourceCollection<RouteScheduleResource>
     */
    public function schedules(string $id, Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $pattern = RoutePattern::query()->with('route.company')->inPublicCatalogue()->find($id);

        if (! $pattern) {
            abort(404, __('The requested route pattern does not exist or has been archived.'));
        }

        $query = $pattern->schedules()->with(['exceptions' => fn (HasMany $exceptions): HasMany => $exceptions->orderBy('service_date')]);

        if ($request->filled('date')) {
            $companyTz = $pattern->route?->company?->timezone ?? 'UTC';
            $targetDate = CarbonImmutable::parse($request->input('date'), $companyTz);
            $query->scheduledOn($targetDate);
        }

        $schedules = $query
            ->orderBy('day_of_week')
            ->orderBy('departure_time')
            ->get();

        return RouteScheduleResource::collection($schedules);
    }
}
