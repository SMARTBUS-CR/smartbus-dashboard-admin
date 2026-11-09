<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Operations\TripResource;
use App\Models\Trip;
use App\Services\TripManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TripExecutionController extends Controller
{
    /**
     * Retrieve trip details.
     */
    public function show(Request $request, string $id): TripResource
    {
        $trip = Trip::with(['company', 'bus', 'pattern', 'schedule', 'driver'])->find($id);

        if (! $trip) {
            throw new NotFoundHttpException(__('The requested trip was not found.'));
        }

        $user = $request->user();
        if (! $user) {
            abort(401, __('Authentication required.'));
        }

        $isAssignedDriver = $trip->driver && (string) $trip->driver->user_id === (string) $user->id;
        $isAuthorized = $user->isSuperAdmin() || $isAssignedDriver || $user->can('view', [$trip, $trip->company]);

        if (! $isAuthorized) {
            abort(403, __('You are not authorized to view this trip.'));
        }

        return TripResource::make($trip);
    }

    /**
     * Start an eligible trip assignment.
     */
    public function start(Request $request, string $id, TripManagementService $service): TripResource|JsonResponse
    {
        $trip = Trip::with(['company', 'driver'])->find($id);

        if (! $trip) {
            throw new NotFoundHttpException(__('The requested trip was not found.'));
        }

        $actor = $request->user();
        if (! $actor) {
            abort(401, __('Authentication required.'));
        }

        try {
            $started = $service->start($actor, $trip->company, $trip->id);

            return TripResource::make($started);
        } catch (ValidationException $exception) {
            if ($exception->errors()['status'] ?? false) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ], 409);
            }

            throw $exception;
        }
    }

    /**
     * Complete an in-progress trip.
     */
    public function complete(Request $request, string $id, TripManagementService $service): TripResource|JsonResponse
    {
        $trip = Trip::with(['company', 'driver'])->find($id);

        if (! $trip) {
            throw new NotFoundHttpException(__('The requested trip was not found.'));
        }

        $actor = $request->user();
        if (! $actor) {
            abort(401, __('Authentication required.'));
        }

        try {
            $completed = $service->complete($actor, $trip->company, $trip->id);

            return TripResource::make($completed);
        } catch (ValidationException $exception) {
            if ($exception->errors()['status'] ?? false) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ], 409);
            }

            throw $exception;
        }
    }
}
