<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\StopDepartureRequest;
use App\Http\Resources\Api\V1\Operations\StopDepartureResource;
use App\Models\Stop;
use App\Services\Operations\StopDepartureService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StopDepartureController extends Controller
{
    /**
     * List upcoming planned departures from a stop within a time window.
     *
     * @response AnonymousResourceCollection<StopDepartureResource>
     */
    public function index(string $id, StopDepartureRequest $request, StopDepartureService $departureService): AnonymousResourceCollection
    {
        $stop = Stop::query()->whereNull('deleted_at')->find($id);

        if (! $stop) {
            abort(404, __('The requested stop does not exist or has been archived.'));
        }

        $departures = $departureService->getUpcomingDepartures(
            $stop,
            $request->input('departure_at'),
            (int) $request->input('window_minutes', 120),
        );

        return StopDepartureResource::collection($departures);
    }
}
