<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Operations\RoutePatternResource;
use App\Http\Resources\Api\V1\Operations\RouteScheduleResource;
use App\Models\RoutePattern;
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
            ->whereNull('deleted_at')
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
        $pattern = RoutePattern::query()->whereNull('deleted_at')->find($id);

        if (! $pattern) {
            abort(404, __('The requested route pattern does not exist or has been archived.'));
        }

        $schedules = $pattern->schedules()
            ->orderBy('day_of_week')
            ->orderBy('departure_time')
            ->get();

        return RouteScheduleResource::collection($schedules);
    }
}
