<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Enums\TripStatus;
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

        // 1. Idempotency: If already in progress, return current state
        if ($trip->status === TripStatus::IN_PROGRESS) {
            // Verify actor is authorized
            if ($actor && ! $actor->isSuperAdmin() && $trip->driver?->user_id !== $actor->id) {
                abort(403);
            }

            return TripResource::make($trip);
        }

        // 2. Reject terminal state transitions with 409 Conflict
        if (in_array($trip->status, [TripStatus::COMPLETED, TripStatus::CANCELLED], true)) {
            return response()->json([
                'message' => __('Only scheduled trips can be started.'),
            ], 409);
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

        // 1. Idempotency: If already completed, return current state
        if ($trip->status === TripStatus::COMPLETED) {
            if ($actor && ! $actor->isSuperAdmin() && $trip->driver?->user_id !== $actor->id) {
                abort(403);
            }

            return TripResource::make($trip);
        }

        // 2. Reject if trip is not in progress with 409 Conflict
        if ($trip->status !== TripStatus::IN_PROGRESS) {
            return response()->json([
                'message' => __('Only in-progress trips can be completed.'),
            ], 409);
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
