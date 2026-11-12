<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateTrackingService;
use App\Http\Resources\Api\V1\Operations\TripTrackingContextResource;
use App\Models\Trip;
use App\Services\Operations\EtaTripContext;
use Dedoc\Scramble\Attributes\QueryParameter;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /**
     * With projection=eta-v1, authenticate with the ETA service Bearer token
     * and return the frozen trip plan. Other requests use the GPS contract.
     */
    #[QueryParameter('projection', description: 'Use eta-v1 with the ETA service Bearer token. Omit for the signed GPS context.', type: 'string', example: 'eta-v1')]
    public function showInternal(string $tripId, Request $request, EtaTripContext $etaContext): TripTrackingContextResource|JsonResponse
    {
        if (! $request->attributes->getBoolean(AuthenticateTrackingService::ETA_CONTEXT_ATTRIBUTE)) {
            return $this->show($tripId);
        }

        $trip = Trip::query()->find($tripId);
        if (! $trip) {
            abort(404, __('The requested trip was not found.'));
        }

        try {
            $projection = $etaContext->project($trip);
        } catch (DomainException) {
            abort(422, __('The saved trip plan cannot be projected for ETA.'));
        }

        return response()->json($projection)->header('Cache-Control', 'no-store');
    }
}
