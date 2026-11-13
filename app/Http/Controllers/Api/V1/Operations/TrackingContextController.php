<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Operations\TripTrackingContextResource;
use App\Models\Trip;
use App\Models\User;
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
    public function show(Request $request, string $tripId): TripTrackingContextResource
    {
        $this->ensureActiveUser($request);
        $trip = Trip::query()->withAggregate('driver', 'user_id')->find($tripId);

        if (! $trip) {
            abort(404, __('The requested trip was not found.'));
        }

        return new TripTrackingContextResource($trip);
    }

    /**
     * With projection=eta-v1, return the frozen trip plan.
     */
    #[QueryParameter('projection', description: 'Use eta-v1 for the ETA projection. Omit for the standard tracking context.', type: 'string', example: 'eta-v1')]
    public function showInternal(Request $request, string $tripId, EtaTripContext $etaContext): TripTrackingContextResource|JsonResponse
    {
        $this->ensureActiveUser($request);

        if ($request->query('projection') !== 'eta-v1') {
            return $this->show($request, $tripId);
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

    private function ensureActiveUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User || $user->trashed()) {
            abort(401, __('The access token is invalid or expired.'));
        }

        return $user;
    }
}
