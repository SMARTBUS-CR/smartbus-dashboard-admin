<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Operations\TripTrackingContextResource;
use App\Models\Trip;

class TrackingContextController extends Controller
{
    /**
     * Expose authoritative tracking context and planned snapshot for GPS telemetry.
     */
    public function show(string $tripId): TripTrackingContextResource
    {
        $trip = Trip::query()->withAggregate('driver', 'user_id')->find($tripId);

        if (! $trip) {
            abort(404, __('The requested trip was not found.'));
        }

        return new TripTrackingContextResource($trip);
    }
}
