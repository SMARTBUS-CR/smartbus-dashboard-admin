<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Operations\TripTrackingContextResource;
use App\Models\Trip;
use Illuminate\Http\Request;

class TrackingContextController extends Controller
{
    /**
     * Expose authoritative tracking context and planned snapshot for GPS telemetry.
     */
    public function show(string $tripId, Request $request): TripTrackingContextResource
    {
        $user = $request->user();

        if (! $user) {
            abort(401, __('Authentication required.'));
        }

        $trip = Trip::query()->with('company')->find($tripId);

        if (! $trip) {
            abort(404, __('The requested trip was not found.'));
        }

        if (! $user->isSuperAdmin() && ! $user->can('view', [$trip, $trip->company])) {
            abort(403, __('You are not authorized to view tracking context for this trip.'));
        }

        return new TripTrackingContextResource($trip);
    }
}
