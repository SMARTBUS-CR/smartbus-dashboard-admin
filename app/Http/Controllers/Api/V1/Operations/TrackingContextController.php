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
use Illuminate\Support\Facades\Gate;

class TrackingContextController extends Controller
{
    /**
     * Expose authoritative tracking context and planned snapshot for GPS telemetry.
     */
    public function show(Request $request, string $tripId): TripTrackingContextResource
    {
        $user = $this->ensureActiveUser($request);
        $trip = Trip::query()->with('company')->withAggregate('driver', 'user_id')->find($tripId);

        if (! $trip) {
            abort(404, __('The requested trip was not found.'));
        }

        $includeDriverUserId = $this->authorizeTrackingContext($user, $trip);

        return (new TripTrackingContextResource($trip))->includeDriverUserId($includeDriverUserId);
    }

    /**
     * With projection=eta-v1, return the frozen trip plan.
     */
    #[QueryParameter('projection', description: 'Use eta-v1 for the ETA projection. Omit for the standard tracking context.', type: 'string', example: 'eta-v1')]
    public function showInternal(Request $request, string $tripId, EtaTripContext $etaContext): TripTrackingContextResource|JsonResponse
    {
        $user = $this->ensureActiveUser($request);

        if ($request->query('projection') !== 'eta-v1') {
            return $this->show($request, $tripId);
        }

        $trip = Trip::query()->with('company')->find($tripId);
        if (! $trip) {
            abort(404, __('The requested trip was not found.'));
        }

        $this->authorizeTrackingContext($user, $trip);

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

    private function authorizeTrackingContext(User $user, Trip $trip): bool
    {
        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId($trip->company_id);
            $user->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($user)->authorize('viewTrackingContext', $trip);

            return Gate::forUser($user)->allows('viewTrackingIdentity', $trip);
        } finally {
            setPermissionsTeamId($previousTeam);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
